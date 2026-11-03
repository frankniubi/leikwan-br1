//go:build windows

package main

// Windows has no SIGHUP, so there is no signal-based reload trigger there. The
// platform-neutral, local-only reload marker file (see reload.go) is the only
// trigger on Windows, and it is available on Unix too.

import "os"

func notifyReloadSignals(ch chan os.Signal) {
	_ = ch
}
