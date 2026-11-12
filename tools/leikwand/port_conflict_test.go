package main

import (
	"context"
	"testing"
	"time"
)

// Two forwards the Panel configures on one listen port must not take the port
// from each other: the old reclaim killed the sibling's runtime on every pass.

func conflictForward(id, port int, transport string) ForwardConfig {
	return ForwardConfig{ForwardID: id, ListenPort: port, Transport: transport, BackendMode: "ultra", ClientMode: "single"}
}

func TestTransportsOverlap(t *testing.T) {
	cases := []struct {
		first, second string
		want          bool
	}{
		{"tcp+udp", "tcp+udp", true},
		{"", "tcp", true},
		{"tcp", "tcp+udp", true},
		{"udp", "tcp+udp", true},
		{"tcp", "udp", false},
		{"UDP", " tcp ", false},
		{"udp", "udp", true},
	}
	for _, tc := range cases {
		if got := transportsOverlap(tc.first, tc.second); got != tc.want {
			t.Errorf("transportsOverlap(%q, %q) = %v, want %v", tc.first, tc.second, got, tc.want)
		}
	}
}

func TestSecondForwardOnAHeldPortIsRefusedAndTheHolderKeepsRunning(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	holder := conflictForward(448, 45871, "tcp+udp")
	duplicate := conflictForward(449, 45871, "tcp+udp")
	proc := startTestChild(t)
	agent.mu.Lock()
	agent.forwards = map[int]ForwardConfig{448: holder, 449: duplicate}
	agent.runtimes[448] = &runtimeState{proc: proc, forward: holder, contract: contractSignature(holder)}
	agent.mu.Unlock()

	agent.reconcileForward(context.Background(), duplicate, "test")

	if !proc.alive() {
		t.Fatal("the holder's runtime was stopped to make room for a duplicate forward")
	}
	agent.mu.Lock()
	_, started := agent.runtimes[449]
	agent.mu.Unlock()
	if started {
		t.Fatal("the duplicate forward was started on a port its sibling holds")
	}
}

func TestPortHolderIgnoresSelfDeadRuntimesAndDisjointTransports(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	live := startTestChild(t)
	agent.mu.Lock()
	agent.runtimes[1] = &runtimeState{proc: live, forward: conflictForward(1, 45872, "tcp")}
	agent.runtimes[2] = &runtimeState{proc: &runtimeProcess{forwardID: 2}, forward: conflictForward(2, 45873, "tcp+udp")}
	agent.mu.Unlock()

	if holder := agent.portHolder(1, 45872, "tcp"); holder != nil {
		t.Fatal("a forward is not its own port holder")
	}
	if holder := agent.portHolder(9, 45872, "udp"); holder != nil {
		t.Fatal("a udp-only forward can share a port with a tcp-only one")
	}
	if holder := agent.portHolder(9, 45872, "tcp+udp"); holder == nil || holder.forward.ForwardID != 1 {
		t.Fatalf("forward 1 holds tcp 45872, got %+v", holder)
	}
	if holder := agent.portHolder(9, 45873, "tcp+udp"); holder != nil {
		t.Fatal("a runtime that is not alive holds no port")
	}
}

func TestDesiredClaimsFollowsThePanelConfiguration(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	moved := conflictForward(5, 45880, "tcp+udp")
	stopped := conflictForward(6, 45874, "tcp+udp")
	agent.mu.Lock()
	agent.forwards = map[int]ForwardConfig{5: moved, 6: stopped, 7: conflictForward(7, 45874, "tcp")}
	agent.panelStopped[6] = policyGenerationOf(stopped)
	agent.mu.Unlock()

	if agent.desiredClaims(5, 45874, "tcp+udp") {
		t.Error("forward 5 moved to 45880 and no longer claims 45874")
	}
	if agent.desiredClaims(6, 45874, "tcp+udp") {
		t.Error("a forward the Panel stopped keeps no claim on its port")
	}
	if !agent.desiredClaims(7, 45874, "tcp+udp") {
		t.Error("forward 7 still listens on tcp 45874")
	}
	if agent.desiredClaims(8, 45874, "tcp+udp") {
		t.Error("a forward the Panel no longer lists claims nothing")
	}
}

func TestHolderThatMovedOffThePortHandsItOver(t *testing.T) {
	agent := newTestAgent(t, defaultConfig())
	holderOld := conflictForward(448, 45875, "tcp+udp")
	holderNew := conflictForward(448, 45890, "tcp+udp")
	taker := conflictForward(449, 45875, "tcp+udp")
	proc := startTestChild(t)
	agent.mu.Lock()
	agent.forwards = map[int]ForwardConfig{448: holderNew, 449: taker}
	agent.runtimes[448] = &runtimeState{proc: proc, forward: holderOld, contract: contractSignature(holderOld)}
	agent.mu.Unlock()

	done := make(chan struct{})
	go func() {
		agent.reconcileForward(context.Background(), taker, "test")
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(30 * time.Second):
		t.Fatal("reconcile did not return")
	}
	if proc.alive() {
		t.Fatal("the old holder moved to another port and should have released this one")
	}
}
