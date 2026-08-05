#!/usr/bin/env bash
set -euo pipefail

INSTALLER_VERSION="2026.08.05.1"
MASTER_URL=""
ARTIFACT_BASE=""
TOKEN=""
GROUP_KEY="default"
REGION="unknown"
CONFIG_DIR="/etc/leikwan-slave"
CONFIG_PATH=""
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-slave-agent"
PUBLIC_IP=""
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0
MODE="install"
TLS_MODE="self-signed"
TLS_MODE_SET=0
TLS_DOMAIN=""
TLS_DOMAIN_SET=0
TLS_EMAIL=""
TLS_EMAIL_SET=0
TLS_WILDCARD=0
TLS_WILDCARD_SET=0
TLS_CERT_PATH="/etc/wstunnel/ssl/server.crt"
TLS_CERT_PATH_SET=0
TLS_KEY_PATH="/etc/wstunnel/ssl/server.key"
TLS_KEY_PATH_SET=0
CLOUDFLARE_EMAIL=""
CLOUDFLARE_EMAIL_SET=0
CLOUDFLARE_GLOBAL_KEY=""
CLOUDFLARE_GLOBAL_KEY_SET=0
FORCE_REGISTER=0

current_installer_sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$0" | awk '{print $1}'
    return 0
  fi
  shasum -a 256 "$0" | awk '{print $1}'
}

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

download_atomic() {
  local url="$1"
  local path="$2"
  local tmp_path="${path}.new"
  curl -fsSL "$url" -o "$tmp_path"
  chmod 0755 "$tmp_path"
  mv "$tmp_path" "$path"
}

download_manifest_artifact() {
  local key="$1" url="$2" path="$3" expected_sha256 expected_size_bytes
  expected_sha256="$(_vers_sha256 "$key")"
  expected_size_bytes="$(_vers_size_bytes "$key")"
  if [[ ! "$expected_sha256" =~ ^[a-f0-9]{64}$ || ! "$expected_size_bytes" =~ ^[1-9][0-9]*$ ]]; then
    echo "invalid or missing artifact manifest pin: $key" >&2
    return 1
  fi
  if check_manifest_artifact_current "$path" "$expected_sha256" "$expected_size_bytes"; then
    echo "  $key up to date, skipping download"
    return 0
  fi
  download_atomic "$url" "$path"
  if ! check_manifest_artifact_current "$path" "$expected_sha256" "$expected_size_bytes"; then
    rm -f "$path"
    echo "downloaded artifact hash or size mismatch: $key" >&2
    return 1
  fi
}

check_manifest_artifact_current() {
  local local_path="$1" expected_sha256="$2" expected_size_bytes="$3"
  [[ -f "$local_path" && "$expected_sha256" =~ ^[a-f0-9]{64}$ && "$expected_size_bytes" =~ ^[1-9][0-9]*$ ]] || return 1
  local actual_sha256 actual_size_bytes
  actual_sha256=$(sha256sum "$local_path" 2>/dev/null | awk '{print $1}')
  actual_size_bytes=$(wc -c < "$local_path" | tr -d '[:space:]')
  [[ "$actual_sha256" == "$expected_sha256" && "$actual_size_bytes" == "$expected_size_bytes" ]]
}

# Returns 0 if local file exists and sha256 matches expected; 1 otherwise.
check_binary_current() {
  local local_path="$1" expected_sha256="$2"
  [[ -f "$local_path" && -n "$expected_sha256" ]] || return 1
  local actual
  actual=$(sha256sum "$local_path" 2>/dev/null | awk '{print $1}')
  [[ "$actual" == "$expected_sha256" ]]
}

# Extract sha256 for a versions.json key from the pre-fetched $_VERSIONS_JSON variable.
_vers_sha256() {
  python3 -c "
import json, sys
key = sys.argv[2]
try:
    d = json.loads(sys.argv[1])
    entry = d.get(key)
    print(entry.get('sha256', '') if isinstance(entry, dict) else '')
except Exception:
    print('')
" "$_VERSIONS_JSON" "$1" 2>/dev/null || echo ''
}

