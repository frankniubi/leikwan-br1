package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
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

func TestStartSelfUpdateSkippedWithoutOpkg(t *testing.T) {
	// startSelfUpdate must fail cleanly (not panic) where opkg does not exist,
	// which is the case on every non-OpenWrt dev box.
	if _, err := exec.LookPath("opkg"); err == nil {
		t.Skip("opkg exists on this machine; nothing to assert")
	}
	a := &Agent{}
	_, err := a.startSelfUpdate(context.Background(), selfUpdateTarget{version: "9.9.9"})
	if err == nil || !strings.Contains(err.Error(), "opkg") {
		t.Fatalf("error should name opkg, got: %v", err)
	}
}

// The pull names the ipk, so the router never needs an opkg feed. The file is
// only kept when its sha256 matches what the same Panel published.
func TestDownloadVerifiedChecksSha256(t *testing.T) {
	payload := []byte("leikwand ipk bytes")
	sum := sha256.Sum256(payload)
	good := hex.EncodeToString(sum[:])
	var paths []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.Path)
		_, _ = w.Write(payload)
	}))
	defer srv.Close()

	cfg := defaultConfig()
	cfg.PanelURL = srv.URL
	a := &Agent{}
	a.panel = newPanelClient(cfg, SecretState{AgentToken: "token"}, "dev-1", "router")
	dst := filepath.Join(t.TempDir(), "leikwand.ipk")

	const ref = "/artifacts/files/openwrt/arm64/leikwand_9.9.9-r1_aarch64_generic.ipk"
	if err := a.downloadVerified(context.Background(), ref, good, dst); err != nil {
		t.Fatalf("download with matching sha256: %v", err)
	}
	if got, err := os.ReadFile(dst); err != nil || string(got) != string(payload) {
		t.Fatalf("staged package = %q, %v", got, err)
	}
	if len(paths) != 1 || paths[0] != ref {
		t.Fatalf("requested paths = %v", paths)
	}

	os.Remove(dst)
	bad := strings.Repeat("0", 64)
	if err := a.downloadVerified(context.Background(), ref, bad, dst); err == nil || !strings.Contains(err.Error(), "sha256") {
		t.Fatalf("mismatched sha256 must be refused, got %v", err)
	}
	if _, err := os.Stat(dst); !os.IsNotExist(err) {
		t.Fatal("a package with the wrong hash must not be staged")
	}
	if _, err := os.Stat(dst + ".part"); !os.IsNotExist(err) {
		t.Fatal("the partial download must be removed")
	}
}

// Only Panel-relative paths are fetched; the pull cannot point the router at
// another host.
func TestDownloadRejectsForeignHosts(t *testing.T) {
	cfg := defaultConfig()
	cfg.PanelURL = "http://127.0.0.1:1"
	client := newPanelClient(cfg, SecretState{}, "dev-1", "router")
	for _, ref := range []string{"https://evil.example/leikwand.ipk", "//evil.example/leikwand.ipk", "artifacts/x.ipk"} {
		if _, err := client.Download(context.Background(), ref, 1024, io.Discard); err == nil || !strings.Contains(err.Error(), "panel-relative") {
			t.Fatalf("Download(%q) = %v; want panel-relative refusal", ref, err)
		}
	}
}

func TestAgentUpdateTargetCarriesFile(t *testing.T) {
	a := &Agent{}
	a.clientMeta = ClientMeta{
		DesiredAgentVer: "9.9.9",
		DesiredAgentSHA: strings.Repeat("AB", 32),
		DesiredAgentURL: " /artifacts/files/openwrt/amd64/leikwand_9.9.9-r1_x86_64.ipk ",
	}
	target, needed := a.agentUpdateTarget()
	if !needed || target.url != "/artifacts/files/openwrt/amd64/leikwand_9.9.9-r1_x86_64.ipk" || target.sha256 != strings.Repeat("ab", 32) {
		t.Fatalf("target = %+v, %v", target, needed)
	}
}
