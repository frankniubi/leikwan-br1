package main

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
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

func (a *Agent) buildDebugLogBundle(ctx context.Context) []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "=== leikwand %s arch=%s utc=%s ===\n",
		agentVersion, goArch(), time.Now().UTC().Format(time.RFC3339))
	if diagJSON, err := json.MarshalIndent(a.diagnoseWithContext(ctx), "", "  "); err == nil {
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

const (
	debugStateFile    = "debug_upload.json"
	debugStateLimit   = 12 * 1024 * 1024 // One base64-encoded 8 MiB bundle plus metadata.
	debugAttemptLimit = 40 * time.Second
	debugMaxReceipts  = 128 // At most 128 collections per 24h; overflow fails closed.
)

var (
	errDebugUnsupported = errors.New("unsupported_upload_protocol")
	errDebugSuperseded  = errors.New("debug upload superseded by a newer collection")
	errDebugDeferred    = errors.New("debug upload awaiting retry backoff or authentication")
)

type debugUpload struct {
	Collection    string    `json:"collection"`
	UploadID      string    `json:"upload_id"`
	ReferenceCode string    `json:"reference_code"`
	Bundle        []byte    `json:"bundle"`
	Digest        string    `json:"digest"`
	Sequence      int       `json:"next_sequence"`
	Failures      int       `json:"failures"`
	RetryAt       time.Time `json:"retry_at"`
	AuthPaused    bool      `json:"auth_paused"`
}

type debugReceipt struct {
	Collection string    `json:"collection"`
	Until      time.Time `json:"until"`
}

type debugUploadState struct {
	Version   int            `json:"version"`
	Binding   string         `json:"binding"` // Hash only: endpoint pool, token, client and device.
	Requested string         `json:"requested,omitempty"`
	Reference string         `json:"reference,omitempty"`
	Pending   *debugUpload   `json:"pending,omitempty"`
	Done      []debugReceipt `json:"done,omitempty"`
}

func validDebugUUID(value string) bool {
	if len(value) != 36 || value[8] != '-' || value[13] != '-' || value[18] != '-' || value[23] != '-' || value[14] != '4' || !strings.ContainsRune("89ab", rune(value[19])) {
		return false
	}
	_, err := hex.DecodeString(strings.ReplaceAll(value, "-", ""))
	return err == nil && value == strings.ToLower(value)
}

func newDebugUploadID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", err
	}
	b[6], b[8] = b[6]&0x0f|0x40, b[8]&0x3f|0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[:4], b[4:6], b[6:8], b[8:10], b[10:]), nil
}

func (s debugUploadState) validate() error {
	if s.Version != 1 || !isHex64(s.Binding) || len(s.Done) > debugMaxReceipts || len(s.Reference) > 128 || (s.Requested != "" && !validDebugUUID(s.Requested)) {
		return errors.New("invalid debug upload state")
	}
	for _, done := range s.Done {
		if !validDebugUUID(done.Collection) || done.Until.IsZero() {
			return errors.New("invalid debug upload receipt")
		}
	}
	if p := s.Pending; p != nil {
		sum := sha256.Sum256(p.Bundle)
		if p.Collection != s.Requested || !validDebugUUID(p.Collection) || !validDebugUUID(p.UploadID) ||
			len(p.Bundle) == 0 || len(p.Bundle) > debugLogByteLimit || p.Digest != hex.EncodeToString(sum[:]) ||
			p.Sequence < 0 || p.Sequence > (len(p.Bundle)+debugLogChunkBytes-1)/debugLogChunkBytes || p.Failures < 0 || p.Failures > 16 || len(p.ReferenceCode) > 128 {
			return errors.New("invalid pending debug upload")
		}
	}
	return nil
}

func (a *Agent) debugBinding(panelBinding string) string {
	cfg := a.config()
	identity, _ := json.Marshal([]string{panelBinding, cfg.PanelURL, cfg.FallbackURL})
	return sha256Hex(string(identity))
}

func (a *Agent) currentDebugBinding() string {
	_, binding := a.panel.debugClientSnapshot()
	return a.debugBinding(binding)
}

func (a *Agent) bindDebugLocked() error {
	binding := a.currentDebugBinding()
	if a.debugState.Binding == binding {
		return nil
	}
	// A changed account/panel/device invalidates all persisted work before any
	// POST. Persist only the digest, never credentials or authenticated URLs.
	if err := a.saveDebugLocked(debugUploadState{Version: 1, Binding: binding}); err != nil {
		return err
	}
	a.debugEpoch++
	return nil
}

