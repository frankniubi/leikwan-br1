package main

// Coverage for the usage file: exact document format, random per-process
// instance id, atomic replace without leftovers, 0600/0700 modes, failure
// handling that never stops the relay, and the final write on shutdown.

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"testing"
	"time"
)

func fixedUsageWriter(t *testing.T, path string, meters *meterRegistry, logs *syncBuffer) *usageWriter {
	t.Helper()
	writer, err := newUsageWriter(path, meters, newSidecarLogger("INFO", logs))
	if err != nil {
		t.Fatalf("newUsageWriter failed: %v", err)
	}
	writer.startedAt = time.Date(2026, 10, 21, 8, 0, 0, 0, time.UTC)
	writer.now = func() time.Time { return time.Date(2026, 10, 21, 8, 0, 10, 0, time.UTC) }
	return writer
}

func TestUsageFileFormatInstanceAndAtomicReplace(t *testing.T) {
	meters := newMeterRegistry(nil)
	forward := meters.forward(170)
	forward.countToTarget(1200)
	forward.countToTarget(300)
	forward.countFromTarget(900)
	forward.countRateLimitedDrop()
	meters.forward(9) // metered but idle: present with zero counters

	dir := filepath.Join(t.TempDir(), "missing", "leikwan-external-uot")
	path := filepath.Join(dir, "usage.json")
	writer := fixedUsageWriter(t, path, meters, &syncBuffer{})
	if !regexp.MustCompile(`^[0-9a-f]{32}$`).MatchString(writer.instance) {
		t.Fatalf("instance id %q is not 32 lowercase hex characters", writer.instance)
	}
	other := fixedUsageWriter(t, path, meters, &syncBuffer{})
	if other.instance == writer.instance {
		t.Fatal("two writers share an instance id")
	}
	if err := writer.write(); err != nil {
		t.Fatalf("write failed: %v", err)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("usage file missing: %v", err)
	}
	want := `{"version":1,"instance":"` + writer.instance + `","started_at":"2026-10-21T08:00:00Z","written_at":"2026-10-21T08:00:10Z",` +
		`"forwards":{"170":{"to_target_bytes":1500,"from_target_bytes":900,"to_target_packets":2,"from_target_packets":1,"rate_limited_drops":1},` +
		`"9":{"to_target_bytes":0,"from_target_bytes":0,"to_target_packets":0,"from_target_packets":0,"rate_limited_drops":0}}}` + "\n"
	if string(raw) != want {
		t.Fatalf("usage file =\n%s\nwant\n%s", raw, want)
	}

	// A rewrite replaces the document whole and leaves no temp file behind.
	forward.countFromTarget(100)
	if err := writer.write(); err != nil {
		t.Fatalf("rewrite failed: %v", err)
	}
	var document usageDocument
	raw, _ = os.ReadFile(path)
	if err := json.Unmarshal(raw, &document); err != nil {
		t.Fatalf("rewritten file is not JSON: %v", err)
	}
	if document.Forwards["170"].FromTargetBytes != 1000 || document.Instance != writer.instance {
		t.Fatalf("rewritten document = %+v", document)
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 1 || entries[0].Name() != "usage.json" {
		names := make([]string, 0, len(entries))
		for _, entry := range entries {
			names = append(names, entry.Name())
		}
		t.Fatalf("directory holds %v, want only usage.json", names)
	}
	if runtime.GOOS != "windows" {
		info, _ := os.Stat(path)
		if info.Mode().Perm() != 0o600 {
			t.Fatalf("usage file mode = %v, want 0600", info.Mode().Perm())
		}
		dirInfo, _ := os.Stat(dir)
		if dirInfo.Mode().Perm() != 0o700 {
			t.Fatalf("created directory mode = %v, want 0700", dirInfo.Mode().Perm())
		}
	}
}

func TestUsageFileWithoutForwardsIsAnEmptyObject(t *testing.T) {
	path := filepath.Join(t.TempDir(), "usage.json")
	writer := fixedUsageWriter(t, path, newMeterRegistry(nil), &syncBuffer{})
	if err := writer.write(); err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(path)
	if !strings.HasSuffix(strings.TrimSpace(string(raw)), `"forwards":{}}`) {
		t.Fatalf("empty usage document = %s", raw)
	}
}

func TestUsageWriterFailureIsLoggedAndRecovers(t *testing.T) {
	root := t.TempDir()
	blocker := filepath.Join(root, "not-a-directory")
	if err := os.WriteFile(blocker, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	logs := &syncBuffer{}
	writer := fixedUsageWriter(t, filepath.Join(blocker, "usage.json"), newMeterRegistry(nil), logs)
	writer.writeAndLog()
	writer.writeAndLog()
	if count := strings.Count(logs.String(), "WARNING external UoT usage file write failed"); count != 1 {
		t.Fatalf("failure logged %d times, want once per distinct error: %s", count, logs.String())
	}
	if err := os.Remove(blocker); err != nil {
		t.Fatal(err)
	}
	writer.writeAndLog()
	if !strings.Contains(logs.String(), "INFO external UoT usage file write recovered") {
		t.Fatalf("recovery was not logged: %s", logs.String())
	}
	if _, err := os.Stat(filepath.Join(blocker, "usage.json")); err != nil {
		t.Fatalf("usage file was not written after recovery: %v", err)
	}
}

func TestUsageWriterRunWritesFinalCountersOnStop(t *testing.T) {
	path := filepath.Join(t.TempDir(), "usage.json")
	meters := newMeterRegistry(nil)
	writer := fixedUsageWriter(t, path, meters, &syncBuffer{})
	stop := make(chan struct{})
	done := make(chan struct{})
	go func() {
		defer close(done)
		writer.run(stop, time.Hour)
	}()
	// The first write happens immediately on start.
	deadline := time.Now().Add(3 * time.Second)
	for {
		if _, err := os.Stat(path); err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("run did not write on start")
		}
		time.Sleep(5 * time.Millisecond)
	}
	meters.forward(170).countToTarget(42)
	close(stop)
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("run did not return after stop")
	}
	var document usageDocument
	raw, _ := os.ReadFile(path)
	if err := json.Unmarshal(raw, &document); err != nil {
		t.Fatal(err)
	}
	if document.Forwards["170"].ToTargetBytes != 42 {
		t.Fatalf("final write missed the last counters: %s", raw)
	}
}
