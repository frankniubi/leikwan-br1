//go:build !windows

package main

// SIGHUP is the conventional reload signal, and it is deliberately a reload
// rather than a termination: an operator or a package upgrade script can add a
// binding without tearing down live sessions. SIGINT/SIGTERM keep their
// existing meaning (main.go registers them separately).

import (
	"os"
	"os/signal"
	"syscall"
)

func notifyReloadSignals(ch chan os.Signal) {
	signal.Notify(ch, syscall.SIGHUP)
}