// All state methods require debugMu. A bad read or an uncertain write poisons
// uploads for this process: never create a new ID or rebuild bytes after an IO
// failure. The previously committed file remains the authority after restart.
func (a *Agent) loadDebugLocked() error {
	if a.debugErr != nil {
		return a.debugErr
	}
	if a.debugLoaded {
		return a.bindDebugLocked()
	}
	a.debugLoaded = true
	a.debugState = debugUploadState{Version: 1, Binding: a.currentDebugBinding()}
	path := filepath.Join(a.store.dir, debugStateFile)
	f, err := os.Open(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		a.debugErr = err
		return err
	}
	raw, err := io.ReadAll(io.LimitReader(f, debugStateLimit+1))
	closeErr := f.Close()
	if err == nil {
		err = closeErr
	}
	if err == nil && len(raw) > debugStateLimit {
		err = errors.New("debug upload state exceeds size limit")
	}
	if err == nil {
		err = json.Unmarshal(raw, &a.debugState)
	}
	if err == nil {
		err = a.debugState.validate()
	}
	a.debugErr = err
	if err != nil {
		return err
	}
	return a.bindDebugLocked()
}

func (a *Agent) saveDebugLocked(next debugUploadState) error {
	if a.debugErr != nil {
		return a.debugErr
	}
	err := next.validate()
	var raw []byte
	if err == nil {
		raw, err = json.Marshal(next)
	}
	if err == nil && len(raw) > debugStateLimit {
		err = errors.New("debug upload state exceeds size limit")
	}
	if err == nil {
		err = writeFileAtomic(filepath.Join(a.store.dir, debugStateFile), raw, 0o600)
	}
	// Persist the rename too, not just the temp file, before sending any bytes.
	if err == nil && runtime.GOOS != "windows" {
		var dir *os.File
		dir, err = os.Open(a.store.dir)
		if err == nil {
			err = dir.Sync()
			closeErr := dir.Close()
			if err == nil {
				err = closeErr
			}
		}
	}
	if err != nil {
		a.debugErr = fmt.Errorf("persist debug upload: %w", err)
		return a.debugErr
	}
	a.debugState = next
	return nil
}

func (s debugUploadState) completed(uuid string) bool {
	for _, done := range s.Done {
		if done.Collection == uuid && time.Now().Before(done.Until) {
			return true
		}
	}
	return false
}

func (a *Agent) debugObservation() uint64 {
	a.debugMu.Lock()
	defer a.debugMu.Unlock()
	return a.debugEpoch
}

// The observation ticket is captured BEFORE the HTTP pull. A late inactive
// response must not clear a manual start or newer request received meanwhile.
func (a *Agent) observeDebugCollection(coll *DebugCollectionMeta, epoch uint64) error {
	a.debugMu.Lock()
	defer a.debugMu.Unlock()
	if err := a.loadDebugLocked(); err != nil {
		return err
	}
	if epoch != a.debugEpoch {
		return nil
	}
	next := a.debugState
	uuid := ""
	if coll != nil {
		uuid = strings.ToLower(strings.TrimSpace(coll.UUID))
	}
	active := coll != nil && strings.EqualFold(strings.TrimSpace(coll.Status), "active") && validDebugUUID(uuid)
	if !active || !coll.SupportsUploadID {
		// Named inactive metadata for an older collection is not a revocation
		// of a newer one. Nil metadata revokes only the observed generation.
		if uuid != "" && uuid != next.Requested {
			return nil
		}
		if next.Requested == "" {
			return nil
		}
		next.Requested, next.Reference, next.Pending = "", "", nil
	} else if next.completed(uuid) {
		return nil
	} else if next.Requested == uuid {
		// A successful authenticated pull permits a paused upload to resume,
		// but does not bypass its persisted cooldown.
		if next.Pending == nil || !next.Pending.AuthPaused {
			return nil
		}
		p := *next.Pending
		p.AuthPaused = false
		next.Pending = &p
	} else {
		next.Requested, next.Reference, next.Pending = uuid, uuid[:8], nil
	}
	if err := a.saveDebugLocked(next); err != nil {
		return err
	}
	a.debugEpoch++
	return nil
}

