package main

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"regexp"
	"sort"
	"strings"
	"time"
)

const (
	debugLogChunkBytes = 256 * 1024
	debugLogByteLimit  = 8 * 1024 * 1024 // Router-side budget (well inside panel's 100 MiB cap).
)

var (
	debugIPv4Pattern = regexp.MustCompile(`\b(?:\d{1,3}\.){3}\d{1,3}\b`)
	debugIPv6Pattern = regexp.MustCompile(`(?i)(?:[0-9a-f]{0,4}:){2,}[0-9a-f:.]+`)
)

// redactDebugText applies credential redaction plus IP literal scrubbing so
// uploaded router logs match the desktop/python privacy contract.
func redactDebugText(value string) string {
	text := redactSecrets(value)
	text = debugIPv4Pattern.ReplaceAllStringFunc(text, func(candidate string) string {
		if ip := net.ParseIP(candidate); ip != nil && ip.To4() != nil {
			return "[REDACTED]"
		}
		return candidate
	})
	text = debugIPv6Pattern.ReplaceAllStringFunc(text, func(candidate string) string {
		trimmed := strings.Trim(candidate, "[]")
		if idx := strings.IndexByte(trimmed, '%'); idx >= 0 {
			trimmed = trimmed[:idx]
		}
		if ip := net.ParseIP(trimmed); ip != nil && ip.To4() == nil {
			return "[REDACTED]"
		}
		return candidate
	})
	return text
}

func (a *Agent) buildDebugLogBundle() []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "=== leikwand %s arch=%s utc=%s ===\n",
		agentVersion, goArch(), time.Now().UTC().Format(time.RFC3339))
	if diagJSON, err := json.MarshalIndent(a.diagnose(), "", "  "); err == nil {
		b.WriteString("=== diagnose ===\n")
		b.Write(diagJSON)
		b.WriteByte('\n')
	}
	statuses := a.runtimeStatusFiles()
	names := make([]string, 0, len(statuses))
	for name := range statuses {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		fmt.Fprintf(&b, "=== runtime status %s ===\n%s\n", name, strings.TrimSpace(statuses[name]))
	}
	b.WriteString("=== in-memory events ===\n")
	for _, ev := range a.log.Events(256) {
		fmt.Fprintf(&b, "%s [%s] %s: %s\n",
			ev.At.Format(time.RFC3339), strings.ToUpper(ev.Level), ev.Scope, ev.Message)
	}
	if trace := a.readTrace(); trace != "" {
		b.WriteString("=== trace (leikwand + runtime lines kept in tmpfs) ===\n")
		b.WriteString(trace)
	}
	if sysOut, err := runCommandFunc(2*time.Second, "logread", "-e", "leikwan"); err == nil && strings.TrimSpace(sysOut) != "" {
		lines := strings.Split(sysOut, "\n")
		if len(lines) > 400 {
			lines = lines[len(lines)-400:]
		}
		b.WriteString("=== logread -e leikwan ===\n")
		b.WriteString(strings.Join(lines, "\n"))
		b.WriteByte('\n')
	}
	sanitized := []byte(redactDebugText(b.String()))
	if len(sanitized) > debugLogByteLimit {
		sanitized = sanitized[:debugLogByteLimit]
	}
	return sanitized
}

func (a *Agent) uploadBundleToCollection(ctx context.Context, uuid string) (int, int, error) {
	bundle := a.buildDebugLogBundle()
	if len(bundle) == 0 {
		bundle = []byte("=== empty log ===\n")
	}
	chunks := 0
	for offset := 0; offset < len(bundle); offset += debugLogChunkBytes {
		end := offset + debugLogChunkBytes
		if end > len(bundle) {
			end = len(bundle)
		}
		part := bundle[offset:end]
		sum := sha256.Sum256(part)
		shaHex := hex.EncodeToString(sum[:])
		encoded := base64.StdEncoding.EncodeToString(part)
		if err := a.panel.UploadDebugLogChunk(ctx, uuid, chunks, shaHex, encoded, "leikwand.log"); err != nil {
			return chunks, offset, err
		}
		chunks++
	}
	return chunks, len(bundle), nil
}

// submitDebugLogs uploads logs when the panel's pull metadata carries an active
// debug_collection window.
func (a *Agent) submitDebugLogs(ctx context.Context, uuid string) {
	chunks, totalBytes, err := a.uploadBundleToCollection(ctx, uuid)
	if err != nil {
		a.log.Warn("diagnostics", "debug log upload failed for %s: %v", shortRevision(uuid), err)
		return
	}
	a.mu.Lock()
	if a.debugUUID == uuid {
		a.debugUUID = ""
	}
	a.debugDoneUUID = uuid
	a.mu.Unlock()
	a.log.Info("diagnostics", "uploaded %d byte(s) in %d chunk(s) for collection %s",
		totalBytes, chunks, shortRevision(uuid))
}

// uploadLogsNow opens a fresh collection window on the panel and uploads logs immediately.
func (a *Agent) uploadLogsNow(ctx context.Context) (map[string]any, error) {
	started, err := a.panel.StartDebugCollection(ctx)
	if err != nil {
		return nil, err
	}
	uuid := strings.ToLower(strings.TrimSpace(started.DebugCollection.UUID))
	chunks, totalBytes, err := a.uploadBundleToCollection(ctx, uuid)
	if err != nil {
		return nil, err
	}
	a.mu.Lock()
	a.debugDoneUUID = uuid
	a.mu.Unlock()
	return map[string]any{
		"reference_code": started.ReferenceCode,
		"uuid":           uuid,
		"chunks":         chunks,
		"bytes":          totalBytes,
	}, nil
}
