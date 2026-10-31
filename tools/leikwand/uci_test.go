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

// Legacy options from the transparent-gateway and multi-WAN era are ignored:
// loading a pre-router-edition config must not resurrect removed features.
func TestLoadConfigIgnoresLegacyGatewayAndUplinkOptions(t *testing.T) {
	content := `
config leikwan 'main'
	option mode 'hybrid'
	option gateway_enabled '1'
	option gateway_tun_address '10.9.9.9/30'
	option gateway_dns_servers '9.9.9.9'
	option uplink_enabled '1'
	option uplink_interfaces 'wan wanb'
`
	path := writeTempFile(t, "leikwan", content)
	t.Setenv("LEIKWAN_UCI_PATH", path)
	cfg := loadConfig()
	if cfg.GatewayRouteScope != "auto" || cfg.GatewayRoutePolicy != "auto" {
		t.Fatalf("legacy gateway options leaked: %s/%s", cfg.GatewayRouteScope, cfg.GatewayRoutePolicy)
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
