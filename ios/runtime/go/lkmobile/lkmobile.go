// Package lkmobile is the gomobile-facing surface of the LeiKwan runtimes that
// run inside the iOS packet tunnel. It is bound into the same framework as
// sing-box's libbox (one Go runtime per process), so Swift sees it as the
// Lkmobile* symbols next to Libbox*.
//
// The build (ios/scripts/build-go-mobile.sh) copies this package to
// <sing-box>/leikwan/lkmobile and the ultra runtime sources to
// <sing-box>/leikwan/ultracore.
package lkmobile

import (
	"github.com/sagernet/sing-box/leikwan/ultracore"
)

// UltraRuntime is one running ultra forward runtime.
type UltraRuntime struct {
	runtime *ultracore.MobileRuntime
}

// StartUltra starts an ultra runtime from the same configuration JSON the
// Android client feeds the standalone binary on stdin. Leases are delivered
// afterwards over the control socket named in the configuration.
func StartUltra(configJSON string) (*UltraRuntime, error) {
	runtime, err := ultracore.StartMobileRuntime([]byte(configJSON))
	if err != nil {
		return nil, err
	}
	return &UltraRuntime{runtime: runtime}, nil
}

// Stop stops the runtime and waits (bounded) for its listeners to close.
func (u *UltraRuntime) Stop() {
	if u == nil || u.runtime == nil {
		return
	}
	u.runtime.Stop()
}

// Running reports whether the runtime is still serving.
func (u *UltraRuntime) Running() bool {
	return u != nil && u.runtime != nil && u.runtime.Running()
}

// LastError returns the reason the runtime stopped, or "" while it runs.
func (u *UltraRuntime) LastError() string {
	if u == nil || u.runtime == nil {
		return ""
	}
	if err := u.runtime.LastError(); err != nil {
		return err.Error()
	}
	return ""
}

// UltraAttestationJSON reports the runtime attestation of the hosting binary.
func UltraAttestationJSON() string {
	return ultracore.MobileRuntimeAttestationJSON()
}

// UltraVersion returns the runtime build fingerprint.
func UltraVersion() string {
	return ultracore.MobileRuntimeVersion()
}
