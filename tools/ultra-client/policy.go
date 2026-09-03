package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"net"
	"strings"
	"sync"
	"time"
)

const (
	policyProtocol             = "leikwan-ultra-policy-v2"
	nativeSocks5Protocol       = "leikwan-ultra-native-socks5-v1"
	nativeSocks5Purpose        = "native_outbound"
	maxPolicyTTL               = 5 * time.Minute
	maxQuotaBytes        int64 = 107374182400
	maxRateMbps          int64 = 100000
	selectionTierPaid          = "paid"
	selectionTierMixed         = "mixed"
	selectionTierFree          = "free"
	selectionTierUnknown       = "unknown"
)

var (
	ErrPolicyInvalid  = errors.New("policy invalid")
	ErrPolicyExpired  = errors.New("policy expired")
	ErrPolicyRollback = errors.New("policy generation rollback")
	ErrPolicyStopped  = errors.New("policy stopped")
	ErrQuotaExhausted = errors.New("policy quota exhausted")
	ErrLeaseInvalid   = errors.New("lease invalid")
	ErrLeaseReplay    = errors.New("lease replayed")
	ErrFrameTooLarge  = errors.New("control frame too large")
	ErrFrameInvalid   = errors.New("control frame invalid")
)

// Policy is the non-provider portion of a Panel grant. It is safe to keep in
// the runtime memory and is never written back to the static config file.
type Policy struct {
	Protocol            string    `json:"protocol"`
	Purpose             string    `json:"purpose,omitempty"`
	Native              bool      `json:"native_outbound,omitempty"`
	ForwardID           int       `json:"forward_id"`
	PolicyGeneration    string    `json:"policy_generation"`
	SessionTicket       string    `json:"session_ticket"`
	ExpiresAt           time.Time `json:"expires_at"`
	Transport           string    `json:"transport"`
	RateLimitMbps       int64     `json:"rate_limit_mbps"`
	QuotaRemainingBytes int64     `json:"quota_remaining_bytes"`
	TargetDigest        string    `json:"target_digest"`
	Direction           string    `json:"direction,omitempty"`
	RouteScope          string    `json:"route_scope,omitempty"`
	RoutePolicy         string    `json:"route_policy,omitempty"`
}

func (p Policy) Validate(config Config, now time.Time) error {
	if p.ForwardID <= 0 || p.ForwardID != config.ForwardID {
		return ErrPolicyInvalid
	}
	if p.Native {
		if !config.NativeSocks5 || p.Protocol != nativeSocks5Protocol || p.Purpose != nativeSocks5Purpose {
			return ErrPolicyInvalid
		}
		if p.Transport != "tcp+udp" || p.Direction == "" ||
			(p.RouteScope != "cn_only" && p.RouteScope != "intl_only" && p.RouteScope != "global") {
			return ErrPolicyInvalid
		}
		routePolicy, err := ParseNativeRoutePolicy(p.RoutePolicy)
		if err != nil || routePolicy != nativeRoutePolicyForConfig(config) {
			return ErrPolicyInvalid
		}
	} else if p.Protocol != policyProtocol {
		return ErrPolicyInvalid
	}
	if len(p.PolicyGeneration) != 64 || !isLowerHex(p.PolicyGeneration) || strings.TrimSpace(p.SessionTicket) == "" {
		if !p.Native || len(p.PolicyGeneration) != 64 || !isLowerHex(p.PolicyGeneration) {
			return ErrPolicyInvalid
		}
	}
	if p.ExpiresAt.IsZero() || !p.ExpiresAt.After(now) || p.ExpiresAt.After(now.Add(maxPolicyTTL+time.Second)) {
		return ErrPolicyExpired
	}
	if !p.Native && p.Transport != "tcp" && p.Transport != "udp" && p.Transport != "tcp+udp" {
		return ErrPolicyInvalid
	}
	if p.RateLimitMbps < 0 || p.RateLimitMbps > maxRateMbps || p.QuotaRemainingBytes < 0 || p.QuotaRemainingBytes > maxQuotaBytes {
		return ErrPolicyInvalid
	}
	if len(p.TargetDigest) != 64 || !isLowerHex(p.TargetDigest) {
		return ErrPolicyInvalid
	}
	if !p.Native && targetDigest(config.Target) != p.TargetDigest {
		return ErrPolicyInvalid
	}
	return nil
}

func targetDigest(target string) string {
	sum := sha256.Sum256([]byte(strings.TrimSpace(target)))
	return hex.EncodeToString(sum[:])
}

func isLowerHex(value string) bool {
	if value == "" || strings.ToLower(value) != value {
		return false
	}
	for _, char := range value {
		if !((char >= '0' && char <= '9') || (char >= 'a' && char <= 'f')) {
			return false
		}
	}
	return true
}

func normalizeSelectionTier(value string) (string, error) {
	normalized := strings.ToLower(strings.TrimSpace(value))
	if normalized == "" {
		return selectionTierUnknown, nil
	}
	switch normalized {
	case selectionTierPaid, selectionTierMixed, selectionTierFree, selectionTierUnknown:
		return normalized, nil
	default:
		return "", ErrLeaseInvalid
	}
}

