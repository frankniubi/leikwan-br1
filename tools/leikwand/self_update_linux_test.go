//go:build linux

package main

import (
	"path/filepath"
	"testing"
)

// The installer (opkg or apk) must write to a file, not to a pipe this
// process owns, run in its own session, and receive the staged package path
// intact.
func TestStartDetachedInstallerLogsToFile(t *testing.T) {
	prev := selfUpdateLogPath
	selfUpdateLogPath = filepath.Join(t.TempDir(), "update.log")
	defer func() { selfUpdateLogPath = prev }()

	cmd, err := startDetachedInstaller(`echo "installing $1"; exit 3`, "/tmp/with space.apk")
	if err != nil {
		t.Fatalf("start: %v", err)
	}
	if cmd.Stdout == nil || cmd.SysProcAttr == nil || !cmd.SysProcAttr.Setsid {
		t.Fatal("the installer must run in its own session with stdout on a file")
	}
	if err := cmd.Wait(); err == nil {
		t.Fatal("exit status must propagate")
	}
	if got := logTail(selfUpdateLogPath, 512); got != "installing /tmp/with space.apk" {
		t.Fatalf("log = %q", got)
	}
}

// The flavour of the named update file routes to the right installer: a .apk
// extension goes to apk, an .ipk (or anything else) stays on opkg.
func TestSelfUpdateIsApk(t *testing.T) {
	cases := map[string]bool{
		"/artifacts/files/openwrt/apk/amd64/leikwand_1.2.7-r1_x86_64.apk": true,
		"/artifacts/files/openwrt/amd64/leikwand_1.2.7-r1_x86_64.APK":     true,
		"/artifacts/files/openwrt/amd64/leikwand_1.2.6-r1_x86_64.ipk":     false,
		"leikwand_1.2.6-r1_x86_64":                                        false,
		"":                                                                false,
	}
	for url, want := range cases {
		if got := selfUpdateIsApk(url); got != want {
			t.Fatalf("selfUpdateIsApk(%q) = %v, want %v", url, got, want)
		}
	}
}
