package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Gateway mode turns the router into a transparent egress for the whole LAN.
//
// Data path: LAN client -> router (default gateway) -> sing-box TUN inbound
// -> SOCKS5 facade exposed by a native ultra-client -> Panel-selected relay.
//
// sing-box owns auto_route (its own nftables/route installation), so this
// package never writes firewall rules by hand. That keeps one authority for
// routing and avoids the classic double-redirect mistakes.
const (
	gatewayTunName     = "lkwtun0"
	gatewaySocksPort   = 2080
	gatewayInterface   = "lkwgw0"
	gatewayConfigName  = "sing-box.json"
	gatewayCNRuleSet   = "geosite-cn"
	gatewayCNIPRuleSet = "geoip-cn"
)

type GatewayView struct {
	Enabled     bool   `json:"enabled"`
	Running     bool   `json:"running"`
	CarrierID   int    `json:"carrier_forward_id"`
	RouteScope  string `json:"route_scope"`
	RoutePolicy string `json:"route_policy"`
	TunAddress  string `json:"tun_address,omitempty"`
	SocksPort   int    `json:"socks_port"`
	BypassCN    bool   `json:"bypass_cn"`
	LastError   string `json:"last_error,omitempty"`
	SingBoxVer  string `json:"sing_box_version,omitempty"`
}

type gatewayManager struct {
	agent *Agent

	mu        sync.Mutex
	proc      *runtimeProcess
	carrier   ForwardConfig
	leaseOK   bool
	lastErr   string
	configGen string
	startedAt time.Time
	status    string
}

func (a *Agent) triggerGatewayReconcile() {
	select {
	case a.gatewaySignal <- struct{}{}:
	default:
	}
}

func (a *Agent) gatewayLoop(ctx context.Context) {
	manager := &gatewayManager{agent: a}
	a.gateway = manager
	if !fileExists(a.cfg.GatewaySingBoxBin) {
		a.log.Warn("gateway", "sing-box is not installed at %s; gateway mode stays idle", a.cfg.GatewaySingBoxBin)
	}
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	defer manager.stop("shutdown")
	for {
		select {
		case <-ctx.Done():
			return
		case <-a.gatewaySignal:
		case <-ticker.C:
		}
		if err := manager.reconcile(ctx); err != nil {
			a.log.Warn("gateway", "reconcile failed: %v", err)
		}
	}
}

// carrierForward picks the forward that carries LAN egress. An explicit
// gateway_forward_id wins; otherwise the Panel's own default is used, and only
// then the first carrier offered by the capability.
func (m *gatewayManager) carrierForward() (ForwardConfig, string, string, error) {
	a := m.agent
	a.mu.Lock()
	capability := a.nativeCap
	forwards := make(map[int]ForwardConfig, len(a.forwards))
	for id, forward := range a.forwards {
		forwards[id] = forward
	}
	a.mu.Unlock()

	if capability == nil || !capability.Enabled {
		return ForwardConfig{}, "", "", errors.New("panel has not granted native outbound for this device")
	}
	wantedID := a.cfg.GatewayForwardID
	if wantedID <= 0 && capability.DefaultForwardID != nil {
		wantedID = *capability.DefaultForwardID
	}
	carrier, ok := forwards[wantedID]
	if !ok {
		if len(capability.ForwardIDs) == 0 {
			return ForwardConfig{}, "", "", errors.New("no native outbound carrier assigned")
		}
		wantedID = capability.ForwardIDs[0]
		carrier, ok = forwards[wantedID]
		if !ok {
			return ForwardConfig{}, "", "", errors.New("assigned native carrier is not in the forward list")
		}
	}
	scope := a.cfg.GatewayRouteScope
	policy := a.cfg.GatewayRoutePolicy
	for _, candidate := range capability.Forwards {
		if candidate.ForwardID != wantedID {
			continue
		}
		if !containsString(candidate.RouteScopes, scope) && len(candidate.RouteScopes) > 0 {
			scope = candidate.DefaultRouteScope
		}
		if policy == "unrestricted" && !candidate.UnrestrictedOK {
			policy = "restricted"
		}
	}
	return carrier, scope, policy, nil
}

func containsString(values []string, wanted string) bool {
	for _, value := range values {
		if value == wanted {
			return true
		}
	}
	return false
}

// scopeDigestFor builds the digest the Panel validates for a native lease.
// Restricted scopes accept both digest versions for backwards compatibility;
// unrestricted only accepts v2.
func scopeDigestFor(carrier ForwardConfig, transport, scope, policy string) string {
	direction := strings.ToLower(strings.TrimSpace(carrier.NativeDirection))
	// The Panel accepts the v1 digest only for restricted scopes; v2 is valid
	// for both, so it is always the safe choice for a new client.
	return nativeScopeDigestV2(carrier.ForwardID, direction, transport, scope, normalizeRoutePolicy(policy))
}

