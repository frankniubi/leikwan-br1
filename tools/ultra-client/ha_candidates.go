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

func (s *PolicyStore) wipeBackupsLocked() {
	for i := range s.backups {
		s.backups[i].wipe()
	}
	s.backups = nil
}

func (s *PolicyStore) CandidateSnapshots(protocol string) []RuntimeSnapshot {
	s.mu.RLock()
	defer s.mu.RUnlock()
	now := time.Now()
	if s.policy == nil || s.lease == nil || !s.policy.ExpiresAt.After(now) {
		return nil
	}
	candidates := append([]ProviderLease{*s.lease}, s.backups...)
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
func (s *PolicyStore) failCandidateKey(protocol, key, generation string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.isolated == nil {
		s.isolated = map[string]time.Time{}
	}
	// A healthy association survives generation rotation, so its later failure
	// must still isolate the SAME physical/logical path. Never affect a new path.
	if s.policy == nil || s.lease == nil {
		return
	}
	matched := haPathKey(*s.lease) == key
	for _, l := range s.backups {
		matched = matched || haPathKey(l) == key
	}
	if !matched {
		return
	}
	s.isolated[protocol+":"+key] = time.Now().Add(15 * time.Second)
}

type candidateStreamDial func(ProviderLease, string, int, time.Duration) (net.Conn, error)

var errHACandidatesUnavailable = errors.New("no healthy authorized candidate")

// No caller payload has been read when this helper retries. Attempts are scoped
// to one immutable generation; a concurrent refresh cannot widen its authority.
func dialCandidateStream(ctx context.Context, s *PolicyStore, host string, port int, total time.Duration, dial candidateStreamDial) (net.Conn, RuntimeSnapshot, error) {
	candidates := s.CandidateSnapshots("tcp")
	defer wipeSnapshots(candidates)
	if total <= 0 {
		total = 15 * time.Second
	}
	deadline := time.Now().Add(total)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	last := error(errHACandidatesUnavailable)
	for i, ss := range candidates {
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
	return nil, RuntimeSnapshot{}, fmt.Errorf("%w: %v", errHACandidatesUnavailable, last)
}
