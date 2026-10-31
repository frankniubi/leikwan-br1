package main

import (
	"context"
	"crypto/rand"
	"fmt"
	"os"
	"runtime"
	"strings"
	"time"
)

// agentVersion is reported to the Panel as the client agent version and shown
// in LuCI. The build script overrides it with the packaged version.
var agentVersion = "development"

func readRandom(buf []byte) (int, error) { return rand.Read(buf) }

// reloadConfig re-reads UCI and adopts any change the operator made in LuCI
// without requiring a service restart.
func (a *Agent) reloadConfig() {
	next := loadConfig()
	a.mu.Lock()
	a.cfg = next
	a.mu.Unlock()
	a.panel.egress.setOverride(next.PanelEgressInterface)
	a.log.Info("ctl", "configuration reloaded")
	// The gateway option may have changed; the relay loop re-plans and only
	// restarts what its new configuration actually differs in.
	a.triggerGatewayReconcile()
}

// adoptCredentials persists a newly issued agent token and switches the running
// panel client over to it. The token is written 0600 and never returned to the
// caller.
func (a *Agent) adoptCredentials(token string, clientID int, clientName, email, method string) error {
	secrets := a.secrets
	secrets.AgentToken = strings.TrimSpace(token)
	secrets.ClientID = clientID
	secrets.ClientName = clientName
	secrets.DeviceID = a.deviceID
	secrets.DeviceName = a.deviceNm
	secrets.MasterURL = a.panel.endpointLabel()
	secrets.EnrolledAt = formatRFC3339(time.Now())
	secrets.EnrollMethod = method
	if email != "" {
		secrets.SignedInMail = email
	}
	if err := saveSecrets(a.cfg.DataDir, secrets); err != nil {
		return err
	}
	a.secrets = secrets
	a.panel.setToken(secrets.AgentToken, clientID)
	a.log.Info("ctl", "device enrolled as client %d (%s) via %s", clientID, clientName, method)
	a.triggerReconcile("enrolled")
	a.triggerGatewayReconcile()
	return nil
}

func (a *Agent) eventRuntimeExit(forwardID int, failures int) {
	// A restart is driven by the health loop; this only records the fact so an
	// operator reading the log sees the ordering of events.
	if failures > 0 {
		a.log.Debug("runtime", "forward %d recorded %d consecutive abnormal exits", forwardID, failures)
	}
}

// DiagnosticReport is the payload LuCI shows and the Panel can consume for a
// remote diagnostics request. It never contains credentials, endpoints or
// lease identifiers.
type DiagnosticReport struct {
	GeneratedAt   string            `json:"generated_at"`
	Version       string            `json:"version"`
	Architecture  string            `json:"architecture"`
	Kernel        string            `json:"kernel,omitempty"`
	Panel         string            `json:"panel"`
	PanelTag      string            `json:"panel_platform_tag"`
	Config        map[string]string `json:"effective_config"`
	RuntimeBinary bool              `json:"runtime_present"`
	Checks        []DiagnosticCheck `json:"checks"`
}

type DiagnosticCheck struct {
	Name   string `json:"name"`
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`
}

func (a *Agent) diagnose() DiagnosticReport {
	report := DiagnosticReport{
		GeneratedAt:   formatRFC3339(time.Now()),
		Version:       agentVersion,
		Architecture:  runtime.GOARCH,
		Panel:         a.panel.endpointLabel(),
		PanelTag:      a.panel.platform(),
		RuntimeBinary: fileExists(a.cfg.RuntimeBin),
		Config: map[string]string{
			"poll_interval":   itoa(a.cfg.PollIntervalSecs),
			"heartbeat":       itoa(a.cfg.HeartbeatIntervalSecs),
			"health_interval": itoa(a.cfg.HealthIntervalSecs),
			"max_candidates":  itoa(a.cfg.MaxCandidates),
		},
	}
	if raw, err := os.ReadFile("/proc/sys/kernel/osrelease"); err == nil {
		report.Kernel = strings.TrimSpace(string(raw))
	}
	add := func(name string, ok bool, detail string) {
		report.Checks = append(report.Checks, DiagnosticCheck{Name: name, OK: ok, Detail: detail})
	}
	add("runtime_binary", report.RuntimeBinary, a.cfg.RuntimeBin)
	add("run_directory", dirExists(a.cfg.RunDir), a.cfg.RunDir)
	add("data_directory", dirExists(a.cfg.DataDir), a.cfg.DataDir)

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := a.panel.Probe(ctx); err != nil {
		add("panel_reachable", false, redactSecrets(err.Error()))
	} else {
		add("panel_reachable", true, a.panel.endpointLabel())
	}
	if !a.enrolled() {
		add("enrolled", false, "no agent token; complete onboarding in LuCI")
	} else {
		add("enrolled", true, "client "+itoa(a.panel.clientIDValue()))
	}
	for _, view := range a.forwardViews() {
		add("forward_"+itoa(view.ForwardID), view.Status == "running" && view.Health != "unavailable",
			view.Status+" / "+view.Health)
	}
	if gateway := a.gatewayView(); gateway.NativeGranted {
		add("native_relay", gateway.Running, firstNonEmpty(gateway.LastError,
			fmt.Sprintf("carrier #%d %s scope=%s policy=%s socks 127.0.0.1:%d", gateway.CarrierID, gateway.Direction,
				gateway.RouteScope, gateway.RoutePolicy, gateway.SocksPort)))
		add("native_lease", gateway.LeaseExpiry != "" && gateway.LeaseError == "",
			firstNonEmpty(gateway.LeaseError, fmt.Sprintf("%d candidates, expires %s", gateway.Candidates, gateway.LeaseExpiry)))
	}
	return report
}

// submitDiagnostics answers a Panel diagnostics request. The response body is
// intentionally the same report LuCI renders, so an operator and the Panel see
// identical evidence.
func (a *Agent) submitDiagnostics(ctx context.Context, requestToken string) {
	report := a.diagnose()
	payload := map[string]any{
		"request_token": requestToken,
		"results":       report.Checks,
		"error":         "",
	}
	if err := a.panel.DiagnosticsResult(ctx, payload); err != nil {
		a.log.Warn("diagnostics", "could not submit requested diagnostics: %v", err)
		return
	}
	a.mu.Lock()
	a.diagToken = ""
	a.mu.Unlock()
	a.log.Info("diagnostics", "submitted diagnostics for panel request %s", shortRevision(requestToken))
}
