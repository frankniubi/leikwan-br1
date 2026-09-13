package main

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"path/filepath"
	"sync"
)

// RuntimeAttestation describes the identity of the running helper binary.
//
// The panel cannot stop a determined user from unpacking an obfuscated runtime,
// but it can tell an unmodified, released runtime from a repackaged one. Every
// field here is compared against the release manifest of the artifact the panel
// served; nothing is trusted as proof on its own.
//
// released_sha256 is only comparable because our garble builds are reproducible
// (scripts/build-ultra-*.ps1 verify two consecutive builds hash equal), so a
// given version always ships the same bytes.
type RuntimeAttestation struct {
	// Fingerprint is the build identity injected at link time
	// (-X main.runtimeBuildVersion). "development" for a local build.
	Fingerprint string `json:"fingerprint"`
	// SelfSHA256 is the SHA-256 of the running executable.
	SelfSHA256 string `json:"self_sha256,omitempty"`
	// Debugger reports an attached debugger or an injected dynamic loader.
	Debugger bool `json:"debugger,omitempty"`
	// Instrumented reports environment-based instrumentation of this process.
	Instrumented bool `json:"instrumented,omitempty"`
}

var (
	selfHashOnce sync.Once
	selfHash     string
)

// selfExecutableHash hashes the binary this process is running from, following
// symlinks so a relocated helper still reports the bytes that were loaded.
func selfExecutableHash() string {
	selfHashOnce.Do(func() {
		path, err := os.Executable()
		if err != nil {
			return
		}
		if resolved, resolveErr := filepath.EvalSymlinks(path); resolveErr == nil {
			path = resolved
		}
		file, err := os.Open(path)
		if err != nil {
			return
		}
		defer file.Close()
		digest := sha256.New()
		if _, err := io.Copy(digest, file); err != nil {
			return
		}
		selfHash = hex.EncodeToString(digest.Sum(nil))
	})
	return selfHash
}

// runtimeAttestation snapshots the identity of this process. It never blocks
// the data path on failure: an unreadable executable simply omits the hash.
func runtimeAttestation() RuntimeAttestation {
	fingerprint := runtimeBuildVersion
	if fingerprint == "" {
		fingerprint = "development"
	}
	return RuntimeAttestation{
		Fingerprint:  fingerprint,
		SelfSHA256:   selfExecutableHash(),
		Debugger:     debuggerAttached(),
		Instrumented: instrumentationDetected(),
	}
}
