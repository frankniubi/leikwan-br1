package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
	"sync"
	"time"
)

// Agent self-update policy on OpenWrt.
//
// Like the Linux client-agent, the daemon upgrades itself when the console
// requests it (agent_update_request_token pending) and the pull response
// names a different agent version. It never upgrades on its own schedule:
// "desired" fields are empty unless an update was requested from the console,
// which keeps the no-forced-updates rule intact.
//
// The routers in the field installed leikwand from a URL, not from an opkg
// feed, so "opkg install leikwand" has nothing to resolve. The pull therefore
// names the ipk (desired_agent_url, Panel-relative) and its sha256; the daemon
// downloads it to tmpfs, verifies the hash, and hands the local file to opkg.
// Only when the Panel names no file does it fall back to the feed.
//
// opkg runs in its own session with output to a file, never on a pipe owned by
// this process: the ipk postinst restarts leikwand, and an opkg still writing
// to a dead parent's pipe would die of SIGPIPE half way through configuring.

const (
	// selfUpdateCooldown guards against download/feed flaps: one attempt per
	// 30 minutes.
	selfUpdateCooldown = 30 * time.Minute
	// selfUpdateMaxBytes caps the ipk download; leikwand ipks are ~3 MiB.
	selfUpdateMaxBytes = 32 << 20
)

// Paths live in tmpfs so a failed update leaves nothing on flash.
var (
	selfUpdatePackagePath = "/tmp/leikwand-update.ipk"
	selfUpdateLogPath     = "/tmp/leikwand-update.log"
)

type selfUpdateState struct {
	mu           sync.Mutex
	lastAttempt  time.Time
	lastResult   string // "" | "started" | "failed: <reason>"
	lastAttemptV string // version we tried to reach
	inProgress   bool
}

var selfUpdate selfUpdateState

// selfUpdateTarget is what the console asked for.
type selfUpdateTarget struct {
	version string
	sha256  string
	url     string
}

// agentUpdateNeeded reports whether the console has requested an update that
// this binary has not applied yet. Mirrors the Linux client-agent contract:
// the pull only carries non-empty desired_agent_version while a
// console-requested update is pending
// (ClientAgentController::desiredClientArtifacts).
func (a *Agent) agentUpdateNeeded() (string, bool) {
	target, needed := a.agentUpdateTarget()
	return target.version, needed
}

func (a *Agent) agentUpdateTarget() (selfUpdateTarget, bool) {
	a.mu.Lock()
	target := selfUpdateTarget{
		version: strings.TrimSpace(a.clientMeta.DesiredAgentVer),
		sha256:  strings.ToLower(strings.TrimSpace(a.clientMeta.DesiredAgentSHA)),
		url:     strings.TrimSpace(a.clientMeta.DesiredAgentURL),
	}
	a.mu.Unlock()
	if target.version == "" || target.version == agentVersion {
		return selfUpdateTarget{}, false
	}
	return target, true
}

// maybeSelfUpdate upgrades the leikwand ipk when the console asked for it.
// Called from the heartbeat loop so the cadence follows the panel, and so a
// busy router is never interrupted mid-write by a second attempt.
func (a *Agent) maybeSelfUpdate(ctx context.Context) {
	if !a.cfg.AutoUpdate {
		return
	}
	target, needed := a.agentUpdateTarget()
	if !needed {
		return
	}

	selfUpdate.mu.Lock()
	if selfUpdate.inProgress || time.Since(selfUpdate.lastAttempt) < selfUpdateCooldown {
		selfUpdate.mu.Unlock()
		return
	}
	selfUpdate.inProgress = true
	selfUpdate.lastAttempt = time.Now()
	selfUpdate.lastAttemptV = target.version
	selfUpdate.mu.Unlock()

	a.log.Info("update", "console requested agent %s (running %s); updating", target.version, agentVersion)
	cmd, err := a.startSelfUpdate(ctx, target)
	if err != nil {
		a.finishSelfUpdate("failed: " + err.Error())
		a.log.Warn("update", "self-update failed: %v (retry after cooldown)", err)
		return
	}
	a.finishSelfUpdate("started")
	a.log.Info("update", "opkg started (log %s); procd restarts the daemon into %s", selfUpdateLogPath, target.version)

	// Reap opkg. On success the postinst restart ends this process first, so
	// reaching the end of Wait means the install did not take.
	go func() {
		waitErr := cmd.Wait()
		tail := logTail(selfUpdateLogPath, 512)
		if waitErr != nil {
			a.finishSelfUpdate("failed: opkg: " + waitErr.Error())
			a.log.Warn("update", "opkg install failed: %v: %s", waitErr, tail)
			return
		}
		a.log.Warn("update", "opkg finished but the daemon was not restarted: %s", tail)
	}()
}

