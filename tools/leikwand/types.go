package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"strings"
)

// ---------------------------------------------------------------------------
// Panel wire contract
//
// These types mirror the deployed contract that the Windows/macOS desktop
// clients already speak. Field names and semantics are deliberately identical
// so the Panel needs no behavioural change to serve an OpenWrt device.
// ---------------------------------------------------------------------------

type PullResponse struct {
	OK              bool                      `json:"ok"`
	ConfigRevision  string                    `json:"config_revision"`
	ForwardRevision string                    `json:"forward_revision"`
	ForceApply      bool                      `json:"force_apply"`
	NextPollAfterMS int                       `json:"next_poll_after_ms"`
	Client          ClientMeta                `json:"client"`
	Forwards        []ForwardConfig           `json:"forwards"`
	NativeOutbound  *NativeOutboundCapability `json:"native_outbound,omitempty"`
}

type ClientMeta struct {
	ID                 int             `json:"id"`
	Name               string          `json:"name"`
	DisplayHost        string          `json:"display_host"`
	GroupID            *int            `json:"group_id"`
	DiagRequestToken   string          `json:"diag_request_token"`
	DiagStatus         string          `json:"diag_status"`
	AgentConfigJSON    json.RawMessage `json:"agent_config_json"`
	UpdateRequestToken string          `json:"agent_update_request_token"`
	DesiredAgentVer    string          `json:"desired_agent_version"`
	DesiredAgentSHA    string          `json:"desired_agent_sha256"`
	DesiredUltraVer    string          `json:"desired_ultra_runtime_version"`
	DesiredUltraSHA    string          `json:"desired_ultra_runtime_sha256"`
	OnlineDeviceLimit  int             `json:"online_device_limit"`
	ActiveOnlineDevice int             `json:"active_online_devices"`
	CurrentDeviceID    string          `json:"current_device_id"`
}

type ForwardConfig struct {
	ForwardID       int      `json:"forward_id"`
	ServiceStatus   string   `json:"service_status"`
	Mode            string   `json:"mode"`
	ClientMode      string   `json:"client_mode"`
	BackendMode     string   `json:"backend_mode"`
	RuntimePref     string   `json:"runtime_preference"`
	ListenPort      int      `json:"listen_port"`
	Target          string   `json:"target"`
	Transport       string   `json:"transport"`
	Aggregation     bool     `json:"aggregation_enabled"`
	SidecarRequired bool     `json:"sidecar_required"`
	TargetContract  string   `json:"target_contract"`
	ActiveFamily    string   `json:"active_family"`
	SpeedLimitMbps  *float64 `json:"speed_limit_mbps"`

	NativeOutboundEnabled bool   `json:"native_outbound"`
	NativeDirection       string `json:"native_direction"`

	UltraAccess *UltraPolicy `json:"ultra_access"`
}

// UltraPolicy is the authoritative, short-lived grant delivered by the pull
// endpoint. Everything about it is validated before use: protocol tag, service
// state, digest binding, TTL window, quota and transport.
type UltraPolicy struct {
	Protocol            string `json:"protocol"`
	ForwardID           int    `json:"forward_id"`
	PolicyGeneration    string `json:"policy_generation"`
	SessionTicket       string `json:"session_ticket"`
	IssuedAt            string `json:"issued_at"`
	RenewAfter          string `json:"renew_after"`
	ExpiresAt           string `json:"expires_at"`
	ServiceState        string `json:"service_state"`
	Transport           string `json:"transport"`
	RateLimitMbps       int64  `json:"rate_limit_mbps"`
	QuotaRemainingBytes int64  `json:"quota_remaining_bytes"`
	TargetDigest        string `json:"target_digest"`
}

type NativeOutboundCapability struct {
	Enabled           bool                    `json:"enabled"`
	Protocol          string                  `json:"protocol"`
	LeaseProtocol     string                  `json:"lease_protocol"`
	Transport         string                  `json:"transport"`
	ForwardIDs        []int                   `json:"forward_ids"`
	DefaultForwardID  *int                    `json:"default_forward_id"`
	RouteScopes       []string                `json:"route_scopes"`
	DefaultRouteScope string                  `json:"default_route_scope"`
	UnrestrictedOK    bool                    `json:"unrestricted_allowed"`
	Forwards          []NativeOutboundForward `json:"forwards"`
}

type NativeOutboundForward struct {
	ForwardID         int      `json:"forward_id"`
	Direction         string   `json:"direction"`
	ScopeDigest       string   `json:"scope_digest"`
	Transport         string   `json:"transport"`
	RouteScopes       []string `json:"route_scopes"`
	DefaultRouteScope string   `json:"default_route_scope"`
	UnrestrictedOK    bool     `json:"unrestricted_allowed"`
}

type LeaseRequest struct {
	DeviceID string                `json:"device_id"`
	Forwards []LeaseRequestForward `json:"forwards"`
}

type LeaseRequestForward struct {
	Purpose          string `json:"purpose"`
	ForwardID        int    `json:"forward_id"`
	SessionTicket    string `json:"session_ticket"`
	PolicyGeneration string `json:"policy_generation"`
	Nonce            string `json:"nonce,omitempty"`
	TargetDigest     string `json:"target_digest"`
	Transport        string `json:"transport"`
	RouteScope       string `json:"route_scope,omitempty"`
	RoutePolicy      string `json:"route_policy,omitempty"`
	SelectionMode    string `json:"selection_mode"`
	MaxCandidates    int    `json:"max_candidates"`
}

