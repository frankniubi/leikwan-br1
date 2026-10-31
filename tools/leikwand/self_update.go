package main

import (
	"context"
	"fmt"
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
// The mechanics are opkg's, not ours: the daemon shells out to
//   opkg update && opkg install --force-reinstall leikwand
// and lets procd restart it (the ipk postinst already restarts the service).
// A failed run just logs; the next heartbeat retries after cooldown. The
// panel artifact base is a feed on OpenWrt builds (customfeeds.conf), so
// "opkg update" picks up a new ipk without any HTTP code living here.

const (
	// selfUpdateCooldown guards against opkg feed flaps: one attempt per
	// 30 minutes.
	selfUpdateCooldown = 30 * time.Minute
	// selfUpdateTimeout bounds one opkg invocation.
	selfUpdateTimeout = 10 * time.Minute
)

type selfUpdateState struct {
	mu           sync.Mutex
	lastAttempt  time.Time
	lastResult   string // "" | "ok" | "failed: <reason>"
	lastAttemptV string // version we tried to reach
	inProgress   bool
}

var selfUpdate selfUpdateState

// agentUpdateNeeded reports whether the console has requested an update that
// this binary has not applied yet. Mirrors the Linux client-agent contract:
// the pull only carries non-empty desired_agent_version while a
// console-requested update is pending
// (ClientAgentController::desiredClientArtifacts).
func (a *Agent) agentUpdateNeeded() (string, bool) {
	a.mu.Lock()
	desiredVer := strings.TrimSpace(a.clientMeta.DesiredAgentVer)
	a.mu.Unlock()
	if desiredVer == "" || desiredVer == agentVersion {
		return "", false
	}
	return desiredVer, true
}

// maybeSelfUpdate upgrades the leikwand ipk via opkg when the console asked
// for it. Called from the heartbeat loop so the cadence follows the panel,
// and so a busy router is never interrupted mid-write by a second attempt.
func (a *Agent) maybeSelfUpdate(ctx context.Context) {
	if !a.cfg.AutoUpdate {
		return
	}
	desired, needed := a.agentUpdateNeeded()
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
	selfUpdate.lastAttemptV = desired
	selfUpdate.mu.Unlock()

	defer func() {
		selfUpdate.mu.Lock()
		selfUpdate.inProgress = false
		selfUpdate.mu.Unlock()
	}()

	a.log.Info("update", "console requested agent %s (running %s); updating via opkg", desired, agentVersion)
	if err := runSelfUpdate(ctx); err != nil {
		selfUpdate.mu.Lock()
		selfUpdate.lastResult = "failed: " + err.Error()
		selfUpdate.mu.Unlock()
		a.log.Warn("update", "self-update failed: %v (retry after cooldown)", err)
		return
	}
	selfUpdate.mu.Lock()
	selfUpdate.lastResult = "ok"
	selfUpdate.mu.Unlock()
	a.log.Info("update", "opkg finished; procd restarts the daemon into %s", desired)
}

// runSelfUpdate shells out to opkg. --force-reinstall covers the
// same-version-rerelease case (like the 1.2.0-r1 nonce fix that shipped under
// an unchanged filename), though versioned releases are the norm now.
func runSelfUpdate(ctx context.Context) error {
	if _, err := runCommand(selfUpdateTimeout, "opkg", "update"); err != nil {
		return fmt.Errorf("opkg update: %w", err)
	}
	if _, err := runCommand(selfUpdateTimeout, "opkg", "install", "--force-reinstall", "leikwand"); err != nil {
		return fmt.Errorf("opkg install leikwand: %w", err)
	}
	return nil
}
