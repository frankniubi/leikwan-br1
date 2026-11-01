//go:build linux

package main

import (
	"path/filepath"
	"testing"
)

// opkg must write to a file, not to a pipe this process owns, run in its own
// session, and receive the staged package path intact.
func TestStartDetachedOpkgLogsToFile(t *testing.T) {
	prev := selfUpdateLogPath
	selfUpdateLogPath = filepath.Join(t.TempDir(), "update.log")
	defer func() { selfUpdateLogPath = prev }()

	cmd, err := startDetachedOpkg(`echo "installing $1"; exit 3`, "/tmp/with space.ipk")
	if err != nil {
		t.Fatalf("start: %v", err)
	}
	if cmd.Stdout == nil || cmd.SysProcAttr == nil || !cmd.SysProcAttr.Setsid {
		t.Fatal("opkg must run in its own session with stdout on a file")
	}
	if err := cmd.Wait(); err == nil {
		t.Fatal("exit status must propagate")
	}
	if got := logTail(selfUpdateLogPath, 512); got != "installing /tmp/with space.ipk" {
		t.Fatalf("log = %q", got)
	}
}
