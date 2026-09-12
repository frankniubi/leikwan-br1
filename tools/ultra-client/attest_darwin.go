//go:build darwin

package main

import (
	"os"
	"strings"
)

// debuggerAttached reports whether the dynamic loader was told to load extra
// images. macOS does not expose TracerPid, and reading P_TRACED out of
// kinfo_proc depends on a private struct layout, so we deliberately do not
// guess there: a wrong answer would flag healthy users.
func debuggerAttached() bool {
	return instrumentationDetected()
}

// instrumentationDetected reports DYLD injection, the usual way a repackaged
// client hooks the runtime on macOS.
func instrumentationDetected() bool {
	for _, name := range []string{"DYLD_INSERT_LIBRARIES", "DYLD_FORCE_FLAT_NAMESPACE"} {
		if strings.TrimSpace(os.Getenv(name)) != "" {
			return true
		}
	}
	return false
}
