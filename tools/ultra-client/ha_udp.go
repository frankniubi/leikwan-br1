package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"log"
	"net"
	"sync"
	"time"
)

func armRuntimeCandidates(ctx context.Context, store *PolicyStore, config Config, status *StatusWriter) (udpWarmSession, error) {
	candidates := store.CandidateSnapshots("udp")
	defer wipeSnapshots(candidates)
	active, activeOK := store.ActiveSnapshot()
	if activeOK {
		defer active.Lease.wipe()
	}
	deadline := time.Now().Add(probeAssociationTimeout(config))
	last := error(errHACandidatesUnavailable)
	activeAttempted := false
	for i, ss := range candidates {
		if activeOK && haPathKey(ss.Lease) == haPathKey(active.Lease) {
			activeAttempted = true
		}
		if ctx.Err() != nil {
			return udpWarmSession{}, ctx.Err()
		}
		if !store.acceptsInFlight(ss) {
			return udpWarmSession{}, ErrPolicyExpired
		}
		budget := time.Until(deadline) / time.Duration(len(candidates)-i)
		if expiry := time.Until(ss.Lease.ExpiresAt); expiry < budget {
			budget = expiry
		}
		if budget <= 0 {
			return udpWarmSession{}, context.DeadlineExceeded
		}
		started := time.Now()
		session, err := armDatagram(ss.Lease, budget)
		if err == nil {
			if ctx.Err() != nil || !store.acceptsInFlight(ss) {
				session.Close()
				return udpWarmSession{}, ErrPolicyExpired
			}
			configureRuntimeDatagram(session, store, status, ss.Lease)
			session.startRuntimeReader(3 * safeUDPKeepaliveInterval)
			return udpWarmSession{
				session: session, selectionRef: ss.Lease.SelectionRef, selectionTier: ss.Lease.SelectionTier,
				candidateKeyHash: ss.Lease.CandidateKeyHash, leaseHash: ss.Lease.LeaseHash,
				payloadCeiling: normalizeRelayPayloadCeiling(ss.Lease.RelayPayloadCeiling), started: started,
			}, nil
		}
		store.FailCandidate("udp", ss.Lease)
		status.RecordCandidateFailure(ss.Lease.CandidateKeyHash, "udp", udpArmFailureReason(err), ss.Lease.LeaseHash)
		status.RecordHAEvent("udp", haErrorClass(err), ss.Policy.PolicyGeneration)
		log.Printf("udp candidate failed forward_id=%d selection_ref=%s generation=%s attempt=%d reason=%s", ss.Policy.ForwardID, ss.Lease.SelectionRef, ss.Policy.PolicyGeneration, i+1, haErrorClass(err))
		last = err
	}
	// A transient UDP probe false negative can isolate every fresh candidate
	// while the path already carrying traffic is still usable. Give that active
	// path one fresh association plus relay-echo check before failing closed.
	if activeOK && !activeAttempted && store.acceptsInFlight(active) && ctx.Err() == nil {
		budget := probeAssociationTimeout(config)
		if expiry := time.Until(active.Lease.ExpiresAt); expiry < budget {
			budget = expiry
		}
		if budget > 0 {
			started := time.Now()
			log.Printf("active candidate forwarding test started protocol=udp forward_id=%d selection_ref=%s generation=%s", active.Policy.ForwardID, active.Lease.SelectionRef, active.Policy.PolicyGeneration)
			session, err := armDatagram(active.Lease, budget)
			if err == nil && ctx.Err() == nil && store.acceptsInFlight(active) {
				if session.probeEcho([]byte("leikw-an-active-forward-check"), probeTimeout(config)) {
					forwardID := active.Policy.ForwardID
					generation := active.Policy.PolicyGeneration
					store.ClearPathFailure("udp", haPathKey(active.Lease))
					status.RecordCandidateSuccess(active.Lease.CandidateKeyHash, "udp", active.Lease.LeaseHash)
					configureRuntimeDatagram(session, store, status, active.Lease)
					session.startRuntimeReader(3 * safeUDPKeepaliveInterval)
					fallback := udpWarmSession{
						session: session, selectionRef: active.Lease.SelectionRef, selectionTier: active.Lease.SelectionTier,
						candidateKeyHash: active.Lease.CandidateKeyHash, leaseHash: active.Lease.LeaseHash,
						payloadCeiling: normalizeRelayPayloadCeiling(active.Lease.RelayPayloadCeiling), started: started,
					}
					active.Lease.wipe()
					active = RuntimeSnapshot{}
					log.Printf("active candidate forwarding test succeeded protocol=udp forward_id=%d selection_ref=%s generation=%s", forwardID, fallback.selectionRef, generation)
					return fallback, nil
				}
				session.Close()
				last = errUDPReceiveTimeout
			} else if err != nil {
				last = err
			} else {
				session.Close()
				last = ErrPolicyExpired
			}
			log.Printf("active candidate forwarding test failed protocol=udp forward_id=%d selection_ref=%s generation=%s reason=%s", store.config.ForwardID, active.Lease.SelectionRef, active.Policy.PolicyGeneration, haErrorClass(last))
		}
	}
	return udpWarmSession{}, last
}

