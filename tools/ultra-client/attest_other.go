//go:build !linux && !windows && !darwin

package main

// debuggerAttached is a no-op on platforms without a supported check.
func debuggerAttached() bool { return false }

// instrumentationDetected is a no-op on platforms without a supported check.
func instrumentationDetected() bool { return false }
