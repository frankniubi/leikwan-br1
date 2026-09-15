package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"net"
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
		if seen[key] || !l.ExpiresAt.After(now) || s.isolated[protocol+":"+key].After(now) {
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
func (s *PolicyStore) FailSilentPath(protocol, key, generation string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.failPathLocked(protocol, key, generation, pathIsolationMinStrikes)
}

// ClearPathFailure forgets both the isolation and the escalation streak: the
// path just carried data, so it is healthy again.
func (s *PolicyStore) ClearPathFailure(protocol, key string) {
	if key == "" {
		return
	}
	stateKey := protocol + ":" + key
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.pathFailures, stateKey)
	delete(s.isolated, stateKey)
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
	keys := s.authorizedPathKeysLocked()
	for _, key := range keys {
		if until, ok := s.isolated[protocol+":"+key]; !ok || !until.After(now) {
			return false
		}
	}
	return len(keys) > 0
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
	s.pathFailures = map[string]int{}
	limit := now.Add(pathIsolationWindows[0])
	for _, key := range s.authorizedPathKeysLocked() {
		stateKey := protocol + ":" + key
		if until, ok := s.isolated[stateKey]; ok && until.After(limit) {
			s.isolated[stateKey] = limit
		}
	}
}

func (s *PolicyStore) pathIsAuthorizedLocked(key string) bool {
	matched := s.lease != nil && haPathKey(*s.lease) == key
	for _, l := range s.candidates {
		matched = matched || (l != nil && haPathKey(*l) == key)
	}
	return matched
}

type candidateStreamDial func(ProviderLease, string, int, time.Duration) (net.Conn, error)

var errHACandidatesUnavailable = errors.New("no healthy authorized candidate")

// No caller payload has been read when this helper retries. Attempts are scoped
// to one immutable generation; a concurrent refresh cannot widen its authority.
func dialCandidateStream(ctx context.Context, s *PolicyStore, host string, port int, total time.Duration, dial candidateStreamDial) (net.Conn, RuntimeSnapshot, error) {
	candidates := s.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	active, activeOK := s.ActiveSnapshot()
	if activeOK {
		defer active.Lease.wipe()
	}
	if total <= 0 {
		total = 15 * time.Second
	}
	deadline := time.Now().Add(total)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	last := error(errHACandidatesUnavailable)
	activeAttempted := false
	for i, ss := range candidates {
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
			return nil, RuntimeSnapshot{}, context.DeadlineExceeded
		}
		budget := remaining / time.Duration(len(candidates)-i)
		if budget > 3*time.Second {
			budget = 3 * time.Second
		}
		if expiry := time.Until(ss.Lease.ExpiresAt); expiry < budget {
			budget = expiry
		}
		if budget <= 0 {
			continue
		}
		// The worker owns a clone even if cancellation returns before its dial ends.
		owned := cloneHALease(ss.Lease)
		type dialResult struct {
			conn net.Conn
			err  error
		}
		result := make(chan dialResult)
		attempt, stop := context.WithTimeout(ctx, budget)
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
		var c net.Conn
		var e error
		select {
		case r := <-result:
			c, e = r.conn, r.err
		case <-attempt.Done():
			e = attempt.Err()
		}
		stop()
		if ctx.Err() != nil {
			if c != nil {
				c.Close()
			}
			return nil, RuntimeSnapshot{}, ctx.Err()
		}
		if e == nil {
			if !s.acceptsInFlight(ss) {
				c.Close()
				return nil, RuntimeSnapshot{}, ErrPolicyExpired
			}
			ss.Lease = cloneHALease(ss.Lease)
			return c, ss, nil
		}
		if c != nil {
			c.Close()
		}
		s.FailCandidate("tcp", ss.Lease)
		log.Printf("tcp candidate failed forward_id=%d selection_ref=%s generation=%s attempt=%d reason=%s", ss.Policy.ForwardID, ss.Lease.SelectionRef, ss.Policy.PolicyGeneration, i+1, haErrorClass(e))
		last = e
	}
	// A probe can reject every fresh candidate during a transient false
	// negative even though the path that was carrying traffic still works. Give
	// that active path one real target-forwarding attempt before surfacing
	// no_healthy_candidate. The attempt stays inside the same lease/policy
	// generation checks and never widens the authorized candidate set.
	if activeOK && !activeAttempted && s.acceptsInFlight(active) && ctx.Err() == nil {
		remaining := time.Until(deadline)
		if remaining > 0 {
			budget := remaining
			if budget > 3*time.Second {
				budget = 3 * time.Second
			}
			log.Printf("active candidate forwarding test started protocol=tcp forward_id=%d selection_ref=%s generation=%s", active.Policy.ForwardID, active.Lease.SelectionRef, active.Policy.PolicyGeneration)
			connection, err := dial(active.Lease, host, port, budget)
			if err == nil {
				s.ClearPathFailure("tcp", haPathKey(active.Lease))
				fallback := RuntimeSnapshot{Policy: active.Policy, Lease: cloneHALease(active.Lease)}
				active.Lease.wipe()
				active = RuntimeSnapshot{}
				log.Printf("active candidate forwarding test succeeded protocol=tcp forward_id=%d selection_ref=%s generation=%s", fallback.Policy.ForwardID, fallback.Lease.SelectionRef, fallback.Policy.PolicyGeneration)
				return connection, fallback, nil
			}
			last = err
			log.Printf("active candidate forwarding test failed protocol=tcp forward_id=%d selection_ref=%s generation=%s reason=%s", active.Policy.ForwardID, active.Lease.SelectionRef, active.Policy.PolicyGeneration, haErrorClass(err))
		}
	}
	return nil, RuntimeSnapshot{}, fmt.Errorf("%w: %v", errHACandidatesUnavailable, last)
}