var errUDPReceiveTimeout = errors.New("udp valid response timeout")

type datagramReceiver struct {
	once    sync.Once
	mu      sync.Mutex
	packets chan []byte
	done    chan struct{}
	err     error
	// readerOwned flips under mu before the socket's single reader loop starts
	// (the ultra runtime reader or the native SOCKS5 response loop). While set,
	// probeEcho must not touch SetReadDeadline/Read: it registers a waiter and
	// the reader dispatches the matching echo frame (2026-09-16 reader-race fix).
	readerOwned bool
	waiters     map[*probeWaiter]struct{}
}

// probeWaiter is one registered echo probe. The reader delivers the frame whose
// payload matches exactly; every other frame keeps flowing through the normal
// runtime paths.
type probeWaiter struct {
	payload []byte
	echo    chan []byte
}

// Exactly one socket reader survives the warm -> active ownership transfer.
// The provider has a fixed ACK frame, not a nonce-bearing ping protocol.
func (s *DatagramSession) startRuntimeReader(timeout time.Duration) {
	s.receiver.once.Do(func() {
		if timeout <= 0 {
			timeout = 3 * safeUDPKeepaliveInterval
		}
		// Sized for game burst rates; overflow is still counted as a drop
		// instead of blocking the reader.
		s.receiver.mu.Lock()
		s.receiver.packets = make(chan []byte, 512)
		s.receiver.done = make(chan struct{})
		s.receiver.readerOwned = true
		s.receiver.mu.Unlock()
		go func() {
			defer close(s.receiver.done)
			defer close(s.receiver.packets)
			buffer := make([]byte, 65535)
			deadline := time.Now().Add(timeout)
			for {
				if s.remote == nil {
					s.setRuntimeError(net.ErrClosed)
					return
				}
				_ = s.remote.SetReadDeadline(deadline)
				n, err := s.remote.Read(buffer)
				if err != nil {
					var ne net.Error
					if errors.As(err, &ne) && ne.Timeout() {
						err = errUDPReceiveTimeout
					}
					s.setRuntimeError(err)
					if !errors.Is(err, net.ErrClosed) && s.onRuntimeFailure != nil {
						s.onRuntimeFailure(err)
					}
					s.Close()
					return
				}
				if s.dispatchProbeEcho(buffer[:n]) {
					// The frame answered a registered probe. It proves the
					// association alive but is not a game datagram, so it never
					// reaches the packet channel.
					deadline = time.Now().Add(timeout)
					continue
				}
				ack := isDatagramKeepaliveResponse(buffer[:n], nil)
				info, valid := decodeDatagramResponse(buffer[:n])
				if !ack && (!valid || len(info.payload) == 0) {
					continue
				}
				deadline = time.Now().Add(timeout)
				if ack {
					if s.onRuntimeACK != nil {
						s.onRuntimeACK()
					}
					continue
				}
				packet := append([]byte(nil), buffer[:n]...)
				select {
				case s.receiver.packets <- packet:
					if testHookRuntimeQueueDepth != nil {
						testHookRuntimeQueueDepth(len(s.receiver.packets))
					}
				default:
					if s.onRuntimeDrop != nil {
						s.onRuntimeDrop()
					}
				}
			}
		}()
	})
}

// markSocketReaderOwned records that one reader loop now owns this socket's
// reads. Call it before spawning that loop so a concurrent probeEcho can never
// choose direct-read mode against a live reader.
func (s *DatagramSession) markSocketReaderOwned() {
	s.receiver.mu.Lock()
	s.receiver.readerOwned = true
	s.receiver.mu.Unlock()
}

