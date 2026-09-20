package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

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
	// PayloadCeiling is the datagram payload this selection can carry, 0 when the
	// association was measured as unable to carry a floor-sized payload.
	// PayloadCeilingSource says whether the panel declared it or the runtime
	// measured it, so a narrow relay is distinguishable from an unprobed one.
	PayloadCeiling       int    `json:"payload_ceiling,omitempty"`
	PayloadCeilingSource string `json:"payload_ceiling_source,omitempty"`
}

type RuntimeStatus struct {
	// UDPTransport reports which UDP data plane is actually in use, as selected
	// by the effective bootstrap, so the panel never has to guess.
	UDPTransport               string         `json:"udp_transport,omitempty"`
	OperatorQuarantineProtocol string         `json:"operator_quarantine_protocol,omitempty"`
	OperatorQuarantineActive   bool           `json:"operator_quarantine_active"`
	OperatorQuarantineUntil    string         `json:"operator_quarantine_until,omitempty"`
	OperatorQuarantineExcluded int            `json:"operator_quarantine_excluded"`
	UoTReliable                *reliableStats `json:"reliable_udp,omitempty"`
	UoTQueueBlocked            uint64         `json:"queue_blocked"`
	UoTQueueTimeout            uint64         `json:"queue_timeout"`
	UoTQueueDrop               uint64         `json:"queue_drop"`
	UoTSocketReceiveDrop       *uint64        `json:"socket_receive_drop"`
	UoTSocketRcvbuf            int            `json:"socket_rcvbuf"`
	UoTSocketSndbuf            int            `json:"socket_sndbuf"`
	UoTSocketBufferError       bool           `json:"socket_buffer_error"`

	UDPRuntimeSession      *SelectionStatus `json:"udp_runtime_session,omitempty"`
	HAEvents               []HAEvent        `json:"ha_events,omitempty"`
	HAFailures             int64            `json:"ha_failures,omitempty"`
	UDPKeepaliveACKs       int64            `json:"udp_keepalive_acks,omitempty"`
	UDPLastACKAt           string           `json:"udp_last_ack_at,omitempty"`
	ForwardID              int              `json:"forward_id"`
	State                  string           `json:"state"`
	DetectedIngressZone    string           `json:"detected_ingress_zone"`
	LocationSource         string           `json:"location_source"`
	LatestTCP              *SelectionStatus `json:"latest_tcp,omitempty"`
	ActiveUDP              *SelectionStatus `json:"active_udp,omitempty"`
	UpdatedAt              string           `json:"updated_at"`
	ErrorCode              string           `json:"error_code,omitempty"`
	PolicyGeneration       string           `json:"policy_generation,omitempty"`
	BytesIn                int64            `json:"bytes_in,omitempty"`
	BytesOut               int64            `json:"bytes_out,omitempty"`
	UDPActiveAssociations  int              `json:"udp_active_associations,omitempty"`
	UDPPendingAssociations int              `json:"udp_pending_associations,omitempty"`
	UDPQueueDrops          int64            `json:"udp_queue_drops,omitempty"`
	UDPSetupFailures       int64            `json:"udp_setup_failures,omitempty"`
	// UDPPayloadCeiling is the largest datagram payload the current association was
	// measured to carry (0 = not measured yet, or the association is unusable).
	// Hosts size the TUN MTU from this instead of guessing a fixed value.
	UDPPayloadCeiling int `json:"udp_payload_ceiling,omitempty"`
	// UDPPayloadCeilingSource is "declared" or "measured"; "measured" with no
	// udp_payload_ceiling means the active association carries no real payload.
	UDPPayloadCeilingSource string `json:"udp_payload_ceiling_source,omitempty"`
	// UDPOversizeDrops counts datagrams refused because they exceeded that ceiling.
	UDPOversizeDrops  int64               `json:"udp_oversize_drops,omitempty"`
	CandidateFeedback []CandidateFeedback `json:"candidate_feedback,omitempty"`
	// RuntimeAttestation is the identity of the running helper binary.
	RuntimeAttestation *RuntimeAttestation `json:"runtime_attestation,omitempty"`
}

