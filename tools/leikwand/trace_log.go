package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// The router's logd ring is small (tens of KiB on most firmwares), so an
// overnight run of relay handshake failures is long gone by the time anyone
// asks for logs. The trace keeps a bounded copy of leikwand's own lines -
// including everything the Ultra runtime prints, which reaches logd only
// through leikwand's stdout - in tmpfs, so a debug collection can still show
// what happened hours ago. It never touches flash.
const (
	traceInterval  = time.Minute
	traceFileBytes = 1536 * 1024 // two generations: ~3 MiB of RAM at most
	traceFileName  = "trace.log"
)

type traceState struct {
	lastLine string
}

func (a *Agent) tracePath() string {
	return filepath.Join(a.cfg.RunDir, traceFileName)
}

func (a *Agent) traceLoop(ctx context.Context) {
	var state traceState
	a.captureTrace(&state)
	ticker := time.NewTicker(traceInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			a.captureTrace(&state)
		}
	}
}

// captureTrace appends the logd lines that arrived since the previous capture.
func (a *Agent) captureTrace(state *traceState) {
	out, err := runCommandFunc(5*time.Second, "logread", "-e", "leikwan")
	if err != nil || strings.TrimSpace(out) == "" {
		return
	}
	fresh := traceNewLines(strings.Split(strings.TrimRight(out, "\n"), "\n"), state.lastLine)
	if len(fresh) == 0 {
		return
	}
	state.lastLine = fresh[len(fresh)-1]
	appendTrace(a.tracePath(), redactSecrets(strings.Join(fresh, "\n")+"\n"))
}

// traceNewLines returns the lines after the last occurrence of lastLine. When
// lastLine has rotated out of logd (or this is the first capture) every line
// is new; a marker records that a gap may exist.
func traceNewLines(lines []string, lastLine string) []string {
	if lastLine == "" {
		return lines
	}
	for i := len(lines) - 1; i >= 0; i-- {
		if lines[i] == lastLine {
			return lines[i+1:]
		}
	}
	return append([]string{"--- trace: logd wrapped since last capture, lines may be missing ---"}, lines...)
}

func appendTrace(path, text string) {
	if info, err := os.Stat(path); err == nil && info.Size()+int64(len(text)) > traceFileBytes {
		_ = os.Rename(path, path+".1")
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return
	}
	defer f.Close()
	_, _ = f.WriteString(text)
}

// readTrace returns the older generation followed by the current one.
func (a *Agent) readTrace() string {
	var b strings.Builder
	for _, path := range []string{a.tracePath() + ".1", a.tracePath()} {
		if raw, err := os.ReadFile(path); err == nil {
			b.Write(raw)
		}
	}
	return b.String()
}

// runtimeStatusFiles returns each runtime's status.json (HA events, failure
// counters, latest candidate) keyed by its run directory name.
func (a *Agent) runtimeStatusFiles() map[string]string {
	matches, _ := filepath.Glob(filepath.Join(a.cfg.RunDir, "*", "status.json"))
	out := make(map[string]string, len(matches))
	for _, path := range matches {
		raw, err := os.ReadFile(path)
		if err != nil || len(raw) > 512*1024 {
			continue
		}
		out[filepath.Base(filepath.Dir(path))] = string(raw)
	}
	return out
}
