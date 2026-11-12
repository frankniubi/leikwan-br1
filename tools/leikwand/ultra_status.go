package main

import (
	"encoding/json"
	"regexp"
	"sort"
	"strings"
	"time"
)

// ---------------------------------------------------------------------------
// Runtime status for the Panel heartbeat
//
// The Panel stores what arrives in "ultra_status" as the device session's
// ultra_status_json (master/src/Support/UltraClientStatusService.php ingest /
// normalizeStatus). The desktop client sends the newest status document of
// every forward; the router does the same from the documents it already parsed
// for usage accounting, so the heartbeat never reads a status file twice.
// ---------------------------------------------------------------------------

const (
	// maxUltraStatusEntries matches UltraClientStatusService::MAX_STATUSES.
	maxUltraStatusEntries = 32
	// maxUltraStatusEvents matches normalizeHAEvents, which keeps the last 8.
	maxUltraStatusEvents = 8
	// maxUltraStatusBytes keeps the encoded list well inside the Panel's
	// 64 KiB MAX_STATUS_BYTES after it re-encodes every entry with all keys;
	// an oversized list is dropped by the Panel as a whole.
	maxUltraStatusBytes = 48 * 1024
	// ultraStatusMaxAge mirrors the desktop: a document the runtime has not
	// rewritten for five minutes describes a runtime that is gone or wedged.
	ultraStatusMaxAge = 5 * time.Minute
)