func (a *Agent) acquireDebug(ctx context.Context) error {
	select {
	case a.debugGate <- struct{}{}:
		if err := ctx.Err(); err != nil {
			<-a.debugGate
			return err
		}
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

// prepareDebug snapshots logs exactly once. Network work is outside debugMu,
// with a generation check before committing the immutable bundle.
func (a *Agent) prepareDebug(ctx context.Context, uuid string) (*debugUpload, error) {
	a.debugMu.Lock()
	if err := a.loadDebugLocked(); err != nil {
		a.debugMu.Unlock()
		return nil, err
	}
	s := a.debugState
	if s.Requested != uuid || s.completed(uuid) {
		a.debugMu.Unlock()
		return nil, errDebugSuperseded
	}
	if s.Pending != nil {
		p := *s.Pending
		a.debugMu.Unlock()
		return &p, nil
	}
	epoch := a.debugEpoch
	a.debugMu.Unlock()
	bundle := a.buildDebugLogBundle(ctx)
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	id, err := newDebugUploadID()
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(bundle)
	p := &debugUpload{Collection: uuid, UploadID: id, Bundle: bundle, Digest: hex.EncodeToString(sum[:]), ReferenceCode: s.Reference}
	a.debugMu.Lock()
	defer a.debugMu.Unlock()
	if err := a.loadDebugLocked(); err != nil {
		return nil, err
	}
	if epoch != a.debugEpoch || a.debugState.Requested != uuid {
		return nil, errDebugSuperseded
	}
	next := a.debugState
	next.Pending = p
	if err := a.saveDebugLocked(next); err != nil {
		return nil, err
	}
	return p, nil
}

type debugErrorAction int

const (
	debugRetry debugErrorAction = iota
	debugTerminal
	debugPause
)

func classifyDebugError(err error) debugErrorAction {
	var pe *panelError
	if !errors.As(err, &pe) {
		return debugRetry
	}
	switch pe.Status {
	case http.StatusUnauthorized, http.StatusForbidden:
		return debugPause
	case http.StatusGone, http.StatusRequestEntityTooLarge, http.StatusUnprocessableEntity:
		return debugTerminal
	case http.StatusNotFound:
		if pe.Code == "debug_collection_not_found" {
			return debugTerminal
		}
	case http.StatusConflict:
		if pe.Code == "debug_chunk_replay" || pe.Code == "debug_upload_replay" {
			return debugTerminal
		}
	}
	return debugRetry // Includes 408/429, unknown refusals, transport and 5xx.
}

func debugRetryDelay(failures int) time.Duration {
	return time.Duration(1<<uint(clampInt(failures-1, 0, 4))) * 30 * time.Second
}

func (a *Agent) sameDebugLocked(p *debugUpload) bool {
	current := a.debugState.Pending
	return current != nil && current.Collection == p.Collection && current.UploadID == p.UploadID
}

func (a *Agent) finishDebugLocked(p *debugUpload) error {
	next := a.debugState
	done := make([]debugReceipt, 0, len(next.Done)+1)
	for _, receipt := range next.Done {
		if time.Now().Before(receipt.Until) {
			done = append(done, receipt)
		}
	}
	if len(done) >= debugMaxReceipts {
		a.debugErr = errors.New("debug upload receipt capacity exceeded")
		return a.debugErr
	}
	next.Done = append(done, debugReceipt{Collection: p.Collection, Until: time.Now().Add(24 * time.Hour)})
	next.Requested, next.Reference, next.Pending = "", "", nil
	if err := a.saveDebugLocked(next); err != nil {
		return err
	}
	a.debugEpoch++
	return nil
}

// uploadPendingDebug runs under the manual/passive gate. After each ACK the
// cursor is committed before the next POST. If an ACK or its state write is
// lost, restart replays precisely the same ID, sequence, hash and base64 bytes.
func (a *Agent) uploadPendingDebug(ctx context.Context, uuid string) (map[string]any, error) {
	p, err := a.prepareDebug(ctx, uuid)
	if err != nil {
		return nil, err
	}
	if p.AuthPaused || time.Now().Before(p.RetryAt) {
		return nil, errDebugDeferred
	}
	chunks := (len(p.Bundle) + debugLogChunkBytes - 1) / debugLogChunkBytes
	for {
		if err := ctx.Err(); err != nil {
			return nil, err // Cancellation never completes or drops pending work.
		}
		a.debugMu.Lock()
		client, panelBinding := a.panel.debugClientSnapshot()
		if stateErr := a.loadDebugLocked(); stateErr != nil {
			err = stateErr
		} else if !a.sameDebugLocked(p) || a.debugState.Binding != a.debugBinding(panelBinding) {
			err = errDebugSuperseded
		} else if p.Sequence == chunks {
			err = a.finishDebugLocked(p)
			a.debugMu.Unlock()
			if err != nil {
				return nil, err
			}
			return map[string]any{"reference_code": p.ReferenceCode, "uuid": p.Collection, "upload_id": p.UploadID, "chunks": chunks, "bytes": len(p.Bundle)}, nil
		}
		a.debugMu.Unlock()
		if err != nil {
			return nil, err
		}
		offset := p.Sequence * debugLogChunkBytes
		end := offset + debugLogChunkBytes
		if end > len(p.Bundle) {
			end = len(p.Bundle)
		}
		part := p.Bundle[offset:end]
		sum := sha256.Sum256(part)
		err = client.UploadDebugLogChunk(ctx, DebugLogChunkRequest{
			CollectionUUID: p.Collection, UploadID: p.UploadID, Sequence: p.Sequence,
			SHA256: hex.EncodeToString(sum[:]), ChunkBase64: base64.StdEncoding.EncodeToString(part), Source: "leikwand.log",
		})
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		a.debugMu.Lock()
		if stateErr := a.loadDebugLocked(); stateErr != nil {
			a.debugMu.Unlock()
			return nil, stateErr
		}
		if !a.sameDebugLocked(p) {
			a.debugMu.Unlock()
			return nil, errDebugSuperseded
		}
		if err != nil {
			next := a.debugState
			updated := *p
			switch classifyDebugError(err) {
			case debugTerminal:
				stateErr := a.finishDebugLocked(p)
				a.debugMu.Unlock()
				return nil, errors.Join(err, stateErr)
			case debugPause:
				updated.AuthPaused = true
				updated.RetryAt = time.Now().Add(5 * time.Minute)
			default:
				updated.Failures = clampInt(updated.Failures+1, 1, 16)
				updated.RetryAt = time.Now().Add(debugRetryDelay(updated.Failures))
			}
			next.Pending = &updated
			stateErr := a.saveDebugLocked(next)
			a.debugMu.Unlock()
			return nil, errors.Join(err, stateErr)
		}
		updated := *p
		updated.Sequence++
		updated.Failures, updated.RetryAt, updated.AuthPaused = 0, time.Time{}, false
		next := a.debugState
		next.Pending = &updated
		err = a.saveDebugLocked(next)
		a.debugMu.Unlock()
		if err != nil {
			return nil, err
		}
		p = &updated
	}
}

func (a *Agent) submitDebugLogs(ctx context.Context, uuid string) {
	ctx, cancel := context.WithTimeout(ctx, debugAttemptLimit)
	defer cancel()
	if err := a.acquireDebug(ctx); err != nil {
		return
	}
	defer func() { <-a.debugGate }()
	if _, err := a.uploadPendingDebug(ctx, uuid); err != nil && !errors.Is(err, errDebugDeferred) && !errors.Is(err, errDebugSuperseded) {
		a.log.Warn("diagnostics", "debug log upload failed for %s: %v", shortRevision(uuid), err)
	}
}

// Independent of heartbeat budgets. There is one worker, not one goroutine per
// heartbeat, and both gate waits and HTTP work have a bounded cancellable budget.
func (a *Agent) debugUploadLoop(ctx context.Context) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		if !a.enrolled() {
			continue
		}
		a.debugMu.Lock()
		err := a.loadDebugLocked()
		uuid := a.debugState.Requested
		a.debugMu.Unlock()
		if err == nil && uuid != "" {
			a.submitDebugLogs(ctx, uuid)
		}
	}
}

