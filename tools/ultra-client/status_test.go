package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

func TestStatusWriterCoalescesAndWritesSafeAtomicState(t *testing.T) {
	path := filepath.Join(t.TempDir(), "forward-42.status.json")
	writer := newStatusWriter(path, 42, 50*time.Millisecond)
	writer.UpdateLocation(LocationResult{Code: "shanghai", Source: "network", ObservedAt: time.Now().UTC()})
	if writer.writeCountValue() != 1 {
		t.Fatalf("initial writes=%d", writer.writeCountValue())
	}
	entry := Entry{EntryRef: "entry-reference-abcdefghijklmnopqrstuvwxyz", IP: "203.0.113.20"}
	writer.UpdateTCP(entry, 18*time.Millisecond)
	writer.UpdateTCP(entry, 17*time.Millisecond)
	writer.UpdateUDP(entry, 20*time.Millisecond)
	if writer.writeCountValue() != 1 {
		t.Fatalf("uncoalesced writes=%d", writer.writeCountValue())
	}
	time.Sleep(100 * time.Millisecond)
	writer.Close()
	if writer.writeCountValue() != 2 {
		t.Fatalf("coalesced writes=%d", writer.writeCountValue())
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var status RuntimeStatus
	if err := json.Unmarshal(raw, &status); err != nil {
		t.Fatal(err)
	}
	if status.ForwardID != 42 || status.DetectedIngressZone != "shanghai" {
		t.Fatalf("status=%+v", status)
	}
	if status.LatestTCP == nil || status.LatestTCP.EntryRef != entry.EntryRef || status.LatestTCP.RelayIP != entry.IP {
		t.Fatalf("tcp=%+v", status.LatestTCP)
	}
	if status.ActiveUDP == nil || status.ActiveUDP.EntryRef != entry.EntryRef || status.ActiveUDP.RelayIP != entry.IP {
		t.Fatalf("udp=%+v", status.ActiveUDP)
	}
	lower := strings.ToLower(string(raw))
	for _, forbidden := range []string{"credential", "token", "material", "\"user\"", "\"port\""} {
		if strings.Contains(lower, forbidden) {
			t.Fatalf("status contains %q: %s", forbidden, raw)
		}
	}
	if runtime.GOOS != "windows" {
		info, err := os.Stat(path)
		if err != nil || info.Mode().Perm() != 0o600 {
			t.Fatalf("mode=%v err=%v", info.Mode().Perm(), err)
		}
	}
	matches, _ := filepath.Glob(filepath.Join(filepath.Dir(path), ".*.tmp"))
	if len(matches) != 0 {
		t.Fatalf("temporary status files remain: %v", matches)
	}
}

func TestStatusWriterKeepsTCPAndUDPIndependent(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 7, time.Millisecond)
	tcp := Entry{EntryRef: "tcp-entry-reference-abcdefghijklmnop", IP: "192.0.2.10"}
	udp := Entry{EntryRef: "udp-entry-reference-abcdefghijklmnop", IP: "192.0.2.20"}
	writer.UpdateTCP(tcp, 10*time.Millisecond)
	time.Sleep(2 * time.Millisecond)
	writer.UpdateUDP(udp, 20*time.Millisecond)
	writer.Close()
	if writer.status.LatestTCP == nil || writer.status.ActiveUDP == nil || writer.status.LatestTCP.EntryRef == writer.status.ActiveUDP.EntryRef {
		t.Fatalf("status selections are not independent: %+v", writer.status)
	}
}