var (
	ultraZonePattern      = regexp.MustCompile(`^[a-z0-9-]{1,32}$`)
	ultraSelectionPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{16,128}$`)
	ultraCodePattern      = regexp.MustCompile(`^[a-z0-9_]{1,64}$`)
)

type ultraStatusSource struct {
	forwardID     int
	status        *RuntimeStatus
	readAt        time.Time
	leaseFailures int
	leaseErr      string
}

// collectUltraStatus builds the heartbeat's ultra_status list from the cached
// runtime documents: the health loop's per-forward reads and the native relay
// document collectUsage just read. It always returns a non-nil slice.
func (a *Agent) collectUltraStatus(now time.Time) []UltraStatusReport {
	a.mu.Lock()
	sources := make([]ultraStatusSource, 0, len(a.runtimes)+1)
	for _, state := range a.runtimes {
		sources = append(sources, ultraStatusSource{
			forwardID:     state.forward.ForwardID,
			status:        state.status,
			readAt:        state.statusAt,
			leaseFailures: state.leaseFailures,
			leaseErr:      state.lastLeaseErr,
		})
	}
	gateway := a.gateway
	a.mu.Unlock()
	if gateway != nil {
		gateway.mu.Lock()
		slot := &gateway.primary
		if slot.relay != nil && slot.status != nil {
			sources = append(sources, ultraStatusSource{
				forwardID:     slot.plan.Carrier.ForwardID,
				status:        slot.status,
				readAt:        slot.statusAt,
				leaseFailures: slot.leaseFailures,
				leaseErr:      slot.leaseErr,
			})
		}
		gateway.mu.Unlock()
	}
	return buildUltraStatusReports(sources, now)
}

type datedUltraStatus struct {
	report     UltraStatusReport
	observedAt time.Time
}

func buildUltraStatusReports(sources []ultraStatusSource, now time.Time) []UltraStatusReport {
	latest := make(map[int]datedUltraStatus, len(sources))
	for _, source := range sources {
		status := source.status
		if status == nil || source.forwardID <= 0 {
			continue
		}
		if status.ForwardID > 0 && status.ForwardID != source.forwardID {
			continue
		}
		state := strings.ToLower(strings.TrimSpace(status.State))
		code := strings.TrimSpace(status.ErrorCode)
		if state == "stopped" || code == "runtime_stopped" {
			continue
		}
		observedAt := source.readAt
		if parsed, err := time.Parse(time.RFC3339Nano, strings.TrimSpace(status.UpdatedAt)); err == nil {
			observedAt = parsed
		}
		if !observedAt.IsZero() && observedAt.Before(now.Add(-ultraStatusMaxAge)) {
			continue
		}
		report := ultraStatusReport(source.forwardID, status)
		markLeaseFailure(&report, source.leaseFailures, source.leaseErr)
		if previous, ok := latest[source.forwardID]; ok && !observedAt.After(previous.observedAt) {
			continue
		}
		latest[source.forwardID] = datedUltraStatus{report: report, observedAt: observedAt}
	}

	ordered := make([]datedUltraStatus, 0, len(latest))
	for _, item := range latest {
		ordered = append(ordered, item)
	}
	sort.Slice(ordered, func(i, j int) bool {
		if !ordered[i].observedAt.Equal(ordered[j].observedAt) {
			return ordered[i].observedAt.After(ordered[j].observedAt)
		}
		return ordered[i].report.ForwardID < ordered[j].report.ForwardID
	})

	reports := make([]UltraStatusReport, 0, len(ordered))
	budget := maxUltraStatusBytes
	for _, item := range ordered {
		if len(reports) >= maxUltraStatusEntries {
			break
		}
		report := item.report
		size := encodedSize(report)
		if size > budget {
			// Keep the health verdict and counters; the event history is the
			// bulk of an entry and the first thing to give up.
			report.HAEvents = []HAEvent{}
			size = encodedSize(report)
		}
		if size > budget {
			break
		}
		budget -= size
		reports = append(reports, report)
	}
	return reports
}

func ultraStatusReport(forwardID int, status *RuntimeStatus) UltraStatusReport {
	state := strings.ToLower(strings.TrimSpace(status.State))
	switch state {
	case "":
		state = "ready"
	case "ready", "degraded", "error", "stopped":
	default:
		state = "error"
	}
	zone := strings.ToLower(strings.TrimSpace(status.DetectedIngressZone))
	if !ultraZonePattern.MatchString(zone) {
		zone = "unknown"
	}
	transport := ""
	switch status.UDPTransport {
	case "disabled", "native_udp", "external_uot":
		transport = status.UDPTransport
	}
	return UltraStatusReport{
		ForwardID:           forwardID,
		State:               state,
		UDPTransport:        transport,
		DetectedIngressZone: zone,
		LatestTCP:           ultraSelectionReport(status.LatestTCP),
		ActiveUDP:           ultraSelectionReport(status.ActiveUDP),
		HAFailures:          nonNegative(status.HAFailures),
		UDPQueueDrops:       nonNegative(status.UDPQueueDrops),
		UDPSetupFailures:    nonNegative(status.UDPSetupFailures),
		BytesIn:             nonNegative(status.BytesIn),
		BytesOut:            nonNegative(status.BytesOut),
		HAEvents:            ultraHAEvents(status.HAEvents),
		UpdatedAt:           statusText(status.UpdatedAt, 40),
		ErrorCode:           statusText(status.ErrorCode, 64),
	}
}

func ultraSelectionReport(selection *SelectionStatus) *UltraSelectionReport {
	if selection == nil || !ultraSelectionPattern.MatchString(strings.TrimSpace(selection.SelectionRef)) {
		return nil
	}
	return &UltraSelectionReport{
		SelectionRef:     strings.TrimSpace(selection.SelectionRef),
		RTTMillis:        nonNegative(selection.RTTMillis),
		SelectedAt:       statusText(selection.SelectedAt, 40),
		CandidateKeyHash: opaqueHash(selection.CandidateKeyHash),
		P95RTTMillis:     nonNegative(selection.P95RTTMillis),
		JitterMillis:     nonNegative(selection.JitterMillis),
		LossPercent:      nonNegative(selection.LossPercent),
		ProbeSent:        max(selection.ProbeSent, 0),
		ProbeReceived:    max(selection.ProbeReceived, 0),
	}
}

// ultraHAEvents keeps the newest events the Panel would keep, restricted to
// the fields it accepts. It is never nil, so the wire shape is always a list.
func ultraHAEvents(events []HAEvent) []HAEvent {
	if len(events) > maxUltraStatusEvents {
		events = events[len(events)-maxUltraStatusEvents:]
	}
	result := make([]HAEvent, 0, len(events))
	for _, event := range events {
		protocol := strings.ToLower(strings.TrimSpace(event.Protocol))
		reason := strings.ToLower(statusText(event.Reason, 64))
		if (protocol != "tcp" && protocol != "udp") || reason == "" {
			continue
		}
		cleaned := HAEvent{
			Protocol:           protocol,
			Reason:             reason,
			Generation:         statusText(event.Generation, 64),
			At:                 statusText(event.At, 40),
			CandidateKeyHash:   opaqueHash(event.CandidateKeyHash),
			LeaseHash:          opaqueHash(event.LeaseHash),
			UpstreamBytes:      nonNegativePtr(event.UpstreamBytes),
			RemoteReadBytes:    nonNegativePtr(event.RemoteReadBytes),
			RemoteStoppedFirst: event.RemoteStoppedFirst,
			LocalPathAvoided:   event.LocalPathAvoided,
		}
		if ultraCodePattern.MatchString(event.RemoteReadReason) {
			cleaned.RemoteReadReason = event.RemoteReadReason
		}
		result = append(result, cleaned)
	}
	return result
}

// markLeaseFailure reports a forward whose grant could not be renewed as
// degraded, like the Linux agent: the runtime keeps saying "ready" while it
// serves an aging lease. Only a code-shaped reason is forwarded, never the
// free-text error.
func markLeaseFailure(report *UltraStatusReport, failures int, lastErr string) {
	if failures <= 0 || report.State != "ready" {
		return
	}
	report.State = "degraded"
	if report.ErrorCode == "" || report.ErrorCode == "ok" {
		report.ErrorCode = leaseFailureCode(lastErr)
	}
}

// leaseFailureCode extracts the Panel's error code from a recorded lease
// failure ("panel error 403: runtime_unattested" or a bare "no_leases").
func leaseFailureCode(lastErr string) string {
	text := strings.TrimSpace(lastErr)
	if strings.HasPrefix(text, "panel error ") {
		if index := strings.Index(text, ": "); index >= 0 {
			text = strings.TrimSpace(text[index+2:])
		}
	}
	if ultraCodePattern.MatchString(text) {
		return text
	}
	return "lease_refresh_failed"
}

// statusText trims and bounds a runtime-provided string to printable ASCII,
// so nothing the runtime wrote can grow the payload or smuggle control bytes.
func statusText(value string, limit int) string {
	value = strings.TrimSpace(value)
	var b strings.Builder
	for i := 0; i < len(value) && b.Len() < limit; i++ {
		if c := value[i]; c > 0x20 && c < 0x7f {
			b.WriteByte(c)
		}
	}
	return b.String()
}

func opaqueHash(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	if isHex64(value) {
		return value
	}
	return ""
}

func nonNegative(value int64) int64 {
	if value < 0 {
		return 0
	}
	return value
}

func nonNegativePtr(value *int64) *int64 {
	if value == nil {
		return nil
	}
	bounded := nonNegative(*value)
	return &bounded
}

func encodedSize(value any) int {
	raw, err := json.Marshal(value)
	if err != nil {
		return maxUltraStatusBytes + 1
	}
	return len(raw) + 1 // the separating comma
}
