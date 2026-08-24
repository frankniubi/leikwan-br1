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
	EntryRef   string `json:"entry_ref"`
	RelayIP    string `json:"relay_ip"`
	RTTMillis  int64  `json:"rtt_ms"`
	SelectedAt string `json:"selected_at"`
}

type RuntimeStatus struct {
	ForwardID           int              `json:"forward_id"`
	State               string           `json:"state"`
	DetectedIngressZone string           `json:"detected_ingress_zone"`
	LocationSource      string           `json:"location_source"`
	LatestTCP           *SelectionStatus `json:"latest_tcp,omitempty"`
	ActiveUDP           *SelectionStatus `json:"active_udp,omitempty"`
	UpdatedAt           string           `json:"updated_at"`
	ErrorCode           string           `json:"error_code,omitempty"`
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

func (writer *StatusWriter) UpdateTCP(entry Entry, rtt time.Duration) {
	writer.update(func(status *RuntimeStatus) {
		status.LatestTCP = selectionStatus(entry, rtt)
		status.State = "ready"
		status.ErrorCode = ""
	})
}

func (writer *StatusWriter) UpdateUDP(entry Entry, rtt time.Duration) {
	writer.update(func(status *RuntimeStatus) {
		status.ActiveUDP = selectionStatus(entry, rtt)
		status.State = "ready"
		status.ErrorCode = ""
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

func selectionStatus(entry Entry, rtt time.Duration) *SelectionStatus {
	millis := rtt.Milliseconds()
	if millis < 0 {
		millis = 0
	}
	if millis > 60000 {
		millis = 60000
	}
	return &SelectionStatus{
		EntryRef:   boundedStatusText(entry.EntryRef, 128),
		RelayIP:    entry.IP,
		RTTMillis:  millis,
		SelectedAt: time.Now().UTC().Format(time.RFC3339),
	}
}

func boundedStatusText(value string, limit int) string {
	runes := []rune(value)
	if len(runes) > limit {
		return string(runes[:limit])
	}
	return value
}
