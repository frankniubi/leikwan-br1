//go:build !windows

package main

import (
	"testing"

	"golang.org/x/sys/unix"
)

func TestApplyProcessSecurityDisablesCoreDumps(t *testing.T) {
	if err := applyProcessSecurity(); err != nil {
		t.Fatal(err)
	}
	var limit unix.Rlimit
	if err := unix.Getrlimit(unix.RLIMIT_CORE, &limit); err != nil {
		t.Fatal(err)
	}
	if limit.Cur != 0 || limit.Max != 0 {
		t.Fatalf("core limit=%+v", limit)
	}
}

func TestClearSecretZerosTheOriginalBuffer(t *testing.T) {
	secret := []byte("provider-password-canary")
	locked := lockSecret(secret)
	clearSecret(secret, locked)
	assertZeroed(t, secret, "secret buffer")
}