_vers_size_bytes() {
  python3 -c "
import json, sys
key = sys.argv[2]
try:
    d = json.loads(sys.argv[1])
    entry = d.get(key)
    print(entry.get('size_bytes', '') if isinstance(entry, dict) else '')
except Exception:
    print('')
" "$_VERSIONS_JSON" "$1" 2>/dev/null || echo ''
}

json_config_value() {
  local key="$1"
  local default_value="${2:-}"
  python3 - <<'PY' "$CONFIG_PATH" "$key" "$default_value"
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
key = sys.argv[2]
default = sys.argv[3]
if not path.exists():
    print(default)
    raise SystemExit(0)
try:
    payload = json.loads(path.read_text(encoding="utf-8"))
except Exception:
    print(default)
    raise SystemExit(0)
value = payload.get(key, default) if isinstance(payload, dict) else default
if isinstance(value, bool):
    print("true" if value else "false")
elif value is None:
    print("")
else:
    print(value)
PY
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) MASTER_URL="$2"; shift 2 ;;
    --artifact-base) ARTIFACT_BASE="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --group) GROUP_KEY="$2"; shift 2 ;;
    --region) REGION="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --config) CONFIG_PATH="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --public-ip) PUBLIC_IP="$2"; shift 2 ;;
    --tls-mode) TLS_MODE="$2"; TLS_MODE_SET=1; shift 2 ;;
    --tls-domain) TLS_DOMAIN="$2"; TLS_DOMAIN_SET=1; shift 2 ;;
    --tls-email) TLS_EMAIL="$2"; TLS_EMAIL_SET=1; shift 2 ;;
    --tls-wildcard) TLS_WILDCARD="$2"; TLS_WILDCARD_SET=1; shift 2 ;;
    --tls-cert|--tls-cert-path) TLS_CERT_PATH="$2"; TLS_CERT_PATH_SET=1; shift 2 ;;
    --tls-key|--tls-key-path) TLS_KEY_PATH="$2"; TLS_KEY_PATH_SET=1; shift 2 ;;
    --cloudflare-email) CLOUDFLARE_EMAIL="$2"; CLOUDFLARE_EMAIL_SET=1; shift 2 ;;
    --cloudflare-global-key) CLOUDFLARE_GLOBAL_KEY="$2"; CLOUDFLARE_GLOBAL_KEY_SET=1; shift 2 ;;
    --force-register) FORCE_REGISTER=1; shift ;;
    --update) MODE="update"; shift ;;
    --check-update) MODE="check"; shift ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$CONFIG_PATH" ]]; then
  CONFIG_PATH="$CONFIG_DIR/config.json"
else
  CONFIG_DIR="$(dirname "$CONFIG_PATH")"
fi

if [[ -f "$CONFIG_PATH" ]]; then
  if [[ "$TLS_MODE_SET" != "1" ]]; then
    TLS_MODE="$(json_config_value tls_mode self-signed)"
  fi
  if [[ "$TLS_DOMAIN_SET" != "1" ]]; then
    TLS_DOMAIN="$(json_config_value tls_domain)"
  fi
  if [[ "$TLS_EMAIL_SET" != "1" ]]; then
    TLS_EMAIL="$(json_config_value tls_email)"
  fi
  if [[ "$TLS_WILDCARD_SET" != "1" ]]; then
    TLS_WILDCARD="$(json_config_value tls_wildcard 0)"
  fi
  if [[ "$TLS_CERT_PATH_SET" != "1" ]]; then
    TLS_CERT_PATH="$(json_config_value tls_cert_path /etc/wstunnel/ssl/server.crt)"
  fi
  if [[ "$TLS_KEY_PATH_SET" != "1" ]]; then
    TLS_KEY_PATH="$(json_config_value tls_key_path /etc/wstunnel/ssl/server.key)"
  fi
  if [[ "$CLOUDFLARE_EMAIL_SET" != "1" ]]; then
    CLOUDFLARE_EMAIL="$(json_config_value cloudflare_email)"
  fi
  if [[ "$CLOUDFLARE_GLOBAL_KEY_SET" != "1" ]]; then
    CLOUDFLARE_GLOBAL_KEY="$(json_config_value cloudflare_global_key)"
  fi
