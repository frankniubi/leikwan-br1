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
	LeaseHash        string
	PolicyGeneration string
	ExpiresAt        time.Time
	Transport        string
	SelectionRef     string
	SelectionTier    string
	CandidateKeyHash string
	NonceHash        string
	IP               string
	Port             int
	// RelayPayloadCeiling is the panel-declared payload ceiling of this relay or
	// line. 0 means the panel did not declare one and the runtime must measure.
	RelayPayloadCeiling int
	User                []byte
	Password            []byte
	userLocked          bool
	passwordLocked      bool
}

func (l *ProviderLease) wipe() {
	clearSecret(l.User, l.userLocked)
	clearSecret(l.Password, l.passwordLocked)
	l.User = nil
	l.Password = nil
	l.IP = ""
	l.SelectionRef = ""
	l.SelectionTier = ""
	l.CandidateKeyHash = ""
	l.LeaseHash = ""
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
	if l.CandidateKeyHash != "" && (len(l.CandidateKeyHash) != 64 || !isLowerHex(l.CandidateKeyHash)) {
		return ErrLeaseInvalid
	}
	if l.LeaseHash != "" && (len(l.LeaseHash) != 64 || !isLowerHex(l.LeaseHash)) {
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
	isolated              map[string]time.Time
	pathFailures          map[string]int
	mu                    sync.RWMutex
	config                Config
	policy                *Policy
	lease                 *ProviderLease
	candidates            []*ProviderLease
	candidateFailures     map[string]int
	candidateIsolatedTill map[string]time.Time
	seenNonces            map[string]struct{}
	seenGens              map[string]struct{}
	revoked               map[string]struct{}
	limiter               *PolicyLimiter
	updates               chan struct{}
}

func NewPolicyStore(config Config) *PolicyStore {
	return &PolicyStore{
		config: config, candidateFailures: make(map[string]int), candidateIsolatedTill: make(map[string]time.Time),
		pathFailures: make(map[string]int),
		seenNonces:   make(map[string]struct{}), seenGens: make(map[string]struct{}), revoked: make(map[string]struct{}),
		limiter: NewPolicyLimiter(), updates: make(chan struct{}, 1),
	}
}

func (s *PolicyStore) ReplaceCandidates(policy Policy, selected ProviderLease, leases []ProviderLease) error {
	return s.ReplaceBatch(policy, selected, leases)
}

func (s *PolicyStore) Replace(policy Policy, lease ProviderLease) error {
	return s.ReplaceBatch(policy, lease, []ProviderLease{lease})
}

// ReplaceBatch atomically installs a policy and a bounded candidate set. The
// selected lease is kept as the initial sticky candidate, while subsequent
// new streams may move to a healthy sibling after local isolation.
func (s *PolicyStore) ReplaceBatch(policy Policy, selected ProviderLease, leases []ProviderLease) error {
	if len(leases) < 1 || len(leases) > 4 {
		return ErrLeaseInvalid
	}
	now := time.Now()
	if err := policy.Validate(s.config, now); err != nil {
		return err
	}
	if err := selected.Validate(policy, now); err != nil {
		return err
	}
	selectedFound := false
	for _, lease := range leases {
		if lease.NonceHash == selected.NonceHash && haPathKey(lease) == haPathKey(selected) {
			selectedFound = true
		}
		if err := lease.Validate(policy, now); err != nil {
			return err
		}
	}
	if !selectedFound {
		return ErrLeaseInvalid
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, revoked := s.revoked[policy.PolicyGeneration]; revoked {
		return ErrPolicyRollback
	}
	batchNonces := make(map[string]struct{}, len(leases))
	for _, lease := range leases {
		if _, duplicate := batchNonces[lease.NonceHash]; duplicate {
			return ErrLeaseReplay
		}
		if _, replayed := s.seenNonces[lease.NonceHash]; replayed {
			return ErrLeaseReplay
		}
		batchNonces[lease.NonceHash] = struct{}{}
	}
	if _, seen := s.seenGens[policy.PolicyGeneration]; seen && s.policy != nil && s.policy.PolicyGeneration != policy.PolicyGeneration {
		return ErrPolicyRollback
	}
	oldFailures, oldIsolation := s.candidateFailures, s.candidateIsolatedTill
	oldPathFailures := s.pathFailures
	s.wipeCandidatesLocked()
	s.candidateFailures, s.candidateIsolatedTill = oldFailures, oldIsolation
	// Path health is keyed by endpoint+user, so it survives a lease rotation.
	s.pathFailures = oldPathFailures
	for key, until := range s.isolated {
		if !until.After(now) {
			delete(s.isolated, key)
		}
	}
	for nonce := range batchNonces {
		s.seenNonces[nonce] = struct{}{}
	}
	s.seenGens[policy.PolicyGeneration] = struct{}{}
	policyCopy := policy
	s.policy = &policyCopy
	s.candidates = make([]*ProviderLease, 0, len(leases))
	selectedKey := providerLeaseIdentity(selected)
	for index := range leases {
		leaseCopy := cloneProviderLease(leases[index])
		s.candidates = append(s.candidates, leaseCopy)
		if selectedKey != "" && leaseCopy.NonceHash == selected.NonceHash && providerLeaseIdentity(*leaseCopy) == selectedKey {
			s.lease = leaseCopy
		}
		if index == 0 && s.lease == nil {
			s.lease = leaseCopy
		}
	}
	_ = s.limiter.Replace(policy.RateLimitMbps, policy.QuotaRemainingBytes, policy.ExpiresAt, policy.PolicyGeneration)
	s.notifyLocked()
	return nil
}

func (s *PolicyStore) Revoke(generation string) {
	s.mu.Lock()
	s.revoked[generation] = struct{}{}
	if s.policy != nil && (generation == "" || s.policy.PolicyGeneration == generation) {
		s.wipeCandidatesLocked()
		s.policy = nil
		s.lease = nil
		s.limiter.Revoke(generation)
	}
	s.notifyLocked()
	s.mu.Unlock()
}

// RevokedAny reports whether the store has ever been told to revoke a
// generation. A revocation is an authorization decision and must tear the
// listeners down at once, unlike a policy that merely lapsed or was never
// issued: those are outages, and serving is already refused while
// CandidateSnapshots has nothing valid.
func (s *PolicyStore) RevokedAny() bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.revoked) > 0
}

