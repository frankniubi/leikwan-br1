// Command linux-arm64-installer is the single-file Linux arm64 client-agent
// installer. The build script populates payload/ before compiling this file.
package main

import (
	"embed"
	"fmt"
	"io/fs"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// payload contains the installer and every native/helper artifact required by
// install-client-agent.sh. It is intentionally served only over loopback.
//
//go:embed payload/**
var payload embed.FS

const installerName = "install-client-agent.sh"

func usage() {
	fmt.Fprintln(os.Stderr, "Leikwan Linux arm64 client-agent installer")
	fmt.Fprintln(os.Stderr, "Usage: install-client-agent-linux-arm64 --master <url> --client-id <id> --agent-token <token> [options]")
	fmt.Fprintln(os.Stderr, "The installer contains the arm64 runtime bundle and keeps the configured public artifact base for future updates.")
}

func run(args []string) int {
	if runtime.GOOS != "linux" || runtime.GOARCH != "arm64" {
		fmt.Fprintf(os.Stderr, "unsupported runtime: %s/%s; this installer is Linux arm64 only\n", runtime.GOOS, runtime.GOARCH)
		return 2
	}
	for _, arg := range args {
		if arg == "--help" || arg == "-h" {
			usage()
			return 0
		}
		if arg == "--self-test" {
			return selfTest()
		}
	}

	if !hasFlag(args, "--master") {
		args = append([]string{"--master", "https://v3.leikwanhost.com"}, args...)
	}
	installer, err := fs.ReadFile(payload, "payload/"+installerName)
	if err != nil {
		fmt.Fprintf(os.Stderr, "embedded installer is unavailable: %v\n", err)
		return 1
	}
	tempDir, err := os.MkdirTemp("", "leikwan-linux-arm64-installer-")
	if err != nil {
		fmt.Fprintf(os.Stderr, "create temporary installer directory: %v\n", err)
		return 1
	}
	defer os.RemoveAll(tempDir)
	installerPath := filepath.Join(tempDir, installerName)
	if err := os.WriteFile(installerPath, installer, 0o700); err != nil {
		fmt.Fprintf(os.Stderr, "write temporary installer: %v\n", err)
		return 1
	}

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		fmt.Fprintf(os.Stderr, "start embedded artifact server: %v\n", err)
		return 1
	}
	server := &http.Server{
		Handler:           http.FileServer(http.FS(bundleFS())),
		ReadHeaderTimeout: 10 * time.Second,
	}
	serveErr := make(chan error, 1)
	go func() { serveErr <- server.Serve(listener) }()
	defer func() {
		_ = server.Close()
		select {
		case <-serveErr:
		case <-time.After(time.Second):
		}
	}()

	// The embedded server must win over any caller-provided --download-base,
	// while --artifact-base remains untouched and is persisted for later updates.
	args = append(append([]string{}, args...), "--download-base", "http://"+listener.Addr().String())
	cmd := exec.Command("/bin/bash", append([]string{installerPath}, args...)...)
	cmd.Stdin = os.Stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	cmd.Env = withLocalNoProxy(os.Environ())
	if err := cmd.Run(); err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			return exitErr.ExitCode()
		}
		fmt.Fprintf(os.Stderr, "run embedded installer: %v\n", err)
		return 1
	}
	return 0
}

func selfTest() int {
	required := []string{
		"payload/install-client-agent.sh",
		"payload/artifacts/versions.json",
		"payload/artifacts/client-agent.py",
		"payload/artifacts/leikwan-lease-seal.py",
		"payload/artifacts/leikwan-linux.sh",
		"payload/artifacts/install-tcp-accel.sh",
		"payload/artifacts/leikwan-tcp-accel.py",
		"payload/artifacts/install-forward-runtime.py",
		"payload/artifacts/ultra/ultra-client_linux_arm64",
		"payload/artifacts/wstunnel/leikwan-bond_linux_arm64",
		"payload/artifacts/wstunnel/leikwan-ingress_linux_arm64",
		"payload/artifacts/wstunnel/leikwan-uot-bond_linux_arm64",
		"payload/artifacts/wstunnel/wstunnel_linux_arm64",
	}
	for _, path := range required {
		data, err := fs.ReadFile(payload, path)
		if err != nil || len(data) == 0 {
			fmt.Fprintf(os.Stderr, "embedded payload check failed: %s: %v\n", path, err)
			return 1
		}
	}
	fmt.Printf("embedded arm64 payload ok (%d files checked)\n", len(required))
	return 0
}

func hasFlag(args []string, name string) bool {
	for index, arg := range args {
		if arg == name || strings.HasPrefix(arg, name+"=") {
			return true
		}
		if arg == "--master" && index+1 < len(args) {
			return true
		}
	}
	return false
}

func bundleFS() fs.FS {
	bundle, err := fs.Sub(payload, "payload")
	if err != nil {
		panic(err)
	}
	return bundle
}

func withLocalNoProxy(env []string) []string {
	result := append([]string(nil), env...)
	for _, key := range []string{"NO_PROXY", "no_proxy"} {
		found := false
		for index, item := range result {
			if strings.HasPrefix(item, key+"=") {
				value := strings.TrimPrefix(item, key+"=")
				if !strings.Contains(value, "127.0.0.1") {
					value += ",127.0.0.1"
				}
				if !strings.Contains(value, "localhost") {
					value += ",localhost"
				}
				result[index] = key + "=" + value
				found = true
				break
			}
		}
		if !found {
			result = append(result, key+"=127.0.0.1,localhost")
		}
	}
	return result
}

func main() {
	// Keep an explicit reference in the source to the public helper contract;
	// this also makes malformed bundle generation fail loudly during review.
	if strings.TrimSpace(installerName) == "" {
		panic("empty installer name")
	}
	os.Exit(run(os.Args[1:]))
}
