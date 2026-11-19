package main

import "testing"

func TestParseUCIFile(t *testing.T) {
	content := `
# comment line
config leikwan 'main'
	option enabled '1'
	option panel_url "https://v3.leikwanhost.com"
	option gateway_route_scope cn_only
	list uplink_probe '1.1.1.1'
	list uplink_probe '223.5.5.5'

config other 'x'
	option mode ignored
`
	path := writeTempFile(t, "leikwan", content)
	values := parseUCIFile(path, "leikwan")
	if values["enabled"] != "1" {
		t.Fatalf("enabled = %q", values["enabled"])
	}
	if values["panel_url"] != "https://v3.leikwanhost.com" {
		t.Fatalf("double-quoted value parsed as %q", values["panel_url"])
	}
	if values["gateway_route_scope"] != "cn_only" {
		t.Fatalf("bare value parsed as %q", values["gateway_route_scope"])
	}
	if values["uplink_probe"] != "1.1.1.1 223.5.5.5" {
		t.Fatalf("list join = %q", values["uplink_probe"])
	}
}

func TestLoadConfigAppliesUCIToDefaults(t *testing.T) {
	content := `
config leikwan 'main'
	option enabled '1'
	option panel_url 'https://panel.example/'
	option poll_interval '45'
	option max_candidates '9'
	option gateway_route_scope 'cn_only'
	option gateway_route_policy 'unrestricted'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	t.Setenv("LEIKWAN_DATA_DIR", t.TempDir())
	t.Setenv("LEIKWAN_RUN_DIR", t.TempDir())

	cfg := loadConfig()
	if cfg.PanelURL != "https://panel.example" {
		t.Fatalf("panel url not trimmed: %q", cfg.PanelURL)
	}
	if cfg.PollIntervalSecs != 45 {
		t.Fatalf("poll interval = %d", cfg.PollIntervalSecs)
	}
	// max_candidates is clamped to the runtime's protocol limit of 4.
	if cfg.MaxCandidates != 4 {
		t.Fatalf("max_candidates = %d, want clamp to 4", cfg.MaxCandidates)
	}
	if cfg.GatewayRouteScope != "cn_only" || cfg.GatewayRoutePolicy != "unrestricted" {
		t.Fatalf("gateway scope/policy = %s/%s", cfg.GatewayRouteScope, cfg.GatewayRoutePolicy)
	}
}

// Without mode=gateway the 1.1-era gateway keys do not switch the data path:
// gatewayActive() stays false, so the router edition behaves like 1.2.x. The
// multi-WAN (uplink_*) keys are ignored entirely; that manager is not part of
// this edition.
func TestLoadConfigLegacyKeysWithoutGatewayModeStayInert(t *testing.T) {
	content := `
config leikwan 'main'
	option gateway_enabled '1'
	option gateway_tun_address '10.9.9.9/30'
	option gateway_dns_servers '9.9.9.9'
	option uplink_enabled '1'
	option uplink_interfaces 'wan wanb'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	cfg := loadConfig()
	if cfg.Mode != "forward" || cfg.gatewayActive() || !cfg.forwardActive() {
		t.Fatalf("mode=%s gatewayActive=%v forwardActive=%v; a config without mode=gateway must stay router edition",
			cfg.Mode, cfg.gatewayActive(), cfg.forwardActive())
	}
	if cfg.GatewayRouteScope != "auto" || cfg.GatewayRoutePolicy != "auto" {
		t.Fatalf("scope/policy = %s/%s", cfg.GatewayRouteScope, cfg.GatewayRoutePolicy)
	}
}

// The 1.1.1 PVE gateway config loads to the same gateway settings 1.1.1 used:
// IPv4-only TUN (IPv6 capture is opt-in), cidr capture from the SHIXP list.
func TestLoadConfigPVEGatewayMatches111(t *testing.T) {
	content := `
config leikwan 'main'
	option enabled '1'
	option panel_url 'https://v3.leikwanhost.com'
	option fallback_panel_url 'https://api-leikwanbridge.nbwly.com'
	option panel_egress_interface 'auto'
	option device_name 'PVE pootung (180.153.83.59)'
	option mode 'gateway'
	option data_dir '/etc/leikwan'
	option run_dir '/run/leikwand'
	option runtime_bin '/usr/local/libexec/leikwan/ultra-client'
	option auto_update '0'
	option log_level 'info'
	option gateway_enabled '1'
	option gateway_forward_id '326'
	option gateway_capture 'cidr'
	option gateway_route_policy 'auto'
	option gateway_split_cidr_file '/etc/shixp/shixp-ixonly-v4.txt'
	option gateway_singbox_bin '/usr/local/bin/sing-box'
	option gateway_tun_address '172.19.0.1/30'
	option gateway_tun_mtu '1280'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	t.Setenv("LEIKWAN_DATA_DIR", "")
	t.Setenv("LEIKWAN_RUN_DIR", "")
	t.Setenv("LEIKWAN_SINGBOX_BIN", "")
	cfg := loadConfig()
	if !cfg.gatewayActive() || cfg.forwardActive() {
		t.Fatalf("gatewayActive=%v forwardActive=%v; want gateway only", cfg.gatewayActive(), cfg.forwardActive())
	}
	if cfg.GatewayForwardID != 326 || cfg.GatewayCapture != "cidr" || cfg.GatewaySplitCIDRFile != "/etc/shixp/shixp-ixonly-v4.txt" {
		t.Fatalf("gateway = #%d capture=%s file=%s", cfg.GatewayForwardID, cfg.GatewayCapture, cfg.GatewaySplitCIDRFile)
	}
	if cfg.GatewayTunAddress6 != "" {
		t.Fatalf("IPv6 TUN address = %q; IPv6 capture must be opt-in", cfg.GatewayTunAddress6)
	}
	if cfg.GatewaySingBoxBin != "/usr/local/bin/sing-box" || cfg.GatewayTunMTU != 1280 || cfg.RunDir != "/run/leikwand" {
		t.Fatalf("singbox=%s mtu=%d run_dir=%s", cfg.GatewaySingBoxBin, cfg.GatewayTunMTU, cfg.RunDir)
	}
	if len(cfg.gatewaySplitConfigs()) != 0 {
		t.Fatalf("splits = %+v; the PVE config has none", cfg.gatewaySplitConfigs())
	}
}

func TestLoadConfigIPv6CaptureOptIn(t *testing.T) {
	content := `
config leikwan 'main'
	option mode 'gateway'
	option gateway_enabled '1'
	option gateway_tun_address6 'fdfe:dcba:9876::1/126'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	if got := loadConfig().GatewayTunAddress6; got != "fdfe:dcba:9876::1/126" {
		t.Fatalf("explicit IPv6 TUN address = %q", got)
	}
}