func (s *PolicyStore) ClearExpired() {
	s.mu.Lock()
	if s.policy != nil && !s.policy.ExpiresAt.After(time.Now()) {
		s.wipeCandidatesLocked()
		s.policy = nil
		s.lease = nil
		s.limiter.Revoke("")
		s.notifyLocked()
	}
	s.mu.Unlock()
}

func (s *PolicyStore) Snapshot() (RuntimeSnapshot, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.selectActiveLocked("")
	return s.snapshotLocked(time.Now())
}

// ActiveSnapshot returns the path the runtime was already using without
// reselecting around a locally isolated candidate. It is used only as a last
// resort when a fresh probe batch says every candidate is unhealthy: the
// caller still has to prove the same path with a real forwarding attempt.
func (s *PolicyStore) ActiveSnapshot() (RuntimeSnapshot, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.snapshotLocked(time.Now())
}

// SnapshotNextCandidate selects a healthy sibling for a new connection while
// excluding candidates already attempted for this connection. Existing
// streams retain their own lease snapshot and are not moved by this method.
func (s *PolicyStore) SnapshotNextCandidate(excluded ...string) (RuntimeSnapshot, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.policy == nil || !s.policy.ExpiresAt.After(time.Now()) {
		return RuntimeSnapshot{}, false
	}
	now := time.Now()
	excludedKeys := make(map[string]struct{}, len(excluded))
	for _, value := range excluded {
		if key := s.findCandidateIdentityLocked(value); key != "" {
			excludedKeys[key] = struct{}{}
		}
	}
	for _, candidate := range s.candidates {
		if candidate == nil || !candidate.ExpiresAt.After(now) {
			continue
		}
		key := providerLeaseIdentity(*candidate)
		if _, skip := excludedKeys[key]; skip || s.candidateIsolatedLocked(key, now) {
			continue
		}
		s.lease = candidate
		return s.snapshotLocked(now)
	}
	return RuntimeSnapshot{}, false
}