func selectionTierRank(value string) int {
	normalized := strings.ToLower(strings.TrimSpace(value))
	switch normalized {
	case selectionTierPaid:
		return 0
	case selectionTierMixed:
		return 1
	case selectionTierFree:
		return 2
	case selectionTierUnknown:
		return 3
	default:
		// Invalid wire values are rejected during lease validation. Treat a
		// manually-constructed result as least preferred instead of allowing
		// it to outrank a known pricing class.
		return 3
	}
}

type PolicyLimiter struct {
	mu         sync.Mutex
	rate       float64
	capacity   float64
	tokens     float64
	last       time.Time
	quota      int64
	expiresAt  time.Time
	generation string
	revoked    bool
}

func NewPolicyLimiter() *PolicyLimiter { return &PolicyLimiter{} }

func (l *PolicyLimiter) Replace(rateMbps int64, quotaBytes int64, expiresAt time.Time, generation string) error {
	if rateMbps < 0 || rateMbps > maxRateMbps || quotaBytes < 0 || quotaBytes > maxQuotaBytes || generation == "" {
		return ErrPolicyInvalid
	}
	now := time.Now()
	l.mu.Lock()
	defer l.mu.Unlock()
	l.rate = float64(rateMbps) * 1_000_000 / 8
	l.capacity = l.rate
	l.tokens = l.capacity
	l.last = now
	l.quota = quotaBytes
	l.expiresAt = expiresAt
	l.generation = generation
	l.revoked = false
	return nil
}

func (l *PolicyLimiter) Revoke(generation string) {
	l.mu.Lock()
	if generation == "" || l.generation == generation {
		l.revoked = true
	}
	l.mu.Unlock()
}

func (l *PolicyLimiter) Consume(n int) error {
	if n <= 0 {
		return nil
	}
	for {
		l.mu.Lock()
		now := time.Now()
		if l.revoked || l.generation == "" || !l.expiresAt.After(now) {
			l.mu.Unlock()
			return ErrPolicyStopped
		}
		if l.quota < int64(n) {
			l.mu.Unlock()
			return ErrQuotaExhausted
		}
		if l.rate <= 0 {
			l.quota -= int64(n)
			l.mu.Unlock()
			return nil
		}
		l.tokens += now.Sub(l.last).Seconds() * l.rate
		if l.tokens > l.capacity {
			l.tokens = l.capacity
		}
		l.last = now
		if l.tokens >= float64(n) {
			l.tokens -= float64(n)
			l.quota -= int64(n)
			l.mu.Unlock()
			return nil
		}
		wait := (float64(n) - l.tokens) / l.rate
		l.tokens = 0
		l.mu.Unlock()
		if wait > 2 {
			wait = 2
		}
		time.Sleep(time.Duration(wait*float64(time.Second)) + time.Millisecond)
	}
}

type ProviderLease struct {
	LeaseID          string
	PolicyGeneration string
	ExpiresAt        time.Time
	Transport        string
	SelectionRef     string
	SelectionTier    string
	NonceHash        string
	IP               string
	Port             int
	User             []byte
	Password         []byte
	userLocked       bool
	passwordLocked   bool
}

func (l *ProviderLease) wipe() {
	clearSecret(l.User, l.userLocked)
	clearSecret(l.Password, l.passwordLocked)
	l.User = nil
	l.Password = nil
	l.IP = ""
	l.SelectionRef = ""
	l.SelectionTier = ""
	l.LeaseID = ""
	l.NonceHash = ""
	l.userLocked = false
	l.passwordLocked = false
}

var secretMemoryWarning sync.Once

func (l *ProviderLease) lock() {
	l.userLocked = lockSecret(l.User)
	l.passwordLocked = lockSecret(l.Password)
	if !l.userLocked || !l.passwordLocked {
		secretMemoryWarning.Do(func() { log.Print("secret_memory_unlocked") })
	}
}

func (l ProviderLease) Validate(policy Policy, now time.Time) error {
	if l.LeaseID == "" || l.PolicyGeneration == "" || l.PolicyGeneration != policy.PolicyGeneration || l.NonceHash == "" || !isLowerHex(l.NonceHash) {
		return ErrLeaseInvalid
	}
	if l.ExpiresAt.IsZero() || !l.ExpiresAt.After(now) || l.ExpiresAt.After(policy.ExpiresAt.Add(time.Second)) {
		return ErrLeaseInvalid
	}
	if net.ParseIP(l.IP) == nil || l.Port < 1 || l.Port > 65535 || len(l.User) == 0 || len(l.User) > 255 || len(l.Password) == 0 || len(l.Password) > 255 {
		return ErrLeaseInvalid
	}
	if l.Transport != policy.Transport || len(l.SelectionRef) < 16 || len(l.SelectionRef) > 128 {
		return ErrLeaseInvalid
	}
	if _, err := normalizeSelectionTier(l.SelectionTier); err != nil {
		return ErrLeaseInvalid
	}
	return nil
}

