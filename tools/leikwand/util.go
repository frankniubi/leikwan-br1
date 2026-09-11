package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// NL is reused by digest helpers in types.go.
func itoa(v int) string { return strconv.Itoa(v) }

func atoiDefault(value string, fallback int) int {
	parsed, err := strconv.Atoi(strings.TrimSpace(value))
	if err != nil {
		return fallback
	}
	return parsed
}

func boolDefault(value string, fallback bool) bool {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "1", "true", "yes", "on", "enabled":
		return true
	case "0", "false", "no", "off", "disabled":
		return false
	default:
		return fallback
	}
}

// clampInt keeps operator-supplied timings inside a sane band so a bad UCI
// value can never turn a supervision loop into a busy loop.
func clampInt(value, low, high int) int {
	if value < low {
		return low
	}
	if value > high {
		return high
	}
	return value
}

func fileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

func dirExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && info.IsDir()
}

// ensurePrivateDir creates a directory only root can traverse. The Ultra
// control socket refuses to start unless its parent is 0700, so this is a
// hard requirement rather than a hardening nicety.
func ensurePrivateDir(path string) error {
	if err := os.MkdirAll(path, 0o700); err != nil {
		return err
	}
	if err := os.Chmod(path, 0o700); err != nil {
		return err
	}
	info, err := os.Lstat(path)
	if err != nil {
		return err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("%s is not a private directory", path)
	}
	return nil
}

// writeFileAtomic writes through a temporary file in the same directory and
// renames, so a crash or power loss never leaves a half-written secret.
func writeFileAtomic(path string, data []byte, mode os.FileMode) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp*")
	if err != nil {
		return err
	}
	tmpName := tmp.Name()
	defer func() { _ = os.Remove(tmpName) }()
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmpName, path)
}

// runCommandFunc is the seam the uplink manager uses to shell out. The
// multi-WAN logic is mostly a decision procedure over ip(8)/ubus(8) output, and
// a unit test cannot provide two real WAN links — so the seam is what makes
// that decision procedure testable at all.
var runCommandFunc = runCommand

// runCommand executes a control-plane helper with a hard timeout. It is used
// for nft/ip/uci invocations, never for the data path.
func runCommand(timeout time.Duration, name string, args ...string) (string, error) {
	cmd := exec.Command(name, args...)
	var out strings.Builder
	cmd.Stdout = &out
	cmd.Stderr = &out
	if err := cmd.Start(); err != nil {
		return "", err
	}
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	select {
	case err := <-done:
		return out.String(), err
	case <-time.After(timeout):
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
		}
		<-done
		return out.String(), errors.New(name + " timed out")
	}
}

func formatRFC3339(t time.Time) string { return t.UTC().Format(time.RFC3339) }

func parseRFC3339(value string) (time.Time, error) {
	return time.Parse(time.RFC3339, strings.TrimSpace(value))
}

// secretMarkers are the shapes that carry a credential. Longer markers must
// come first so that "agent_token=" is not split by a shorter "token=" match.
var secretMarkers = []string{
	"Bearer ",
	"agent_token=",
	"access_token=",
	"session_token=",
	"pairing_code=",
	"password=",
	"token=",
}

// redactSecrets removes anything token-shaped before a string can reach a log
// file, a LuCI page or a diagnostics upload.
//
// The scan is single-pass on purpose: rewriting the input and rescanning would
// re-match the marker it just wrote back and never terminate.
func redactSecrets(value string) string {
	var out strings.Builder
	out.Grow(len(value))
	for i := 0; i < len(value); {
		matched := ""
		for _, marker := range secretMarkers {
			if strings.HasPrefix(value[i:], marker) {
				matched = marker
				break
			}
		}
		if matched == "" {
			out.WriteByte(value[i])
			i++
			continue
		}
		out.WriteString(matched)
		out.WriteString("***")
		i += len(matched)
		for i < len(value) && !isSpaceOrQuote(value[i]) {
			i++
		}
	}
	return out.String()
}

func isSpaceOrQuote(c byte) bool {
	return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '"' || c == '\'' || c == '&'
}