func (m *gatewayManager) reconcile(ctx context.Context) error {
	a := m.agent
	if !a.enrolled() {
		return nil
	}
	carrier, scope, policy, err := m.carrierForward()
	if err != nil {
		m.mu.Lock()
		m.lastErr = err.Error()
		m.carrier = ForwardConfig{}
		m.mu.Unlock()
		if m.running() {
			m.stop("no_carrier")
		}
		return nil
	}
	if !fileExists(a.cfg.GatewaySingBoxBin) {
		return errors.New("sing-box is not installed")
	}

	// 1. Ensure the native runtime is up and holds a lease.
	if err := m.ensureNativeRuntime(ctx, carrier, scope, policy); err != nil {
		m.mu.Lock()
		m.lastErr = err.Error()
		m.mu.Unlock()
		return err
	}

	// 2. Ensure sing-box is up with a config matching the current scope.
	return m.ensureSingBox(ctx, carrier, scope, policy)
}

func (m *gatewayManager) running() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.proc != nil && m.proc.alive()
}

func (m *gatewayManager) ensureNativeRuntime(ctx context.Context, carrier ForwardConfig, scope, policy string) error {
	a := m.agent
	m.mu.Lock()
	sameCarrier := m.carrier.ForwardID == carrier.ForwardID
	m.carrier = carrier
	proc := m.proc
	leaseOK := m.leaseOK
	m.mu.Unlock()

	if sameCarrier && proc != nil && proc.alive() && leaseOK {
		return nil
	}
	if proc != nil && (!sameCarrier || !proc.alive()) {
		m.stop("carrier_changed")
	}

	// The native facade listens on a loopback port, so it needs a synthetic
	// forward descriptor rather than a LAN listen port.
	forward := ForwardConfig{
		ForwardID:   carrier.ForwardID,
		ListenPort:  gatewaySocksPort,
		Target:      "",
		Transport:   "tcp+udp",
		BackendMode: "ultra",
	}
	paths := pathsForForward(a.cfg.RunDir, carrier.ForwardID)
	cleanupRuntimeDir(paths)
	runtimeCfg := buildNativeRuntimeConfig(a.cfg, forward, paths, &UltraPolicy{}, scope, policy)
	if err := writeRuntimeConfig(paths, runtimeCfg); err != nil {
		return err
	}
	proc, err := m.spawn(paths)
	if err != nil {
		return err
	}
	m.mu.Lock()
	m.proc = proc
	m.leaseOK = false
	m.startedAt = time.Now()
	m.lastErr = ""
	m.mu.Unlock()
	a.log.Info("gateway", "native egress runtime started (forward=%d socks=127.0.0.1:%d scope=%s policy=%s)",
		carrier.ForwardID, gatewaySocksPort, scope, policy)

	// Order matters: the runtime blocks in WaitValid() until a lease exists,
	// and only then does it expose the SOCKS5 facade. Waiting for the port
	// first would deadlock.
	if err := waitForControlSocket(ctx, paths.controlSocket, 20*time.Second); err != nil {
		m.stop("readiness_failed")
		return err
	}

	if err := m.acquireNativeLease(ctx, carrier, scope, policy, paths); err != nil {
		return err
	}
	if err := waitForListenPort(ctx, gatewaySocksPort, 20*time.Second); err != nil {
		m.stop("socks_not_ready")
		return err
	}
	m.mu.Lock()
	m.leaseOK = true
	m.mu.Unlock()
	return nil
}

func (m *gatewayManager) spawn(paths runtimePaths) (*runtimeProcess, error) {
	a := m.agent
	cmd := exec.Command(a.cfg.RuntimeBin, "--config", paths.configPath)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stdout
	cmd.SysProcAttr = detachedProcAttr()
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("start native runtime: %w", err)
	}
	proc := &runtimeProcess{forwardID: a.cfg.GatewayForwardID, native: true, paths: paths, cmd: cmd, pid: cmd.Process.Pid, startedAt: time.Now()}
	go func() { _ = cmd.Wait() }()
	return proc, nil
}

