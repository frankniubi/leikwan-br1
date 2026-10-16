package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const maxControlPayload = 64 * 1024

// leaseBatchTimeout must exceed ultra-client's own control deadline
// (probe_duration + association timeout + grace, ~48s at the default probe
// contract) plus network transit for the Panel round trip.
const leaseBatchTimeout = 75 * time.Second

// RuntimeConfig mirrors tools/ultra-client Config exactly. Any field the
// runtime does not recognise is ignored, and any field we omit falls back to
// the runtime's own default, so this stays forward compatible.
type RuntimeConfig struct {
	ForwardID                int                 `json:"forward_id"`
	ListenHost               string              `json:"listen_host"`
	ListenPort               int                 `json:"listen_port"`
	Target                   string              `json:"target"`
	NativeSocks5             bool                `json:"native_socks5,omitempty"`
	NativeRoutePolicy        string              `json:"native_route_policy,omitempty"`
	NativePortMin            int                 `json:"native_port_min,omitempty"`
	NativeFilterProfile      string              `json:"native_filter_profile,omitempty"`
	ProbeTarget              string              `json:"probe_target,omitempty"`
	ControlSocket            string              `json:"control_socket"`
	PolicyGeneration         string              `json:"policy_generation,omitempty"`
	TargetDigest             string              `json:"target_digest,omitempty"`
	Transport                string              `json:"transport"`
	MaxTCPConnections        int                 `json:"max_tcp_connections"`
	UDPPrewarm               bool                `json:"udp_prewarm"`
	UDPKeepaliveIntervalSecs int                 `json:"udp_keepalive_interval_seconds"`
	UDPQueuePackets          int                 `json:"udp_queue_packets"`
	UDPMaxAssociations       int                 `json:"udp_max_associations,omitempty"`
	UDPSetupWorkers          int                 `json:"udp_setup_workers,omitempty"`
	ConnectTimeoutSecs       int                 `json:"connect_timeout_seconds"`
	LocationCachePath        string              `json:"location_cache_path"`
	LocationLockPath         string              `json:"location_lock_path"`
	StatusPath               string              `json:"status_path"`
	AutoSelect               bool                `json:"auto_select"`
	ProbeDurationSecs        int                 `json:"probe_duration_seconds,omitempty"`
	ProbeTimeoutMillis       int                 `json:"probe_timeout_ms,omitempty"`
	ProbeWorkers             int                 `json:"probe_workers,omitempty"`
	ProbeSamples             int                 `json:"probe_samples,omitempty"`
	ProbeIntervalMillis      int                 `json:"probe_interval_ms,omitempty"`
	ZoneAliases              map[string][]string `json:"zone_aliases,omitempty"`
}

// RuntimeStatus is the subset of ultra-client's status document that the
// router needs. Field names match tools/ultra-client/status.go.
type RuntimeStatus struct {
	ForwardID             int               `json:"forward_id"`
	State                 string            `json:"state"`
	UpdatedAt             string            `json:"updated_at"`
	ErrorCode             string            `json:"error_code,omitempty"`
	PolicyGeneration      string            `json:"policy_generation,omitempty"`
	DetectedIngressZone   string            `json:"detected_ingress_zone,omitempty"`
	LocationSource        string            `json:"location_source,omitempty"`
	LatestTCP             *SelectionStatus  `json:"latest_tcp,omitempty"`
	ActiveUDP             *SelectionStatus  `json:"active_udp,omitempty"`
	UDPRuntimeSession     *SelectionStatus  `json:"udp_runtime_session,omitempty"`
	HAEvents              []HAEvent         `json:"ha_events,omitempty"`
	HAFailures            int64             `json:"ha_failures,omitempty"`
	UDPKeepaliveACKs      int64             `json:"udp_keepalive_acks,omitempty"`
	UDPLastACKAt          string            `json:"udp_last_ack_at,omitempty"`
	BytesIn               int64             `json:"bytes_in,omitempty"`
	BytesOut              int64             `json:"bytes_out,omitempty"`
	UDPActiveAssociations int               `json:"udp_active_associations,omitempty"`
	UDPPendingAssoc       int               `json:"udp_pending_associations,omitempty"`
	UDPQueueDrops         int64             `json:"udp_queue_drops,omitempty"`
	UDPSetupFailures      int64             `json:"udp_setup_failures,omitempty"`
	CandidateFeedback     []CandidateFeedbk `json:"candidate_feedback,omitempty"`
}

type SelectionStatus struct {
	SelectionRef     string `json:"selection_ref"`
	SelectionTier    string `json:"selection_tier"`
	CandidateKeyHash string `json:"candidate_key_hash,omitempty"`
	LeaseHash        string `json:"lease_hash,omitempty"`
	RTTMillis        int64  `json:"rtt_ms"`
	P95RTTMillis     int64  `json:"p95_rtt_ms,omitempty"`
	JitterMillis     int64  `json:"jitter_ms,omitempty"`
	LossPercent      int64  `json:"loss_percent,omitempty"`
	ProbeSent        int    `json:"probe_sent,omitempty"`
	ProbeReceived    int    `json:"probe_received,omitempty"`
	SelectedAt       string `json:"selected_at"`
}

