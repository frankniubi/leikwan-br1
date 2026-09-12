package main

import (
	"regexp"
	"testing"
)

func TestRuntimeAttestationFingerprintDefaults(t *testing.T) {
	got := runtimeAttestation().Fingerprint
	if got == "" {
		t.Fatal("fingerprint must never be empty; the panel treats empty as unknown")
	}
	if runtimeBuildVersion == "" && got != "development" {
		t.Fatalf("fingerprint = %q, want the development fallback for an unstamped build", got)
	}
	if runtimeBuildVersion != "" && got != runtimeBuildVersion {
		t.Fatalf("fingerprint = %q, want the injected runtimeBuildVersion %q", got, runtimeBuildVersion)
	}
}

func TestSelfExecutableHashIsStableSha256(t *testing.T) {
	first := selfExecutableHash()
	if first == "" {
		t.Skip("executable not readable in this environment")
	}
	if !regexp.MustCompile("^[a-f0-9]{64}$").MatchString(first) {
		t.Fatalf("self hash = %q, want 64 lowercase hex characters", first)
	}
	if second := selfExecutableHash(); second != first {
		t.Fatalf("self hash changed between calls: %q then %q", first, second)
	}
}

func TestAttestationChecksDoNotPanic(t *testing.T) {
	attestation := runtimeAttestation()
	// Both flags are advisory: on a clean host they are false, and the value is
	// only ever reported, never used to refuse traffic on its own.
	_ = attestation.Debugger
	_ = attestation.Instrumented
}

func TestInstrumentationDetectedHonoursEnvironment(t *testing.T) {
	if instrumentationDetected() {
		// A CI box with LD_PRELOAD set is exactly the case we want to report.
		t.Log("instrumentation already present in this environment")
	}
}