// registerProbeWaiter parks a probeEcho on the receive loop. It returns nil
// while no reader owns the socket: the caller is then the only reader and may
// read the socket directly (candidate arms, warm-up and availability checks).
func (s *DatagramSession) registerProbeWaiter(payload []byte) *probeWaiter {
	s.receiver.mu.Lock()
	defer s.receiver.mu.Unlock()
	if !s.receiver.readerOwned {
		return nil
	}
	waiter := &probeWaiter{payload: append([]byte(nil), payload...), echo: make(chan []byte, 1)}
	if s.receiver.waiters == nil {
		s.receiver.waiters = make(map[*probeWaiter]struct{})
	}
	s.receiver.waiters[waiter] = struct{}{}
	return waiter
}

func (s *DatagramSession) unregisterProbeWaiter(waiter *probeWaiter) {
	s.receiver.mu.Lock()
	delete(s.receiver.waiters, waiter)
	s.receiver.mu.Unlock()
}

// dispatchProbeEcho hands one reserved keep-echo frame (source 2.0.0.0:0) to
// the probe waiter waiting for exactly those payload bytes. It reports whether
// a waiter consumed the frame: probe echoes stay out of the game packet
// channel, and business datagrams can never satisfy a waiter because probes
// only ever target the reserved address.
func (s *DatagramSession) dispatchProbeEcho(packet []byte) bool {
	if !isDatagramKeepaliveResponse(packet, nil) {
		return false
	}
	payload, ok := unwrapDatagramForSession(packet)
	if !ok {
		return false
	}
	s.receiver.mu.Lock()
	var matched *probeWaiter
	for waiter := range s.receiver.waiters {
		if bytes.Equal(waiter.payload, payload) {
			matched = waiter
			delete(s.receiver.waiters, waiter)
			break
		}
	}
	s.receiver.mu.Unlock()
	if matched == nil {
		return false
	}
	select {
	case matched.echo <- payload:
	default:
	}
	return true
}

func (s *DatagramSession) setRuntimeError(err error) {
	s.receiver.mu.Lock()
	s.receiver.err = err
	s.receiver.mu.Unlock()
}
func (s *DatagramSession) runtimeError() error {
	s.receiver.mu.Lock()
	defer s.receiver.mu.Unlock()
	return s.receiver.err
}
func (s *DatagramSession) runtimeDone() <-chan struct{} { return s.receiver.done }
func (s *DatagramSession) readRuntime(buffer []byte) (int, error) {
	packet, ok := <-s.receiver.packets
	if !ok {
		if err := s.runtimeError(); err != nil {
			return 0, err
		}
		return 0, io.EOF
	}
	return copy(buffer, packet), nil
}

// udpRuntimeFailureReason keeps the payload-ceiling verdict visible in status
// while every other runtime failure keeps the generic label.
func udpRuntimeFailureReason(err error) string {
	if errors.Is(err, errDatagramUnusable) {
		return udpPayloadCeilingUnusableReason
	}
	return "udp_association_failed"
}

// udpArmFailureReason labels a candidate that never produced a session.
//
// Losing a session that was established is evidence about the line. Failing to
// establish one is not: the relay accepts the control connection and then closes
// it without a reply, or the reply never arrives, and the very next attempt
// usually succeeds. Reporting those as line failures let a single broken local
// path manufacture a quarantine of a healthy line, because every retry carried a
// fresh evidence id. An endpoint that cannot be reached at all is different -
// nothing about the line can work through it - so that keeps counting.
func udpArmFailureReason(err error) string {
	if errors.Is(err, errProviderDial) {
		return "udp_endpoint_unreachable"
	}
	return "udp_arm_failed"
}

func configureRuntimeDatagram(s *DatagramSession, store *PolicyStore, status *StatusWriter, lease ProviderLease) {
	key, generation := haPathKey(lease), lease.PolicyGeneration
	candidateKey, leaseHash := lease.CandidateKeyHash, lease.LeaseHash
	s.onRuntimeFailure = func(err error) {
		store.failCandidateKey("udp", key, generation)
		status.RecordCandidateFailure(candidateKey, "udp", udpRuntimeFailureReason(err), leaseHash)
		status.RecordHAEvent("udp", haErrorClass(err), generation)
		log.Printf("udp session failed forward_id=%d reason=%s generation=%s", store.config.ForwardID, haErrorClass(err), generation)
	}
	s.onRuntimeACK = func() {
		status.RecordUDPKeepaliveACK()
		// A keepalive ACK is proof the path currently carries traffic: forget
		// the strikes so a later, unrelated failure starts short again instead of
		// inheriting an escalated isolation window.
		store.ClearPathFailure("udp", key)
	}
	s.onRuntimeDrop = status.AddUDPQueueDrop
	s.onCeilingMeasured = status.UpdateUDPPayloadCeiling
}
