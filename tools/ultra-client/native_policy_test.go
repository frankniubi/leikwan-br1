package main

import "testing"

func TestNativeRoutePolicyDefaultsToRestricted(t *testing.T) {
	policy, err := ParseNativeRoutePolicy("")
	if err != nil || policy != NativeRoutePolicy(nativeRoutePolicyRestricted) {
		t.Fatalf("default policy = %q, err=%v", policy, err)
	}
	if policy.AllowsPort(999) || !policy.AllowsPort(1000) || !policy.AllowsPort(65535) {
		t.Fatal("restricted policy must allow only 1000-65535")
	}
	if policy.AllowsPort(0) || policy.AllowsPort(65536) {
		t.Fatal("invalid ports must be rejected")
	}
}

func TestNativeRoutePolicyUnrestricted(t *testing.T) {
	policy, err := ParseNativeRoutePolicy("unrestricted")
	if err != nil || policy.FilterProfile() != "off" {
		t.Fatalf("unrestricted policy = %q, err=%v", policy, err)
	}
	if !policy.AllowsPort(1) || !policy.AllowsPort(999) || !policy.AllowsPort(65535) {
		t.Fatal("unrestricted policy must allow every valid port")
	}
}

func TestNativeRoutePolicyRejectsUnknown(t *testing.T) {
	if _, err := ParseNativeRoutePolicy("full"); err == nil {
		t.Fatal("unknown policy accepted")
	}
}
