package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

const usageText = `leikwand — Leikwan 会聚 agent for OpenWrt

Usage:
  leikwand run                     run the supervisor in the foreground (procd)
  leikwand ctl <command> [k=v...]  talk to the running supervisor
  leikwand version                 print the agent version
  leikwand selftest                verify the local installation

Control commands:
  status                 full status snapshot
  forwards               per-forward view
  logs [limit=N]         recent events
  diagnose               run an end-to-end diagnostic
  test_connection        verify the panel is reachable
  register client_id=N agent_token=...
                         adopt the panel-issued credentials directly
  sign_out               remove credentials and stop all runtimes
  reload                 re-read /etc/config/leikwan
`

func main() {
	args := os.Args[1:]
	if len(args) == 0 {
		fmt.Fprint(os.Stderr, usageText)
		os.Exit(2)
	}
	switch args[0] {
	case "run":
		os.Exit(runAgent())
	case "ctl":
		os.Exit(runCtl(args[1:]))
	case "version", "--version", "-v":
		fmt.Println(agentVersion)
	case "selftest":
		os.Exit(selfTest())
	case "help", "--help", "-h":
		fmt.Print(usageText)
	default:
		fmt.Fprintf(os.Stderr, "unknown command %q\n\n%s", args[0], usageText)
		os.Exit(2)
	}
}

func runAgent() int {
	cfg := loadConfig()
	if !cfg.Enabled {
		fmt.Fprintln(os.Stderr, "leikwand: disabled by /etc/config/leikwan (option enabled '0')")
		return 0
	}
	log := newLogger(cfg.LogLevel)
	store, err := newStore(cfg.DataDir)
	if err != nil {
		log.Error("agent", "cannot prepare state directory: %v", err)
		return 1
	}
	secrets := loadSecrets(cfg.DataDir)
	agent := newAgent(cfg, store, secrets, log)

	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()

	server, err := newCtlServer(agent)
	if err != nil {
		log.Error("agent", "cannot create control socket: %v", err)
		return 1
	}
	go server.Serve(ctx)

	log.Info("agent", "leikwand %s starting (arch=%s data=%s run=%s)",
		agentVersion, goArch(), cfg.DataDir, cfg.RunDir)
	if err := agent.Run(ctx); err != nil {
		log.Error("agent", "fatal: %v", err)
		return 1
	}
	log.Info("agent", "leikwand stopped")
	return 0
}

func runCtl(args []string) int {
	if len(args) == 0 {
		fmt.Fprint(os.Stderr, usageText)
		return 2
	}
	cfg := loadConfig()
	request := CtlRequest{Cmd: args[0], Args: map[string]string{}}
	for _, arg := range args[1:] {
		key, value, found := strings.Cut(arg, "=")
		if !found {
			request.Args[key] = "1"
			continue
		}
		request.Args[key] = value
	}
	if requiresSecret(request.Cmd) {
		request.Args["token"] = ctlToken(cfg.RunDir)
	}
	response, err := ctlCall(cfg.RunDir, request)
	if err != nil {
		fmt.Fprintf(os.Stderr, "{\"ok\":false,\"error\":%q}\n", redactSecrets(err.Error()))
		return 1
	}
	encoded, err := json.Marshal(response)
	if err != nil {
		fmt.Fprintf(os.Stderr, "{\"ok\":false,\"error\":\"encode_failed\"}\n")
		return 1
	}
	fmt.Println(string(encoded))
	if !response.OK {
		return 1
	}
	return 0
}

// selfTest verifies the parts of the installation a packaging bug would break.
// It is safe to run before enrolment and never touches the network.
func selfTest() int {
	cfg := loadConfig()
	failures := 0
	check := func(name string, ok bool, detail string) {
		status := "ok"
		if !ok {
			status = "FAIL"
			failures++
		}
		fmt.Printf("%-24s %-5s %s\n", name, status, detail)
	}
	check("config_parse", true, fmt.Sprintf("panel=%s", cfg.PanelURL))
	check("runtime_binary", fileExists(cfg.RuntimeBin), cfg.RuntimeBin)
	check("data_dir", ensurePrivateDir(cfg.DataDir) == nil, cfg.DataDir)
	check("run_dir", ensurePrivateDir(cfg.RunDir) == nil, cfg.RunDir)
	check("digest_contract", targetDigest(" example.com:443 ") == targetDigest("example.com:443"),
		"target digest trims surrounding whitespace")
	check("clock", time.Since(time.Now().Add(-time.Second)) < 5*time.Second, time.Now().Format(time.RFC3339))
	if failures > 0 {
		fmt.Printf("\n%d check(s) failed\n", failures)
		return 1
	}
	fmt.Println("\nall checks passed")
	return 0
}

func goArch() string {
	return runtimeArch()
}
