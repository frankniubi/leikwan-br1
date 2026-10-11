package main

import (
	"context"
	"log"
	"os"
	"runtime"
	"time"
)

var parentWatchInterval = 2 * time.Second

// watchParentProcess stops the runtime once the host that started it is gone.
// A host that crashes (or is SIGKILLed by launchd) cannot stop its children,
// and an orphaned runtime keeps the forward's port bound, so the restarted host
// then fails its own bind. POSIX re-parents an orphan, so a changed parent PID
// means the host died. Windows hosts already tie runtimes to a kill-on-close
// job object and never re-parent, so there is nothing to watch there.
func watchParentProcess(ctx context.Context, cancel context.CancelFunc, parentPID int) {
	if parentPID <= 1 || runtime.GOOS == "windows" {
		return
	}
	ticker := time.NewTicker(parentWatchInterval)
	defer ticker.Stop()
	for {
		if os.Getppid() != parentPID {
			log.Printf("runtime stopped: host process %d is gone", parentPID)
			cancel()
			return
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}