// Manual requests resume an existing upload (including after restart), rather
// than silently opening a new collection on every click or transport retry.
func (a *Agent) uploadLogsNow(ctx context.Context) (map[string]any, error) {
	ctx, cancel := context.WithTimeout(ctx, debugAttemptLimit)
	defer cancel()
	if err := a.acquireDebug(ctx); err != nil {
		return nil, err
	}
	defer func() { <-a.debugGate }()
	a.debugMu.Lock()
	if err := a.loadDebugLocked(); err != nil {
		a.debugMu.Unlock()
		return nil, err
	}
	uuid := a.debugState.Requested
	if uuid != "" {
		a.debugMu.Unlock()
		return a.uploadPendingDebug(ctx, uuid)
	}
	// Invalidate pulls started before or during this manual start.
	a.debugEpoch++
	epoch := a.debugEpoch
	client, panelBinding := a.panel.debugClientSnapshot()
	binding := a.debugBinding(panelBinding)
	a.debugMu.Unlock()
	started, err := client.StartDebugCollection(ctx)
	if err != nil {
		return nil, err
	}
	coll := started.DebugCollection
	uuid = strings.ToLower(strings.TrimSpace(coll.UUID))
	if !coll.SupportsUploadID {
		return nil, errDebugUnsupported
	}
	if !validDebugUUID(uuid) || !strings.EqualFold(strings.TrimSpace(coll.Status), "active") {
		return nil, errors.New("debug_collection_invalid")
	}
	a.debugMu.Lock()
	if err := a.loadDebugLocked(); err != nil {
		a.debugMu.Unlock()
		return nil, err
	}
	if epoch != a.debugEpoch || a.debugState.Binding != binding {
		a.debugMu.Unlock()
		return nil, errDebugSuperseded
	}
	next := a.debugState
	next.Requested, next.Reference = uuid, started.ReferenceCode
	err = a.saveDebugLocked(next)
	a.debugEpoch++
	a.debugMu.Unlock()
	if err != nil {
		return nil, err
	}
	return a.uploadPendingDebug(ctx, uuid)
}
