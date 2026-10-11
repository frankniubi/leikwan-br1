package main

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func selectionTierLeaseFrame(policy Policy) leaseFrame {
	frame := leaseFrame{
		Protocol:            policy.Protocol,
		ForwardID:           policy.ForwardID,
		SessionTicket:       policy.SessionTicket,
		PolicyGeneration:    policy.PolicyGeneration,
		LeaseID:             "ul2_selection_tier",
		ExpiresAt:           policy.ExpiresAt.UTC().Format(time.RFC3339),
		Transport:           policy.Transport,
		RateLimitMbps:       policy.RateLimitMbps,
		QuotaRemainingBytes: policy.QuotaRemainingBytes,
		TargetDigest:        policy.TargetDigest,
		SelectionRef:        "selection-reference-selection-tier",
		NonceHash:           "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
	}
	frame.Endpoint.IP = "203.0.113.20"
	frame.Endpoint.Port = 5438
	frame.Credential.User = "user"
	frame.Credential.Password = "password"
	return frame
}

func TestDecodeSelectionTierDefaultsAndValidates(t *testing.T) {
	config := testPolicyConfig()
	policy := testPolicy(time.Now(), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	frame := selectionTierLeaseFrame(policy)

	raw, err := json.Marshal(frame)
	if err != nil {
		t.Fatal(err)
	}
	var legacy map[string]any
	if err := json.Unmarshal(raw, &legacy); err != nil {
		t.Fatal(err)
	}
	delete(legacy, "selection_tier")
	raw, err = json.Marshal(legacy)
	if err != nil {
		t.Fatal(err)
	}
	_, lease, err := decodeLeaseFrame(raw, config)
	if err != nil {
		t.Fatal(err)
	}
	if lease.SelectionTier != selectionTierUnknown {
		t.Fatalf("missing selection_tier decoded as %q, want %q", lease.SelectionTier, selectionTierUnknown)
	}
	lease.wipe()

	frame.SelectionTier = selectionTierPaid
	raw, err = json.Marshal(frame)
	if err != nil {
		t.Fatal(err)
	}
	_, lease, err = decodeLeaseFrame(raw, config)
	if err != nil {
		t.Fatal(err)
	}
	if lease.SelectionTier != selectionTierPaid {
		t.Fatalf("selection_tier decoded as %q, want %q", lease.SelectionTier, selectionTierPaid)
	}
	lease.wipe()

	frame.SelectionTier = "enterprise"
	raw, err = json.Marshal(frame)
	if err != nil {
		t.Fatal(err)
	}
	if _, lease, err = decodeLeaseFrame(raw, config); !errors.Is(err, ErrLeaseInvalid) {
		lease.wipe()
		t.Fatalf("invalid selection_tier err=%v, want %v", err, ErrLeaseInvalid)
	}

	direct := testLease(policy, "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc")
	direct.SelectionTier = "enterprise"
	if err := direct.Validate(policy, time.Now()); !errors.Is(err, ErrLeaseInvalid) {
		t.Fatalf("direct invalid selection_tier err=%v, want %v", err, ErrLeaseInvalid)
	}
	direct.wipe()
}

func TestRankUDPProbeResultsPrefersPaidAndMixedBeforeFreeAndUnknown(t *testing.T) {
	results := []udpProbeResult{
		{Index: 0, SelectionTier: selectionTierUnknown, Sent: 20, Received: 20, MedianRTT: time.Millisecond},
		{Index: 1, SelectionTier: selectionTierFree, Sent: 20, Received: 20, MedianRTT: time.Millisecond},
		{Index: 2, SelectionTier: selectionTierMixed, Sent: 20, Received: 20, MedianRTT: time.Millisecond},
		{Index: 3, SelectionTier: selectionTierPaid, Sent: 20, Received: 20, MedianRTT: time.Millisecond},
	}
	ranked := rankUDPProbeResults(results)
	if len(ranked) != len(results) {
		t.Fatalf("ranked count=%d, want %d", len(ranked), len(results))
	}
	want := []int{3, 2, 1, 0}
	for index, expected := range want {
		if ranked[index].Index != expected {
			t.Fatalf("ranked[%d]=%+v, want index %d", index, ranked[index], expected)
		}
	}
}

func TestControlDeadlineTracksConfiguredAssociationBudget(t *testing.T) {
	config := Config{AutoSelect: true, ProbeDurationSecs: 30, ConnectTimeoutSecs: 15}
	want := probeDuration(config) + probeAssociationTimeout(config) + selectionResponseGrace + activeCandidateFallbackBudget(config)
	if got := controlDeadlineDuration(config); got != want {
		t.Fatalf("control deadline=%s, want %s", got, want)
	}
}

func TestSelectBestLeaseCarriesLeaseTierIntoProbeResult(t *testing.T) {
	policy := Policy{Transport: "udp"}
	leases := []ProviderLease{
		{IP: "203.0.113.20", Port: 5438, User: []byte("free"), SelectionRef: "selection-reference-free-abcdefghijkl", SelectionTier: selectionTierFree},
		{IP: "203.0.113.21", Port: 5438, User: []byte("paid"), SelectionRef: "selection-reference-paid-abcdefghijkl", SelectionTier: selectionTierPaid},
	}
	probe := func(_ context.Context, _ ProviderLease, _ Config, _ Policy) udpProbeResult {
		return udpProbeResult{SelectionTier: selectionTierUnknown, Sent: 10, Received: 10, MedianRTT: time.Millisecond}
	}
	selected, result, err := selectBestLeaseWithProbe(context.Background(), Config{ProbeWorkers: 2}, policy, leases, probe)
	if err != nil {
		t.Fatal(err)
	}
	if selected.SelectionTier != selectionTierPaid || result.SelectionTier != selectionTierPaid {
		t.Fatalf("selected=%+v result=%+v, want paid tier on both", selected, result)
	}
}

func TestStatusPublishesSelectionTierWithoutProviderMaterial(t *testing.T) {
	path := filepath.Join(t.TempDir(), "status.json")
	writer := newStatusWriter(path, 11, time.Millisecond)
	writer.UpdateTCPWithTier("tcp-entry-reference-abcdefghijklmnop", selectionTierPaid, 10*time.Millisecond)
	writer.UpdateUDPProbeWithTier("udp-entry-reference-abcdefghijklmnop", selectionTierMixed, udpProbeResult{
		SelectionTier: selectionTierUnknown,
		Sent:          20,
		Received:      20,
		MedianRTT:     12 * time.Millisecond,
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
	if status.LatestTCP == nil || status.LatestTCP.SelectionTier != selectionTierPaid {
		t.Fatalf("tcp status=%+v, want tier %q", status.LatestTCP, selectionTierPaid)
	}
	if status.ActiveUDP == nil || status.ActiveUDP.SelectionTier != selectionTierMixed {
		t.Fatalf("udp status=%+v, want tier %q", status.ActiveUDP, selectionTierMixed)
	}
}

func TestStickyLeaseAcceptsUniqueMaterialRotationForSameLogicalUser(t *testing.T) {
	config := testPolicyConfig()
	policy := testPolicy(time.Now(), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	selected := testLease(policy, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	selected.SelectionRef = "selection-reference-selected-abcdefghijkl"
	store := NewPolicyStore(config)
	if err := store.Replace(policy, selected); err != nil {
		t.Fatal(err)
	}

	rotated := selected
	rotated.LeaseID = "ul2_rotated"
	rotated.NonceHash = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	rotated.SelectionRef = "selection-reference-material-rotated"
	got, replace := stickyLeaseForPolicy(store, policy, []ProviderLease{rotated})
	if !replace || got.SelectionRef != rotated.SelectionRef {
		t.Fatalf("rotated sticky selection=%+v replace=%v", got, replace)
	}

	otherUser := rotated
	otherUser.SelectionRef = "selection-reference-other-user"
	otherUser.User = []byte("other-user")
	got, replace = stickyLeaseForPolicy(store, policy, []ProviderLease{otherUser})
	if replace || got.SelectionRef != "" {
		t.Fatalf("different logical user unexpectedly matched: %+v replace=%v", got, replace)
	}

	selected.wipe()
}
