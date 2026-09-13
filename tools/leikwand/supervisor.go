package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"
)

// runtimeProcess owns one ultra-client child. The daemon deliberately does not
// use a process group kill for normal stops: the runtime handles SIGTERM by
// closing listeners cleanly, which keeps the Panel's byte accounting accurate.
type runtimeProcess struct {
	forwardID int
	native    bool
	paths     runtimePaths

	mu        sync.Mutex
	cmd       *exec.Cmd
	pid       int
	startedAt time.Time
	stoppedAt time.Time
	lastErr   string
	failures  int
	stopping  bool
	exitSeen  bool
}

func (p *runtimeProcess) alive() bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.cmd != nil && p.cmd.Process != nil && !p.exitSeen && !p.stopping
}

func (p *runtimeProcess) uptime() time.Duration {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.startedAt.IsZero() {
		return 0
	}
	return time.Since(p.startedAt)
}

func (p *runtimeProcess) snapshot() (pid int, startedAt time.Time, failures int, lastErr string, running bool) {
	p.mu.Lock()
	defer p.mu.Unlock()
	running = p.cmd != nil && p.cmd.Process != nil && !p.exitSeen && !p.stopping
	return p.pid, p.startedAt, p.failures, p.lastErr, running
}

// startRuntime writes the runtime config and launches ultra-client. The caller
// must have already validated the forward contract.
func (a *Agent) startRuntime(ctx context.Context, forward ForwardConfig, native bool, policy *UltraPolicy, routeScope, routePolicy string) (*runtimeProcess, error) {
	if !fileExists(a.cfg.RuntimeBin) {
		return nil, fmt.Errorf("ultra runtime %s is not installed", a.cfg.RuntimeBin)
	}
	paths := pathsForForward(a.cfg.RunDir, forward.ForwardID)
	cleanupRuntimeDir(paths)

	var runtime RuntimeConfig
	if native {
		runtime = buildNativeRuntimeConfig(a.cfg, forward, paths, policy, routeScope, routePolicy)
	} else {
		runtime = buildRuntimeConfig(a.cfg, forward, paths, policy)
	}
	if !native && (forward.ListenPort < 1 || forward.ListenPort > 65535) {
		return nil, fmt.Errorf("forward %d has an invalid listen port", forward.ForwardID)
	}
	if err := writeRuntimeConfig(paths, runtime); err != nil {
		return nil, err
	}

	cmd := exec.Command(a.cfg.RuntimeBin, "--config", paths.configPath)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stdout
	cmd.Env = append(os.Environ(), "LEIKWAN_OPENWRT_FORWARD_ID="+itoa(forward.ForwardID))
	// A private session keeps a stray Ctrl-C on the console from tearing down
	// the supervised children.
	cmd.SysProcAttr = detachedProcAttr()

	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("start ultra runtime: %w", err)
	}
	proc := &runtimeProcess{
		forwardID: forward.ForwardID,
		native:    native,
		paths:     paths,
		cmd:       cmd,
		pid:       cmd.Process.Pid,
		startedAt: time.Now(),
	}
	go a.watchRuntime(ctx, proc)
	a.log.Info("runtime", "forward %d runtime started pid=%d native=%v port=%d", forward.ForwardID, proc.pid, native, forward.ListenPort)
	return proc, nil
}

// watchRuntime records an unexpected exit so the health loop can restart it,
// and clears the record when the stop was intentional.
func (a *Agent) watchRuntime(ctx context.Context, proc *runtimeProcess) {
	err := proc.cmd.Wait()
	proc.mu.Lock()
	proc.exitSeen = true
	intentional := proc.stopping
	proc.stoppedAt = time.Now()
	if err != nil && !intentional {
		proc.failures++
		proc.lastErr = err.Error()
	}
	failures := proc.failures
	proc.mu.Unlock()
	if intentional {
		a.log.Debug("runtime", "forward %d runtime stopped", proc.forwardID)
		return
	}
	if err != nil {
		a.log.Warn("runtime", "forward %d runtime exited unexpectedly: %v", proc.forwardID, err)
	} else {
		a.log.Warn("runtime", "forward %d runtime exited with status 0", proc.forwardID)
	}
	a.eventRuntimeExit(proc.forwardID, failures)
}

// stopRuntime asks the runtime to exit and waits, escalating to SIGKILL only
// when the process ignores SIGTERM past the grace window.
func (a *Agent) stopRuntime(proc *runtimeProcess, reason string) {
	if proc == nil {
		return
	}
	proc.mu.Lock()
	if proc.cmd == nil || proc.cmd.Process == nil || proc.exitSeen {
		proc.stopping = true
		proc.mu.Unlock()
		return
	}
	proc.stopping = true
	cmd := proc.cmd
	proc.mu.Unlock()

	a.log.Debug("runtime", "forward %d stopping runtime: %s", proc.forwardID, reason)
	_ = cmd.Process.Signal(syscall.SIGTERM)
	done := make(chan struct{})
	go func() {
		_ = cmd.Wait()
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(8 * time.Second):
		a.log.Warn("runtime", "forward %d ignored SIGTERM, sending SIGKILL", proc.forwardID)
		_ = cmd.Process.Kill()
		select {
		case <-done:
		case <-time.After(3 * time.Second):
		}
	}
	cleanupRuntimeDir(proc.paths)
}

