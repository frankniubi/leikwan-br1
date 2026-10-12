//go:build ios

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"sync"
	"time"
)

// The iOS packet tunnel cannot spawn the runtime as a child process, so it
// hosts it in-process: the iOS build copies this package into the sing-box
// mobile framework (see ios/scripts/build-go-mobile.sh) and drives it through
// MobileRuntime. The configuration JSON and the control-socket protocol are
// the same ones the Android client uses with the standalone binary.

// mobileStartupGrace is how long StartMobileRuntime waits for an immediate
// failure (control socket bind) so the caller gets it synchronously.
const mobileStartupGrace = 300 * time.Millisecond

const mobileStopTimeout = 3 * time.Second

type MobileRuntime struct {
	cancel context.CancelFunc
	done   chan struct{}

	mu  sync.Mutex
	err error
}

func StartMobileRuntime(configJSON []byte) (*MobileRuntime, error) {
	config, err := loadConfig("", true, bytes.NewReader(configJSON))
	if err != nil {
		return nil, errors.New("runtime configuration unavailable")
	}
	// The host is this process; there is no parent to watch.
	config.ParentPID = 0
	config, err = normalizeRuntimeConfig(config)
	if err != nil {
		return nil, err
	}
	rootContext, cancelRoot := context.WithCancel(context.Background())
	runtime := &MobileRuntime{cancel: cancelRoot, done: make(chan struct{})}
	go func() {
		defer close(runtime.done)
		runtime.setErr(serveRuntime(rootContext, cancelRoot, config))
	}()
	select {
	case <-runtime.done:
		cancelRoot()
		if err := runtime.LastError(); err != nil {
			return nil, err
		}
		return nil, errors.New("runtime stopped during startup")
	case <-time.After(mobileStartupGrace):
		return runtime, nil
	}
}

func (runtime *MobileRuntime) setErr(err error) {
	runtime.mu.Lock()
	runtime.err = err
	runtime.mu.Unlock()
}

func (runtime *MobileRuntime) LastError() error {
	runtime.mu.Lock()
	defer runtime.mu.Unlock()
	return runtime.err
}

func (runtime *MobileRuntime) Running() bool {
	select {
	case <-runtime.done:
		return false
	default:
		return true
	}
}

// Stop cancels the runtime and waits (bounded) for its listeners to close.
func (runtime *MobileRuntime) Stop() {
	runtime.cancel()
	select {
	case <-runtime.done:
	case <-time.After(mobileStopTimeout):
	}
}

// MobileRuntimeAttestationJSON reports the identity of the hosting binary in
// the same shape the standalone runtime reports it.
func MobileRuntimeAttestationJSON() string {
	encoded, err := json.Marshal(runtimeAttestation())
	if err != nil {
		return "{}"
	}
	return string(encoded)
}

func MobileRuntimeVersion() string {
	if runtimeBuildVersion == "" {
		return "development"
	}
	return runtimeBuildVersion
}
