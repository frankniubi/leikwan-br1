package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
)

// SecretState holds everything that must never be world-readable on the
// router: the client agent token issued by the Panel and the device identity
// bound to it. UCI is world-readable by design, so these live in a 0600 file.
type SecretState struct {
	AgentToken   string `json:"agent_token"`
	DeviceID     string `json:"device_id"`
	DeviceName   string `json:"device_name"`
	ClientID     int    `json:"client_id"`
	ClientName   string `json:"client_name"`
	MasterURL    string `json:"master_url"`
	EnrolledAt   string `json:"enrolled_at"`
	EnrollMethod string `json:"enroll_method"`
	SignedInMail string `json:"signed_in_email"`
}

func secretPath(dataDir string) string { return filepath.Join(dataDir, "secret.json") }

func loadSecrets(dataDir string) SecretState {
	var state SecretState
	raw, err := os.ReadFile(secretPath(dataDir))
	if err != nil {
		return state
	}
	_ = json.Unmarshal(raw, &state)
	state.AgentToken = strings.TrimSpace(state.AgentToken)
	state.DeviceID = strings.TrimSpace(state.DeviceID)
	state.MasterURL = strings.TrimRight(strings.TrimSpace(state.MasterURL), "/")
	return state
}

func saveSecrets(dataDir string, state SecretState) error {
	raw, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	if err := ensurePrivateDir(dataDir); err != nil {
		return err
	}
	return writeFileAtomic(secretPath(dataDir), raw, 0o600)
}

func clearSecrets(dataDir string) error {
	if err := os.Remove(secretPath(dataDir)); err != nil && !os.IsNotExist(err) {
		return err
	}
	return nil
}

// deviceIdentity prefers an operator-set UCI value, then the persisted device
// id, and finally a stable hash of the router's own identity. The Panel binds
// online-device limits to this value, so it must not change across reboots.
func deviceIdentity(cfg Config, secrets SecretState) string {
	if v := strings.TrimSpace(cfg.DeviceID); v != "" {
		return sanitizeDeviceID(v)
	}
	if v := strings.TrimSpace(secrets.DeviceID); v != "" {
		return sanitizeDeviceID(v)
	}
	return sanitizeDeviceID("openwrt-" + stableMachineID())
}

func deviceDisplayName(cfg Config, secrets SecretState) string {
	if v := strings.TrimSpace(cfg.DeviceName); v != "" {
		return v
	}
	if v := strings.TrimSpace(secrets.DeviceName); v != "" {
		return v
	}
	host, err := os.Hostname()
	if err != nil || strings.TrimSpace(host) == "" {
		host = "openwrt"
	}
	return "Leikwan Bridge on " + host
}

// sanitizeDeviceID enforces the Panel's device-id rules: non-empty, at most
// 191 bytes and free of control characters or spaces.
func sanitizeDeviceID(value string) string {
	var b strings.Builder
	for _, r := range value {
		if r <= 0x20 || r == 0x7f {
			b.WriteByte('-')
			continue
		}
		b.WriteRune(r)
	}
	out := b.String()
	if len(out) > 191 {
		out = out[:191]
	}
	if strings.Trim(out, "-") == "" {
		return "openwrt-device"
	}
	return out
}

// stableMachineID reads the best available hardware identity. /etc/machine-id
// is written by procd on OpenWrt; the DMI product UUID and the first MAC are
// fallbacks for images where machine-id is regenerated.
func stableMachineID() string {
	for _, candidate := range []string{"/etc/machine-id", "/sys/class/dmi/id/product_uuid", "/sys/class/net/eth0/address"} {
		if raw, err := os.ReadFile(candidate); err == nil {
			if value := strings.TrimSpace(strings.ReplaceAll(string(raw), ":", "")); value != "" {
				return value
			}
		}
	}
	return "unknown"
}
