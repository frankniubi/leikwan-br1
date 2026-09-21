package main

// Configuration reload.
//
// The sidecar used to read its configuration exactly once at startup, so adding
// a forward binding meant restarting the process and tearing down every live
// session. This file adds a safe reload: the whole file is re-read and
// re-validated, and only then published as one immutable snapshot behind an
// atomic pointer.
//
// Two properties matter and are deliberately kept simple:
//
//   - All-or-nothing. A rejected reload changes nothing at all: the previous
//     snapshot keeps serving every request. There is no partial application and
//     no half-applied state.
//   - Readers never lock. Every request loads the pointer once and keeps that
//     snapshot for its lifetime, so a swap cannot be observed half-way through
//     a HELLO, and a session admitted under a retired binding keeps working.
//
// Both triggers are local only: SIGHUP on Unix and a marker file that a local
// user has to create. Nothing here opens a socket, so an unauthenticated remote
// party cannot trigger a reload.

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"sort"
	"sync"
	"sync/atomic"
	"time"
)

// reloadMarkerPollInterval is how often the local reload marker file is
// checked. One second keeps reloads prompt without a busy loop.
const reloadMarkerPollInterval = time.Second

// configStore owns the currently published configuration.
type configStore struct {
	path string

	// current points at an immutable sidecarConfig. parseConfig builds a fresh
	// value with fresh maps for every load and nothing ever writes through a
	// published pointer, so a reader sees either the whole previous
	// configuration or the whole new one, never a mix.
	current atomic.Pointer[sidecarConfig]

	// mu serialises reloads: two triggers racing (SIGHUP and the marker file)
	// must not validate against the same predecessor and publish out of order.
	// Readers never take it.
	mu sync.Mutex
}

func newConfigStore(path string, initial *sidecarConfig) *configStore {
	store := &configStore{path: path}
	store.current.Store(initial)
	return store
}

// snapshot returns the configuration a request must use. Call it once per
// request: a second call could already observe a different snapshot.
func (s *configStore) snapshot() *sidecarConfig {
	return s.current.Load()
}

// reload re-reads the configuration file, validates it completely and publishes
// it atomically. On any failure the previous snapshot stays published and the
// returned error states exactly what was rejected.
func (s *configStore) reload() (*sidecarConfig, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	candidate, err := loadSnapshot(s.path)
	if err != nil {
		return nil, err
	}
	if previous := s.current.Load(); previous != nil {
		if err := checkReloadable(previous, candidate); err != nil {
			return nil, err
		}
	}
	s.current.Store(candidate)
	return candidate, nil
}

// checkReloadable rejects a candidate that cannot be applied to a process that
// is already listening. Moving the listen address would need a new listener,
// and keeping the old socket while the snapshot claims the new address is
// exactly the half-applied state a reload must never produce.
func checkReloadable(previous *sidecarConfig, candidate *sidecarConfig) error {
	if previous.listenHost != candidate.listenHost || previous.listenPort != candidate.listenPort {
		return fmt.Errorf("listen address %s:%d -> %s:%d needs a restart",
			previous.listenHost, previous.listenPort, candidate.listenHost, candidate.listenPort)
	}
	return nil
}

// applyReload performs one reload triggered by a signal or the marker file and
// reports the outcome: a rejection is a log line, never a state change.
func applyReload(store *configStore, logger *sidecarLogger) {
	previous := store.snapshot()
	next, err := store.reload()
	if err != nil {
		logger.warnf("external UoT configuration reload rejected path=%s reason=%v previous_snapshot=still-serving",
			store.path, err)
		return
	}
	logger.infof("external UoT configuration reloaded path=%s %s", store.path, describeForwardChange(previous, next))
}

// describeForwardChange summarises what a reload did without ever printing a
// secret or a token.
func describeForwardChange(previous *sidecarConfig, next *sidecarConfig) string {
	added := make([]int64, 0)
	removed := make([]int64, 0)
	credentials := make([]int64, 0)
	for forwardID, nextBinding := range next.forwards {
		previousBinding, present := previous.forwards[forwardID]
		if !present {
			added = append(added, forwardID)
			continue
		}
		if !bytes.Equal(previousBinding.secret, nextBinding.secret) || previousBinding.staticToken != nextBinding.staticToken {
			credentials = append(credentials, forwardID)
		}
	}
	for forwardID := range previous.forwards {
		if _, present := next.forwards[forwardID]; !present {
			removed = append(removed, forwardID)
		}
	}
	return fmt.Sprintf("forwards=%d->%d added=%v removed=%v credentials_changed=%v",
		len(previous.forwards), len(next.forwards), sortedForwardIDs(added), sortedForwardIDs(removed), sortedForwardIDs(credentials))
}

func sortedForwardIDs(ids []int64) []int64 {
	sort.Slice(ids, func(left int, right int) bool { return ids[left] < ids[right] })
	return ids
}

// runReloadMarkerLoop watches a local marker file and runs reload once per
// trigger. Windows has no SIGHUP, so this is the trigger that keeps reload
// operable and testable there; it is available on Unix as well.
//
// Security: the loop opens no socket and reads nothing from the network. The
// marker carries no data - the configuration is always read from the file the
// process was started with - and creating it requires write access to the
// directory that already holds the configuration and its secrets. An
// unauthenticated remote party therefore has no way to reach this path.
//
// The marker is removed before the reload runs, so a later reload needs a fresh
// file. If it cannot be removed (for example a read-only directory), the loop
// falls back to the file's modification time and size so it still fires once
// per change instead of once per tick.
func runReloadMarkerLoop(stop <-chan struct{}, markerPath string, interval time.Duration, logger *sidecarLogger, reload func()) {
	if markerPath == "" || reload == nil {
		return
	}
	if interval <= 0 {
		interval = reloadMarkerPollInterval
	}
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	lastStamp := ""
	lastError := ""
	for {
		select {
		case <-stop:
			return
		case <-ticker.C:
		}
		info, err := os.Stat(markerPath)
		if err != nil {
			if !errors.Is(err, os.ErrNotExist) && err.Error() != lastError {
				lastError = err.Error()
				logger.warnf("external UoT reload marker unavailable path=%s: %v", markerPath, err)
			}
			continue
		}
		lastError = ""
		if info.IsDir() {
			continue
		}
		stamp := fmt.Sprintf("%d:%d", info.ModTime().UnixNano(), info.Size())
		if stamp == lastStamp {
			continue
		}
		if removeErr := os.Remove(markerPath); removeErr != nil {
			lastStamp = stamp
			logger.warnf("external UoT reload marker not removable path=%s, reloading once per change: %v", markerPath, removeErr)
		} else {
			lastStamp = ""
		}
		logger.infof("external UoT reload marker observed path=%s", markerPath)
		reload()
	}
}
