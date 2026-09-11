package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// The control socket is the only local interface. It is a newline-delimited
// JSON request/response protocol on a 0600 unix socket inside a 0700 directory,
// which makes it trivial to drive from LuCI's ucode rpcd script without
// pulling in an HTTP server, ubus bindings or cgo.
type CtlRequest struct {
	Cmd  string            `json:"cmd"`
	Args map[string]string `json:"args,omitempty"`
}

type CtlResponse struct {
	OK    bool   `json:"ok"`
	Error string `json:"error,omitempty"`
	Data  any    `json:"data,omitempty"`
}

func ctlSocketPath(runDir string) string { return filepath.Join(runDir, "ctl.sock") }

type ctlServer struct {
	agent  *Agent
	path   string
	ln     net.Listener
	secret string
}

// newCtlServer creates the socket and a per-boot shared secret. The secret is
// defence in depth: the socket is already root-only, but the token also blocks
// a stray process that somehow reaches the path from issuing privileged
// commands such as enrolment.
func newCtlServer(agent *Agent) (*ctlServer, error) {
	if err := ensurePrivateDir(agent.cfg.RunDir); err != nil {
		return nil, err
	}
	path := ctlSocketPath(agent.cfg.RunDir)
	if info, err := os.Lstat(path); err == nil {
		if info.Mode()&os.ModeSocket == 0 {
			return nil, errors.New("control socket path is not a socket")
		}
		if err := os.Remove(path); err != nil {
			return nil, err
		}
	}
	ln, err := net.Listen("unix", path)
	if err != nil {
		return nil, err
	}
	if err := os.Chmod(path, 0o600); err != nil {
		ln.Close()
		return nil, err
	}
	secret, err := randomToken()
	if err != nil {
		ln.Close()
		return nil, err
	}
	if err := writeFileAtomic(filepath.Join(agent.cfg.RunDir, "ctl.token"), []byte(secret+"\n"), 0o600); err != nil {
		ln.Close()
		return nil, err
	}
	return &ctlServer{agent: agent, path: path, ln: ln, secret: secret}, nil
}

func (s *ctlServer) Serve(ctx context.Context) {
	go func() {
		<-ctx.Done()
		_ = s.ln.Close()
	}()
	for {
		conn, err := s.ln.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			continue
		}
		go s.handle(conn)
	}
}

func (s *ctlServer) handle(conn net.Conn) {
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(30 * time.Second))
	reader := bufio.NewReaderSize(conn, 64*1024)
	line, err := reader.ReadBytes('\n')
	if err != nil {
		return
	}
	var request CtlRequest
	if err := json.Unmarshal(line, &request); err != nil {
		writeCtl(conn, CtlResponse{OK: false, Error: "bad_request"})
		return
	}
	reply := s.dispatch(request)
	writeCtl(conn, reply)
}

func writeCtl(conn net.Conn, response CtlResponse) {
	encoded, err := json.Marshal(response)
	if err != nil {
		return
	}
	_, _ = conn.Write(append(encoded, '\n'))
}

func (s *ctlServer) dispatch(request CtlRequest) CtlResponse {
	agent := s.agent
	// Commands that mutate control-plane identity require the shared secret.
	if requiresSecret(request.Cmd) {
		if request.Args["token"] != s.secret {
			return CtlResponse{OK: false, Error: "unauthorized"}
		}
	}
	switch request.Cmd {
	case "status":
		return CtlResponse{OK: true, Data: agent.snapshot()}
	case "forwards":
		return CtlResponse{OK: true, Data: agent.forwardViews()}
	case "logs":
		limit := atoiDefault(request.Args["limit"], 200)
		return CtlResponse{OK: true, Data: agent.log.Events(limit)}
	case "diagnose":
		return CtlResponse{OK: true, Data: agent.diagnose()}
	case "test_connection":
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		defer cancel()
		if err := agent.panel.Probe(ctx); err != nil {
			return CtlResponse{OK: false, Error: redactSecrets(err.Error())}
		}
		return CtlResponse{OK: true, Data: map[string]any{"panel": agent.panel.endpointLabel(), "platform": agent.panel.platform()}}
	case "enroll":
		return s.enroll(request.Args)
	case "pair":
		if _, err := agent.pairWithCode(request.Args["code"]); err != nil {
			return CtlResponse{OK: false, Error: redactSecrets(err.Error())}
		}
		return CtlResponse{OK: true, Data: map[string]any{"client_id": agent.panel.clientIDValue(), "client_name": agent.secrets.ClientName}}
	case "sign_out":
		agent.signOut()
		return CtlResponse{OK: true, Data: map[string]any{"signed_out": true}}
	case "forward_action":
		return s.forwardAction(request.Args)
	case "reload":
		agent.reloadConfig()
		agent.triggerReconcile("ctl_reload")
		return CtlResponse{OK: true, Data: agent.snapshot()}
	default:
		return CtlResponse{OK: false, Error: "unknown_command"}
	}
}

