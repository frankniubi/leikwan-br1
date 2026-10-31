package main

import (
	"context"
	"strings"
	"testing"
	"time"
)

func TestAgentUpdateNeeded(t *testing.T) {
	a := &Agent{}
	a.cfg = Config{AutoUpdate: true}

	// No desired version: nothing to do.
	a.clientMeta = ClientMeta{DesiredAgentVer: ""}
	if _, needed := a.agentUpdateNeeded(); needed {
		t.Fatal("empty desired version must not request an update")
	}

	// Same version: nothing to do.
	a.clientMeta = ClientMeta{DesiredAgentVer: agentVersion}
	if _, needed := a.agentUpdateNeeded(); needed {
		t.Fatal("matching desired version must not request an update")
	}

	// Different version: update requested.
	a.clientMeta = ClientMeta{DesiredAgentVer: "9.9.9"}
	if got, needed := a.agentUpdateNeeded(); !needed || got != "9.9.9" {
		t.Fatalf("agentUpdateNeeded = %q, %v; want 9.9.9, true", got, needed)
	}
}

func TestMaybeSelfUpdateRespectsCooldown(t *testing.T) {
	a := &Agent{}
	a.cfg = Config{AutoUpdate: true}
	a.clientMeta = ClientMeta{DesiredAgentVer: "9.9.9"}

	// AutoUpdate off: never runs.
	off := &Agent{}
	off.cfg = Config{AutoUpdate: false}
	off.clientMeta = ClientMeta{DesiredAgentVer: "9.9.9"}
	offMaybe := func() bool {
		off.maybeSelfUpdate(context.Background())
		selfUpdate.mu.Lock()
		defer selfUpdate.mu.Unlock()
		return selfUpdate.lastAttempt.IsZero()
	}
	if !offMaybe() {
		t.Fatal("AutoUpdate=false must not record an update attempt")
	}

	// In-progress lock: a second call inside the cooldown does not re-enter.
	selfUpdate.mu.Lock()
	selfUpdate.inProgress = true
	selfUpdate.lastAttempt = time.Now()
	selfUpdate.mu.Unlock()
	a.maybeSelfUpdate(context.Background())
	selfUpdate.mu.Lock()
	inProgress := selfUpdate.inProgress
	selfUpdate.mu.Unlock()
	if !inProgress {
		t.Fatal("cooldown must not clear another attempt's in-progress flag")
	}
	selfUpdate.mu.Lock()
	selfUpdate.inProgress = false
	selfUpdate.lastAttempt = time.Time{}
	selfUpdate.mu.Unlock()
}

func TestRunSelfUpdateSkippedWithoutOpkg(t *testing.T) {
	// runSelfUpdate must fail cleanly (not panic) where opkg does not exist,
	// which is the case on every non-OpenWrt dev box.
	if err := runSelfUpdate(context.Background()); err == nil {
		t.Skip("opkg exists on this machine; nothing to assert")
	} else if !strings.Contains(err.Error(), "opkg") {
		t.Fatalf("error should name opkg, got: %v", err)
	}
}
