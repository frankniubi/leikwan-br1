#!/usr/bin/env bash
set -euo pipefail

MASTER_URL=""
TOKEN=""
GROUP_KEY="default"
REGION="unknown"
CONFIG_DIR="/etc/leikwan-slave"
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-slave-agent"
PUBLIC_IP=""
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0

running_kernel_has_mptcp() {
  [[ -e /proc/sys/net/mptcp/enabled ]]
}

enable_mptcp_runtime() {
  if running_kernel_has_mptcp; then
    sysctl -w net.mptcp.enabled=1 >/dev/null 2>&1 || true
    mkdir -p /etc/sysctl.d
    printf 'net.mptcp.enabled=1\n' > /etc/sysctl.d/90-leikwan-mptcp.conf
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

report_mptcp_status() {
  if running_kernel_has_mptcp; then
    enable_mptcp_runtime
    echo "slave: running kernel supports MPTCP"
    return 0
  fi

  echo "slave: running kernel does not support MPTCP; this is acceptable for transit mode" >&2
  if [[ "$AUTO_INSTALL_MPTCP_KERNEL" == "1" ]]; then
    auto_install_mptcp_kernel
    if running_kernel_has_mptcp; then
      enable_mptcp_runtime
      echo "slave: MPTCP became available immediately after package install"
      return 0
    fi
    echo "slave: new kernel package installed, but a reboot is required before MPTCP will work" >&2
    if [[ "$REBOOT_AFTER_MPTCP_KERNEL_INSTALL" == "1" ]]; then
      reboot
      exit 0
    fi
  fi
  return 0
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
    --token) TOKEN="$2"; shift 2 ;;
    --group) GROUP_KEY="$2"; shift 2 ;;
    --region) REGION="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --public-ip) PUBLIC_IP="$2"; shift 2 ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$TOKEN" ]]; then
  echo "usage: install-slave.sh --master <url> --token <enrollment-token>" >&2
  exit 1
fi

report_mptcp_status
install_wireguard_tools

mkdir -p "$CONFIG_DIR" "$BIN_DIR"
curl -fsSL "$MASTER_URL/artifacts/slave-agent.py" -o "$BIN_DIR/leikwan-slave-agent.py"
curl -fsSL "$MASTER_URL/artifacts/leikwan-uot.py" -o "$BIN_DIR/leikwan-uot.py"
chmod 0755 "$BIN_DIR/leikwan-slave-agent.py"
chmod 0755 "$BIN_DIR/leikwan-uot.py"

# --- WireGuard watchdog ---
curl -fsSL "$MASTER_URL/artifacts/leikwan-wg-watchdog.sh" -o "$BIN_DIR/leikwan-wg-watchdog.sh"
chmod 0755 "$BIN_DIR/leikwan-wg-watchdog.sh"
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

SUPPORTS_MPTCP=false
if running_kernel_has_mptcp; then
  SUPPORTS_MPTCP=true
fi

cat > "$CONFIG_DIR/config.json" <<JSON
{
  "master_url": "$MASTER_URL",
  "enrollment_token": "$TOKEN",
  "group_key": "$GROUP_KEY",
  "region": "$REGION",
  "public_ip": "$PUBLIC_IP",
  "supports_mptcp": $SUPPORTS_MPTCP,
  "state_dir": "/var/lib/leikwan-slave",
  "log_level": "INFO"
}
JSON

cat > "/etc/systemd/system/$SERVICE_NAME.service" <<UNIT
[Unit]
Description=Leikwan Slave Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 $BIN_DIR/leikwan-slave-agent.py --config $CONFIG_DIR/config.json run
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

/usr/bin/python3 "$BIN_DIR/leikwan-slave-agent.py" --config "$CONFIG_DIR/config.json" register || true
systemctl daemon-reload
systemctl enable "$SERVICE_NAME.service"
systemctl restart "$SERVICE_NAME.service"
echo "installed $SERVICE_NAME"