func (m *gatewayManager) acquireNativeLease(ctx context.Context, carrier ForwardConfig, scope, policy string, paths runtimePaths) error {
	a := m.agent
	digest := scopeDigestFor(carrier, "tcp+udp", scope, policy)
	request := LeaseRequest{
		DeviceID: a.deviceID,
		Forwards: []LeaseRequestForward{{
			Purpose:       "native_outbound",
			ForwardID:     carrier.ForwardID,
			TargetDigest:  digest,
			Transport:     "tcp+udp",
			RouteScope:    scope,
			RoutePolicy:   normalizeRoutePolicy(policy),
			SelectionMode: "auto",
			MaxCandidates: a.cfg.MaxCandidates,
		}},
	}
	leaseCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	response, err := a.panel.Lease(leaseCtx, request)
	cancel()
	if err != nil {
		return err
	}
	if !response.OK || len(response.Leases) == 0 {
		return fmt.Errorf("native lease rejected: %s", firstNonEmpty(response.Error, "no_leases"))
	}
	control := ultraControl{socketPath: paths.controlSocket}
	if err := waitForControlSocket(ctx, paths.controlSocket, 15*time.Second); err != nil {
		return err
	}
	batchCtx, batchCancel := context.WithTimeout(ctx, leaseBatchTimeout)
	defer batchCancel()
	if _, err := control.sendLeaseBatch(batchCtx, response.Leases); err != nil {
		return fmt.Errorf("native lease delivery: %w", err)
	}
	a.log.Info("gateway", "native lease installed (%d candidates)", len(response.Leases))
	return nil
}

func (m *gatewayManager) stop(reason string) {
	m.mu.Lock()
	proc := m.proc
	m.proc = nil
	m.leaseOK = false
	m.mu.Unlock()
	if proc != nil {
		m.agent.stopRuntime(proc, "gateway_"+reason)
	}
}

// ensureSingBox renders and (re)starts the LAN-facing proxy when its generated
// configuration changes. The config file lives in a 0700 directory because it
// carries the loopback SOCKS port and the DNS policy, not because it holds a
// credential: the credential stays inside ultra-client.
func (m *gatewayManager) ensureSingBox(ctx context.Context, carrier ForwardConfig, scope, policy string) error {
	a := m.agent
	configPath := filepath.Join(a.cfg.RunDir, "gateway", gatewayConfigName)
	rendered, err := a.renderSingBoxConfig(scope, policy)
	if err != nil {
		return err
	}
	encoded, err := json.Marshal(rendered)
	if err != nil {
		return err
	}
	generation := sha256Hex(string(encoded))

	m.mu.Lock()
	unchanged := m.configGen == generation && m.status == "running"
	m.mu.Unlock()
	if unchanged {
		return nil
	}
	if err := ensurePrivateDir(filepath.Dir(configPath)); err != nil {
		return err
	}
	if err := writeFileAtomic(configPath, encoded, 0o600); err != nil {
		return err
	}

	if out, err := runCommand(5*time.Second, a.cfg.GatewaySingBoxBin, "check", "-c", configPath); err != nil {
		return fmt.Errorf("sing-box config rejected: %v %s", err, strings.TrimSpace(out))
	}

	m.stop("config_changed")
	cmd := exec.Command(a.cfg.GatewaySingBoxBin, "run", "-c", configPath)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stdout
	cmd.SysProcAttr = detachedProcAttr()
	if err := cmd.Start(); err != nil {
		return err
	}
	go func() { _ = cmd.Wait() }()
	m.mu.Lock()
	m.proc = &runtimeProcess{native: true, paths: runtimePaths{dir: filepath.Dir(configPath)}, cmd: cmd, pid: cmd.Process.Pid, startedAt: time.Now()}
	m.configGen = generation
	m.status = "running"
	m.startedAt = time.Now()
	m.lastErr = ""
	m.mu.Unlock()
	a.log.Info("gateway", "sing-box started (scope=%s policy=%s)", scope, policy)
	return nil
}

