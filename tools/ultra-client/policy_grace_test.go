package main

import (
	"testing"
	"time"
)

// installGraceTestPolicy installs a policy that expires in two minutes, the way
// the Panel issues one, and returns the store with its generation.
func installGraceTestPolicy(t *testing.T, generation string) (*PolicyStore, Policy) {
	t.Helper()
	config := testPolicyConfig()
	store := NewPolicyStore(config)
	now := time.Now()
	policy := testPolicy(now, generation)
	if err := store.Replace(policy, testLease(policy, "a1b2c3d4e5f60718")); err != nil {
		t.Fatalf("install policy: %v", err)
	}
	return store, policy
}

func TestExtendGraceKeepsServingPastPanelExpiry(t *testing.T) {
	gen := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	config := testPolicyConfig()
	store := NewPolicyStore(config)
	now := time.Now()
	policy := testPolicy(now, gen)
	// The Panel expires this grant in one second; the grace must carry it on.
	policy.ExpiresAt = now.Add(time.Second)
	if err := store.Replace(policy, testLease(policy, "a1b2c3d4e5f60718")); err != nil {
		t.Fatalf("install policy: %v", err)
	}
	until := now.Add(5 * time.Minute)
	if err := store.ExtendGrace(gen, until); err != nil {
		t.Fatalf("extend within bound: %v", err)
	}
	time.Sleep(1200 * time.Millisecond)
	if _, ok := store.Snapshot(); !ok {
		t.Fatal("extended grant must keep serving after the Panel expiry")
	}
}

func TestExtendGraceIsBoundedFromPanelExpiry(t *testing.T) {
	gen := "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	store, policy := installGraceTestPolicy(t, gen)
	if err := store.ExtendGrace(gen, policy.ExpiresAt.Add(maxLeaseGrace+time.Second)); err != ErrPolicyInvalid {
		t.Fatalf("beyond the 10 minute bound: got %v, want ErrPolicyInvalid", err)
	}
	// Repeated extensions cannot walk the bound forward: it is measured from
	// the issued expiry, not from the previous extension.
	if err := store.ExtendGrace(gen, policy.ExpiresAt.Add(maxLeaseGrace)); err != nil {
		t.Fatalf("extend to exactly the bound: %v", err)
	}
	if err := store.ExtendGrace(gen, policy.ExpiresAt.Add(maxLeaseGrace+time.Minute)); err != ErrPolicyInvalid {
		t.Fatalf("second extension past the bound: got %v, want ErrPolicyInvalid", err)
	}
}

func TestExtendGraceRefusesOtherGenerationAndLapsedPolicy(t *testing.T) {
	gen := "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	store, policy := installGraceTestPolicy(t, gen)
	other := "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"
	if err := store.ExtendGrace(other, policy.ExpiresAt.Add(time.Minute)); err != ErrPolicyExpired {
		t.Fatalf("unknown generation: got %v, want ErrPolicyExpired", err)
	}
	store.mu.Lock()
	store.policy.ExpiresAt = time.Now().Add(-time.Second)
	store.mu.Unlock()
	if err := store.ExtendGrace(gen, time.Now().Add(time.Minute)); err != ErrPolicyExpired {
		t.Fatalf("lapsed policy: got %v, want ErrPolicyExpired", err)
	}
}

func TestExtendGraceDoesNotRefillQuota(t *testing.T) {
	gen := "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"
	store, policy := installGraceTestPolicy(t, gen)
	if err := store.limiter.Consume(512); err != nil {
		t.Fatalf("consume: %v", err)
	}
	before := store.limiter.QuotaRemaining()
	if err := store.ExtendGrace(gen, policy.ExpiresAt.Add(time.Minute)); err != nil {
		t.Fatalf("extend: %v", err)
	}
	if after := store.limiter.QuotaRemaining(); after != before {
		t.Fatalf("quota after extension = %d, want %d (extension must not refill)", after, before)
	}
}

func TestStopGraceEndsServingWithoutRememberingGeneration(t *testing.T) {
	gen := "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"
	store, policy := installGraceTestPolicy(t, gen)
	if err := store.ExtendGrace(gen, policy.ExpiresAt.Add(time.Minute)); err != nil {
		t.Fatalf("extend: %v", err)
	}
	if err := store.StopGrace(gen); err != nil {
		t.Fatalf("stop: %v", err)
	}
	if _, ok := store.Snapshot(); ok {
		t.Fatal("a stopped grace must not serve")
	}
	// The Panel can issue this generation again once it is reachable; a stop
	// must not poison it the way Revoke would.
	if _, revoked := store.revoked[gen]; revoked {
		t.Fatal("StopGrace must not record the generation as revoked")
	}
	if err := store.Replace(testPolicy(time.Now(), gen), testLease(testPolicy(time.Now(), gen), "0102030405060708")); err != nil {
		t.Fatalf("reinstall after stop: %v", err)
	}
}

func TestControlCommandRoutesGraceOperations(t *testing.T) {
	gen := "1111111111111111111111111111111111111111111111111111111111111111"
	store, policy := installGraceTestPolicy(t, gen)
	extend := []byte(`{"op":"extend_grace","policy_generation":"` + gen + `","grace_until":"` + policy.ExpiresAt.Add(time.Minute).UTC().Format(time.RFC3339) + `"}`)
	reply, handled := handleControlCommand(extend, store)
	if !handled || reply["ok"] != true {
		t.Fatalf("extend_grace reply = %v handled=%v", reply, handled)
	}
	reply, handled = handleControlCommand([]byte(`{"op":"stop_grace","policy_generation":"`+gen+`"}`), store)
	if !handled || reply["ok"] != true {
		t.Fatalf("stop_grace reply = %v handled=%v", reply, handled)
	}
	reply, handled = handleControlCommand([]byte(`{"op":"launch_missiles"}`), store)
	if !handled || reply["ok"] != false {
		t.Fatalf("unknown op must be refused, got %v", reply)
	}
	// A lease batch has no op member and must fall through to the normal path.
	if _, handled := handleControlCommand([]byte(`{"leases":[{"forward_id":42}]}`), store); handled {
		t.Fatal("a lease batch must not be treated as a control command")
	}
}
