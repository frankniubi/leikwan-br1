#!/usr/bin/env bash
set -euo pipefail

INSTALLER_VERSION="2026.08.10.1"
MODE="all"
ARTIFACT_BASE=""
BIN_DIR="/usr/local/bin"
INSTALL_ROOT="/opt/leikwan/tcp-accel"
CONFIG_ROOT="/etc/leigod/tcp-accel"
LISTEN_PORT_MIN=7000
LISTEN_PORT_MAX=9000

usage() {
  cat <<'USAGE'
Usage:
  install-tcp-accel.sh [client|slave|sidecar|all] --artifact-base <url> [--bin-dir /usr/local/bin]

Installs the Python-only BR1 tcp-accel pilot components for Debian 12.
Roles:
  client  - tcp_accel.py + leigod-tcp-accel-client@.service
  slave   - tcp_accel.py + leigod_tcp_accel_bridge.py + leigod-tcp-accel-bridge@.service
  sidecar - tcp_accel.py + leigod-tcp-accel-server.service
  all     - all of the above

The installer installs Debian 12 package dependencies itself and does not
activate any pilot traffic. Enable the systemd units only after writing configs.
USAGE
}

if [[ $# -gt 0 && "${1:-}" != --* ]]; then
  MODE="$1"
  shift
fi
case "$MODE" in
  client|slave|sidecar|all) ;;
  -h|--help) usage; exit 0 ;;
  *) echo "unsupported mode: $MODE" >&2; usage >&2; exit 2 ;;
esac

while [[ $# -gt 0 ]]; do
  case "$1" in
    --artifact-base) ARTIFACT_BASE="${2%/}"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --install-root) INSTALL_ROOT="$2"; shift 2 ;;
    --config-dir) CONFIG_ROOT="$2"; shift 2 ;;
    --listen-port-min) LISTEN_PORT_MIN="$2"; shift 2 ;;
    --listen-port-max) LISTEN_PORT_MAX="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ "$(id -u)" != "0" ]]; then
  echo "run as root: sudo bash install-tcp-accel.sh $MODE --artifact-base <url>" >&2
  exit 1
fi

ensure_debian12_dependencies() {
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y python3 curl ca-certificates coreutils iproute2
    return 0
  fi
  for cmd in python3 curl install ip ss sha256sum awk; do
    command -v "$cmd" >/dev/null 2>&1 || { echo "missing required command: $cmd" >&2; return 1; }
  done
}

sha256_file() {
  local path="$1"
  sha256sum "$path" | awk '{print $1}'
}

json_manifest_sha256() {
  local key="$1"
  python3 -c 'import json,sys; entry=json.loads(sys.argv[1]).get(sys.argv[2], {}); print(entry.get("sha256", "") if isinstance(entry, dict) else "")' "${VERSIONS_JSON:-{}}" "$key" 2>/dev/null || true
}

download_checked() {
  local key="$1" url="$2" path="$3" expected tmp actual
  expected="$(json_manifest_sha256 "$key")"
  [[ "$expected" =~ ^[0-9a-fA-F]{64}$ ]] || {
    echo "missing manifest sha256 for $key" >&2
    return 1
  }
  tmp="${path}.new"
  curl -fsSL --max-time 120 "$url" -o "$tmp"
  actual="$(sha256_file "$tmp")"
  if [[ "$actual" != "$expected" ]]; then
    rm -f "$tmp"
    echo "downloaded artifact sha256 mismatch: $key" >&2
    return 1
  fi
  chmod 0755 "$tmp"
  mv "$tmp" "$path"
}

copy_local_checked() {
  local key="$1" relative="$2" path="$3" source
  source="$REPO_ROOT/$relative"
  [[ -f "$source" ]] || { echo "local source missing: $source" >&2; return 1; }
  install -m 0755 "$source" "$path"
}