fi

if [[ "$MODE" != "install" ]]; then
  if [[ ! -f "$CONFIG_PATH" ]]; then
    echo "config file not found: $CONFIG_PATH" >&2
    exit 1
  fi
  if [[ -z "$MASTER_URL" ]]; then
    MASTER_URL="$(json_config_value master_url)"
  fi
  if [[ -z "$ARTIFACT_BASE" ]]; then
    ARTIFACT_BASE="$(json_config_value artifact_base_url)"
  fi
  if [[ -z "$TOKEN" ]]; then
    TOKEN="$(json_config_value enrollment_token)"
  fi
  if [[ "$GROUP_KEY" == "default" ]]; then
    GROUP_KEY="$(json_config_value group_key default)"
  fi
  if [[ "$REGION" == "unknown" ]]; then
    REGION="$(json_config_value region unknown)"
  fi
  if [[ -z "$PUBLIC_IP" ]]; then
    PUBLIC_IP="$(json_config_value public_ip)"
  fi
  if [[ "$MODE" == "check" ]]; then
    CURRENT_INSTALLER_VERSION="$(json_config_value installer_version)"
    CURRENT_INSTALLER_SHA256="$(json_config_value installer_sha256)"
    TARGET_INSTALLER_SHA256="$(current_installer_sha256)"
    if [[ "$CURRENT_INSTALLER_VERSION" == "$INSTALLER_VERSION" && "$CURRENT_INSTALLER_SHA256" == "$TARGET_INSTALLER_SHA256" ]]; then
      echo "up-to-date: installer_version=$CURRENT_INSTALLER_VERSION installer_sha256=$CURRENT_INSTALLER_SHA256"
    else
      echo "update-available: installer_version=${CURRENT_INSTALLER_VERSION:-unknown} target=$INSTALLER_VERSION installer_sha256=${CURRENT_INSTALLER_SHA256:-unknown} target_sha256=$TARGET_INSTALLER_SHA256"
    fi
    exit 0
  fi
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi

if [[ -z "$MASTER_URL" || -z "$TOKEN" ]]; then
  echo "usage: install-slave.sh --master <url> --token <enrollment-token>" >&2
  exit 1
fi

case "$TLS_MODE" in
  self-signed|letsencrypt|existing) ;;
  *)
    echo "invalid tls mode: $TLS_MODE" >&2
    exit 1
    ;;
esac

case "$TLS_WILDCARD" in
  0|1) ;;
  *)
    echo "invalid tls wildcard flag: $TLS_WILDCARD" >&2
    exit 1
    ;;
esac

if [[ "$TLS_MODE" == "letsencrypt" ]]; then
  if [[ -z "$TLS_DOMAIN" || -z "$TLS_EMAIL" ]]; then
    echo "letsencrypt mode requires --tls-domain and --tls-email" >&2
    exit 1
  fi
  if [[ "$TLS_WILDCARD" == "1" && ( -z "$CLOUDFLARE_EMAIL" || -z "$CLOUDFLARE_GLOBAL_KEY" ) ]]; then
    echo "wildcard letsencrypt mode requires --cloudflare-email and --cloudflare-global-key" >&2
    exit 1
  fi
fi

if [[ "$TLS_MODE" == "existing" && ( -z "$TLS_CERT_PATH" || -z "$TLS_KEY_PATH" ) ]]; then
  echo "existing tls mode requires --tls-cert and --tls-key" >&2
  exit 1
