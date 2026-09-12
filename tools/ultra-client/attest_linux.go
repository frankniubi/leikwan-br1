//go:build linux

package main

import (
	"os"
	"strings"
)

// debuggerAttached reads TracerPid from /proc/self/status. A non-zero value
// means another process (a debugger or a ptrace-based tracer) is attached.
func debuggerAttached() bool {
	body, err := os.ReadFile("/proc/self/status")
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(body), "\n") {
		rest, found := strings.CutPrefix(line, "TracerPid:")
		if !found {
			continue
		}
		return strings.TrimSpace(rest) != "0"
	}
	return false
}

// instrumentationDetected reports LD_PRELOAD-style injection, which is how a
// repackaged client usually hooks the data path on Linux.
func instrumentationDetected() bool {
	for _, name := range []string{"LD_PRELOAD", "LD_AUDIT"} {
		if strings.TrimSpace(os.Getenv(name)) != "" {
			return true
		}
	}
	return false
}