// Split carriers: the legacy keys are the implicit first split, then the
// 'config split' sections follow in file order. Disabled sections are skipped;
// a repeated carrier is kept here and dropped by the plan, with a note.
func TestLoadConfigSplitSections(t *testing.T) {
	content := `
config leikwan 'main'
	option mode 'gateway'
	option gateway_enabled '1'
	option gateway_forward_id '325'
	option gateway_split_forward_id '326'

config split 'pudong'
	option forward_id '330'
	option route_policy 'restricted'
	option cidr_file '/etc/leikwan/pudong.txt'

config split 'off'
	option enabled '0'
	option forward_id '331'
	option cidr_file '/etc/leikwan/off.txt'

config split
	option forward_id '332'
	option cidr_file '/etc/leikwan/anon.txt'

config split 'dup'
	option forward_id '326'
	option cidr_file '/etc/leikwan/dup.txt'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	cfg := loadConfig()
	splits := cfg.gatewaySplitConfigs()
	want := []SplitConfig{
		{Name: "legacy", ForwardID: 326, RoutePolicy: "auto", CIDRFile: "/usr/share/leikwan/split/shixp-v4.txt", Legacy: true},
		{Name: "pudong", ForwardID: 330, RoutePolicy: "restricted", CIDRFile: "/etc/leikwan/pudong.txt"},
		{Name: "split3", ForwardID: 332, RoutePolicy: "auto", CIDRFile: "/etc/leikwan/anon.txt"},
		{Name: "dup", ForwardID: 326, RoutePolicy: "auto", CIDRFile: "/etc/leikwan/dup.txt"},
	}
	if len(splits) != len(want) {
		t.Fatalf("splits = %+v", splits)
	}
	for i := range want {
		if splits[i] != want[i] {
			t.Fatalf("split %d = %+v, want %+v", i, splits[i], want[i])
		}
	}
}

func TestLoadConfigSplitSectionsWithoutLegacyKey(t *testing.T) {
	content := `
config leikwan 'main'
	option mode 'gateway'
	option gateway_enabled '1'

config split 'only'
	option forward_id '330'
	option cidr_file '/etc/leikwan/only.txt'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	splits := loadConfig().gatewaySplitConfigs()
	if len(splits) != 1 || splits[0].Name != "only" || splits[0].ForwardID != 330 {
		t.Fatalf("splits = %+v", splits)
	}
}

// A malformed or hostile UCI value must never turn a supervision loop into a
// busy loop.
func TestLoadConfigClampsTimings(t *testing.T) {
	content := `
config leikwan 'main'
	option poll_interval '0'
	option health_interval '99999'
	option cold_start_grace '-5'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	cfg := loadConfig()
	if cfg.PollIntervalSecs != 5 {
		t.Fatalf("poll interval = %d, want clamped to 5", cfg.PollIntervalSecs)
	}
	if cfg.HealthIntervalSecs != 600 {
		t.Fatalf("health interval = %d, want clamped to 600", cfg.HealthIntervalSecs)
	}
	if cfg.ColdStartGraceSecs != 10 {
		t.Fatalf("cold start grace = %d, want clamped to 10", cfg.ColdStartGraceSecs)
	}
}

func TestNormalizeHelpers(t *testing.T) {
	if normalizeRoutePolicy("UNRESTRICTED") != "unrestricted" || normalizeRoutePolicy("") != "restricted" {
		t.Fatal("route policy normalisation is wrong")
	}
	if normalizeGatewayChoice("CN_ONLY", "cn_only", "global") != "cn_only" || normalizeGatewayChoice("bogus", "cn_only") != "auto" {
		t.Fatal("gateway choice normalisation is wrong")
	}
}
