#!/usr/bin/env bash
set -euo pipefail

INSTALLER_VERSION="2026.09.17.4"
MASTER_URL=""
ARTIFACT_BASE=""
AGENT_TOKEN=""
SERVICE_NAME="leikwan-bond-sidecar"
CONFIG_DIR="/etc/leikwan-sidecar"
BIN_DIR="/usr/local/bin"
SKIP_EXTERNAL_UOT=0
REQUIRE_EXTERNAL_UOT=0
EXTERNAL_UOT_CONFIG=""
EXTERNAL_UOT_LISTEN_PORT="7443"
RUNTIME_ARCH="amd64"
SIDECAR_RUNTIME_MODE="python"
SIDECAR_AGENT_KEY=""
SIDECAR_AGENT_PATH=""
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0
SKIP_MPTCP_CHECK=0

running_kernel_has_mptcp() {
  [[ -e /proc/sys/net/mptcp/enabled ]]
}

enable_mptcp_runtime() {
  if running_kernel_has_mptcp; then
    sysctl -w net.mptcp.enabled=1 >/dev/null 2>&1 || true
    mkdir -p /etc/sysctl.d
    cat > /etc/sysctl.d/90-leikwan-mptcp.conf <<'SYSCTL'
net.mptcp.enabled=1
net.mptcp.add_addr_timeout=5
net.mptcp.stale_loss_cnt=2
net.ipv4.tcp_wmem=4096 65536 16777216
net.ipv4.tcp_rmem=4096 131072 16777216
net.core.wmem_max=16777216
net.core.rmem_max=16777216
net.ipv4.tcp_syn_retries=2
net.ipv4.tcp_no_metrics_save=1
SYSCTL
    sysctl --system >/dev/null 2>&1 || true
  fi
}

suggested_mptcp_kernel_packages() {
  if [[ ! -r /etc/os-release ]]; then
    return 1
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  case "${ID:-}" in
    debian)
      if uname -r | grep -q 'cloud'; then
        echo "linux-image-cloud-amd64 linux-headers-cloud-amd64"
      else
        echo "linux-image-amd64 linux-headers-amd64"
      fi
      ;;
    ubuntu)
      echo "linux-generic"
      ;;
    *)
      return 1
      ;;
  esac
}

auto_install_mptcp_kernel() {
  local packages
  packages="$(suggested_mptcp_kernel_packages)" || {
    echo "unable to determine an automatic MPTCP-capable kernel package for this distro" >&2
    return 1
  }
  if ! command -v apt-get >/dev/null 2>&1; then
    echo "automatic MPTCP kernel install currently supports apt-based systems only" >&2
    return 1
  fi
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  # shellcheck disable=SC2086
  apt-get install -y $packages
}

ensure_mptcp_ready() {
  if running_kernel_has_mptcp; then
    enable_mptcp_runtime
    echo "sidecar: running kernel already supports MPTCP"
    return 0
  fi

  echo "sidecar: running kernel does not support MPTCP" >&2
  if [[ "$AUTO_INSTALL_MPTCP_KERNEL" == "1" ]]; then
    auto_install_mptcp_kernel
    if running_kernel_has_mptcp; then
      enable_mptcp_runtime
      echo "sidecar: MPTCP became available immediately after package install"
      return 0
    fi
    echo "sidecar: new kernel package installed, but a reboot is required before MPTCP will work" >&2
    if [[ "$REBOOT_AFTER_MPTCP_KERNEL_INSTALL" == "1" ]]; then
      reboot
      exit 0
    fi
  fi

  return 1
}

install_wireguard_tools() {
  if command -v wg >/dev/null 2>&1 && command -v wg-quick >/dev/null 2>&1; then
    return 0
  fi
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y wireguard-tools
  fi
}