type CandidateFeedback struct {
	CandidateKeyHash string `json:"candidate_key_hash"`
	LeaseHash        string `json:"lease_hash"`
	Protocol         string `json:"protocol"`
	Outcome          string `json:"outcome"`
	Reason           string `json:"reason"`
	EvidenceID       string `json:"evidence_id"`
	ObservedAt       string `json:"observed_at"`
}

type StatusWriter struct {
	path            string
	interval        time.Duration
	mu              sync.Mutex
	writeMu         sync.Mutex
	status          RuntimeStatus
	lastWrite       time.Time
	dirty           bool
	timer           *time.Timer
	closed          bool
	writeCount      atomic.Int64
	reliableLive    map[[16]byte]reliableStats
	reliableRetired reliableStats
}

func newStatusWriter(path string, forwardID int, interval time.Duration) *StatusWriter {
	if interval <= 0 {
		interval = 5 * time.Second
	}
	attestation := runtimeAttestation()
	return &StatusWriter{
		path:     path,
		interval: interval,
		status: RuntimeStatus{
			ForwardID:           forwardID,
			State:               "ready",
			DetectedIngressZone: "unknown",
			RuntimeAttestation:  &attestation,
		},
	}
}

// RecordTransportConfig exposes only the selected transport, never bootstrap
// credentials. Desktop bootstrap is stdin-only, so disk config is not evidence.
func (writer *StatusWriter) RecordTransportConfig(config Config) {
	mode := "disabled"
	if stringsContainsUDP(config.Transport) {
		mode = "native_udp"
		if externalUoTEnabled(config) {
			mode = "external_uot"
		}
	}
	writer.update(func(status *RuntimeStatus) { status.UDPTransport = mode })
}