install_artifact() {
  local key="$1" route="$2" relative="$3" path="$4"
  if [[ -n "$ARTIFACT_BASE" ]]; then
    download_checked "$key" "$ARTIFACT_BASE$route" "$path"
  else
    copy_local_checked "$key" "$relative" "$path"
  fi
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." 2>/dev/null && pwd || printf '')"

ensure_debian12_dependencies

if [[ -n "$ARTIFACT_BASE" ]]; then
  VERSIONS_JSON="$(curl -fsSL --max-time 60 "$ARTIFACT_BASE/artifacts/versions.json")"
  # Edge/middlebox paths occasionally append stray bytes after the JSON
  # object. Keep only through the final closing brace so the manifest
  # stays parseable instead of failing closed as "missing sha256".
  VERSIONS_JSON="$(printf '%s' "$VERSIONS_JSON" | sed 's/\(.*\).*/\1/')"
else
  if [[ -z "$REPO_ROOT" || ! -f "$REPO_ROOT/tools/tcp-accel/tcp_accel.py" ]]; then
    echo "--artifact-base is required when running outside a BR1 source checkout" >&2
    exit 2
  fi
  VERSIONS_JSON="{}"
fi

install -d -m 0755 "$INSTALL_ROOT" "$CONFIG_ROOT" "$BIN_DIR"

install_artifact tcp_accel_runtime /artifacts/leikwan-tcp-accel.py tools/tcp-accel/tcp_accel.py "$INSTALL_ROOT/tcp_accel.py"
python3 -m py_compile "$INSTALL_ROOT/tcp_accel.py"

cat > "$BIN_DIR/leikwan-tcp-accel" <<WRAPPER
#!/usr/bin/env bash
exec /usr/bin/python3 "$INSTALL_ROOT/tcp_accel.py" "\$@"
WRAPPER
chmod 0755 "$BIN_DIR/leikwan-tcp-accel"

if [[ ! -s "$CONFIG_ROOT/token" ]]; then
  umask 077
  python3 - <<'PY' >"$CONFIG_ROOT/token"
import secrets
print(secrets.token_urlsafe(48))
PY
fi
chmod 0600 "$CONFIG_ROOT/token"

install_server_role() {
  if [[ ! -f "$CONFIG_ROOT/server.env" ]]; then
    cat > "$CONFIG_ROOT/server.env" <<'ENV'
TCP_ACCEL_LISTEN=0.0.0.0:29030
TCP_ACCEL_TOKEN_FILE=/etc/leigod/tcp-accel/token
TCP_ACCEL_TCP_USER_TIMEOUT_MS=30000
ENV
    chmod 0600 "$CONFIG_ROOT/server.env"
  fi

  cat > /etc/systemd/system/leigod-tcp-accel-server.service <<'UNIT'
[Unit]
Description=Leikwan BR1 tcp-accel sidecar server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/leigod/tcp-accel/server.env
ExecStart=/usr/bin/python3 /opt/leikwan/tcp-accel/tcp_accel.py --log-level INFO server --listen ${TCP_ACCEL_LISTEN} --tcp-user-timeout-ms ${TCP_ACCEL_TCP_USER_TIMEOUT_MS} --token-file ${TCP_ACCEL_TOKEN_FILE}
Restart=always
RestartSec=1
TimeoutStopSec=5
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT
}

install_client_role() {
  if [[ ! -f "$CONFIG_ROOT/client-through-bridge-example.json" ]]; then
    cat > "$CONFIG_ROOT/client-through-bridge-example.json" <<'JSON'
{
  "token_file": "/etc/leigod/tcp-accel/token",
  "remotes": ["SLAVE_BRIDGE_HOST:8230", "SLAVE_BRIDGE_HOST:8231"],
  "lanes": 4,
  "minimum_lanes": 1,
  "join_timeout_ms": 1200,
  "chunk_size": 32768,
  "lane_queue_frames": 8,
  "tcp_user_timeout_ms": 30000,
  "forwards": [
    {"name": "pilot", "listen": "0.0.0.0:15210", "target": "10.7.1.1:5210"}
  ]
}
JSON
    chmod 0600 "$CONFIG_ROOT/client-through-bridge-example.json"
  fi

  cat > /etc/systemd/system/leigod-tcp-accel-client@.service <<'UNIT'
[Unit]
Description=Leikwan BR1 tcp-accel client %i
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStartPre=/usr/bin/python3 /opt/leikwan/tcp-accel/tcp_accel.py check-client-config --config /etc/leigod/tcp-accel/client-%i.json
ExecStart=/usr/bin/python3 /opt/leikwan/tcp-accel/tcp_accel.py --log-level INFO client-config --config /etc/leigod/tcp-accel/client-%i.json
Restart=always
RestartSec=1
TimeoutStopSec=5
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT
}

install_slave_role() {
  install_artifact tcp_accel_bridge_runtime /artifacts/leikwan-tcp-accel-bridge.py slave-agent/leigod_tcp_accel_bridge.py "$INSTALL_ROOT/leigod_tcp_accel_bridge.py"
  python3 -m py_compile "$INSTALL_ROOT/leigod_tcp_accel_bridge.py"

  if [[ ! -f "$CONFIG_ROOT/bridge-example.json" ]]; then
    cat > "$CONFIG_ROOT/bridge-example.json" <<JSON
{
  "remote": "SIDECAR_OVERLAY_OR_REACHABLE_HOST:29030",
  "connect_timeout_seconds": 10,
  "tcp_user_timeout_ms": 30000,
  "half_close_drain_timeout_ms": 2000,
  "listeners": [
    {"listen": "0.0.0.0:8230", "socks": "127.0.0.1:20455", "line_id": 455},
    {"listen": "0.0.0.0:8231", "socks": "127.0.0.1:20456", "line_id": 456}
  ],
  "slave_listen_port_range": "${LISTEN_PORT_MIN}-${LISTEN_PORT_MAX}"
}
JSON
    chmod 0600 "$CONFIG_ROOT/bridge-example.json"
  fi

  cat > /etc/systemd/system/leigod-tcp-accel-bridge@.service <<'UNIT'
[Unit]
Description=Leikwan BR1 tcp-accel slave SOCKS bridge %i
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStartPre=/usr/bin/python3 /opt/leikwan/tcp-accel/leigod_tcp_accel_bridge.py bridge-validate --config /etc/leigod/tcp-accel/bridge-%i.json
ExecStart=/usr/bin/python3 /opt/leikwan/tcp-accel/leigod_tcp_accel_bridge.py --log-level INFO bridge --config /etc/leigod/tcp-accel/bridge-%i.json
Restart=always
RestartSec=1
TimeoutStopSec=5
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT
}

case "$MODE" in
  client) install_client_role ;;
  slave) install_slave_role ;;
  sidecar) install_server_role ;;
  all) install_client_role; install_slave_role; install_server_role ;;
esac

if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload
fi

cat <<EOF
TCP_ACCEL_INSTALL_OK mode=$MODE
installed_root=$INSTALL_ROOT
config_root=$CONFIG_ROOT
units_installed_not_started=true
EOF
