package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"math/rand/v2"
	"net"
	"strings"
	"time"
)

// Local identity deliberately excludes rotating selection refs/passwords.
// It is never sent to the panel and never permits candidates outside its lease.
func haPathKey(l ProviderLease) string {
	sum := sha256.Sum256([]byte(net.JoinHostPort(l.IP, fmt.Sprint(l.Port)) + "\x00" + string(l.User)))
	return hex.EncodeToString(sum[:])
}

func cloneHALease(l ProviderLease) ProviderLease {
	l.User = append([]byte(nil), l.User...)
	l.Password = append([]byte(nil), l.Password...)
	l.userLocked = false
	l.passwordLocked = false
	l.lock()
	return l
}

func wipeSnapshots(ss []RuntimeSnapshot) {
	for i := range ss {
		ss[i].Lease.wipe()
	}
}

func (s *PolicyStore) CandidateSnapshots(protocol string) []RuntimeSnapshot {
	s.mu.RLock()
	defer s.mu.RUnlock()
	now := time.Now()
	if s.policy == nil || s.lease == nil || !s.policy.ExpiresAt.After(now) {
		return nil
	}
	candidates := []ProviderLease{*s.lease}
	for _, candidate := range s.candidates {
		if candidate != nil {
			candidates = append(candidates, *candidate)
		}
	}
	result := make([]RuntimeSnapshot, 0, len(candidates))
	seen := map[string]bool{}
	for _, l := range candidates {
		key := haPathKey(l)
		if seen[key] || !l.ExpiresAt.After(now) || s.isolated[protocol+":"+key].After(now) ||
			operatorQuarantines(s.config, l, protocol, now) {
			continue
		}
		seen[key] = true
		result = append(result, RuntimeSnapshot{Policy: *s.policy, Lease: cloneHALease(l)})
	}
	return result
}

func (s *PolicyStore) FailCandidate(protocol string, l ProviderLease) {
	s.failCandidateKey(protocol, haPathKey(l), l.PolicyGeneration)
}

func (s *PolicyStore) acceptsInFlight(ss RuntimeSnapshot) bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	now := time.Now()
	if _, revoked := s.revoked[ss.Policy.PolicyGeneration]; revoked {
		return false
	}
	if s.policy == nil || !s.policy.ExpiresAt.After(now) || !ss.Lease.ExpiresAt.After(now) {
		return false
	}
	return s.policy.TargetDigest == ss.Policy.TargetDigest && s.policy.Transport == ss.Policy.Transport && s.policy.RouteScope == ss.Policy.RouteScope && s.policy.RoutePolicy == ss.Policy.RoutePolicy
}

// pathIsolationWindows escalates the isolation of a path that keeps failing.
// Retrying a dead candidate every 15 seconds is what stretched one half-dead
// relay into minutes of outage on 2026-09-13: every retry spent its arm budget
// on the same corpse before walking on. Any real success clears the record.
var pathIsolationWindows = [...]time.Duration{15 * time.Second, 45 * time.Second, 2 * time.Minute}

// pathIsolationMinStrikes is how many consecutive failures a path may show
// before new streams stop using it. One is not enough: a client that connects
// and aborts before the relay answers looks exactly like a dead relay, and the
// second strike costs about a second when the path really is dead.
const pathIsolationMinStrikes = 2

// FailPath isolates a path after one unambiguous failure: a dial or a datagram
// arm that never completed. The path must belong to the current lease, so a
// stale caller can never isolate something the panel did not authorize.
func (s *PolicyStore) FailPath(protocol, key, generation string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.failPathLocked(protocol, key, generation, 1)
}

// FailSilentPath records a failure that is only suspicious in repetition: a
// stream that connected and then returned nothing. One of those can be a client
// that gave up too early, so it takes pathIsolationMinStrikes of them in a row.
//
// This isolation is business evidence: a real request reached the relay and came
// back as a reset. The active-path fallback must not clear it on a bare
// handshake, or the fallback would defeat the isolation it exists to respect.
func (s *PolicyStore) FailSilentPath(protocol, key, generation string) bool {
	return s.failSilentPath(protocol, key, generation, true)
}

func (s *PolicyStore) failSilentPath(protocol, key, generation string, business bool) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	isolated := s.failPathLocked(protocol, key, generation, pathIsolationMinStrikes)
	if isolated && business {
		if s.businessPathIsolation == nil {
			s.businessPathIsolation = map[string]bool{}
		}
		s.businessPathIsolation[protocol+":"+key] = true
	}
	return isolated
}