fi

report_mptcp_status
ensure_time_sync
install_wireguard_tools

mkdir -p "$CONFIG_DIR" "$BIN_DIR"
_VERSIONS_JSON=$(curl --connect-timeout 10 --max-time 30 -fsSL \
    "$ARTIFACT_BASE/artifacts/versions.json" 2>/dev/null || echo '{}')

_sha=$(_vers_sha256 slave_agent)
if check_binary_current "$BIN_DIR/leikwan-slave-agent.py" "$_sha"; then
  echo "  slave-agent.py up to date, skipping download"
else
  download_atomic "$ARTIFACT_BASE/artifacts/slave-agent.py" "$BIN_DIR/leikwan-slave-agent.py"
fi
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-preconnect-relay.py" "$BIN_DIR/leikwan-preconnect-relay.py"
download_manifest_artifact leigod_forward_relay "$ARTIFACT_BASE/artifacts/leikwan-leigod-forward-relay.py" "$BIN_DIR/leikwan-leigod-forward-relay.py"
download_atomic "$ARTIFACT_BASE/artifacts/slave-agent-update.sh" "$BIN_DIR/leikwan-slave-update"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"

_sha=$(_vers_sha256 wstunnel_linux_amd64)
if check_binary_current "$BIN_DIR/wstunnel" "$_sha"; then
  echo "  wstunnel up to date, skipping download"
else
  download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"
fi

_sha=$(_vers_sha256 leikwan_ingress_linux_amd64)
if check_binary_current "$BIN_DIR/leikwan-ingress" "$_sha"; then
  echo "  leikwan-ingress up to date, skipping download"
else
  download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"
fi

BOND_SHA256="$(_vers_sha256 leikwan_bond_linux_amd64)"
BOND_SIZE_BYTES="$(_vers_size_bytes leikwan_bond_linux_amd64)"
download_manifest_artifact leikwan_bond_linux_amd64 \
  "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" \
  "$BIN_DIR/leikwan-bond"

# --- LeiGod basis binaries (gost UoT carrier + recovered LeiGod accelerator) ---
# Both are self-hosted under leikwan's own artifact store (NOT the upstream leigod-forward
# one-liner) and are gated by check_binary_current against versions.json sha256 so install
# does not re-download an unchanged binary (the 100GB/day churn lesson).
_sha=$(_vers_sha256 gost_linux_amd64)
if check_binary_current "$BIN_DIR/gost" "$_sha"; then
  echo "  gost up to date, skipping download"
else
  download_atomic "$ARTIFACT_BASE/artifacts/gost/gost_linux_amd64" "$BIN_DIR/gost"
fi

_sha=$(_vers_sha256 leigod_linux_amd64)
if check_binary_current "$BIN_DIR/leigod-linux-amd64" "$_sha"; then
  echo "  leigod accelerator up to date, skipping download"
else
  download_atomic "$ARTIFACT_BASE/artifacts/leigod/leigod-linux-amd64" "$BIN_DIR/leigod-linux-amd64"
fi

# lguot: CPU-efficient UDP-over-TCP carrier (batched recvmmsg/sendmmsg) that replaces gost on the
# leigod arm when a combination opts into it (leigod_uot_impl=lguot). ~2.4x throughput at 1/3 CPU.
_sha=$(_vers_sha256 lguot_linux_amd64)
if check_binary_current "$BIN_DIR/lguot" "$_sha"; then
  echo "  lguot up to date, skipping download"
else
  download_atomic "$ARTIFACT_BASE/artifacts/lguot/lguot_linux_amd64" "$BIN_DIR/lguot"
fi

# --- WireGuard watchdog ---
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" "$BIN_DIR/leikwan-wg-watchdog.sh"
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