type HAEvent struct {
	At     string `json:"at"`
	Kind   string `json:"kind"`
	Reason string `json:"reason"`
}

type CandidateFeedbk struct {
	CandidateKeyHash string `json:"candidate_key_hash"`
	LeaseHash        string `json:"lease_hash"`
	Protocol         string `json:"protocol"`
	Outcome          string `json:"outcome"`
	Reason           string `json:"reason"`
	EvidenceID       string `json:"evidence_id"`
	ObservedAt       string `json:"observed_at"`
}

// ---------------------------------------------------------------------------
// Control socket client
//
// The framing is a 4-byte big-endian length followed by a JSON payload, capped
// at 64 KiB. This is the exact protocol tools/ultra-client/control.go serves.
// ---------------------------------------------------------------------------

type ultraControl struct {
	socketPath string
}

func (c ultraControl) sendLeaseBatch(ctx context.Context, leases []LeaseFrame) (map[string]any, error) {
	if len(leases) == 0 {
		return nil, errors.New("empty lease batch")
	}
	payload, err := json.Marshal(map[string]any{"leases": leases})
	if err != nil {
		return nil, err
	}
	if len(payload) == 0 || len(payload) > maxControlPayload {
		return nil, errors.New("lease batch exceeds control frame limit")
	}

	dialer := net.Dialer{Timeout: 5 * time.Second}
	conn, err := dialer.DialContext(ctx, "unix", c.socketPath)
	if err != nil {
		return nil, fmt.Errorf("ultra control socket: %w", err)
	}
	defer conn.Close()
	deadline, ok := ctx.Deadline()
	if !ok {
		deadline = time.Now().Add(leaseBatchTimeout)
	}
	_ = conn.SetDeadline(deadline)

	var header [4]byte
	binary.BigEndian.PutUint32(header[:], uint32(len(payload)))
	if _, err := conn.Write(header[:]); err != nil {
		return nil, err
	}
	if _, err := conn.Write(payload); err != nil {
		return nil, err
	}
	reply, err := readControlFrame(conn)
	if err != nil {
		return nil, err
	}
	var decoded map[string]any
	if err := json.Unmarshal(reply, &decoded); err != nil {
		return nil, err
	}
	if okValue, _ := decoded["ok"].(bool); !okValue {
		reason, _ := decoded["error"].(string)
		if reason == "" {
			reason = "control_rejected"
		}
		return decoded, errors.New(reason)
	}
	return decoded, nil
}

func readControlFrame(reader io.Reader) ([]byte, error) {
	var header [4]byte
	if _, err := io.ReadFull(reader, header[:]); err != nil {
		return nil, err
	}
	length := binary.BigEndian.Uint32(header[:])
	if length == 0 || length > maxControlPayload {
		return nil, errors.New("control frame invalid")
	}
	payload := make([]byte, length)
	if _, err := io.ReadFull(reader, payload); err != nil {
		return nil, err
	}
	return payload, nil
}

// waitForControlSocket tolerates the runtime's cold-start window: the socket
// appears once the listener is bound, which is before the first lease arrives.
func waitForControlSocket(ctx context.Context, path string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for {
		if info, err := os.Stat(path); err == nil && info.Mode()&os.ModeSocket != 0 {
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("control socket %s did not appear", path)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(150 * time.Millisecond):
		}
	}
}

// readRuntimeStatus tolerates the runtime writing its status file concurrently
// by retrying a bounded number of times on a decode error.
func readRuntimeStatus(path string) (*RuntimeStatus, error) {
	var lastErr error
	for attempt := 0; attempt < 3; attempt++ {
		raw, err := os.ReadFile(path)
		if err != nil {
			return nil, err
		}
		var status RuntimeStatus
		if err := json.Unmarshal(raw, &status); err == nil {
			return &status, nil
		} else {
			lastErr = err
		}
		time.Sleep(40 * time.Millisecond)
	}
	return nil, lastErr
}

// runtimePaths is the on-router layout. The control socket must live in a 0700
// directory because ultra-client refuses a world-accessible parent.
type runtimePaths struct {
	dir           string
	configPath    string
	controlSocket string
	statusPath    string
}

func pathsForForward(runDir string, forwardID int) runtimePaths {
	dir := filepath.Join(runDir, itoa(forwardID))
	return runtimePaths{
		dir:           dir,
		configPath:    filepath.Join(dir, "runtime.json"),
		controlSocket: filepath.Join(dir, "control.sock"),
		statusPath:    filepath.Join(dir, "status.json"),
	}
}

// defaultProbeContract matches the desktop client's UltraProbeContract so
// candidate ranking is consistent across platforms.
const (
	defaultProbeTarget         = "1.1.1.1:53"
	defaultProbeDurationSecs   = 30
	defaultProbeTimeoutMillis  = 800
	defaultProbeWorkers        = 4
	defaultProbeSamples        = 30
	defaultProbeIntervalMillis = 1000
)