// waitForListenPort blocks until the runtime is actually accepting connections.
// A runtime that starts but never listens is worse than one that fails fast,
// so this is part of the apply transaction rather than a background check.
func waitForListenPort(ctx context.Context, port int, timeout time.Duration) error {
	if port <= 0 {
		return nil
	}
	address := net.JoinHostPort("127.0.0.1", itoa(port))
	deadline := time.Now().Add(timeout)
	for {
		conn, err := net.DialTimeout("tcp", address, 500*time.Millisecond)
		if err == nil {
			conn.Close()
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("port %d did not become ready", port)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(200 * time.Millisecond):
		}
	}
}

func portIsListening(port int) bool {
	if port <= 0 {
		return false
	}
	conn, err := net.DialTimeout("tcp", net.JoinHostPort("127.0.0.1", itoa(port)), 400*time.Millisecond)
	if err != nil {
		return false
	}
	conn.Close()
	return true
}

// portOwnerKind classifies who holds a listen port. Only a stale runtime from
// this agent is reclaimed; a foreign process is reported and never killed.
func portOwnerKind(port int) string {
	if !portIsListening(port) {
		return "free"
	}
	owners := runtimeExecutableNames()
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return "unknown"
	}
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		pid := entry.Name()
		if pid == "" || pid[0] < '0' || pid[0] > '9' {
			continue
		}
		exe, err := os.Readlink(filepath.Join("/proc", pid, "exe"))
		if err != nil {
			continue
		}
		base := filepath.Base(exe)
		if !owners[base] {
			continue
		}
		if processListensOn(pid, port) {
			return "managed"
		}
	}
	return "foreign"
}

func runtimeExecutableNames() map[string]bool {
	return map[string]bool{
		"ultra-client":       true,
		"ultra-client-arm64": true,
		"leikwan-ultra":      true,
		"leikwand":           true,
	}
}

func processListensOn(pid string, port int) bool {
	fdDir := filepath.Join("/proc", pid, "fd")
	entries, err := os.ReadDir(fdDir)
	if err != nil {
		return false
	}
	want := fmt.Sprintf("%04X", port)
	for _, entry := range entries {
		target, err := os.Readlink(filepath.Join(fdDir, entry.Name()))
		if err != nil {
			continue
		}
		if !strings.HasPrefix(target, "socket:[") {
			continue
		}
		inode := strings.TrimSuffix(strings.TrimPrefix(target, "socket:["), "]")
		if socketInodeListensOn(inode, want) {
			return true
		}
	}
	return false
}

// socketInodeListensOn parses /proc/net/tcp{,6} looking for a LISTEN socket in
// state 0A with the requested local port and the matching inode.
func socketInodeListensOn(inode, hexPort string) bool {
	for _, table := range []string{"/proc/net/tcp", "/proc/net/tcp6"} {
		raw, err := os.ReadFile(table)
		if err != nil {
			continue
		}
		for index, line := range strings.Split(string(raw), "\n") {
			if index == 0 || strings.TrimSpace(line) == "" {
				continue
			}
			fields := strings.Fields(line)
			if len(fields) < 10 {
				continue
			}
			if fields[3] != "0A" || fields[9] != inode {
				continue
			}
			local := fields[1]
			if idx := strings.LastIndex(local, ":"); idx >= 0 && strings.EqualFold(local[idx+1:], hexPort) {
				return true
			}
		}
	}
	return false
}

// reclaimManagedPort kills a leftover runtime from a previous daemon instance
// that is still holding the port. Foreign owners are never touched.
func reclaimManagedPort(port int) error {
	if portOwnerKind(port) != "managed" {
		return errors.New("port is not owned by a managed runtime")
	}
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return err
	}
	for _, entry := range entries {
		pid := entry.Name()
		if pid == "" || pid[0] < '0' || pid[0] > '9' {
			continue
		}
		exe, err := os.Readlink(filepath.Join("/proc", pid, "exe"))
		if err != nil || !runtimeExecutableNames()[filepath.Base(exe)] {
			continue
		}
		if !processListensOn(pid, port) {
			continue
		}
		if process, err := os.FindProcess(atoiDefault(pid, 0)); err == nil {
			_ = process.Signal(syscall.SIGTERM)
		}
	}
	return nil
}