func requiresSecret(cmd string) bool {
	switch cmd {
	case "enroll", "pair", "sign_out", "forward_action", "reload":
		return true
	default:
		return false
	}
}

func (s *ctlServer) enroll(args map[string]string) CtlResponse {
	agent := s.agent
	email := strings.TrimSpace(args["email"])
	password := args["password"]
	if email == "" || password == "" {
		return CtlResponse{OK: false, Error: "credentials_required"}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	login, err := agent.panel.Login(ctx, email, password)
	if err != nil {
		return CtlResponse{OK: false, Error: redactSecrets(err.Error())}
	}
	if !login.OK || strings.TrimSpace(login.SessionToken) == "" {
		return CtlResponse{OK: false, Error: "login_rejected"}
	}
	if len(login.Clients) == 0 {
		return CtlResponse{OK: false, Error: "no_clients_available"}
	}
	targetID := atoiDefault(args["client_id"], login.Clients[0].ID)
	selection, err := agent.panel.SelectClient(ctx, login.SessionToken, targetID)
	if err != nil {
		return CtlResponse{OK: false, Error: redactSecrets(err.Error())}
	}
	if !selection.OK || strings.TrimSpace(selection.AgentToken) == "" {
		return CtlResponse{OK: false, Error: "client_selection_rejected"}
	}
	if err := agent.adoptCredentials(selection.AgentToken, selection.ClientID, selection.ClientName, email, "account"); err != nil {
		return CtlResponse{OK: false, Error: redactSecrets(err.Error())}
	}
	return CtlResponse{OK: true, Data: map[string]any{
		"client_id":   selection.ClientID,
		"client_name": selection.ClientName,
	}}
}

func (s *ctlServer) forwardAction(args map[string]string) CtlResponse {
	agent := s.agent
	forwardID := atoiDefault(args["forward_id"], 0)
	action := strings.ToLower(strings.TrimSpace(args["action"]))
	if forwardID <= 0 {
		return CtlResponse{OK: false, Error: "forward_id_required"}
	}
	agent.mu.Lock()
	state, known := agent.runtimes[forwardID]
	forward, desired := agent.forwards[forwardID]
	agent.mu.Unlock()

	switch action {
	case "start":
		if !desired || !known {
			if !desired {
				return CtlResponse{OK: false, Error: "forward_not_assigned"}
			}
			agent.triggerReconcile("manual_start")
			return CtlResponse{OK: true, Data: map[string]any{"forward_id": forwardID, "action": "start"}}
		}
		state.userStopped = false
		return CtlResponse{OK: true, Data: map[string]any{"forward_id": forwardID, "action": "start"}}
	case "stop":
		if !known {
			return CtlResponse{OK: false, Error: "forward_not_running"}
		}
		state.userStopped = true
		agent.mu.Lock()
		delete(agent.runtimes, forwardID)
		agent.mu.Unlock()
		agent.stopRuntime(state.proc, "user_stop")
		agent.store.DeleteApplied(forwardID)
		return CtlResponse{OK: true, Data: map[string]any{"forward_id": forwardID, "action": "stop"}}
	case "restart":
		if !desired {
			return CtlResponse{OK: false, Error: "forward_not_assigned"}
		}
		if known {
			agent.mu.Lock()
			delete(agent.runtimes, forwardID)
			agent.mu.Unlock()
			agent.stopRuntime(state.proc, "user_restart")
		}
		agent.triggerReconcile("manual_restart")
		return CtlResponse{OK: true, Data: map[string]any{"forward_id": forwardID, "action": "restart"}}
	default:
		_ = forward
		return CtlResponse{OK: false, Error: "unknown_action"}
	}
}

// ctlCall is the client side used by the "leikwand ctl" CLI.
func ctlCall(runDir string, request CtlRequest) (*CtlResponse, error) {
	path := ctlSocketPath(runDir)
	dialer := net.Dialer{Timeout: 5 * time.Second}
	conn, err := dialer.Dial("unix", path)
	if err != nil {
		return nil, err
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(90 * time.Second))
	encoded, err := json.Marshal(request)
	if err != nil {
		return nil, err
	}
	if _, err := conn.Write(append(encoded, '\n')); err != nil {
		return nil, err
	}
	line, err := bufio.NewReaderSize(conn, 4<<20).ReadBytes('\n')
	if err != nil {
		return nil, err
	}
	var response CtlResponse
	if err := json.Unmarshal(line, &response); err != nil {
		return nil, err
	}
	return &response, nil
}

// ctlToken reads the per-boot shared secret so the CLI can issue privileged
// commands without the operator ever handling it.
func ctlToken(runDir string) string {
	raw, err := os.ReadFile(filepath.Join(runDir, "ctl.token"))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(raw))
}

func randomToken() (string, error) {
	buf := make([]byte, 32)
	if _, err := readRandom(buf); err != nil {
		return "", err
	}
	return fmt.Sprintf("%x", buf), nil
}
