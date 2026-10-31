package main

import (
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Config is the operator-facing configuration. Non-secret values live in UCI
// (/etc/config/leikwan) so they are visible and editable from LuCI; the agent
// token and device identity live in a 0600 secret file instead.
type Config struct {
	Enabled     bool
	PanelURL    string
	FallbackURL string
	DeviceName  string
	DeviceID    string
	ClientID    int

	// PanelEgressInterface pins Panel traffic to one device: "auto" (the
	// physical WAN), "none" (no pinning), or a device / UCI interface name.
	PanelEgressInterface string

	PollIntervalSecs      int
	HeartbeatIntervalSecs int
	HealthIntervalSecs    int
	ColdStartGraceSecs    int
	RestartCooldownSecs   int
	LeaseRenewLeadSecs    int
	MaxCandidates         int
	AutoUpdate            bool
	LogLevel              string

	DataDir    string
	RunDir     string
	RuntimeBin string

	// Native outbound relay. The relay itself starts when the Panel grants
	// native outbound; the operator only narrows the scope/policy ("auto"
	// follows the line's direction and the plan's grant).
	GatewayForwardID   int
	GatewayRouteScope  string
	GatewayRoutePolicy string

	CNIPList string
}

// uciConfigPath is a variable so an integration test can point the daemon at
// a scratch configuration without touching /etc.
var uciConfigPath = "/etc/config/leikwan"

// applyEnvOverrides lets the packaged init script and the test harness pin
// paths without rewriting UCI. Only paths and the panel URL are overridable:
// policy (mode, intervals, HA thresholds) always comes from UCI so LuCI stays
// the single source of truth for behaviour.
func applyEnvOverrides(cfg *Config) {
	if value := os.Getenv("LEIKWAN_UCI_PATH"); value != "" {
		uciConfigPath = value
	}
	if value := os.Getenv("LEIKWAN_DATA_DIR"); value != "" {
		cfg.DataDir = value
	}
	if value := os.Getenv("LEIKWAN_RUN_DIR"); value != "" {
		cfg.RunDir = value
	}
	if value := os.Getenv("LEIKWAN_RUNTIME_BIN"); value != "" {
		cfg.RuntimeBin = value
	}
	if value := os.Getenv("LEIKWAN_PANEL_URL"); value != "" {
		cfg.PanelURL = strings.TrimRight(value, "/")
	}
}

func defaultConfig() Config {
	return Config{
		Enabled:                 true,
		PanelURL:                "https://v3.leikwanhost.com",
		FallbackURL:             "https://api-leikwanbridge.nbwly.com",
		PanelEgressInterface:    egressAuto,
		PollIntervalSecs:        30,
		HeartbeatIntervalSecs:   30,
		HealthIntervalSecs:      20,
		ColdStartGraceSecs:      90,
		RestartCooldownSecs:     30,
		LeaseRenewLeadSecs:      90,
		MaxCandidates:           4,
		AutoUpdate:              true,
		LogLevel:                "info",
		DataDir:                 "/etc/leikwan",
		RunDir:                  "/var/run/leikwand",
		RuntimeBin:              "/usr/libexec/leikwan/ultra-client",
		GatewayRouteScope:       "auto",
		GatewayRoutePolicy:      "auto",
		CNIPList:                "/etc/leikwan/CN.conf",
	}
}

// loadConfig reads UCI without shelling out to the uci binary, so the daemon
// still starts correctly during early boot and inside a chroot used by tests.
func loadConfig() Config {
	cfg := defaultConfig()
	if value := os.Getenv("LEIKWAN_UCI_PATH"); value != "" {
		uciConfigPath = value
	}
	values := parseUCIFile(uciConfigPath, "leikwan")
	if len(values) == 0 {
		applyEnvOverrides(&cfg)
		return cfg
	}
	get := func(key string, fallback string) string {
		if v, ok := values[key]; ok && strings.TrimSpace(v) != "" {
			return strings.TrimSpace(v)
		}
		return fallback
	}
	cfg.Enabled = boolDefault(get("enabled", "1"), true)
	cfg.PanelURL = strings.TrimRight(get("panel_url", cfg.PanelURL), "/")
	cfg.FallbackURL = strings.TrimRight(get("fallback_panel_url", cfg.FallbackURL), "/")
	cfg.DeviceName = get("device_name", "")
	cfg.DeviceID = get("device_id", "")
	cfg.ClientID = atoiDefault(get("client_id", "0"), 0)
	cfg.PanelEgressInterface = get("panel_egress_interface", cfg.PanelEgressInterface)

	cfg.PollIntervalSecs = clampInt(atoiDefault(get("poll_interval", ""), cfg.PollIntervalSecs), 5, 3600)
	cfg.HeartbeatIntervalSecs = clampInt(atoiDefault(get("heartbeat_interval", ""), cfg.HeartbeatIntervalSecs), 5, 3600)
	cfg.HealthIntervalSecs = clampInt(atoiDefault(get("health_interval", ""), cfg.HealthIntervalSecs), 2, 600)
	cfg.ColdStartGraceSecs = clampInt(atoiDefault(get("cold_start_grace", ""), cfg.ColdStartGraceSecs), 10, 900)
	cfg.RestartCooldownSecs = clampInt(atoiDefault(get("restart_cooldown", ""), cfg.RestartCooldownSecs), 5, 3600)
	cfg.LeaseRenewLeadSecs = clampInt(atoiDefault(get("lease_renew_lead", ""), cfg.LeaseRenewLeadSecs), 15, 300)
	cfg.MaxCandidates = clampInt(atoiDefault(get("max_candidates", ""), cfg.MaxCandidates), 1, 4)
	cfg.AutoUpdate = boolDefault(get("auto_update", "1"), true)
	cfg.LogLevel = strings.ToLower(get("log_level", cfg.LogLevel))

	cfg.DataDir = get("data_dir", cfg.DataDir)
	cfg.RunDir = get("run_dir", cfg.RunDir)
	cfg.RuntimeBin = get("runtime_bin", cfg.RuntimeBin)

	// Legacy gateway/uplink keys are ignored on purpose: the transparent
	// gateway and the multi-WAN manager no longer exist in this edition, and
	// an operator's leftover options must not resurrect them.
	cfg.GatewayForwardID = atoiDefault(get("gateway_forward_id", "0"), 0)
	cfg.GatewayRouteScope = normalizeGatewayChoice(get("gateway_route_scope", cfg.GatewayRouteScope), "cn_only", "intl_only", "global")
	cfg.GatewayRoutePolicy = normalizeGatewayChoice(get("gateway_route_policy", cfg.GatewayRoutePolicy), "restricted", "unrestricted")

	cfg.CNIPList = get("cn_ip_list", cfg.CNIPList)
	applyEnvOverrides(&cfg)
	return cfg
}

// normalizeRoutePolicy maps any spelling to restricted/unrestricted. The
// native lease contract uses this for both the request and validation.
func normalizeRoutePolicy(value string) string {
	if strings.ToLower(strings.TrimSpace(value)) == "unrestricted" {
		return "unrestricted"
	}
	return "restricted"
}

func splitList(value string) []string {
	fields := strings.FieldsFunc(value, func(r rune) bool {
		return r == ' ' || r == ',' || r == '\t' || r == '\n'
	})
	out := make([]string, 0, len(fields))
	for _, f := range fields {
		if trimmed := strings.TrimSpace(f); trimmed != "" {
			out = append(out, trimmed)
		}
	}
	return out
}

// parseUCIFile understands the subset of UCI syntax that /etc/config files use
// in practice: 'config <type> [<name>]', 'option k v', 'list k v', comments and
// single/double quoting.
func parseUCIFile(path, sectionType string) map[string]string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	values := map[string]string{}
	current := ""
	lists := map[string][]string{}
	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		parts := splitUCIFields(line)
		if len(parts) == 0 {
			continue
		}
		switch parts[0] {
		case "config":
			if len(parts) >= 2 {
				current = parts[1]
				// Anonymous or named section of the requested type.
				if len(parts) >= 3 {
					current = parts[1] + ":" + parts[2]
				}
			}
		case "option":
			if len(parts) >= 3 && matchesSection(current, sectionType) {
				values[parts[1]] = parts[2]
			}
		case "list":
			if len(parts) >= 3 && matchesSection(current, sectionType) {
				lists[parts[1]] = append(lists[parts[1]], parts[2])
			}
		}
	}
	for key, items := range lists {
		if _, exists := values[key]; !exists {
			values[key] = strings.Join(items, " ")
		}
	}
	return values
}

