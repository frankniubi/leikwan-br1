#!/usr/bin/env bash
set -euo pipefail

MASTER_URL=""
ARTIFACT_BASE=""
AGENT_TOKEN=""
SERVICE_NAME="leikwan-bond-sidecar"
CONFIG_DIR="/etc/leikwan-sidecar"
BIN_DIR="/usr/local/bin"
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

while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) MASTER_URL="$2"; shift 2 ;;
    --artifact-base) ARTIFACT_BASE="$2"; shift 2 ;;
    --agent-token) AGENT_TOKEN="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --listen-port|--token-secret|--total-speed-limit-mbps) shift 2 ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    --skip-mptcp-check) SKIP_MPTCP_CHECK=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$AGENT_TOKEN" ]]; then
  echo "usage: install-bond-sidecar.sh --master <url> --agent-token <token>" >&2
  exit 1
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi

if [[ "$SKIP_MPTCP_CHECK" != "1" ]]; then
  ensure_mptcp_ready || {
    echo "sidecar: install aborted because this host cannot run MPTCP yet. Re-run with --auto-install-mptcp-kernel if you want the installer to add a newer kernel package." >&2
    exit 1
  }
fi
install_wireguard_tools

mkdir -p "$CONFIG_DIR" "$BIN_DIR"

download() {
  local url="$1"
  local path="$2"
  curl -fsSL "$url" -o "$path"
  chmod 0755 "$path"
}

download "$ARTIFACT_BASE/artifacts/sidecar-agent.py" "$BIN_DIR/leikwan-sidecar-agent.py"
download "$ARTIFACT_BASE/artifacts/leikwan-mptcp-sidecar.py" "$BIN_DIR/leikwan-mptcp-sidecar.py"
download "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"

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
  "agent_token": "$AGENT_TOKEN",
  "service_name": "$SERVICE_NAME",
  "runtime_bin": "$BIN_DIR/leikwan-mptcp-sidecar.py",
  "runtime_config_path": "$CONFIG_DIR/runtime.json",
  "state_dir": "/var/lib/leikwan-sidecar",
  "log_level": "INFO"
}
JSON

AGENT_SERVICE_NAME="${SERVICE_NAME}-agent"
cat > "/etc/systemd/system/$AGENT_SERVICE_NAME.service" <<UNIT
[Unit]
Description=Leikwan Sidecar Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 $BIN_DIR/leikwan-sidecar-agent.py --config $CONFIG_DIR/config.json run
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable "$AGENT_SERVICE_NAME.service"
systemctl restart "$AGENT_SERVICE_NAME.service"

echo "installed $AGENT_SERVICE_NAME"
