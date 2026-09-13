package main

import (
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
	deadline := time.Now().Add(probeAssociationTimeout(config))
	last := error(errHACandidatesUnavailable)
	for i, ss := range candidates {
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
	return udpWarmSession{}, last
}

var errUDPReceiveTimeout = errors.New("udp valid response timeout")

type datagramReceiver struct {
	once    sync.Once
	mu      sync.Mutex
	packets chan []byte
	done    chan struct{}
	err     error
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
		s.receiver.packets = make(chan []byte, 512)
		s.receiver.done = make(chan struct{})
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