func matchesSection(current, sectionType string) bool {
	if current == "" {
		return false
	}
	base := current
	if idx := strings.Index(current, ":"); idx >= 0 {
		base = current[:idx]
	}
	return base == sectionType
}

func splitUCIFields(line string) []string {
	var fields []string
	var buf strings.Builder
	quote := byte(0)
	flush := func() {
		if buf.Len() > 0 {
			fields = append(fields, buf.String())
			buf.Reset()
		}
	}
	for i := 0; i < len(line); i++ {
		c := line[i]
		switch {
		case quote != 0:
			if c == quote {
				quote = 0
			} else if c == '\\' && i+1 < len(line) {
				i++
				buf.WriteByte(line[i])
			} else {
				buf.WriteByte(c)
			}
		case c == '"' || c == '\'':
			quote = c
		case c == ' ' || c == '\t':
			flush()
		default:
			buf.WriteByte(c)
		}
	}
	flush()
	return fields
}

// writeUCIOption rewrites a single option in /etc/config/leikwan using the
// canonical uci binary when available; the daemon only ever does this for
// values it owns (device identity), never for operator policy.
func writeUCIOption(section, option, value string) error {
	if _, err := os.Stat("/sbin/uci"); err == nil {
		_, err := runCommand(5*time.Second, "/sbin/uci", "set", "leikwan."+section+"."+option+"="+value)
		if err != nil {
			return err
		}
		_, err = runCommand(5*time.Second, "/sbin/uci", "commit", "leikwan")
		return err
	}
	return rewriteUCIFileOption(uciConfigPath, section, option, value)
}

func rewriteUCIFileOption(path, section, option, value string) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	lines := strings.Split(string(raw), "\n")
	inSection := false
	replaced := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "config ") {
			fields := splitUCIFields(trimmed)
			inSection = len(fields) >= 2 && fields[1] == section
			continue
		}
		if inSection && strings.HasPrefix(trimmed, "option "+option+" ") {
			lines[i] = "\toption " + option + " '" + value + "'"
			replaced = true
		}
	}
	if !replaced {
		lines = append(lines, "", "config "+section+" '"+section+"'", "\toption "+option+" '"+value+"'")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	return writeFileAtomic(path, []byte(strings.Join(lines, "\n")), 0o600)
}

// normalizeGatewayChoice keeps "auto" (resolved against the Panel grant at
// run time) or one of the allowed values; anything else is "auto".
func normalizeGatewayChoice(value string, allowed ...string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	for _, candidate := range allowed {
		if value == candidate {
			return value
		}
	}
	return "auto"
}