func buildRuntimeConfig(cfg Config, forward ForwardConfig, paths runtimePaths, policy *UltraPolicy) RuntimeConfig {
	transport := strings.ToLower(strings.TrimSpace(forward.Transport))
	if transport == "" {
		transport = "tcp+udp"
	}
	generation := ""
	digest := ""
	if policy != nil {
		generation = policy.PolicyGeneration
		digest = policy.TargetDigest
	}
	runtime := RuntimeConfig{
		ForwardID:                forward.ForwardID,
		ListenHost:               "0.0.0.0",
		ListenPort:               forward.ListenPort,
		Target:                   forward.Target,
		ControlSocket:            paths.controlSocket,
		PolicyGeneration:         generation,
		TargetDigest:             digest,
		Transport:                transport,
		MaxTCPConnections:        512,
		UDPKeepaliveIntervalSecs: 5,
		UDPQueuePackets:          256,
		ConnectTimeoutSecs:       5,
		LocationCachePath:        filepath.Join(cfg.DataDir, "state", "ultra-location.json"),
		LocationLockPath:         filepath.Join(cfg.RunDir, "location.lock"),
		StatusPath:               paths.statusPath,
		AutoSelect:               true,
		ProbeTarget:              defaultProbeTarget,
		ProbeDurationSecs:        defaultProbeDurationSecs,
		ProbeTimeoutMillis:       defaultProbeTimeoutMillis,
		ProbeWorkers:             defaultProbeWorkers,
		ProbeSamples:             defaultProbeSamples,
		ProbeIntervalMillis:      defaultProbeIntervalMillis,
	}
	if strings.Contains(transport, "udp") {
		runtime.UDPPrewarm = true
	}
	return runtime
}

// buildNativeRuntimeConfig is the gateway relay: the runtime stops being a
// fixed-target forward and becomes a loopback SOCKS5 facade whose egress is
// the Panel-selected native line. The values follow the desktop's
// NativeOutboundRelayHost, sized up for a whole LAN.
func buildNativeRuntimeConfig(cfg Config, forwardID, listenPort int, paths runtimePaths, policyGeneration, scopeDigest, routePolicy string) RuntimeConfig {
	return RuntimeConfig{
		ForwardID:  forwardID,
		ListenHost: "127.0.0.1",
		ListenPort: listenPort,
		// ultra-client refuses an empty target even in native mode; the
		// desktop passes the same placeholder. Native SOCKS5 never dials it:
		// the destination comes from each SOCKS request.
		Target:              nativeRuntimeTargetPlaceholder,
		NativeSocks5:        true,
		NativeRoutePolicy:   normalizeRoutePolicy(routePolicy),
		NativePortMin:       nativePortMin,
		NativeFilterProfile: nativeFilterProfile(routePolicy),
		ControlSocket:       paths.controlSocket,
		PolicyGeneration:    strings.ToLower(strings.TrimSpace(policyGeneration)),
		TargetDigest:        strings.ToLower(strings.TrimSpace(scopeDigest)),
		Transport:           "tcp+udp",
		MaxTCPConnections:   2048,
		UDPQueuePackets:     1024,
		UDPMaxAssociations:  2048,
		ConnectTimeoutSecs:  5,
		LocationCachePath:   filepath.Join(cfg.DataDir, "state", "ultra-location.json"),
		LocationLockPath:    filepath.Join(cfg.RunDir, "location.lock"),
		StatusPath:          paths.statusPath,
		AutoSelect:          true,
		ProbeTarget:         defaultProbeTarget,
		// A short probe window like the desktop relay: the LAN is waiting on
		// the first lease.
		ProbeDurationSecs:   5,
		ProbeTimeoutMillis:  defaultProbeTimeoutMillis,
		ProbeWorkers:        defaultProbeWorkers,
		ProbeSamples:        5,
		ProbeIntervalMillis: defaultProbeIntervalMillis,
	}
}

const nativeRuntimeTargetPlaceholder = "1.1.1.1:443"

func nativeFilterProfile(routePolicy string) string {
	if normalizeRoutePolicy(routePolicy) == "unrestricted" {
		return "off"
	}
	return "opengfw_tcp"
}

func writeRuntimeConfig(paths runtimePaths, runtime RuntimeConfig) error {
	if err := ensurePrivateDir(paths.dir); err != nil {
		return err
	}
	raw, err := json.MarshalIndent(runtime, "", "  ")
	if err != nil {
		return err
	}
	return writeFileAtomic(paths.configPath, raw, 0o600)
}

// cleanupRuntimeDir removes stale sockets and status files. It is called before
// every (re)start so a crashed predecessor cannot confuse the new instance.
func cleanupRuntimeDir(paths runtimePaths) {
	_ = os.Remove(paths.controlSocket)
	_ = os.Remove(paths.statusPath)
}
