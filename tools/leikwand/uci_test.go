package main

import "testing"

func TestParseUCIFile(t *testing.T) {
	content := `
# comment line
config leikwan 'main'
	option enabled '1'
	option panel_url "https://v3.leikwanhost.com"
	option mode gateway
	option uplink_interfaces 'wan wan2'
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
	if values["mode"] != "gateway" {
		t.Fatalf("bare value parsed as %q", values["mode"])
	}
	if values["uplink_probe"] != "1.1.1.1 223.5.5.5" {
		t.Fatalf("list join = %q", values["uplink_probe"])
	}
}

func TestLoadConfigAppliesUCIToDefaults(t *testing.T) {
	content := `
config leikwan 'main'
	option enabled '1'
	option mode 'hybrid'
	option panel_url 'https://panel.example/'
	option poll_interval '45'
	option max_candidates '9'
	option gateway_enabled '1'
	option gateway_route_scope 'cn_only'
	option gateway_route_policy 'unrestricted'
	option uplink_enabled '1'
	option uplink_interfaces 'wan wanb'
	option uplink_probe '9.9.9.9'
	option uplink_fail_threshold '5'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	t.Setenv("LEIKWAN_DATA_DIR", t.TempDir())
	t.Setenv("LEIKWAN_RUN_DIR", t.TempDir())

	cfg := loadConfig()
	if cfg.Mode != "hybrid" {
		t.Fatalf("mode = %q", cfg.Mode)
	}
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
	if !cfg.gatewayActive() || !cfg.forwardActive() {
		t.Fatal("hybrid mode must enable both gateway and forward data paths")
	}
	if cfg.GatewayRouteScope != "cn_only" || cfg.GatewayRoutePolicy != "unrestricted" {
		t.Fatalf("gateway scope/policy = %s/%s", cfg.GatewayRouteScope, cfg.GatewayRoutePolicy)
	}
	if len(cfg.UplinkInterfaces) != 2 || cfg.UplinkProbeTargets[0] != "9.9.9.9" {
		t.Fatalf("uplink list parse: ifaces=%v probes=%v", cfg.UplinkInterfaces, cfg.UplinkProbeTargets)
	}
	if cfg.UplinkFailThreshold != 5 {
		t.Fatalf("uplink_fail_threshold = %d", cfg.UplinkFailThreshold)
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
	if normalizeMode("TUN") != "gateway" || normalizeMode("both") != "hybrid" || normalizeMode("junk") != "forward" {
		t.Fatal("mode normalisation is wrong")
	}
	if normalizeRouteScope("CN_ONLY") != "cn_only" || normalizeRouteScope("bogus") != "global" {
		t.Fatal("route scope normalisation is wrong")
	}
	if normalizeRoutePolicy("UNRESTRICTED") != "unrestricted" || normalizeRoutePolicy("") != "restricted" {
		t.Fatal("route policy normalisation is wrong")
	}
}
