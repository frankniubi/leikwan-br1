package main

// Command uot-sidecar is the operator-owned external UoT sidecar, written in Go
// for hosts without a Python runtime (and for local Windows/macOS testing).
//
//	leikwan-external-uot --config /etc/leikwan-external-uot.json
//
// It speaks exactly the UOT2 contract of sidecar-agent/leikwan_external_uot.py
// and accepts the same JSON configuration, so it can replace the Python entry
// point without touching the panel, the runtime or the systemd unit name.

import (
	"bytes"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
)

// sidecarBuildVersion is stamped by scripts/build-uot-sidecar.ps1.
var sidecarBuildVersion = "dev"

func main() {
	configPath := flag.String("config", "", "path to the sidecar JSON configuration")
	logLevel := flag.String("log-level", "INFO", "DEBUG, INFO, WARNING or ERROR")
	showVersion := flag.Bool("version", false, "print the build version and exit")
	printToken := flag.Bool("print-token", false, "print the HMAC token for --secret/--forward-id/--target and exit")
	secret := flag.String("secret", "", "forward HMAC secret (with --print-token)")
	secretFile := flag.String("secret-file", "", "read the forward HMAC secret from this file (with --print-token)")
	forwardID := flag.Int64("forward-id", 0, "forward id (with --print-token)")
	target := flag.String("target", "", "target host:port (with --print-token)")
	checkConfig := flag.Bool("check-config", false, "validate --config, print the effective sidecar summary as JSON and exit")
	reloadFile := flag.String("reload-file", "", "local reload marker file; creating it reloads the configuration (default <config>.reload, \"-\" disables)")
	flag.Parse()

	if *showVersion {
		fmt.Println(sidecarBuildVersion)
		return
	}
	if *printToken {
		secretBytes, secretErr := resolveSecret(*secret, *secretFile)
		if secretErr != nil {
			fmt.Fprintln(os.Stderr, secretErr)
			os.Exit(2)
		}
		if len(secretBytes) == 0 || *forwardID == 0 || strings.TrimSpace(*target) == "" {
			fmt.Fprintln(os.Stderr, "--print-token requires --secret, --forward-id and --target")
			os.Exit(2)
		}
		token, err := makeHMACToken(secretBytes, *forwardID, *target)
		if err != nil {
			fmt.Fprintln(os.Stderr, "token computation failed:", err)
			os.Exit(2)
		}
		fmt.Println(token)
		return
	}
	if *configPath == "" {
		fmt.Fprintln(os.Stderr, "--config is required")
		flag.Usage()
		os.Exit(2)
	}

	// The marker file is the platform-neutral, local-only reload trigger; "-"
	// disables it. Windows has no SIGHUP, so it is the only trigger there.
	markerPath := *reloadFile
	if markerPath == "" {
		markerPath = *configPath + ".reload"
	}
	if markerPath == "-" {
		markerPath = ""
	}

	logger := newSidecarLogger(*logLevel, os.Stderr)
	config, err := loadConfig(*configPath)
	if err != nil {
		logger.logf(logError, "external UoT sidecar configuration rejected: %v", err)
		os.Exit(2)
	}
	if *checkConfig {
		fmt.Println(strings.TrimSpace(string(summarizeConfig(config))))
		return
	}
	store := newConfigStore(*configPath, &config)
	server := newSidecarServer(store, logger)
	if err := server.start(); err != nil {
		logger.logf(logError, "external UoT sidecar failed to listen: %v", err)
		os.Exit(1)
	}
	logger.infof("external UoT listening on %s", server.address())
	logger.infof("external UoT sidecar ready build=%s forwards=%d queue_packets=%d keepalive_seconds=%s idle_timeout_seconds=%s session_grace_seconds=%s",
		sidecarBuildVersion, len(config.forwards), config.queuePackets,
		formatSeconds(config.keepaliveSeconds), formatSeconds(config.idleTimeoutSeconds), formatSeconds(config.sessionGraceSeconds))
	if markerPath == "" {
		logger.infof("external UoT reload triggers: SIGHUP (unix), local reload marker disabled")
	} else {
		logger.infof("external UoT reload triggers: SIGHUP (unix), local reload marker path=%s", markerPath)
	}

	// Reload is local-only by construction: a signal the operator sends, or a
	// marker file only a local user with write access to the configuration
	// directory can create. No network path reaches either trigger.
	stopReloads := make(chan struct{})
	go runReloadMarkerLoop(stopReloads, markerPath, reloadMarkerPollInterval, logger, func() { applyReload(store, logger) })

	// SIGINT/SIGTERM keep exactly their previous meaning: log and shut down.
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	reloadSignals := make(chan os.Signal, 1)
	notifyReloadSignals(reloadSignals)
	for {
		select {
		case <-signals:
			logger.infof("external UoT sidecar stopping")
			close(stopReloads)
			server.close()
			return
		case <-reloadSignals:
			applyReload(store, logger)
		}
	}
}

func formatSeconds(value float64) string {
	return strconv.FormatFloat(value, 'f', -1, 64)
}

// resolveSecret returns the forward HMAC secret from --secret or from the
// first line of --secret-file.  Secrets are never trimmed beyond the file's
// trailing newline, matching the raw string semantics of the JSON config.
func resolveSecret(inline string, path string) ([]byte, error) {
	if inline != "" && path != "" {
		return nil, errors.New("--secret and --secret-file are mutually exclusive")
	}
	if path == "" {
		return []byte(inline), nil
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("cannot read --secret-file: %w", err)
	}
	return bytes.TrimRight(raw, "\r\n \t"), nil
}
