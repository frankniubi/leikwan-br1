package main

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"strings"
	"sync"
	"time"
)

// Runtime update policy on OpenWrt.
//
// The router has exactly one update authority: opkg. The panel can express
// *what* it expects the runtime to be, and the agent reports whether the
// installed file matches. It never downloads and replaces the runtime itself,
// because a private updater racing opkg is how a box ends up with a binary
// nobody can attribute.
//
// Drift states surfaced to LuCI and the panel:
//
//	""            - panel publishes no expectation, or the check is disabled
//	"ok"          - installed file hash matches the panel's expected hash
//	"update"      - the panel expects a different runtime
//	"missing"     - the configured runtime binary is not installed
type runtimeIdentity struct {
	version string
	sha256  string
	drift   string

	mu           sync.Mutex
	filePath     string
	modTime      time.Time
	size         int64
	lastReported string
}

var runtimeInfo runtimeIdentity

// runtimeVersionString asks the runtime for its build version. It is best
// effort: a runtime that cannot report a version is still usable.
func runtimeVersionString(path string) string {
	if !fileExists(path) {
		return ""
	}
	out, err := runCommand(3*time.Second, path, "--version")
	if err != nil {
		return ""
	}
	return strings.TrimSpace(strings.Split(strings.TrimSpace(out), "\n")[0])
}

// runtimeFileSha256 hashes the runtime binary, caching by (path, mtime, size)
// so the 6 MiB read happens once per installed version, not once per status
// refresh.
func runtimeFileSha256(path string) (string, error) {
	info, err := os.Stat(path)
	if err != nil {
		return "", err
	}
	runtimeInfo.mu.Lock()
	if runtimeInfo.filePath == path && runtimeInfo.modTime.Equal(info.ModTime()) && runtimeInfo.size == info.Size() {
		cached := runtimeInfo.sha256
		runtimeInfo.mu.Unlock()
		return cached, nil
	}
	runtimeInfo.mu.Unlock()

	file, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer file.Close()
	digest := sha256.New()
	if _, err := io.Copy(digest, file); err != nil {
		return "", err
	}
	sum := hex.EncodeToString(digest.Sum(nil))

	runtimeInfo.mu.Lock()
	runtimeInfo.filePath = path
	runtimeInfo.modTime = info.ModTime()
	runtimeInfo.size = info.Size()
	runtimeInfo.sha256 = sum
	runtimeInfo.mu.Unlock()
	return sum, nil
}

// evaluateRuntimeDrift compares the installed runtime against the panel's
// expectation. It is deliberately quiet on every unable-to-check path: a
// router with a working runtime must never look unhealthy because the panel
// has not published a hash.
func (a *Agent) evaluateRuntimeDrift() {
	if !a.cfg.AutoUpdate {
		return
	}
	a.mu.Lock()
	desired := strings.ToLower(strings.TrimSpace(a.clientMeta.DesiredUltraSHA))
	a.mu.Unlock()

	if !fileExists(a.cfg.RuntimeBin) {
		runtimeInfo.version = ""
		runtimeInfo.sha256 = ""
		runtimeInfo.drift = "missing"
		return
	}
	runtimeInfo.version = runtimeVersionString(a.cfg.RuntimeBin)
	actual, err := runtimeFileSha256(a.cfg.RuntimeBin)
	if err != nil {
		runtimeInfo.drift = ""
		return
	}
	runtimeInfo.sha256 = actual
	if !isHex64(desired) {
		// The panel does not publish an OpenWrt runtime artifact yet, so there
		// is nothing honest to compare against.
		runtimeInfo.drift = ""
		return
	}
	if strings.EqualFold(actual, desired) {
		runtimeInfo.drift = "ok"
		return
	}
	runtimeInfo.drift = "update"
	// One line per transition, not per poll.
	if runtimeInfo.lastReported != "update" {
		a.log.Warn("runtime", "installed runtime does not match the panel's expected build; run 'opkg update && opkg upgrade leikwan-runtime' on the router")
	}
	runtimeInfo.lastReported = "update"
}

// releaseBuild reports whether this binary was built as a release.
func releaseBuild() bool { return agentVersion != "development" }
