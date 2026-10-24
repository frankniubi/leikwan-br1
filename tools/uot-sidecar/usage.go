package main

// Usage file writer.
//
// The panel bills external UoT UDP by max(client-reported, sidecar-measured)
// per forward per day. The sidecar side of that is this file: cumulative
// counters per forward for the life of one process ("instance"), rewritten
// every usageWriteInterval and once more on clean shutdown. The panel diffs
// successive documents of the same instance; a new instance id tells it the
// counters restarted from zero. Format and semantics are fixed by C4 of
// docs/plans/2026-10-21-external-uot-sidecar-enforcement.md.
//
// The file is always replaced atomically (temp file in the same directory,
// fsync, rename) so a reader such as the SSH "usage" forced command never sees
// a torn document. A write failure is a WARNING log line and nothing else: the
// relay must keep running even when metering storage is unavailable.

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"time"
)

// usageWriteInterval is how often the usage file is rewritten.
const usageWriteInterval = 10 * time.Second

// usageDocument is the on-disk usage file. Field order matches the contract.
type usageDocument struct {
	Version   int                     `json:"version"`
	Instance  string                  `json:"instance"`
	StartedAt string                  `json:"started_at"`
	WrittenAt string                  `json:"written_at"`
	Forwards  map[string]forwardUsage `json:"forwards"`
}

type usageWriter struct {
	path      string
	instance  string
	startedAt time.Time
	meters    *meterRegistry
	logger    *sidecarLogger
	now       func() time.Time

	// lastError suppresses repeating the same failure every interval. Only the
	// run goroutine touches it.
	lastError string
}

func newUsageWriter(path string, meters *meterRegistry, logger *sidecarLogger) (*usageWriter, error) {
	instance, err := newInstanceID()
	if err != nil {
		return nil, err
	}
	return &usageWriter{
		path:      path,
		instance:  instance,
		startedAt: time.Now().UTC(),
		meters:    meters,
		logger:    logger,
		now:       time.Now,
	}, nil
}

// newInstanceID returns 32 lowercase hex characters of fresh randomness.
func newInstanceID() (string, error) {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		return "", fmt.Errorf("usage instance id: %w", err)
	}
	return hex.EncodeToString(raw), nil
}

// document renders the current counters.
func (w *usageWriter) document() usageDocument {
	usage := w.meters.usage()
	forwards := make(map[string]forwardUsage, len(usage))
	for forwardID, counters := range usage {
		forwards[strconv.FormatInt(forwardID, 10)] = counters
	}
	return usageDocument{
		Version:   1,
		Instance:  w.instance,
		StartedAt: w.startedAt.UTC().Format(time.RFC3339),
		WrittenAt: w.now().UTC().Format(time.RFC3339),
		Forwards:  forwards,
	}
}

// write renders and atomically replaces the usage file once.
func (w *usageWriter) write() error {
	encoded, err := json.Marshal(w.document())
	if err != nil {
		return err
	}
	return writeFileAtomic(w.path, append(encoded, '\n'))
}

// writeAndLog writes once and reports a failure at WARNING, once per distinct
// error, plus a single INFO line when writing recovers.
func (w *usageWriter) writeAndLog() {
	err := w.write()
	if err != nil {
		if err.Error() != w.lastError {
			w.lastError = err.Error()
			w.logger.warnf("external UoT usage file write failed path=%s: %v", w.path, err)
		}
		return
	}
	if w.lastError != "" {
		w.lastError = ""
		w.logger.infof("external UoT usage file write recovered path=%s", w.path)
	}
}

// run writes immediately, then every interval, and one final time when stop is
// closed. It returns only after that final write, so a caller that closes stop
// after the server has shut down can wait for run to return and know the file
// holds the final counters.
func (w *usageWriter) run(stop <-chan struct{}, interval time.Duration) {
	if interval <= 0 {
		interval = usageWriteInterval
	}
	w.writeAndLog()
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-stop:
			w.writeAndLog()
			return
		case <-ticker.C:
			w.writeAndLog()
		}
	}
}

// writeFileAtomic replaces path with data: the bytes go to a temp file in the
// same directory, are fsynced, get mode 0600 and are then renamed over the
// target, so readers see either the old document or the new one. A missing
// parent directory is created with mode 0700.
func writeFileAtomic(path string, data []byte) (err error) {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	temp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	tempPath := temp.Name()
	defer func() {
		if err != nil {
			_ = temp.Close()
			_ = os.Remove(tempPath)
		}
	}()
	if err = os.Chmod(tempPath, 0o600); err != nil {
		return err
	}
	if _, err = temp.Write(data); err != nil {
		return err
	}
	if err = temp.Sync(); err != nil {
		return err
	}
	if err = temp.Close(); err != nil {
		return err
	}
	if err = os.Rename(tempPath, path); err != nil {
		return err
	}
	syncDirectory(dir)
	return nil
}

// syncDirectory makes the rename durable where the platform supports it. It is
// best effort: Windows cannot open a directory for syncing, and the rename has
// already happened either way.
func syncDirectory(dir string) {
	handle, err := os.Open(dir)
	if err != nil {
		return
	}
	_ = handle.Sync()
	_ = handle.Close()
}