INSTALLER_SHA256="$(current_installer_sha256)"
python3 - <<'PY' "$CONFIG_PATH" "$MASTER_URL" "$ARTIFACT_BASE" "$TOKEN" "$GROUP_KEY" "$REGION" "$PUBLIC_IP" "$SUPPORTS_MPTCP" "$TLS_MODE" "$TLS_DOMAIN" "$TLS_EMAIL" "$TLS_WILDCARD" "$TLS_CERT_PATH" "$TLS_KEY_PATH" "$CLOUDFLARE_EMAIL" "$CLOUDFLARE_GLOBAL_KEY" "$INSTALLER_VERSION" "$INSTALLER_SHA256" "$BOND_SHA256" "$BOND_SIZE_BYTES"
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
master_url = sys.argv[2]
artifact_base_url = sys.argv[3]
token = sys.argv[4]
group_key = sys.argv[5]
region = sys.argv[6]
public_ip = sys.argv[7]
supports_mptcp = sys.argv[8].strip().lower() == "true"
tls_mode = sys.argv[9]
tls_domain = sys.argv[10]
tls_email = sys.argv[11]
tls_wildcard = int(sys.argv[12] or "0")
tls_cert_path = sys.argv[13]
tls_key_path = sys.argv[14]
cloudflare_email = sys.argv[15]
cloudflare_global_key = sys.argv[16]
installer_version = sys.argv[17]
installer_sha256 = sys.argv[18]
bond_sha256 = sys.argv[19]
bond_size_bytes = int(sys.argv[20])
payload = {}
if path.exists():
    try:
        loaded = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(loaded, dict):
            payload = loaded
    except Exception:
        payload = {}
payload.update({
    "master_url": master_url,
    "artifact_base_url": artifact_base_url,
    "enrollment_token": token,
    "group_key": group_key,
    "region": region,
    "public_ip": public_ip,
    "supports_mptcp": supports_mptcp,
    "tls_mode": tls_mode,
    "tls_domain": tls_domain,
    "tls_email": tls_email,
    "tls_wildcard": tls_wildcard,
    "tls_cert_path": tls_cert_path,
    "tls_key_path": tls_key_path,
    "cloudflare_email": cloudflare_email,
    "cloudflare_global_key": cloudflare_global_key,
    "state_dir": payload.get("state_dir", "/var/lib/leikwan-slave"),
    "ingress_bin": payload.get("ingress_bin", "/usr/local/bin/leikwan-ingress"),
    "bond_bin": payload.get("bond_bin", "/usr/local/bin/leikwan-bond"),
    "bond_sha256": bond_sha256,
    "bond_size_bytes": bond_size_bytes,
    "gost_bin": payload.get("gost_bin", "/usr/local/bin/gost"),
    "leigod_bin": payload.get("leigod_bin", "/usr/local/bin/leigod-linux-amd64"),
    "log_level": payload.get("log_level", "INFO"),
    "installer_version": installer_version,
    "installer_sha256": installer_sha256,
})
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
PY

if [[ "$FORCE_REGISTER" == "1" ]]; then
  python3 - <<'PY' "$CONFIG_PATH"
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
payload = {}
if path.exists():
    try:
        loaded = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(loaded, dict):
            payload = loaded
    except Exception:
        payload = {}
payload.pop("node_id", None)
payload.pop("node_token", None)
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
PY
fi

cat > "/etc/systemd/system/$SERVICE_NAME.service" <<UNIT
[Unit]
Description=Leikwan Slave Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 $BIN_DIR/leikwan-slave-agent.py --config $CONFIG_PATH run
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

NODE_TOKEN="$(json_config_value node_token)"
if [[ -z "$NODE_TOKEN" || "$FORCE_REGISTER" == "1" ]]; then
  /usr/bin/python3 "$BIN_DIR/leikwan-slave-agent.py" --config "$CONFIG_PATH" register || true
fi
systemctl daemon-reload
systemctl enable "$SERVICE_NAME.service"
systemctl restart "$SERVICE_NAME.service"
echo "installed $SERVICE_NAME"