func (s *PolicyStore) snapshotLocked(now time.Time) (RuntimeSnapshot, bool) {
	if s.policy == nil || s.lease == nil || !s.policy.ExpiresAt.After(now) || !s.lease.ExpiresAt.After(now) {
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
	s.mu.Lock()
	defer s.mu.Unlock()
	s.selectActiveLocked("")
	return s.policy != nil && s.lease != nil &&
		s.policy.ExpiresAt.After(time.Now()) && s.lease.ExpiresAt.After(time.Now())
}

// MarkCandidateFailure records a classified local data-plane failure. The
// second consecutive failure isolates that candidate for new streams while
// preserving the existing connection that already holds its snapshot.
func (s *PolicyStore) MarkCandidateFailure(candidateKeyHash string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	key := s.findCandidateIdentityLocked(candidateKeyHash)
	if key == "" {
		return false
	}
	failures := s.candidateFailures[key] + 1
	if failures > 65535 {
		failures = 65535
	}
	s.candidateFailures[key] = failures
	if s.candidateFailures[key] >= 2 {
		ttl := 30 * time.Second
		if s.policy != nil {
			remaining := time.Until(s.policy.ExpiresAt)
			if remaining > 0 && remaining < ttl {
				ttl = remaining
			}
		}
		s.candidateIsolatedTill[key] = time.Now().Add(ttl)
		s.selectActiveLocked("")
		s.notifyLocked()
		return true
	}
	return false
}

// MarkCandidateSuccess clears local isolation after a successful canary or
// data-plane setup and makes that candidate preferred for new streams.
func (s *PolicyStore) MarkCandidateSuccess(candidateKeyHash string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	key := s.findCandidateIdentityLocked(candidateKeyHash)
	if key == "" {
		return
	}
	delete(s.candidateFailures, key)
	delete(s.candidateIsolatedTill, key)
	s.selectActiveLocked(key)
	s.notifyLocked()
}

func (s *PolicyStore) wipeCandidatesLocked() {
	seen := make(map[*ProviderLease]struct{}, len(s.candidates))
	for _, candidate := range s.candidates {
		if candidate == nil {
			continue
		}
		if _, already := seen[candidate]; already {
			continue
		}
		candidate.wipe()
		seen[candidate] = struct{}{}
	}
	if s.lease != nil {
		if _, already := seen[s.lease]; !already {
			s.lease.wipe()
		}
		s.lease = nil
	}
	s.candidates = nil
	s.candidateFailures = make(map[string]int)
	s.candidateIsolatedTill = make(map[string]time.Time)
}

func cloneProviderLease(lease ProviderLease) *ProviderLease {
	copy := lease
	copy.User = append([]byte(nil), lease.User...)
	copy.Password = append([]byte(nil), lease.Password...)
	copy.userLocked = false
	copy.passwordLocked = false
	copy.lock()
	return &copy
}

func providerLeaseIdentity(lease ProviderLease) string {
	if hash := normalizedLeaseHash(lease.CandidateKeyHash); hash != "" {
		return "hash:" + hash
	}
	if reference := strings.TrimSpace(lease.SelectionRef); reference != "" {
		return "ref:" + reference
	}
	return "endpoint:" + strings.ToLower(net.JoinHostPort(strings.TrimSpace(lease.IP), fmt.Sprint(lease.Port))) + "\x00" + strings.TrimSpace(string(lease.User))
}

func (s *PolicyStore) findCandidateIdentityLocked(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	for _, candidate := range s.candidates {
		if candidate == nil {
			continue
		}
		if value != "" && (strings.EqualFold(candidate.CandidateKeyHash, value) || strings.EqualFold(candidate.SelectionRef, value)) {
			return providerLeaseIdentity(*candidate)
		}
	}
	return ""
}

func (s *PolicyStore) selectActiveLocked(preferred string) {
	if len(s.candidates) == 0 {
		s.lease = nil
		return
	}
	now := time.Now()
	if preferred != "" {
		for _, candidate := range s.candidates {
			if candidate != nil && providerLeaseIdentity(*candidate) == preferred && !s.candidateIsolatedLocked(providerLeaseIdentity(*candidate), now) {
				s.lease = candidate
				return
			}
		}
	}
	if s.lease != nil && !s.candidateIsolatedLocked(providerLeaseIdentity(*s.lease), now) && s.lease.ExpiresAt.After(now) {
		return
	}
	var fallback *ProviderLease
	var earliest time.Time
	for _, candidate := range s.candidates {
		if candidate == nil || !candidate.ExpiresAt.After(now) {
			continue
		}
		key := providerLeaseIdentity(*candidate)
		if !s.candidateIsolatedLocked(key, now) {
			s.lease = candidate
			return
		}
		until := s.candidateIsolatedTill[key]
		if fallback == nil || until.Before(earliest) {
			fallback = candidate
			earliest = until
		}
	}
	if fallback != nil {
		s.lease = fallback
	}
}

func (s *PolicyStore) candidateIsolatedLocked(key string, now time.Time) bool {
	until, ok := s.candidateIsolatedTill[key]
	if !ok || !until.After(now) {
		if ok {
			delete(s.candidateIsolatedTill, key)
		}
		return false
	}
	return true
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