type RuntimeSnapshot struct {
	Policy Policy
	Lease  ProviderLease
}

type PolicyStore struct {
	mu         sync.RWMutex
	config     Config
	policy     *Policy
	lease      *ProviderLease
	seenNonces map[string]struct{}
	seenGens   map[string]struct{}
	revoked    map[string]struct{}
	limiter    *PolicyLimiter
	updates    chan struct{}
}

func NewPolicyStore(config Config) *PolicyStore {
	return &PolicyStore{
		config: config, seenNonces: make(map[string]struct{}), seenGens: make(map[string]struct{}), revoked: make(map[string]struct{}), limiter: NewPolicyLimiter(), updates: make(chan struct{}, 1),
	}
}

func (s *PolicyStore) Replace(policy Policy, lease ProviderLease) error {
	now := time.Now()
	if err := policy.Validate(s.config, now); err != nil {
		return err
	}
	if err := lease.Validate(policy, now); err != nil {
		return err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, revoked := s.revoked[policy.PolicyGeneration]; revoked {
		return ErrPolicyRollback
	}
	if _, replayed := s.seenNonces[lease.NonceHash]; replayed {
		return ErrLeaseReplay
	}
	if _, seen := s.seenGens[policy.PolicyGeneration]; seen && s.policy != nil && s.policy.PolicyGeneration != policy.PolicyGeneration {
		return ErrPolicyRollback
	}
	if s.lease != nil {
		s.lease.wipe()
	}
	s.seenNonces[lease.NonceHash] = struct{}{}
	s.seenGens[policy.PolicyGeneration] = struct{}{}
	policyCopy := policy
	leaseCopy := lease
	leaseCopy.User = append([]byte(nil), lease.User...)
	leaseCopy.Password = append([]byte(nil), lease.Password...)
	leaseCopy.userLocked = false
	leaseCopy.passwordLocked = false
	leaseCopy.lock()
	s.policy = &policyCopy
	s.lease = &leaseCopy
	_ = s.limiter.Replace(policy.RateLimitMbps, policy.QuotaRemainingBytes, policy.ExpiresAt, policy.PolicyGeneration)
	s.notifyLocked()
	return nil
}

func (s *PolicyStore) Revoke(generation string) {
	s.mu.Lock()
	s.revoked[generation] = struct{}{}
	if s.policy != nil && (generation == "" || s.policy.PolicyGeneration == generation) {
		if s.lease != nil {
			s.lease.wipe()
		}
		s.policy = nil
		s.lease = nil
		s.limiter.Revoke(generation)
	}
	s.notifyLocked()
	s.mu.Unlock()
}

func (s *PolicyStore) ClearExpired() {
	s.mu.Lock()
	if s.policy != nil && !s.policy.ExpiresAt.After(time.Now()) {
		if s.lease != nil {
			s.lease.wipe()
		}
		s.policy = nil
		s.lease = nil
		s.limiter.Revoke("")
		s.notifyLocked()
	}
	s.mu.Unlock()
}

func (s *PolicyStore) Snapshot() (RuntimeSnapshot, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	if s.policy == nil || s.lease == nil || !s.policy.ExpiresAt.After(time.Now()) || !s.lease.ExpiresAt.After(time.Now()) {
		return RuntimeSnapshot{}, false
	}
	snapshot := RuntimeSnapshot{Policy: *s.policy, Lease: *s.lease}
	snapshot.Lease.User = append([]byte(nil), s.lease.User...)
	snapshot.Lease.Password = append([]byte(nil), s.lease.Password...)
	snapshot.Lease.userLocked = false
	snapshot.Lease.passwordLocked = false
	snapshot.Lease.lock()
	return snapshot, true
}

func (s *PolicyStore) HasValid() bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.policy != nil && s.lease != nil &&
		s.policy.ExpiresAt.After(time.Now()) && s.lease.ExpiresAt.After(time.Now())
}

func (s *PolicyStore) Limiter() *PolicyLimiter  { return s.limiter }
func (s *PolicyStore) Updates() <-chan struct{} { return s.updates }

func (s *PolicyStore) notifyLocked() {
	select {
	case s.updates <- struct{}{}:
	default:
	}
}

func (s *PolicyStore) WaitValid(ctx context.Context) (RuntimeSnapshot, error) {
	for {
		s.ClearExpired()
		if snapshot, ok := s.Snapshot(); ok {
			return snapshot, nil
		}
		select {
		case <-ctx.Done():
			return RuntimeSnapshot{}, ctx.Err()
		case <-s.updates:
		}
	}
}

func (s *PolicyStore) PolicyConfig() Config { return s.config }

func targetFromConfig(config Config) (string, int, error) {
	host, portText, err := net.SplitHostPort(config.Target)
	if err != nil {
		return "", 0, fmt.Errorf("target: %w", err)
	}
	var port int
	if _, err := fmt.Sscanf(portText, "%d", &port); err != nil || port < 1 || port > 65535 || strings.TrimSpace(host) == "" {
		return "", 0, ErrPolicyInvalid
	}
	return host, port, nil
}