// businessPathIsIsolated reports whether the path's current isolation came from
// repeated business-stream evidence rather than from a probe false negative.
// Only business evidence is strong enough to refuse the active-path fallback.
func (s *PolicyStore) businessPathIsIsolated(protocol, key string) bool {
	if key == "" {
		return false
	}
	s.mu.RLock()
	defer s.mu.RUnlock()
	stateKey := protocol + ":" + key
	return s.businessPathIsolation[stateKey] && s.isolated[stateKey].After(time.Now())
}

// ClearPathFailure forgets both the isolation and the escalation streak: the
// path just carried data, so it is healthy again.
func (s *PolicyStore) ClearPathFailure(protocol, key string) {
	if key == "" {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.clearPathFailureLocked(protocol, key)
}

func (s *PolicyStore) clearPathFailureLocked(protocol, key string) {
	stateKey := protocol + ":" + key
	delete(s.pathFailures, stateKey)
	delete(s.isolated, stateKey)
	delete(s.businessPathIsolation, stateKey)
	delete(s.zeroByteTrips, stateKey)
	prefix := stateKey + ":"
	for counterKey := range s.zeroByteCloses {
		if strings.HasPrefix(counterKey, prefix) {
			delete(s.zeroByteCloses, counterKey)
		}
	}
}

// NoteStreamDelivered clears the path's failure state after a stream carried
// downstream bytes, like ClearPathFailure, except that a stream which started
// before the path's last zero-byte isolation proves nothing about the path now:
// a long download opened while the relay was healthy can finish minutes after
// the relay began closing every new stream.
func (s *PolicyStore) NoteStreamDelivered(protocol, key string, started time.Time) {
	if key == "" {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if trip, ok := s.zeroByteTrips[protocol+":"+key]; ok && started.Before(trip.at) {
		return
	}
	s.clearPathFailureLocked(protocol, key)
}

// zeroByteTrip remembers which target tripped a zero-byte isolation of a path,
// so the same pattern on the alternative path can be recognised as the target's.
type zeroByteTrip struct {
	target string
	at     time.Time
}

const (
	// zeroByteCloseStrikes zero-byte closes on one path to one target within
	// zeroByteCloseWindow isolate the path. A relay that has lost its upstream
	// closes every new stream, so at the observed ~9 streams/s this trips in well
	// under a second, while a single short request that legitimately got no
	// reply does not.
	zeroByteCloseStrikes = 3
	zeroByteCloseWindow  = 10 * time.Second
	// zeroByteTargetSideWindow is how soon after one path's zero-byte isolation
	// the same target tripping on another path reads as the target's fault.
	zeroByteTargetSideWindow = time.Minute
	// zeroByteTargetSideHold suppresses zero-byte isolation for a target that
	// closed streams on two paths, so a dead target cannot bounce traffic
	// between healthy relays.
	zeroByteTargetSideHold = 2 * time.Minute
	zeroByteStateLimit     = 512
)

// NoteZeroByteClose records a stream the relay accepted, took the first flight
// of, and then closed cleanly without returning a byte and without the explicit
// target-refused frame. One such stream is ambiguous; zeroByteCloseStrikes in a
// row on the same path and target isolate the path as business evidence (the
// active-path rescue must not revive it on a bare handshake), with the usual
// escalating windows. It is taken only when another usable path exists.
//
// If the same target then trips on another path within zeroByteTargetSideWindow,
// the target is closing the streams, not the relay: the first path is restored,
// the second is not isolated, and zero-byte isolation for that target pauses.
// None of this is global candidate reputation.
func (s *PolicyStore) NoteZeroByteClose(protocol, key, target, generation string) (isolated, targetSide bool) {
	if key == "" {
		return false, false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	now := time.Now()
	if s.zeroByteCloses == nil {
		s.zeroByteCloses = map[string][]time.Time{}
	}
	if s.zeroByteTrips == nil {
		s.zeroByteTrips = map[string]zeroByteTrip{}
	}
	if s.zeroByteTargetSide == nil {
		s.zeroByteTargetSide = map[string]time.Time{}
	}
	s.pruneZeroByteStateLocked(now)
	targetKey := protocol + ":" + target
	if until, held := s.zeroByteTargetSide[targetKey]; held && until.After(now) {
		return false, true
	}
	counterKey := protocol + ":" + key + ":" + target
	recent := s.zeroByteCloses[counterKey][:0:0]
	for _, at := range s.zeroByteCloses[counterKey] {
		if now.Sub(at) <= zeroByteCloseWindow {
			recent = append(recent, at)
		}
	}
	recent = append(recent, now)
	if len(recent) < zeroByteCloseStrikes {
		s.zeroByteCloses[counterKey] = recent
		return false, false
	}
	delete(s.zeroByteCloses, counterKey)
	prefix := protocol + ":"
	for tripKey, trip := range s.zeroByteTrips {
		if !strings.HasPrefix(tripKey, prefix) || tripKey == prefix+key || trip.target != target ||
			now.Sub(trip.at) > zeroByteTargetSideWindow {
			continue
		}
		s.clearPathFailureLocked(protocol, strings.TrimPrefix(tripKey, prefix))
		s.zeroByteTargetSide[targetKey] = now.Add(zeroByteTargetSideHold)
		return false, true
	}
	if !s.hasOtherUsablePathLocked(protocol, key, now) {
		return false, false
	}
	isolated = s.failPathLocked(protocol, key, generation, 1)
	if isolated {
		stateKey := protocol + ":" + key
		if s.businessPathIsolation == nil {
			s.businessPathIsolation = map[string]bool{}
		}
		s.businessPathIsolation[stateKey] = true
		s.zeroByteTrips[stateKey] = zeroByteTrip{target: target, at: now}
	}
	return isolated, false
}

// pruneZeroByteStateLocked drops expired zero-byte evidence. A native SOCKS
// runtime sees many targets, so the maps are swept rather than left to grow.
func (s *PolicyStore) pruneZeroByteStateLocked(now time.Time) {
	if len(s.zeroByteCloses)+len(s.zeroByteTrips)+len(s.zeroByteTargetSide) < zeroByteStateLimit {
		return
	}
	for key, closes := range s.zeroByteCloses {
		if len(closes) == 0 || now.Sub(closes[len(closes)-1]) > zeroByteCloseWindow {
			delete(s.zeroByteCloses, key)
		}
	}
	for key, trip := range s.zeroByteTrips {
		if now.Sub(trip.at) > zeroByteTargetSideWindow && !s.isolated[key].After(now) {
			delete(s.zeroByteTrips, key)
		}
	}
	for key, until := range s.zeroByteTargetSide {
		if !until.After(now) {
			delete(s.zeroByteTargetSide, key)
		}
	}
}

func (s *PolicyStore) failCandidateKey(protocol, key, generation string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.failPathLocked(protocol, key, generation, 1)
}

func (s *PolicyStore) failPathLocked(protocol, key, generation string, minStrikes int) bool {
	if s.isolated == nil {
		s.isolated = map[string]time.Time{}
	}
	if s.pathFailures == nil {
		s.pathFailures = map[string]int{}
	}
	// A healthy association survives generation rotation, so its later failure
	// must still isolate the SAME physical/logical path. Never affect a new path.
	if s.policy == nil || s.lease == nil || !s.pathIsAuthorizedLocked(key) {
		return false
	}
	stateKey := protocol + ":" + key
	streak := s.pathFailures[stateKey] + 1
	if streak > 65535 {
		streak = 65535
	}
	s.pathFailures[stateKey] = streak
	if streak < minStrikes {
		return false
	}
	index := streak - minStrikes
	if index > len(pathIsolationWindows)-1 {
		index = len(pathIsolationWindows) - 1
	}
	window := pathIsolationWindows[index]
	if remaining := time.Until(s.policy.ExpiresAt); remaining > 0 && remaining < window {
		window = remaining
	}
	now := time.Now()
	s.isolated[stateKey] = now.Add(window)
	if s.allPathsIsolatedLocked(protocol, now) {
		s.collapseGlobalOutageLocked(protocol, now)
	}
	return true
}

// authorizedPathKeysLocked lists the path keys the current lease authorizes.
// Everything the health logic may touch lives in this set; a stale caller can
// never isolate, clear, or shorten a path the panel did not authorize.
func (s *PolicyStore) authorizedPathKeysLocked() []string {
	keys := make([]string, 0, len(s.candidates)+1)
	if s.lease != nil {
		keys = append(keys, haPathKey(*s.lease))
	}
	for _, l := range s.candidates {
		if l != nil {
			keys = append(keys, haPathKey(*l))
		}
	}
	return keys
}

// allPathsIsolatedLocked reports whether a protocol has no usable path left,
// which means the next stream cannot be served at all.
func (s *PolicyStore) allPathsIsolatedLocked(protocol string, now time.Time) bool {
	eligible := 0
	candidates := append([]*ProviderLease{s.lease}, s.candidates...)
	for _, lease := range candidates {
		if lease == nil || operatorQuarantines(s.config, *lease, protocol, now) {
			continue
		}
		eligible++
		key := haPathKey(*lease)
		if until, ok := s.isolated[protocol+":"+key]; !ok || !until.After(now) {
			return false
		}
	}
	return eligible > 0
}

// collapseGlobalOutageLocked bounds a total outage by the first isolation
// window.
//
// Every authorized path is down at once: that is a local or upstream outage,
// not evidence about any single relay, so the escalation must not outlive it.
// Forgetting the streaks alone was not enough. The paths that had already
// escalated kept their 45s/2min deadlines in s.isolated, and the only thing
// that clears an isolation is a stream that succeeds - which cannot happen
// while every path is isolated. The tunnel therefore stayed dead for the whole
// escalated window with nothing left to try.
//
// On 2026-09-14 that turned a relay hiccup of about a second into 151 seconds
// of "no healthy authorized candidate" on one forward (13:15:18 -> 13:17:49),
// long after the panel had issued a fresh, working selection. Clamping the
// deadlines to the first window keeps the documented bound true and leaves the
// next attempt a few seconds away instead of minutes. Dropping the isolations
// outright is not the answer either: that restores the one-second reconnect
// storm against a pool that really is down, which is what the first window
// exists to prevent.
func (s *PolicyStore) collapseGlobalOutageLocked(protocol string, now time.Time) {
	// Protocol isolation: a total outage on one protocol must not erase the
	// failure streak another protocol accumulated against the same relay. Only
	// this protocol's streak is collapsed.
	prefix := protocol + ":"
	for stateKey := range s.pathFailures {
		if strings.HasPrefix(stateKey, prefix) {
			delete(s.pathFailures, stateKey)
		}
	}
	limit := now.Add(pathIsolationWindows[0])
	for _, key := range s.authorizedPathKeysLocked() {
		stateKey := protocol + ":" + key
		if until, ok := s.isolated[stateKey]; ok && until.After(limit) {
			s.isolated[stateKey] = limit
		}
	}
}

// hasOtherUsablePath reports whether a new stream could be served by an
// authorized, unexpired, non-isolated path other than key. Evidence against a
// path only helps when there is somewhere else to go.
func (s *PolicyStore) hasOtherUsablePath(protocol, key string) bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.hasOtherUsablePathLocked(protocol, key, time.Now())
}

func (s *PolicyStore) hasOtherUsablePathLocked(protocol, key string, now time.Time) bool {
	if s.policy == nil || !s.policy.ExpiresAt.After(now) {
		return false
	}
	for _, lease := range append([]*ProviderLease{s.lease}, s.candidates...) {
		if lease == nil || !lease.ExpiresAt.After(now) || operatorQuarantines(s.config, *lease, protocol, now) {
			continue
		}
		other := haPathKey(*lease)
		if other == key {
			continue
		}
		if until, isolated := s.isolated[protocol+":"+other]; isolated && until.After(now) {
			continue
		}
		return true
	}
	return false
}

func (s *PolicyStore) pathIsAuthorizedLocked(key string) bool {
	matched := s.lease != nil && haPathKey(*s.lease) == key
	for _, l := range s.candidates {
		matched = matched || (l != nil && haPathKey(*l) == key)
	}
	return matched
}

// dialCandidateAttempt runs one candidate dial under a cancellation-aware budget.
// A dialer that ignores its own timeout must not be able to block runtime
// cancellation, and the worker owns the cloned lease secrets until it exits.
func dialCandidateAttempt(ctx context.Context, lease ProviderLease, host string, port int, budget time.Duration, dial candidateStreamDial) (net.Conn, error) {
	owned := cloneHALease(lease)
	type dialResult struct {
		conn net.Conn
		err  error
	}
	result := make(chan dialResult)
	attempt, stop := context.WithTimeout(ctx, budget)
	defer stop()
	go func() {
		defer owned.wipe()
		c, e := dial(owned, host, port, budget)
		select {
		case result <- dialResult{c, e}:
		case <-attempt.Done():
			if c != nil {
				c.Close()
			}
		}
	}()
	select {
	case r := <-result:
		if err := attempt.Err(); err != nil {
			if r.conn != nil {
				r.conn.Close()
			}
			return nil, err
		}
		return r.conn, r.err
	case <-attempt.Done():
		return nil, attempt.Err()
	}
}

type candidateStreamDial func(ProviderLease, string, int, time.Duration) (net.Conn, error)

var errHACandidatesUnavailable = errors.New("no healthy authorized candidate")

// No caller payload has been read when this helper retries. Attempts are scoped
// to one immutable generation; a concurrent refresh cannot widen its authority.
func dialCandidateStream(ctx context.Context, s *PolicyStore, host string, port int, total time.Duration, dial candidateStreamDial) (net.Conn, RuntimeSnapshot, error) {
	return dialCandidateStreamForProtocol(ctx, s, "tcp", host, port, total, dial)
}

// Logical UDP uses the same TCP carrier dialer, but has independent health and
// quarantine state. A failed user-TCP path must not evict a working UoT carrier.
func dialCandidateStreamForProtocol(ctx context.Context, s *PolicyStore, protocol, host string, port int, total time.Duration, dial candidateStreamDial) (net.Conn, RuntimeSnapshot, error) {
	return dialCandidateStreamReporting(ctx, s, protocol, host, port, total, dial, nil)
}

// candidateFailureHook observes one failed setup attempt on an authorized
// candidate, after its local isolation was recorded. It may run after the dial
// helper returned (see drainOvertakenAttempts), so it must be goroutine safe.
type candidateFailureHook func(ss RuntimeSnapshot, err error)

// fixedTargetFailureReason names a failed fixed-target setup attempt in the
// native gateway's candidate-feedback vocabulary (nativeTCPFailureReason and
// udpArmFailureReason). ok is false when the attempt is no evidence about the
// relay: a local cancellation or expiry, or the relay's own report that the
// fixed target refused. A downed target is reached through every relay, so
// blaming each relay for it would quarantine healthy lines for every tenant.
func fixedTargetFailureReason(protocol string, err error) (string, bool) {
	switch {
	case err == nil, errors.Is(err, context.Canceled), errors.Is(err, ErrPolicyExpired):
		return "", false
	case streamCredentialRejected(err):
		return credentialRejectedReason, true
	case errors.Is(err, errStreamCommandRejected):
		return "", false
	case protocol == "udp":
		// The UoT carrier is a stream to the sidecar; its relay leg failing
		// is a logical-UDP arm failure, exactly as for a datagram arm.
		return udpArmFailureReason(err), true
	}
	return nativeTCPFailureReason(err), true
}

// fixedTargetFailureFeedback reports failed fixed-target setup attempts to the
// Panel through candidate_feedback, the way dialNativeStreamWithCandidates does
// for the native gateway. Before it existed a port-forward relay that timed
// out only produced a local tcp_connect_failed, so the Panel never learned
// which relay was failing. The Panel counts one failure per observer and
// window however often a device retries, so every attempt is reported.
func fixedTargetFailureFeedback(status *StatusWriter, protocol string) candidateFailureHook {
	if status == nil {
		return nil
	}
	return func(ss RuntimeSnapshot, err error) {
		reason, ok := fixedTargetFailureReason(protocol, err)
		if !ok {
			return
		}
		key := ss.Lease.CandidateKeyHash
		if strings.TrimSpace(key) == "" {
			key = ss.Lease.SelectionRef
		}
		status.RecordCandidateFailure(key, protocol, reason, ss.Lease.LeaseHash)
	}
}

// Staggered candidate setup.
//
// The setup budget used to be split evenly: with the hosts' 5 s connect timeout
// and four candidates every relay got about 1.25 s for connect, greeting and
// reply. One lost SYN (a 1 s retransmission) or a relay that needed 1.5 s to
// open the target therefore failed every candidate in turn, isolated all of
// them, and an OpenWrt router saw minutes of "tcp stream setup failed" against
// relays that were healthy (2026-11-12).
//
// An attempt now keeps up to candidateAttemptCap of its own, and the next
// candidate is started next to it instead of after it: immediately when the
// running attempt fails, otherwise one stagger later. The stagger is the old
// even share of the remaining budget, so every candidate starts no later than
// it did under the split, but a slow attempt is no longer cut off when its
// share runs out. The first completed handshake wins; no caller payload has
// been read, so no byte is ever sent twice. Every attempt still ends at the one
// total deadline.
const (
	candidateAttemptCap = 3 * time.Second
	// The stagger never drops below a normal relay handshake, so a healthy
	// path is not hedged on every stream, and never exceeds half the attempt
	// cap, so a black-holed first candidate delays a stream by at most 1.5 s.
	candidateStaggerMin = 250 * time.Millisecond
	candidateStaggerMax = 1500 * time.Millisecond
)

// candidateLaunchJitter desynchronizes a burst of failed 128-worker connections.
// It stays inside the original setup deadline and never replays user bytes.
func candidateLaunchJitter(remaining time.Duration) time.Duration {
	pause := time.Duration(10+rand.IntN(41)) * time.Millisecond
	if pause > remaining/4 {
		pause = remaining / 4
	}
	if pause < 0 {
		pause = 0
	}
	return pause
}

type candidateAttemptResult struct {
	index int
	conn  net.Conn
	err   error
}

// recordCandidateAttemptFailure is the evidence one failed candidate attempt
// leaves behind: local isolation, a log line that names the candidate by opaque
// hashes and keeps the raw error, and the optional Panel feedback hook.
func recordCandidateAttemptFailure(s *PolicyStore, protocol, host string, port int, ss RuntimeSnapshot, attempt int, err error, onFailure candidateFailureHook) {
	s.FailCandidate(protocol, ss.Lease)
	log.Printf("tcp candidate failed forward_id=%d selection_ref=%s generation=%s attempt=%d reason=%s error=%q candidate_hash=%s lease_hash=%s relay_hash=%s target_hash=%s", ss.Policy.ForwardID, ss.Lease.SelectionRef, ss.Policy.PolicyGeneration, attempt, haErrorClass(err), boundedStatusText(fmt.Sprint(err), 160), ss.Lease.CandidateKeyHash, ss.Lease.LeaseHash, haPathKey(ss.Lease), tcpTargetHash(net.JoinHostPort(host, fmt.Sprint(port))))
	if onFailure != nil {
		onFailure(ss, err)
	}
}

// drainOvertakenAttempts settles the attempts that were still running when a
// sibling won. Abandoning them silently would never isolate a black-holed
// candidate: every new stream would wait one stagger on it again. Each one is
// judged by its own result within its own deadline; a late success is only
// closed. done is closed once every attempt is settled, which releases (and
// closes) any result that arrives later still.
func drainOvertakenAttempts(ctx context.Context, s *PolicyStore, protocol, host string, port int, pending map[int]RuntimeSnapshot, until map[int]time.Time, results <-chan candidateAttemptResult, done chan struct{}, onFailure candidateFailureHook) {
	defer close(done)
	defer func() {
		for _, ss := range pending {
			ss.Lease.wipe()
		}
	}()
	for len(pending) > 0 {
		wake := time.Time{}
		for i := range pending {
			if wake.IsZero() || until[i].Before(wake) {
				wake = until[i]
			}
		}
		timer := time.NewTimer(time.Until(wake))
		select {
		case <-ctx.Done():
			timer.Stop()
			return
		case r := <-results:
			timer.Stop()
			if r.conn != nil {
				r.conn.Close()
			}
			ss, ok := pending[r.index]
			if !ok {
				continue
			}
			delete(pending, r.index)
			if r.err != nil && s.acceptsInFlight(ss) {
				recordCandidateAttemptFailure(s, protocol, host, port, ss, r.index+1, r.err, onFailure)
			}
			ss.Lease.wipe()
		case <-timer.C:
			now := time.Now()
			for i, ss := range pending {
				if now.Before(until[i]) {
					continue
				}
				delete(pending, i)
				if s.acceptsInFlight(ss) {
					recordCandidateAttemptFailure(s, protocol, host, port, ss, i+1, context.DeadlineExceeded, onFailure)
				}
				ss.Lease.wipe()
			}
		}
	}
}

// dialCandidateStreamReporting is dialCandidateStreamForProtocol with a hook
// that sees every failed candidate attempt, so fixed-target port forwarding can
// report the failing relay to the Panel like the native gateway does.
func dialCandidateStreamReporting(ctx context.Context, s *PolicyStore, protocol, host string, port int, total time.Duration, dial candidateStreamDial, onFailure candidateFailureHook) (net.Conn, RuntimeSnapshot, error) {
	if protocol != "tcp" && protocol != "udp" {
		return nil, RuntimeSnapshot{}, ErrPolicyInvalid
	}
	candidates := s.CandidateSnapshots(protocol)
	// A setup-only retry may append an owned lease clone below.
	defer func() { wipeSnapshots(candidates) }()
	active, activeOK := s.ActiveSnapshot()
	if activeOK {
		defer active.Lease.wipe()
		if operatorQuarantines(s.config, active.Lease, protocol, time.Now()) {
			activeOK = false // Never bypass operator exclusion through rescue.
		}
	}
	if total <= 0 {
		total = 15 * time.Second
	}
	deadline := time.Now().Add(total)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	last := error(errHACandidatesUnavailable)
	var first error
	activeAttempted := false

	// Workers hand their result to this loop, or to drainOvertakenAttempts
	// once a sibling won. Closing done releases every worker that is left; a
	// worker then closes its own late connection.
	results := make(chan candidateAttemptResult)
	done := make(chan struct{})
	drainOwnsDone := false
	defer func() {
		if !drainOwnsDone {
			close(done)
		}
	}()
	var running []int
	until := map[int]time.Time{}
	stopRunning := func(i int) bool {
		for j, k := range running {
			if k == i {
				running = append(running[:j], running[j+1:]...)
				return true
			}
		}
		return false
	}
	next := 0
	nextLaunch := time.Now()
	// launchSoon moves the next start forward after a failure; a failed
	// attempt no longer holds its sibling back for the rest of the stagger.
	launchSoon := func() {
		if soon := time.Now().Add(candidateLaunchJitter(time.Until(deadline))); soon.Before(nextLaunch) {
			nextLaunch = soon
		}
	}
	failed := func(i int, e error) {
		ss := candidates[i]
		recordCandidateAttemptFailure(s, protocol, host, port, ss, i+1, e, onFailure)
		if first == nil {
			first = e
		}
		last = e
		launchSoon()
		// With one eligible TCP path, the per-attempt cap used to discard
		// the rest of the total setup budget after one transient timeout.
		// Retry at most once, before reading any caller payload. Do not
		// retry EOF/protocol rejection or replay an established stream.
		var timeout net.Error
		if protocol == "tcp" && i == 0 && len(candidates) == 1 &&
			(errors.Is(e, context.DeadlineExceeded) || (errors.As(e, &timeout) && timeout.Timeout())) &&
			ctx.Err() == nil && time.Until(deadline) > 100*time.Millisecond && s.acceptsInFlight(ss) {
			retry := ss
			retry.Lease = cloneHALease(ss.Lease)
			candidates = append(candidates, retry)
		}
	}
	for next < len(candidates) || len(running) > 0 {
		now := time.Now()
		// An attempt that outlived its own budget failed; its late result is
		// closed when it arrives.
		for _, i := range append([]int(nil), running...) {
			if !now.Before(until[i]) {
				stopRunning(i)
				failed(i, context.DeadlineExceeded)
			}
		}
		if next < len(candidates) && !now.Before(nextLaunch) {
			i := next
			next++
			ss := candidates[i]
			if activeOK && haPathKey(ss.Lease) == haPathKey(active.Lease) {
				activeAttempted = true
			}
			if !s.acceptsInFlight(ss) {
				return nil, RuntimeSnapshot{}, ErrPolicyExpired
			}
			if err := ctx.Err(); err != nil {
				return nil, RuntimeSnapshot{}, err
			}
			remaining := time.Until(deadline)
			if remaining <= 0 {
				return nil, RuntimeSnapshot{}, &candidateDialFailure{First: first, Last: last, Terminal: context.DeadlineExceeded}
			}
			budget := remaining
			if budget > candidateAttemptCap {
				budget = candidateAttemptCap
			}
			if expiry := time.Until(ss.Lease.ExpiresAt); expiry < budget {
				budget = expiry
			}
			if budget <= 0 {
				continue
			}
			stagger := remaining / time.Duration(len(candidates)-i)
			if stagger < candidateStaggerMin {
				stagger = candidateStaggerMin
			}
			if stagger > candidateStaggerMax {
				stagger = candidateStaggerMax
			}
			nextLaunch = now.Add(stagger + candidateLaunchJitter(remaining))
			// The worker owns a clone even if this loop returns before its dial ends.
			owned := cloneHALease(ss.Lease)
			go func(index int, owned ProviderLease, budget time.Duration) {
				defer owned.wipe()
				c, e := dial(owned, host, port, budget)
				select {
				case results <- candidateAttemptResult{index: index, conn: c, err: e}:
				case <-done:
					if c != nil {
						c.Close()
					}
				}
			}(i, owned, budget)
			running = append(running, i)
			until[i] = now.Add(budget)
			continue
		}
		if len(running) == 0 && next >= len(candidates) {
			break
		}
		wake := deadline
		if next < len(candidates) && nextLaunch.Before(wake) {
			wake = nextLaunch
		}
		for _, i := range running {
			if until[i].Before(wake) {
				wake = until[i]
			}
		}
		timer := time.NewTimer(time.Until(wake))
		select {
		case <-ctx.Done():
			timer.Stop()
			return nil, RuntimeSnapshot{}, ctx.Err()
		case <-timer.C:
		case r := <-results:
			timer.Stop()
			if !stopRunning(r.index) {
				// Already counted as timed out at its own deadline.
				if r.conn != nil {
					r.conn.Close()
				}
				continue
			}
			if ctx.Err() != nil {
				if r.conn != nil {
					r.conn.Close()
				}
				return nil, RuntimeSnapshot{}, ctx.Err()
			}
			if r.err != nil {
				if r.conn != nil {
					r.conn.Close()
				}
				failed(r.index, r.err)
				continue
			}
			ss := candidates[r.index]
			if !s.acceptsInFlight(ss) {
				r.conn.Close()
				return nil, RuntimeSnapshot{}, ErrPolicyExpired
			}
			if len(running) > 0 {
				pending := make(map[int]RuntimeSnapshot, len(running))
				deadlines := make(map[int]time.Time, len(running))
				for _, k := range running {
					pending[k] = RuntimeSnapshot{Policy: candidates[k].Policy, Lease: cloneHALease(candidates[k].Lease)}
					deadlines[k] = until[k]
				}
				drainOwnsDone = true
				go drainOvertakenAttempts(ctx, s, protocol, host, port, pending, deadlines, results, done, onFailure)
			}
			ss.Lease = cloneHALease(ss.Lease)
			return r.conn, ss, nil
		}
	}
	// A probe can reject every fresh candidate during a transient false
	// negative even though the path that was carrying traffic still works. Give
	// that active path one real target-forwarding attempt before surfacing
	// no_healthy_candidate. The attempt stays inside the same lease/policy
	// generation checks and never widens the authorized candidate set.
	// Business-read resets are stronger evidence than a probe false negative. An
	// active path isolated by real business failures must not be rescued by
	// another bare handshake.
	if activeOK && !activeAttempted && s.acceptsInFlight(active) && ctx.Err() == nil &&
		!s.businessPathIsIsolated(protocol, haPathKey(active.Lease)) {
		remaining := time.Until(deadline)
		if remaining > 0 {
			budget := remaining
			if budget > 3*time.Second {
				budget = 3 * time.Second
			}
			log.Printf("active candidate forwarding test started protocol=tcp forward_id=%d selection_ref=%s generation=%s", active.Policy.ForwardID, active.Lease.SelectionRef, active.Policy.PolicyGeneration)
			connection, err := dialCandidateAttempt(ctx, active.Lease, host, port, budget, dial)
			if ctx.Err() != nil {
				if connection != nil {
					connection.Close()
				}
				return nil, RuntimeSnapshot{}, ctx.Err()
			}
			if err == nil {
				if !s.acceptsInFlight(active) {
					connection.Close()
					return nil, RuntimeSnapshot{}, ErrPolicyExpired
				}
				if s.businessPathIsIsolated(protocol, haPathKey(active.Lease)) {
					connection.Close()
					return nil, RuntimeSnapshot{}, errHACandidatesUnavailable
				}
				// A handshake is not business availability. The path is left
				// isolated until relayTCP observes actual remote payload bytes.
				fallback := RuntimeSnapshot{Policy: active.Policy, Lease: cloneHALease(active.Lease)}
				active.Lease.wipe()
				active = RuntimeSnapshot{}
				log.Printf("active candidate forwarding test succeeded protocol=tcp forward_id=%d selection_ref=%s generation=%s", fallback.Policy.ForwardID, fallback.Lease.SelectionRef, fallback.Policy.PolicyGeneration)
				return connection, fallback, nil
			}
			last = err
			if first == nil {
				first = err
			}
			log.Printf("active candidate forwarding test failed protocol=tcp forward_id=%d selection_ref=%s generation=%s reason=%s error=%q", active.Policy.ForwardID, active.Lease.SelectionRef, active.Policy.PolicyGeneration, haErrorClass(err), boundedStatusText(fmt.Sprint(err), 160))
			// A real dial through the active path failed: that is the same
			// evidence as any other candidate attempt, not a probe verdict.
			if onFailure != nil {
				onFailure(active, err)
			}
		}
	}
	return nil, RuntimeSnapshot{}, &candidateDialFailure{First: first, Last: last, Terminal: errHACandidatesUnavailable}
}
