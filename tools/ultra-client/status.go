package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"time"
)

type SelectionStatus struct {
	SelectionRef  string `json:"selection_ref"`
	RTTMillis     int64  `json:"rtt_ms"`
	P95RTTMillis  int64  `json:"p95_rtt_ms,omitempty"`
	LossPercent   int64  `json:"loss_percent,omitempty"`
	ProbeSent     int    `json:"probe_sent,omitempty"`
	ProbeReceived int    `json:"probe_received,omitempty"`
	SelectedAt    string `json:"selected_at"`
}

type RuntimeStatus struct {
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
}

type StatusWriter struct {
	path       string
	interval   time.Duration
	mu         sync.Mutex
	writeMu    sync.Mutex
	status     RuntimeStatus
	lastWrite  time.Time
	dirty      bool
	timer      *time.Timer
	closed     bool
	writeCount atomic.Int64
}

func newStatusWriter(path string, forwardID int, interval time.Duration) *StatusWriter {
	if interval <= 0 {
		interval = 5 * time.Second
	}
	return &StatusWriter{
		path:     path,
		interval: interval,
		status: RuntimeStatus{
			ForwardID:           forwardID,
			State:               "ready",
			DetectedIngressZone: "unknown",
		},
	}
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
	writer.update(func(status *RuntimeStatus) {
		status.LatestTCP = selectionStatus(selectionRef, rtt)
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) UpdateUDP(selectionRef string, rtt time.Duration) {
	writer.update(func(status *RuntimeStatus) {
		status.ActiveUDP = selectionStatus(selectionRef, rtt)
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) UpdateUDPProbe(selectionRef string, result udpProbeResult) {
	if result.Sent <= 0 || result.Received <= 0 {
		return
	}
	lost := result.Sent - result.Received
	if lost < 0 {
		lost = 0
	}
	lossPercent := int64(lost * 100 / result.Sent)
	writer.update(func(status *RuntimeStatus) {
		status.ActiveUDP = &SelectionStatus{
			SelectionRef:  boundedStatusText(selectionRef, 128),
			RTTMillis:     maxInt64(result.MedianRTT.Milliseconds(), 0),
			P95RTTMillis:  maxInt64(result.P95RTT.Milliseconds(), 0),
			LossPercent:   maxInt64(minInt64(lossPercent, 100), 0),
			ProbeSent:     result.Sent,
			ProbeReceived: result.Received,
			SelectedAt:    time.Now().UTC().Format(time.RFC3339),
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
	millis := rtt.Milliseconds()
	if millis < 0 {
		millis = 0
	}
	if millis > 60000 {
		millis = 60000
	}
	return &SelectionStatus{
		SelectionRef: boundedStatusText(selectionRef, 128),
		RTTMillis:    millis,
		SelectedAt:   time.Now().UTC().Format(time.RFC3339),
	}
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