ensure_time_sync() {
  if command -v timedatectl >/dev/null 2>&1; then
    if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi '^yes$'; then
      return 0
    fi
  fi
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y ntp
    systemctl enable ntp.service >/dev/null 2>&1 || systemctl enable ntpd.service >/dev/null 2>&1 || true
    systemctl restart ntp.service >/dev/null 2>&1 || systemctl restart ntpd.service >/dev/null 2>&1 || true
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) MASTER_URL="$2"; shift 2 ;;
    --artifact-base) ARTIFACT_BASE="$2"; shift 2 ;;
    --agent-token) AGENT_TOKEN="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --external-uot-config) EXTERNAL_UOT_CONFIG="$2"; shift 2 ;;
    --external-uot-listen-port) EXTERNAL_UOT_LISTEN_PORT="$2"; shift 2 ;;
    --skip-external-uot) SKIP_EXTERNAL_UOT=1; shift ;;
    --require-external-uot) REQUIRE_EXTERNAL_UOT=1; shift ;;
    --listen-port|--token-secret|--total-speed-limit-mbps) shift 2 ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    --skip-mptcp-check) SKIP_MPTCP_CHECK=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$AGENT_TOKEN" ]]; then
  echo "usage: install-bond-sidecar.sh --master <url> --agent-token <token> [--external-uot-config <path|url>] [--skip-external-uot] [--require-external-uot]" >&2
  exit 1
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi

# Fresh installs self-configure the external UoT sidecar from the panel: the
# endpoint resolves this host and returns only the forwards it terminates, so a
# new build never comes up without its UoT carrier (UDP candidate failed).
if [[ "$SKIP_EXTERNAL_UOT" != "1" && -z "$EXTERNAL_UOT_CONFIG" && -n "$ARTIFACT_BASE" ]]; then
  EXTERNAL_UOT_CONFIG="$ARTIFACT_BASE/external-uot-config"
  echo "external UoT sidecar config defaulted to $EXTERNAL_UOT_CONFIG"
fi

if [[ "$SKIP_MPTCP_CHECK" != "1" ]]; then
  ensure_mptcp_ready || {
    echo "sidecar: install aborted because this host cannot run MPTCP yet. Re-run with --auto-install-mptcp-kernel if you want the installer to add a newer kernel package." >&2
    exit 1
  }
fi
ensure_time_sync
install_wireguard_tools

mkdir -p "$CONFIG_DIR" "$BIN_DIR"

case "$(uname -m)" in
  x86_64|amd64) RUNTIME_ARCH=amd64 ;;
  aarch64|arm64) RUNTIME_ARCH=arm64 ;;
  *) echo "unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac

