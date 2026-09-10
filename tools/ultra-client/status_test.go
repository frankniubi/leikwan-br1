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
	selectionRef := "entry-reference-abcdefghijklmnopqrstuvwxyz"
	writer.UpdateTCP(selectionRef, 18*time.Millisecond)
	writer.UpdateTCP(selectionRef, 17*time.Millisecond)
	writer.UpdateUDP(selectionRef, 20*time.Millisecond)
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
	if status.LatestTCP == nil || status.LatestTCP.SelectionRef != selectionRef {
		t.Fatalf("tcp=%+v", status.LatestTCP)
	}
	if status.ActiveUDP == nil || status.ActiveUDP.SelectionRef != selectionRef {
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
	tcp := "tcp-entry-reference-abcdefghijklmnop"
	udp := "udp-entry-reference-abcdefghijklmnop"
	writer.UpdateTCP(tcp, 10*time.Millisecond)
	time.Sleep(2 * time.Millisecond)
	writer.UpdateUDP(udp, 20*time.Millisecond)
	writer.Close()
	if writer.status.LatestTCP == nil || writer.status.ActiveUDP == nil || writer.status.LatestTCP.SelectionRef == writer.status.ActiveUDP.SelectionRef {
		t.Fatalf("status selections are not independent: %+v", writer.status)
	}
}

func TestStatusWriterTracksUDPAssociationPressure(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 9, time.Millisecond)
	writer.UpdateUDPAssociations(7, 11)
	writer.AddUDPQueueDrop()
	writer.AddUDPSetupFailure()
	writer.Close()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var status RuntimeStatus
	if err := json.Unmarshal(raw, &status); err != nil {
		t.Fatal(err)
	}
	if status.UDPActiveAssociations != 7 || status.UDPPendingAssociations != 11 {
		t.Fatalf("association counts = active:%d pending:%d", status.UDPActiveAssociations, status.UDPPendingAssociations)
	}
	if status.UDPQueueDrops != 1 || status.UDPSetupFailures != 1 {
		t.Fatalf("UDP failure counts = drops:%d setup:%d", status.UDPQueueDrops, status.UDPSetupFailures)
	}
}

func TestStatusWriterPublishesUDPProbeQualityWithoutProviderMaterial(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 11, time.Millisecond)
	writer.UpdateUDPProbe("udp-entry-reference-abcdefghijklmnop", udpProbeResult{
		Sent: 20, Received: 19, MedianRTT: 12 * time.Millisecond, P95RTT: 18 * time.Millisecond,
		Jitter: 3 * time.Millisecond,
	})
	writer.Close()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var status RuntimeStatus
	if err := json.Unmarshal(raw, &status); err != nil {
		t.Fatal(err)
	}
	if status.ActiveUDP == nil || status.ActiveUDP.LossPercent != 5 || status.ActiveUDP.P95RTTMillis != 18 || status.ActiveUDP.JitterMillis != 3 {
		t.Fatalf("probe quality=%+v", status.ActiveUDP)
	}
	if strings.Contains(strings.ToLower(string(raw)), "provider") {
		t.Fatalf("status contains provider material marker: %s", raw)
	}
}