func (writer *StatusWriter) UpdateLocation(location LocationResult) {
	writer.update(func(status *RuntimeStatus) {
		status.DetectedIngressZone = normalizeRuntimeZone(location.Code)
		status.LocationSource = boundedStatusText(location.Source, 32)
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) UpdatePolicy(generation string) {
	writer.update(func(status *RuntimeStatus) {
		status.PolicyGeneration = boundedStatusText(generation, 64)
	})
}

func (writer *StatusWriter) AddUsage(bytesIn, bytesOut int) {
	if bytesIn <= 0 && bytesOut <= 0 {
		return
	}
	writer.update(func(status *RuntimeStatus) {
		status.BytesIn += int64(maxInt(bytesIn, 0))
		status.BytesOut += int64(maxInt(bytesOut, 0))
	})
}

func (writer *StatusWriter) UpdateTCP(selectionRef string, rtt time.Duration) {
	writer.UpdateTCPWithTier(selectionRef, selectionTierUnknown, rtt)
}

func (writer *StatusWriter) UpdateTCPWithTier(selectionRef, selectionTier string, rtt time.Duration) {
	writer.UpdateTCPWithTierAndCandidate(selectionRef, selectionTier, "", rtt)
}

func (writer *StatusWriter) UpdateTCPWithTierAndCandidate(selectionRef, selectionTier, candidateKeyHash string, rtt time.Duration, leaseHashes ...string) {
	writer.update(func(status *RuntimeStatus) {
		status.LatestTCP = selectionStatusWithTierAndCandidate(selectionRef, selectionTier, candidateKeyHash, rtt, leaseHashes...)
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) UpdateUDP(selectionRef string, rtt time.Duration) {
	writer.UpdateUDPWithTier(selectionRef, selectionTierUnknown, rtt)
}

func (writer *StatusWriter) UpdateUDPWithTier(selectionRef, selectionTier string, rtt time.Duration) {
	writer.UpdateUDPWithTierAndCandidate(selectionRef, selectionTier, "", rtt)
}

func (writer *StatusWriter) UpdateUDPWithTierAndCandidate(selectionRef, selectionTier, candidateKeyHash string, rtt time.Duration, leaseHashes ...string) {
	writer.update(func(status *RuntimeStatus) {
		status.ActiveUDP = selectionStatusWithTierAndCandidate(selectionRef, selectionTier, candidateKeyHash, rtt, leaseHashes...)
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) RecordCandidateFailure(candidateKeyHash, protocol, reason string, leaseHashes ...string) {
	writer.recordCandidateFeedback(candidateKeyHash, protocol, "failure", reason, leaseHashes...)
}

// RecordProbeFailure turns a candidate the selection probe rejected into
// evidence the Panel can act on.
//
// Until this existed, a relay that accepted the TCP connection and then closed
// the datagram handshake ("datagram handshake: EOF") produced no feedback at
// all: the runtime dropped it locally, selected another candidate, and probed
// the same relay again on the next batch. The reason strings are the same ones
// the control channel reports (probe_udp_failed / probe_tcp_failed), and both
// halves are recorded when both failed, so a relay that carries neither
// protocol is still visible.
func (writer *StatusWriter) RecordProbeFailure(lease ProviderLease, result udpProbeResult) {
	// probeTCPLease also stores its error in Err. Treat the UDP half as failed
	// only when this result actually contains a UDP measurement; otherwise a
	// TCP-only failure would pollute the panel's UDP reputation stream.
	udpFailed := false
	if result.TCPSent == 0 {
		udpFailed = result.Err != nil
	} else if result.Sent > 0 {
		udpFailed = !result.pathUsable(result.Sent, result.Received) &&
			(result.Sent > 0 || result.Err != nil)
	}
	if udpFailed {
		writer.RecordCandidateFailure(lease.CandidateKeyHash, "udp", errProbeUDPFailed.Error(), lease.LeaseHash)
	}
	tcpFailed := result.TCPSent > 0 &&
		(result.TCPError != nil || !result.pathUsable(result.TCPSent, result.TCPReceived))
	if tcpFailed {
		writer.RecordCandidateFailure(lease.CandidateKeyHash, "tcp", errProbeTCPFailed.Error(), lease.LeaseHash)
	}
}

func (writer *StatusWriter) RecordCandidateSuccess(candidateKeyHash, protocol string, leaseHashes ...string) {
	writer.recordCandidateFeedback(candidateKeyHash, protocol, "success", "canary_probe_ok", leaseHashes...)
}

func (writer *StatusWriter) recordCandidateFeedback(candidateKeyHash, protocol, outcome, reason string, leaseHashes ...string) {
	if writer == nil || len(candidateKeyHash) != 64 || !isLowerHex(strings.ToLower(candidateKeyHash)) {
		return
	}
	protocol = strings.ToLower(strings.TrimSpace(protocol))
	if protocol != "tcp" && protocol != "udp" && protocol != "tcp+udp" && protocol != "native_socks5" {
		return
	}
	reason = boundedStatusText(strings.ToLower(strings.TrimSpace(reason)), 64)
	if reason == "" || reason == "process_stderr" {
		return
	}
	leaseHash := ""
	if len(leaseHashes) > 0 {
		leaseHash = normalizedLeaseHash(leaseHashes[0])
	}
	evidence := fmt.Sprintf("%d-%s", time.Now().UTC().UnixNano(), boundedStatusText(candidateKeyHash, 8))
	writer.update(func(status *RuntimeStatus) {
		status.CandidateFeedback = append(status.CandidateFeedback, CandidateFeedback{
			CandidateKeyHash: strings.ToLower(candidateKeyHash),
			LeaseHash:        leaseHash,
			Protocol:         protocol,
			Outcome:          outcome,
			Reason:           reason,
			EvidenceID:       evidence,
			ObservedAt:       time.Now().UTC().Format(time.RFC3339),
		})
		if len(status.CandidateFeedback) > 8 {
			status.CandidateFeedback = status.CandidateFeedback[len(status.CandidateFeedback)-8:]
		}
	})
}

func (writer *StatusWriter) UpdateUDPProbe(selectionRef string, result udpProbeResult) {
	writer.UpdateUDPProbeWithTier(selectionRef, result.SelectionTier, result)
}

func (writer *StatusWriter) UpdateUDPProbeWithTier(selectionRef, selectionTier string, result udpProbeResult) {
	writer.UpdateUDPProbeWithTierAndCandidate(selectionRef, selectionTier, "", result)
}

func (writer *StatusWriter) UpdateUDPProbeWithTierAndCandidate(selectionRef, selectionTier, candidateKeyHash string, result udpProbeResult, leaseHashes ...string) {
	if result.Sent <= 0 || result.Received <= 0 {
		return
	}
	lost := result.Sent - result.Received
	if lost < 0 {
		lost = 0
	}
	lossPercent := int64(lost * 100 / result.Sent)
	writer.update(func(status *RuntimeStatus) {
		ceiling, ceilingSource := payloadCeilingStatus(result)
		status.ActiveUDP = &SelectionStatus{
			SelectionRef:         boundedStatusText(selectionRef, 128),
			SelectionTier:        normalizedSelectionTier(selectionTier),
			CandidateKeyHash:     normalizedCandidateKeyHash(candidateKeyHash),
			LeaseHash:            normalizedLeaseHash(firstOptional(leaseHashes)),
			RTTMillis:            maxInt64(result.MedianRTT.Milliseconds(), 0),
			P95RTTMillis:         maxInt64(result.P95RTT.Milliseconds(), 0),
			JitterMillis:         maxInt64(result.Jitter.Milliseconds(), 0),
			LossPercent:          maxInt64(minInt64(lossPercent, 100), 0),
			ProbeSent:            result.Sent,
			ProbeReceived:        result.Received,
			SelectedAt:           time.Now().UTC().Format(time.RFC3339),
			PayloadCeiling:       ceiling,
			PayloadCeilingSource: ceilingSource,
		}
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) UpdateUDPAssociations(active, pending int) {
	writer.update(func(status *RuntimeStatus) {
		status.UDPActiveAssociations = maxInt(active, 0)
		status.UDPPendingAssociations = maxInt(pending, 0)
	})
}

// UpdateUDPPayloadCeiling publishes the payload ceiling of the active
// association with its provenance, so hosts can clamp the TUN MTU
// (MTU = ceiling - envelope overhead) and the panel can see which association
// is narrow. A declared 0 means "unknown" and keeps the last verdict; a
// measured 0 is a real verdict that the path carries no payload at all.
func (writer *StatusWriter) UpdateUDPPayloadCeiling(ceiling int, source string) {
	if ceiling < 0 {
		ceiling = 0
	}
	source = strings.ToLower(strings.TrimSpace(source))
	if source != payloadCeilingSourceMeasured {
		source = payloadCeilingSourceDeclared
		if ceiling <= 0 {
			return
		}
	}
	writer.update(func(status *RuntimeStatus) {
		if status.UDPPayloadCeiling == ceiling && status.UDPPayloadCeilingSource == source {
			return
		}
		status.UDPPayloadCeiling = ceiling
		status.UDPPayloadCeilingSource = source
		if status.ActiveUDP != nil {
			status.ActiveUDP.PayloadCeiling = ceiling
			status.ActiveUDP.PayloadCeilingSource = source
		}
	})
}

// AddUDPOversizeDrop accounts for a datagram refused above the measured ceiling.
func (writer *StatusWriter) AddUDPOversizeDrop() {
	writer.update(func(status *RuntimeStatus) {
		status.UDPOversizeDrops++
	})
}

func (writer *StatusWriter) AddUDPQueueDrop() {
	writer.update(func(status *RuntimeStatus) {
		status.UDPQueueDrops++
	})
}

func (writer *StatusWriter) AddUDPSetupFailure() {
	writer.update(func(status *RuntimeStatus) {
		status.UDPSetupFailures++
	})
}

func (writer *StatusWriter) UpdateError(code string) {
	writer.update(func(status *RuntimeStatus) {
		status.State = "degraded"
		status.ErrorCode = boundedStatusText(code, 64)
	})
}

func (writer *StatusWriter) update(change func(*RuntimeStatus)) {
	if writer == nil || writer.path == "" {
		return
	}
	writer.mu.Lock()
	if writer.closed {
		writer.mu.Unlock()
		return
	}
	change(&writer.status)
	writer.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	writer.dirty = true
	remaining := writer.interval - time.Since(writer.lastWrite)
	if writer.lastWrite.IsZero() || remaining <= 0 {
		snapshot := writer.status
		writer.dirty = false
		writer.lastWrite = time.Now()
		writer.mu.Unlock()
		writer.writeSnapshot(snapshot)
		return
	}
	if writer.timer == nil {
		writer.timer = time.AfterFunc(remaining, writer.flush)
	}
	writer.mu.Unlock()
}

func (writer *StatusWriter) flush() {
	writer.mu.Lock()
	writer.timer = nil
	if writer.closed || !writer.dirty {
		writer.mu.Unlock()
		return
	}
	snapshot := writer.status
	writer.dirty = false
	writer.lastWrite = time.Now()
	writer.mu.Unlock()
	writer.writeSnapshot(snapshot)
}

func (writer *StatusWriter) Close() {
	if writer == nil {
		return
	}
	writer.mu.Lock()
	if writer.closed {
		writer.mu.Unlock()
		return
	}
	writer.closed = true
	if writer.timer != nil {
		writer.timer.Stop()
		writer.timer = nil
	}
	dirty := writer.dirty
	snapshot := writer.status
	writer.dirty = false
	writer.mu.Unlock()
	if dirty {
		writer.writeSnapshot(snapshot)
	}
	// A timer callback may have cleared dirty and started an atomic write just
	// before Close acquired mu. Wait for that write before returning so a
	// process shutdown cannot leave the caller observing the previous snapshot.
	writer.writeMu.Lock()
	writer.writeMu.Unlock()
}

func (writer *StatusWriter) writeSnapshot(status RuntimeStatus) {
	encoded, err := json.Marshal(status)
	if err != nil || len(encoded) > 64*1024 {
		return
	}
	writer.writeMu.Lock()
	defer writer.writeMu.Unlock()
	if err := os.MkdirAll(filepath.Dir(writer.path), 0o700); err != nil {
		return
	}
	temporary := filepath.Join(filepath.Dir(writer.path), "."+filepath.Base(writer.path)+fmt.Sprintf(".%d.tmp", os.Getpid()))
	file, err := os.OpenFile(temporary, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return
	}
	_, writeErr := file.Write(append(encoded, '\n'))
	if writeErr == nil {
		writeErr = file.Sync()
	}
	closeErr := file.Close()
	if writeErr != nil || closeErr != nil {
		_ = os.Remove(temporary)
		return
	}
	_ = os.Chmod(temporary, 0o600)
	if os.Rename(temporary, writer.path) != nil {
		_ = os.Remove(temporary)
		return
	}
	_ = os.Chmod(writer.path, 0o600)
	writer.writeCount.Add(1)
}

func (writer *StatusWriter) writeCountValue() int64 {
	return writer.writeCount.Load()
}

func selectionStatus(selectionRef string, rtt time.Duration) *SelectionStatus {
	return selectionStatusWithTier(selectionRef, selectionTierUnknown, rtt)
}

func selectionStatusWithTier(selectionRef, selectionTier string, rtt time.Duration) *SelectionStatus {
	return selectionStatusWithTierAndCandidate(selectionRef, selectionTier, "", rtt)
}

func selectionStatusWithTierAndCandidate(selectionRef, selectionTier, candidateKeyHash string, rtt time.Duration, leaseHashes ...string) *SelectionStatus {
	millis := rtt.Milliseconds()
	if millis < 0 {
		millis = 0
	}
	if millis > 60000 {
		millis = 60000
	}
	return &SelectionStatus{
		SelectionRef:     boundedStatusText(selectionRef, 128),
		SelectionTier:    normalizedSelectionTier(selectionTier),
		CandidateKeyHash: normalizedCandidateKeyHash(candidateKeyHash),
		LeaseHash:        normalizedLeaseHash(firstOptional(leaseHashes)),
		RTTMillis:        millis,
		SelectedAt:       time.Now().UTC().Format(time.RFC3339),
	}
}

// payloadCeilingStatus describes a probe result's ceiling for status: the value
// and whether it came from the panel declaration or a local measurement. An
// absent ceiling publishes nothing at all.
func payloadCeilingStatus(result udpProbeResult) (int, string) {
	if result.PayloadCeiling <= 0 {
		if result.PayloadCeilingMeasured {
			return 0, payloadCeilingSourceMeasured
		}
		return 0, ""
	}
	if result.PayloadCeilingMeasured {
		return result.PayloadCeiling, payloadCeilingSourceMeasured
	}
	return result.PayloadCeiling, payloadCeilingSourceDeclared
}

func firstOptional(values []string) string {
	if len(values) == 0 {
		return ""
	}
	return values[0]
}

func normalizedLeaseHash(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	if len(value) == 64 && isLowerHex(value) {
		return value
	}
	return ""
}

func normalizedCandidateKeyHash(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	if len(value) == 64 && isLowerHex(value) {
		return value
	}
	return ""
}

func normalizedSelectionTier(value string) string {
	normalized, err := normalizeSelectionTier(value)
	if err != nil {
		return selectionTierUnknown
	}
	return normalized
}

func boundedStatusText(value string, limit int) string {
	runes := []rune(value)
	if len(runes) > limit {
		return string(runes[:limit])
	}
	return value
}

func maxInt(value, minimum int) int {
	if value < minimum {
		return minimum
	}
	return value
}

func maxInt64(value, minimum int64) int64 {
	if value < minimum {
		return minimum
	}
	return value
}

func minInt64(value, maximum int64) int64 {
	if value > maximum {
		return maximum
	}
	return value
}

func (writer *StatusWriter) AddUoTQueueBlocked() {
	writer.update(func(s *RuntimeStatus) { s.UoTQueueBlocked++ })
}
func (writer *StatusWriter) AddUoTQueueTimeout() {
	writer.update(func(s *RuntimeStatus) { s.UoTQueueTimeout++; s.UoTQueueDrop++; s.UDPQueueDrops++ })
}
func (writer *StatusWriter) SetUoTSocketBuffers(recv, send int, failed bool) {
	writer.update(func(s *RuntimeStatus) {
		s.UoTSocketRcvbuf = recv
		s.UoTSocketSndbuf = send
		s.UoTSocketBufferError = failed
	})
}

// Counters and sequence totals cover every association, including retired ones.
// Window gauges cover live associations only; a quiet prewarm carrier must not
// overwrite the busy data carrier's loss/replay evidence.
func (w *StatusWriter) SetReliableStats(id [16]byte, s reliableStats, retired bool) {
	w.update(func(status *RuntimeStatus) {
		if w.reliableLive == nil {
			w.reliableLive = make(map[[16]byte]reliableStats)
		}
		if retired {
			delete(w.reliableLive, id)
			s.WindowPackets, s.WindowBytes, s.OldestUnackedMS = 0, 0, 0
			addReliableStats(&w.reliableRetired, s)
		} else {
			w.reliableLive[id] = s
		}
		total := w.reliableRetired
		for _, item := range w.reliableLive {
			addReliableStats(&total, item)
		}
		status.UoTReliable = &total
	})
}

func addReliableStats(total *reliableStats, s reliableStats) {
	total.QueueBlocked += s.QueueBlocked
	total.QueueTimeout += s.QueueTimeout
	total.QueueDrop += s.QueueDrop
	total.SequenceGap += s.SequenceGap
	total.RetransmitCount += s.RetransmitCount
	total.ReconnectReplayCount += s.ReconnectReplayCount
	total.UnrecoverableCount += s.UnrecoverableCount
	total.Deduplicated += s.Deduplicated
	total.Acknowledged += s.Acknowledged
	total.Delivered += s.Delivered
	total.WindowPackets += s.WindowPackets
	total.WindowBytes += s.WindowBytes
	if s.OldestUnackedMS > total.OldestUnackedMS {
		total.OldestUnackedMS = s.OldestUnackedMS
	}
	if s.Failed != "" {
		total.Failed = "one or more reliable associations failed"
	}
}

func (w *StatusWriter) SetUoTSocketDrops(n *uint64) {
	w.update(func(s *RuntimeStatus) { s.UoTSocketReceiveDrop = n })
}