func (a *Agent) finishSelfUpdate(result string) {
	selfUpdate.mu.Lock()
	selfUpdate.inProgress = false
	selfUpdate.lastResult = result
	selfUpdate.mu.Unlock()
}

// startSelfUpdate stages the package and launches opkg detached.
func (a *Agent) startSelfUpdate(ctx context.Context, target selfUpdateTarget) (*exec.Cmd, error) {
	if _, err := exec.LookPath("opkg"); err != nil {
		return nil, fmt.Errorf("opkg not available: %w", err)
	}
	if target.url == "" {
		// Older Panel: no file named, so only a configured feed can help.
		return startDetachedOpkg(`opkg update && opkg install --force-reinstall leikwand`)
	}
	if !isHex64(target.sha256) {
		return nil, errors.New("panel named an update package without a sha256")
	}
	if err := a.downloadVerified(ctx, target.url, target.sha256, selfUpdatePackagePath); err != nil {
		return nil, err
	}
	return startDetachedOpkg(`opkg install --force-reinstall "$1"; status=$?; rm -f "$1"; exit $status`, selfUpdatePackagePath)
}

// downloadVerified writes the artifact to dst only if its sha256 matches.
func (a *Agent) downloadVerified(ctx context.Context, url, wantSHA, dst string) error {
	dlCtx, cancel := context.WithTimeout(ctx, downloadTimeout)
	defer cancel()
	partial := dst + ".part"
	file, err := os.OpenFile(partial, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	digest := sha256.New()
	_, dlErr := a.panel.Download(dlCtx, url, selfUpdateMaxBytes, io.MultiWriter(file, digest))
	closeErr := file.Close()
	if dlErr == nil {
		dlErr = closeErr
	}
	if dlErr != nil {
		os.Remove(partial)
		return fmt.Errorf("download %s: %w", url, dlErr)
	}
	if got := hex.EncodeToString(digest.Sum(nil)); got != wantSHA {
		os.Remove(partial)
		return fmt.Errorf("download %s: sha256 %s does not match the panel's %s", url, got, wantSHA)
	}
	return os.Rename(partial, dst)
}

// startDetachedOpkg runs script under /bin/sh in its own session with stdio on
// files, so the postinst restarting leikwand cannot take opkg down with it.
func startDetachedOpkg(script string, args ...string) (*exec.Cmd, error) {
	logFile, err := os.OpenFile(selfUpdateLogPath, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return nil, err
	}
	defer logFile.Close()
	cmd := exec.Command("/bin/sh", append([]string{"-c", script, "sh"}, args...)...)
	cmd.Stdout = logFile
	cmd.Stderr = logFile
	cmd.SysProcAttr = detachedProcAttr()
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("start opkg: %w", err)
	}
	return cmd, nil
}

// logTail returns the last n bytes of path, flattened to one line.
func logTail(path string, n int64) string {
	file, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer file.Close()
	if info, err := file.Stat(); err == nil && info.Size() > n {
		_, _ = file.Seek(info.Size()-n, io.SeekStart)
	}
	data, _ := io.ReadAll(io.LimitReader(file, n))
	return strings.Join(strings.Fields(string(data)), " ")
}
