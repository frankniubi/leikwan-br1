package main

import (
	"context"
	"os"
	"runtime"
	"testing"
	"time"
)

func TestParentWatchCancelsWhenHostIsGone(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("windows hosts use a kill-on-close job object")
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan struct{})
	go func() { watchParentProcess(ctx, cancel, os.Getppid()+100000); close(done) }()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("watcher did not stop for a foreign parent pid")
	}
	if ctx.Err() == nil {
		t.Fatal("runtime context was not cancelled")
	}
}

func TestParentWatchKeepsRunningWithLiveHost(t *testing.T) {
	previous := parentWatchInterval
	parentWatchInterval = 10 * time.Millisecond
	defer func() { parentWatchInterval = previous }()
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { watchParentProcess(ctx, cancel, os.Getppid()); close(done) }()
	time.Sleep(60 * time.Millisecond)
	if ctx.Err() != nil {
		t.Fatal("watcher cancelled while the host is alive")
	}
	cancel()
	<-done
}

func TestParentWatchDisabledWithoutPid(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	watchParentProcess(ctx, cancel, 0)
	if ctx.Err() != nil {
		t.Fatal("watcher without a parent pid must not cancel")
	}
}