type LeaseResponse struct {
	OK     bool         `json:"ok"`
	Leases []LeaseFrame `json:"leases"`
	Error  string       `json:"error,omitempty"`

	// UnsealFailures lists sealed leases that could not be opened and were
	// therefore dropped from Leases. Local bookkeeping only; never encoded.
	UnsealFailures []error `json:"-"`
}

// LeaseFrame is byte-compatible with the frame ultra-client decodes from its
// control socket (see tools/ultra-client/lease.go).
type LeaseFrame struct {
	Protocol           string        `json:"protocol"`
	Purpose            string        `json:"purpose,omitempty"`
	ForwardID          int           `json:"forward_id"`
	SessionTicket      string        `json:"session_ticket"`
	PolicyGeneration   string        `json:"policy_generation"`
	LeaseID            string        `json:"lease_id"`
	ExpiresAt          string        `json:"expires_at"`
	Transport          string        `json:"transport"`
	RateLimitMbps      int64         `json:"rate_limit_mbps"`
	QuotaRemainingByte int64         `json:"quota_remaining_bytes"`
	TargetDigest       string        `json:"target_digest"`
	SelectionRef       string        `json:"selection_ref"`
	SelectionTier      string        `json:"selection_tier"`
	CandidateKeyHash   string        `json:"candidate_key_hash,omitempty"`
	NonceHash          string        `json:"nonce_hash,omitempty"`
	Endpoint           LeaseEndpoint `json:"endpoint"`
	Credential         LeaseCred     `json:"credential"`
	Direction          string        `json:"direction,omitempty"`
	RouteScope         string        `json:"route_scope,omitempty"`
	RoutePolicy        string        `json:"route_policy,omitempty"`
}

type LeaseEndpoint struct {
	IP   string `json:"ip"`
	Port int    `json:"port"`
}

type LeaseCred struct {
	User     string `json:"user"`
	Password string `json:"password"`
}

type HeartbeatPayload struct {
	AgentVersion         string                   `json:"agent_version"`
	UsageBytesIn         int64                    `json:"usage_bytes_in"`
	UsageBytesOut        int64                    `json:"usage_bytes_out"`
	SupportsMPTCP        bool                     `json:"supports_mptcp"`
	WireguardPublicKey   string                   `json:"wireguard_public_key"`
	WireguardListenPort  int                      `json:"wireguard_listen_port"`
	WireguardFallbackTCP int                      `json:"wireguard_fallback_tcp_port"`
	UltraUsage           []UltraUsageSnapshot     `json:"ultra_usage"`
	UltraFeedback        []UltraCandidateFeedback `json:"ultra_candidate_feedback"`
}

type UltraUsageSnapshot struct {
	ForwardID        int    `json:"forward_id"`
	PolicyGeneration string `json:"policy_generation"`
	Sequence         int64  `json:"sequence"`
	BytesIn          int64  `json:"bytes_in"`
	BytesOut         int64  `json:"bytes_out"`
}

type UltraCandidateFeedback struct {
	ForwardID        int    `json:"forward_id"`
	CandidateKeyHash string `json:"candidate_key_hash"`
	LeaseHash        string `json:"lease_hash"`
	Protocol         string `json:"protocol"`
	Outcome          string `json:"outcome"`
	Reason           string `json:"reason"`
	EvidenceID       string `json:"evidence_id"`
	ObservedAt       string `json:"observed_at"`
}

type HeartbeatResponse struct {
	OK                bool                `json:"ok"`
	UsageAcknowledged *bool               `json:"ultra_usage_acknowledged"`
	UltraActions      []UltraPolicyAction `json:"ultra_actions"`
}

type UltraPolicyAction struct {
	ForwardID        int    `json:"forward_id"`
	PolicyGeneration string `json:"policy_generation"`
	Action           string `json:"action"`
	Reason           string `json:"reason"`
}

// ---------------------------------------------------------------------------
// Digest helpers, byte-compatible with the Panel implementation
// (master/src/Support/UltraMode.php) and the desktop client.
// ---------------------------------------------------------------------------

func sha256Hex(value string) string {
	sum := sha256.Sum256([]byte(value))
	return hex.EncodeToString(sum[:])
}

// targetDigest binds a policy to a plain forward target.
func targetDigest(target string) string {
	return sha256Hex(strings.TrimSpace(target))
}

// nativeScopeDigestV1 / V2 bind a native-outbound policy to its route scope.
// The exact newline join order is part of the deployed contract.
func nativeScopeDigestV1(forwardID int, direction, transport, routeScope string) string {
	return sha256Hex(strings.Join([]string{
		"native-outbound:v1",
		itoa(forwardID),
		strings.ToLower(strings.TrimSpace(direction)),
		strings.ToLower(strings.TrimSpace(transport)),
		strings.ToLower(strings.TrimSpace(routeScope)),
	}, NL))
}

func nativeScopeDigestV2(forwardID int, direction, transport, routeScope, routePolicy string) string {
	return sha256Hex(strings.Join([]string{
		"native-outbound:v2",
		itoa(forwardID),
		strings.ToLower(strings.TrimSpace(direction)),
		strings.ToLower(strings.TrimSpace(transport)),
		strings.ToLower(strings.TrimSpace(routeScope)),
		strings.ToLower(strings.TrimSpace(routePolicy)),
	}, NL))
}

// NL is the literal newline the Panel joins digest components with.
const NL = "\n"

func isHex64(value string) bool {
	if len(value) != 64 {
		return false
	}
	for _, c := range value {
		if (c < '0' || c > '9') && (c < 'a' || c > 'f') {
			return false
		}
	}
	return true
}
