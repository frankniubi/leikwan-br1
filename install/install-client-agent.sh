#!/usr/bin/env bash
set -euo pipefail

INSTALLER_VERSION="2026.05.15.3"
MASTER_URL=""
ARTIFACT_BASE=""
CLIENT_ID=""
AGENT_TOKEN=""
CONFIG_DIR="/etc/leikwan-client-agent"
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-client-agent"
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0
REQUIRE_MPTCP=0

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

ensure_mptcp_ready() {
  local role="$1"
  local required="$2"
  if running_kernel_has_mptcp; then
    enable_mptcp_runtime
    echo "$role: running kernel already supports MPTCP"
    return 0
  fi

  echo "$role: running kernel does not support MPTCP" >&2
  if [[ "$AUTO_INSTALL_MPTCP_KERNEL" == "1" ]]; then
    auto_install_mptcp_kernel
    if running_kernel_has_mptcp; then
      enable_mptcp_runtime
      echo "$role: MPTCP became available immediately after package install"
      return 0
    fi
    echo "$role: new kernel package installed, but a reboot is required before MPTCP will work" >&2
    if [[ "$REBOOT_AFTER_MPTCP_KERNEL_INSTALL" == "1" ]]; then
      reboot
      exit 0
    fi
  fi

  if [[ "$required" == "1" ]]; then
    return 1
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

current_installer_sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$0" | awk '{print $1}'
    return 0
  fi
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$0" | awk '{print $1}'
    return 0
  fi
  python3 - "$0" <<'PY'
import hashlib, pathlib, sys
path = pathlib.Path(sys.argv[1])
print(hashlib.sha256(path.read_bytes()).hexdigest())
PY
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) MASTER_URL="$2"; shift 2 ;;
    --artifact-base) ARTIFACT_BASE="$2"; shift 2 ;;
    --client-id) CLIENT_ID="$2"; shift 2 ;;
    --agent-token) AGENT_TOKEN="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --require-mptcp) REQUIRE_MPTCP=1; shift ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$CLIENT_ID" || -z "$AGENT_TOKEN" ]]; then
  echo "usage: install-client-agent.sh --master <url> --client-id <id> --agent-token <token>" >&2
  exit 1
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi

ensure_mptcp_ready "client-agent host" "$REQUIRE_MPTCP" || {
  echo "client-agent host: MPTCP was required but the running kernel does not support it." >&2
  exit 1
}
ensure_time_sync
install_wireguard_tools

STATE_DIR="/var/lib/leikwan-client-agent"
INSTALLER_SHA256="$(current_installer_sha256)"
mkdir -p "$CONFIG_DIR" "$BIN_DIR" "$STATE_DIR"

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
  curl -fsSL "$url" -o "$tmp_path"
  chmod 0755 "$tmp_path"
  mv "$tmp_path" "$path"
}

download_atomic "$ARTIFACT_BASE/artifacts/client-agent.py" "$BIN_DIR/leikwan-client-agent.py"
if [[ "${LEIKWAN_SKIP_UPDATE_HELPER:-0}" != "1" ]]; then
  download_atomic "$ARTIFACT_BASE/artifacts/client-agent-update.sh" "$BIN_DIR/leikwan-client-agent-update"
fi
download_atomic "$ARTIFACT_BASE/artifacts/install-forward-runtime.py" "$BIN_DIR/install_forward_runtime.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-rate-limit.py" "$BIN_DIR/leikwan-rate-limit.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-mptcp-client.py" "$BIN_DIR/leikwan-mptcp-client.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot-bond.py" "$BIN_DIR/leikwan-uot-bond.py"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" "$BIN_DIR/leikwan-bond"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64" "$STATE_DIR/leikwan-mptcp-relay"

cat > "$CONFIG_DIR/config.json" <<JSON
{
  "master_url": "$MASTER_URL",
  "artifact_base_url": "$ARTIFACT_BASE",
  "client_id": $CLIENT_ID,
  "agent_token": "$AGENT_TOKEN",
  "state_dir": "$STATE_DIR",
  "bond_bin": "/usr/local/bin/leikwan-bond",
  "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
  "mptcp_client_bin": "$BIN_DIR/leikwan-mptcp-client.py",
  "log_level": "INFO",
  "installer_version": "$INSTALLER_VERSION",
  "installer_sha256": "$INSTALLER_SHA256"
}
JSON

cat > "/etc/systemd/system/$SERVICE_NAME.service" <<UNIT
[Unit]
Description=Leikwan Client Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 $BIN_DIR/leikwan-client-agent.py --config $CONFIG_DIR/config.json run
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable "$SERVICE_NAME.service"
systemctl restart "$SERVICE_NAME.service"

echo "installed $SERVICE_NAME"