VERSIONS_JSON="$(curl -fsSL "$ARTIFACT_BASE/artifacts/versions.json")"
manifest_value() {
  python3 -c 'import json,sys; entry=json.loads(sys.argv[1]).get(sys.argv[2], {}); print(entry.get(sys.argv[3], "") if isinstance(entry, dict) else "")' "$VERSIONS_JSON" "$1" "$2"
}
manifest_artifact_url() {
  local key="$1" fallback="$2" configured
  configured="$(manifest_value "$key" artifact_url)"
  if [[ "$configured" =~ ^https?:// ]]; then
    printf '%s\n' "$configured"
  elif [[ -n "$configured" ]]; then
    printf '%s/%s\n' "${ARTIFACT_BASE%/}" "${configured#/}"
  else
    printf '%s\n' "$fallback"
  fi
}
download_verified_artifact() {
  local key="$1" url="$2" path="$3" expected tmp
  expected="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2], {}).get("sha256", ""))' "$VERSIONS_JSON" "$key")"
  [[ "$expected" =~ ^[0-9a-fA-F]{64}$ ]] || { echo "missing manifest sha256 for $key" >&2; return 1; }
  tmp="${path}.new"
  if ! curl -fsSL "$url" -o "$tmp" || [[ ! -s "$tmp" ]]; then
    echo "downloaded artifact is empty or unavailable: $key ($url)" >&2
    rm -f "$tmp"
    return 1
  fi
  if ! echo "$expected  $tmp" | sha256sum -c - >/dev/null; then
    echo "sha256 mismatch for $key" >&2
    rm -f "$tmp"
    return 1
  fi
  chmod 0755 "$tmp"
  [[ -x "$tmp" ]] || { echo "verified artifact is not executable: $key" >&2; rm -f "$tmp"; return 1; }
  mv "$tmp" "$path"
}

download() {
  local url="$1"
  local path="$2"
  curl -fsSL "$url" -o "$path"
  chmod 0755 "$path"
}

download_atomic() {
  local url="$1"
  local path="$2"
  local tmp_path="${path}.new"
  if ! curl -fsSL "$url" -o "$tmp_path" || [[ ! -s "$tmp_path" ]]; then
    echo "downloaded artifact is empty or unavailable: $url" >&2
    rm -f "$tmp_path"
    return 1
  fi
  chmod 0755 "$tmp_path"
  [[ -x "$tmp_path" ]] || { echo "downloaded artifact is not executable: $url" >&2; rm -f "$tmp_path"; return 1; }
  mv "$tmp_path" "$path"
}

download_optional_atomic() {
  local url="$1"
  local path="$2"
  local label="${3:-optional artifact}"
  local tmp_path="${path}.new"
  if curl -fsSL "$url" -o "$tmp_path" && [[ -s "$tmp_path" ]]; then
    chmod 0755 "$tmp_path"
    if [[ ! -x "$tmp_path" ]]; then
      rm -f "$tmp_path"
      echo "warning: $label is not executable from $url; continuing without $path" >&2
      return 0
    fi
    mv "$tmp_path" "$path"
    return 0
  else
    local rc=$?
    rm -f "$tmp_path"
    echo "warning: $label unavailable from $url (curl rc=$rc); continuing without $path" >&2
    return 0
  fi
}

download_verified_artifact tcp_accel_installer "$ARTIFACT_BASE/artifacts/install-tcp-accel.sh" "$BIN_DIR/install-tcp-accel.sh"
"$BIN_DIR/install-tcp-accel.sh" sidecar --artifact-base "$ARTIFACT_BASE" --bin-dir "$BIN_DIR"
SIDECAR_AGENT_KEY="sidecar_agent_linux_${RUNTIME_ARCH}"
SIDECAR_AGENT_PATH="$BIN_DIR/leikwan-sidecar-agent"
SIDECAR_AGENT_SHA256="$(manifest_value "$SIDECAR_AGENT_KEY" sha256)"
if [[ "$SIDECAR_AGENT_SHA256" =~ ^[0-9a-fA-F]{64}$ ]]; then
  download_verified_artifact "$SIDECAR_AGENT_KEY" "$(manifest_artifact_url "$SIDECAR_AGENT_KEY" "$ARTIFACT_BASE/artifacts/python-agent/leikwan-sidecar-agent_linux_${RUNTIME_ARCH}")" "$SIDECAR_AGENT_PATH"
  SIDECAR_RUNTIME_MODE="frozen"
  echo "using frozen sidecar-agent binary: $SIDECAR_AGENT_PATH"
else
  download_verified_artifact sidecar_agent "$(manifest_artifact_url sidecar_agent "$ARTIFACT_BASE/artifacts/sidecar-agent.py")" "$BIN_DIR/leikwan-sidecar-agent.py"
  SIDECAR_AGENT_PATH="$BIN_DIR/leikwan-sidecar-agent.py"
  echo "using Python sidecar-agent fallback: $SIDECAR_AGENT_PATH"
fi
if [[ "$SIDECAR_RUNTIME_MODE" == "frozen" ]]; then
  SIDECAR_AGENT_VERSION="$($SIDECAR_AGENT_PATH --version 2>/dev/null | head -n 1)"
  SIDECAR_AGENT_EXPECTED_VERSION="$(manifest_value "$SIDECAR_AGENT_KEY" version)"
else
  SIDECAR_AGENT_VERSION="$(python3 "$SIDECAR_AGENT_PATH" version 2>/dev/null | head -n 1)"
  SIDECAR_AGENT_EXPECTED_VERSION="$(manifest_value sidecar_agent version)"
fi
[[ -n "$SIDECAR_AGENT_VERSION" ]] || { echo "sidecar-agent version probe failed" >&2; exit 1; }
if [[ -n "$SIDECAR_AGENT_EXPECTED_VERSION" && "$SIDECAR_AGENT_VERSION" != "$SIDECAR_AGENT_EXPECTED_VERSION" ]]; then
  echo "sidecar-agent version mismatch: manifest=$SIDECAR_AGENT_EXPECTED_VERSION actual=$SIDECAR_AGENT_VERSION" >&2
  exit 1
fi
if [[ "${LEIKWAN_SKIP_UPDATE_HELPER:-0}" != "1" ]]; then
  SIDECAR_UPDATE_SHA256="$(manifest_value sidecar_agent_update sha256)"
  if [[ "$SIDECAR_UPDATE_SHA256" =~ ^[0-9a-fA-F]{64}$ ]]; then
    download_verified_artifact sidecar_agent_update "$(manifest_artifact_url sidecar_agent_update "$ARTIFACT_BASE/artifacts/sidecar-agent-update.sh")" "$BIN_DIR/leikwan-sidecar-update"
  else
    download_atomic "$ARTIFACT_BASE/artifacts/sidecar-agent-update.sh" "$BIN_DIR/leikwan-sidecar-update"
  fi
fi
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-mptcp-sidecar.py" "$BIN_DIR/leikwan-mptcp-sidecar.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-udp-uot-merge.py" "$BIN_DIR/leikwan-udp-uot-merge.py"
download_verified_artifact leikwan_udp_uot_merge_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64" "$BIN_DIR/leikwan-udp-uot-merge"
"$BIN_DIR/leikwan-udp-uot-merge" --build-info-json | python3 -c '
import json, sys
value = json.load(sys.stdin)
if value.get("protocol_version") != 2 or "feedback_drr" not in value.get("scheduler_modes", []) or "leg-progress-v1" not in value.get("capabilities", []):
    raise SystemExit("installed native UoT merge runtime lacks feedback capability")
'
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-wss-mux.py" "$BIN_DIR/leikwan-wss-mux.py"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" "$BIN_DIR/leikwan-bond"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"
# LeiGod basis: gost is only required for legacy leigod_uot_impl=gost lanes.  The
# current BR1 LeiGod rollout uses lguot; keep install idempotent if the historical
# gost artifact is absent from the panel artifact store.
download_optional_atomic "$ARTIFACT_BASE/artifacts/gost/gost_linux_amd64" "$BIN_DIR/gost" "legacy gost UoT carrier"
# lguot: CPU-efficient UoT server, the leigod_uot_impl=lguot alternative to the gost server.
download_atomic "$ARTIFACT_BASE/artifacts/lguot/lguot_linux_amd64" "$BIN_DIR/lguot"

# --- External UoT sidecar (Go) ---------------------------------------------
# The Ultra runtime's external_uot carrier terminates on this sidecar, so a
# freshly installed host can answer UDP instead of reporting "UDP candidate
# failed".  The forward list stays panel owned: by default the installer pulls
# this host's document from the panel (EXTERNAL_UOT_CONFIG default above),
# --external-uot-config overrides it and --skip-external-uot omits the sidecar
# entirely.  A host with no enabled forwards installs the unit disabled.
if [[ "$SKIP_EXTERNAL_UOT" == "1" || "${LEIKWAN_SKIP_EXTERNAL_UOT:-0}" == "1" ]]; then
  echo "external UoT sidecar install skipped by configuration"
elif download_verified_artifact external_uot_installer "$ARTIFACT_BASE/artifacts/install-external-uot.sh" "$BIN_DIR/install-external-uot.sh"; then
  external_uot_args=(--artifact-base "$ARTIFACT_BASE" --bin-dir "$BIN_DIR" --listen-port "$EXTERNAL_UOT_LISTEN_PORT")
  if [[ -n "$EXTERNAL_UOT_CONFIG" ]]; then
    external_uot_args+=(--config "$EXTERNAL_UOT_CONFIG")
  fi
  if [[ "${LEIKWAN_SKIP_SYSTEMD:-0}" == "1" ]]; then
    external_uot_args+=(--skip-systemd)
  fi
  # A failed sidecar step must not take the whole bond install down with it:
  # without --require-external-uot the host keeps working, its forwards just
  # fall back to the native UDP payload path; with the flag (what the panel
  # passes for a point that owns a sidecar_host) the miss stays fatal so a
  # panel-driven install cannot silently ship without its UoT carrier.
  if ! "$BIN_DIR/install-external-uot.sh" "${external_uot_args[@]}"; then
    if [[ "$REQUIRE_EXTERNAL_UOT" == "1" ]]; then
      echo "external UoT sidecar install failed and --require-external-uot was set" >&2
      exit 1
    fi
    echo "warning: external UoT sidecar install failed; continuing without it" >&2
  fi
elif [[ "$REQUIRE_EXTERNAL_UOT" == "1" ]]; then
  echo "external UoT installer missing from $ARTIFACT_BASE and --require-external-uot was set" >&2
  exit 1
else
  echo "warning: external UoT installer unavailable from $ARTIFACT_BASE; continuing without it" >&2
fi

# --- WireGuard watchdog ---
download "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" "$BIN_DIR/leikwan-wg-watchdog.sh"
cat > /etc/systemd/system/leikwan-wg-watchdog.service <<'WDUNIT'
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/leikwan-wg-watchdog.sh
WDUNIT
cat > /etc/systemd/system/leikwan-wg-watchdog.timer <<'WDTIMER'
[Unit]
Description=Run Leikwan WG Watchdog every 60s

[Timer]
OnBootSec=90s
OnUnitActiveSec=60s
Unit=leikwan-wg-watchdog.service

[Install]
WantedBy=timers.target
WDTIMER
systemctl daemon-reload
systemctl enable --now leikwan-wg-watchdog.timer 2>/dev/null || true

cat > "$CONFIG_DIR/config.json" <<JSON
{
  "master_url": "$MASTER_URL",
  "artifact_base_url": "$ARTIFACT_BASE",
  "agent_token": "$AGENT_TOKEN",
  "service_name": "$SERVICE_NAME",
  "runtime_bin": "$BIN_DIR/leikwan-mptcp-sidecar.py",
  "runtime_binary_mode": "$SIDECAR_RUNTIME_MODE",
  "sidecar_agent_bin": "$SIDECAR_AGENT_PATH",
  "udp_uot_merge_bin": "$BIN_DIR/leikwan-udp-uot-merge",
  "runtime_config_path": "$CONFIG_DIR/runtime.json",
  "state_dir": "/var/lib/leikwan-sidecar",
  "log_level": "INFO",
  "installer_version": "$INSTALLER_VERSION"
}
JSON

AGENT_SERVICE_NAME="${SERVICE_NAME}-agent"
if [[ "$SIDECAR_RUNTIME_MODE" == "frozen" ]]; then
  AGENT_EXEC_START="$SIDECAR_AGENT_PATH --config $CONFIG_DIR/config.json run"
else
  AGENT_EXEC_START="/usr/bin/python3 $SIDECAR_AGENT_PATH --config $CONFIG_DIR/config.json run"
fi
cat > "/etc/systemd/system/$AGENT_SERVICE_NAME.service" <<UNIT
[Unit]
Description=Leikwan Sidecar Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=$AGENT_EXEC_START
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable "$AGENT_SERVICE_NAME.service"
systemctl restart "$AGENT_SERVICE_NAME.service"

echo "installed $AGENT_SERVICE_NAME"