// renderSingBoxConfig builds the LAN-facing proxy configuration. Route rules
// implement the Panel's scope: cn_only sends only mainland destinations to the
// relay, intl_only sends everything except mainland, global sends everything.
func (a *Agent) renderSingBoxConfig(scope, policy string) (map[string]any, error) {
	dnsServers := splitList(a.cfg.GatewayDNSServers)
	if len(dnsServers) == 0 {
		dnsServers = []string{"1.1.1.1"}
	}
	remoteDNS := []map[string]any{}
	for _, server := range dnsServers {
		address := server
		if !strings.Contains(address, ":") {
			address = address + ":53"
		}
		// Modern DNS server form. The legacy string "address" field still
		// parses in 1.12 but is already scheduled for removal in 1.14.
		host, port := splitHostPortDefault(address, 53)
		remoteDNS = append(remoteDNS,
			map[string]any{"type": "udp", "tag": "remote", "server": host, "server_port": port, "detour": "proxy"},
			map[string]any{"type": "udp", "tag": "local", "server": host, "server_port": port, "detour": "direct"},
		)
		break
	}

	// The mainland bypass has to be expressed with rule_set only. The
	// per-rule "geoip" field this used to rely on was removed in sing-box
	// 1.12, which OpenWrt 24.10 ships, and a config that uses it makes the
	// gateway fail to start with a FATAL at load time.
	routeRuleSets := []map[string]any{
		{
			"type": "remote", "tag": gatewayCNRuleSet, "format": "binary",
			"url": "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs",
		},
		{
			"type": "remote", "tag": gatewayCNIPRuleSet, "format": "binary",
			"url": "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs",
		},
	}
	cnMatch := map[string]any{"rule_set": []string{gatewayCNRuleSet, gatewayCNIPRuleSet}}

	// Private destinations are never worth tunnelling, in any scope.
	routeRules := []map[string]any{
		{"ip_is_private": true, "outbound": "direct"},
	}
	// Mainland domains are resolved locally exactly when mainland traffic is
	// routed direct. Matching DNS policy to routing policy is what makes a
	// split tunnel actually split: resolving a mainland name through the
	// relay would hand back an address that then routes the wrong way.
	mainlandResolvedLocally := scope == "intl_only" || (scope != "cn_only" && a.cfg.GatewayBypassCN)
	dnsRules := []map[string]any{}
	if mainlandResolvedLocally {
		dnsRules = append(dnsRules, map[string]any{
			"action":   "route",
			"rule_set": []string{gatewayCNRuleSet},
			"server":   "local",
		})
	}

	// scope decides where mainland traffic goes; bypass_cn only refines the
	// catch-all "global" scope. Getting this backwards would silently send an
	// intl_only router's domestic traffic through the relay.
	finalOutbound := "proxy"
	switch scope {
	case "cn_only":
		// Only mainland destinations go through the relay.
		cn := map[string]any{"outbound": "proxy"}
		for k, v := range cnMatch {
			cn[k] = v
		}
		routeRules = append(routeRules, cn)
		finalOutbound = "direct"
	case "intl_only":
		// Everything except mainland destinations goes through the relay.
		cn := map[string]any{"outbound": "direct"}
		for k, v := range cnMatch {
			cn[k] = v
		}
		routeRules = append(routeRules, cn)
	default: // global
		if a.cfg.GatewayBypassCN {
			cn := map[string]any{"outbound": "direct"}
			for k, v := range cnMatch {
				cn[k] = v
			}
			routeRules = append(routeRules, cn)
		}
	}

	config := map[string]any{
		"log": map[string]any{"level": "warn", "timestamp": true},
		"dns": map[string]any{
			"servers": remoteDNS,
			// The outbound matcher this used to rely on was deprecated in
			// sing-box 1.12 and is removed in 1.14; matching on rule_set is
			// both current and more precise.
			"rules":    dnsRules,
			"final":    "remote",
			"strategy": "prefer_ipv4",
		},
		"inbounds": []map[string]any{{
			"type":           "tun",
			"tag":            "tun-in",
			"interface_name": gatewayTunName,
			"address":        []string{a.cfg.GatewayTunAddress},
			"mtu":            a.cfg.GatewayTunMTU,
			"auto_route":     true,
			"strict_route":   false,
			"stack":          "system",
		}},
		"outbounds": []map[string]any{
			{"type": "socks", "tag": "proxy", "server": "127.0.0.1", "server_port": gatewaySocksPort, "version": "5"},
			{"type": "direct", "tag": "direct"},
		},
		"route": map[string]any{
			"rule_set": routeRuleSets,
			"rules":    routeRules,
			"final":    finalOutbound,
			// sing-box 1.12 requires the domain resolver to be stated
			// explicitly for outbound dials; leaving it implicit is already
			// deprecated and is removed in 1.14. "local" is the safe choice:
			// the proxy outbound is a loopback address, so resolving through
			// the relay would only create a resolution loop.
			"default_domain_resolver": "local",
			"auto_detect_interface":   true,
		},
	}
	return config, nil
}

func (a *Agent) gatewayView() *GatewayView {
	manager := a.gateway
	view := &GatewayView{
		Enabled:     a.cfg.GatewayEnabled,
		CarrierID:   a.cfg.GatewayForwardID,
		RouteScope:  a.cfg.GatewayRouteScope,
		RoutePolicy: a.cfg.GatewayRoutePolicy,
		SocksPort:   gatewaySocksPort,
		BypassCN:    a.cfg.GatewayBypassCN,
	}
	if manager == nil {
		return view
	}
	manager.mu.Lock()
	defer manager.mu.Unlock()
	view.Running = manager.proc != nil && manager.proc.alive()
	if manager.carrier.ForwardID > 0 {
		view.CarrierID = manager.carrier.ForwardID
	}
	view.LastError = manager.lastErr
	return view
}
