#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

INSTALLER_VERSION="2026.08.30.1"
SING_BOX_VERSION="1.13.12"
LISTEN_ADDRESS="0.0.0.0"
PORT=""
PASSWORD=""
PASSWORD_SET=0
PORT_SET=0
SERVICE_NAME="shadowsocks-none"
CONFIG_DIR="/etc/leikwan-shadowsocks-none"
NO_START=0

CONFIG_PATH=""
UNIT_PATH=""
SERVICE_USER=""
SERVICE_GROUP=""
SING_BOX_BIN_DIR="/usr/local/libexec/leikwan-shadowsocks-none"
SING_BOX_BIN_PATH=""
BACKUP_ROOT="/var/backups/leikwan-shadowsocks-none"
BACKUP_DIR=""
CONFIG_TMP=""
UNIT_TMP=""
BINARY_TMP=""
ARCHIVE_TMP=""
EXTRACT_DIR=""
MUTATION_STARTED=0
ROLLBACK_DONE=0
PREVIOUS_CONFIG=0
PREVIOUS_UNIT=0
PREVIOUS_BINARY=0
PREVIOUS_ACTIVE=0
PREVIOUS_ENABLED=0
USER_CREATED=0

usage() {
  cat <<'USAGE'
Usage:
  install-shadowsocks-none.sh [options]

Installs a pinned sing-box Shadowsocks server with method "none".

Options:
  --listen-address <address>  Bind address (default: 0.0.0.0)
  --port <1-65535>            Listen port; prompt when omitted (default: 8388)
  --password <value>          Password; prompt when omitted and never echo
  --service-name <name>       systemd unit and service user (default: shadowsocks-none)
  --config-dir <directory>    Configuration directory (default: /etc/leikwan-shadowsocks-none)
  --no-start                  Install files without enabling or starting the unit
  --help                      Show this help

The none method does not encrypt traffic. Do not use it for confidential data.
USAGE
}

die() {
  echo "install-shadowsocks-none: $*" >&2
  exit 1
}

cleanup() {
  [[ -z "$CONFIG_TMP" ]] || rm -f -- "$CONFIG_TMP"
  [[ -z "$UNIT_TMP" ]] || rm -f -- "$UNIT_TMP"
  [[ -z "$BINARY_TMP" ]] || rm -f -- "$BINARY_TMP"
  [[ -z "$ARCHIVE_TMP" ]] || rm -f -- "$ARCHIVE_TMP"
  [[ -z "$EXTRACT_DIR" ]] || rm -rf -- "$EXTRACT_DIR"
}

rollback() {
  [[ "$MUTATION_STARTED" == "1" && "$ROLLBACK_DONE" == "0" ]] || return 0
  ROLLBACK_DONE=1

  set +e
  if [[ "$PREVIOUS_ACTIVE" == "1" ]]; then
    systemctl stop "$SERVICE_NAME.service" >/dev/null 2>&1
  fi
  if [[ "$PREVIOUS_CONFIG" == "1" ]]; then
    cp -a -- "$BACKUP_DIR/config.json" "$CONFIG_PATH"
  else
    rm -f -- "$CONFIG_PATH"
  fi
  if [[ "$PREVIOUS_UNIT" == "1" ]]; then
    cp -a -- "$BACKUP_DIR/unit.service" "$UNIT_PATH"
  else
    rm -f -- "$UNIT_PATH"
  fi
  if [[ "$PREVIOUS_BINARY" == "1" ]]; then
    cp -a -- "$BACKUP_DIR/sing-box" "$SING_BOX_BIN_PATH"
  else
    rm -f -- "$SING_BOX_BIN_PATH"
  fi
  systemctl daemon-reload >/dev/null 2>&1
  if [[ "$PREVIOUS_ENABLED" == "1" ]]; then
    systemctl enable "$SERVICE_NAME.service" >/dev/null 2>&1
  else
    systemctl disable "$SERVICE_NAME.service" >/dev/null 2>&1
  fi
  if [[ "$PREVIOUS_ACTIVE" == "1" ]]; then
    systemctl start "$SERVICE_NAME.service" >/dev/null 2>&1
  fi
  if [[ "$USER_CREATED" == "1" ]]; then
    userdel "$SERVICE_USER" >/dev/null 2>&1
  fi
  set -e
}

handle_error() {
  local code="$1"
  if [[ "$MUTATION_STARTED" == "1" ]]; then
    rollback
    echo "install-shadowsocks-none: installation failed (exit $code); previous files were restored when available" >&2
  else
    echo "install-shadowsocks-none: installation failed (exit $code); no managed files were changed" >&2
  fi
  exit "$code"
}

trap 'handle_error "$?"' ERR
trap cleanup EXIT

require_next_value() {
  [[ $# -ge 2 ]] || die "missing value for $1"
  [[ -n "$2" ]] || die "empty value for $1"
}

validate_port() {
  [[ "$PORT" =~ ^[0-9]+$ ]] || die "port must be an integer from 1 to 65535"
  (( PORT >= 1 && PORT <= 65535 )) || die "port must be an integer from 1 to 65535"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --listen-address)
      require_next_value "$@"
      LISTEN_ADDRESS="$2"
      shift 2
      ;;
    --port)
      require_next_value "$@"
      PORT="$2"
      PORT_SET=1
      shift 2
      ;;
    --password)
      require_next_value "$@"
      PASSWORD="$2"
      PASSWORD_SET=1
      shift 2
      ;;
    --service-name)
      require_next_value "$@"
      SERVICE_NAME="$2"
      shift 2
      ;;
    --config-dir)
      require_next_value "$@"
      CONFIG_DIR="$2"
      shift 2
      ;;
    --no-start)
      NO_START=1
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      die "unknown argument: $1"
      ;;
  esac
done

[[ "$(id -u)" == "0" ]] || die "must run as root"
[[ -r /etc/os-release ]] || die "/etc/os-release is unavailable"
# shellcheck disable=SC1091
. /etc/os-release
case "$ID" in
  debian|ubuntu) ;;
  *) die "unsupported distribution '$ID'; Debian or Ubuntu is required" ;;
esac

[[ "$SERVICE_NAME" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$ ]] || die "service name must start with a letter/underscore and be at most 32 safe characters"
[[ "$CONFIG_DIR" =~ ^/[A-Za-z0-9._+@%/-]+$ ]] || die "config-dir must be an absolute path without whitespace"
[[ "$LISTEN_ADDRESS" != *[[:space:]]* && "$LISTEN_ADDRESS" != *$'\r'* && "$LISTEN_ADDRESS" != *$'\n'* ]] || die "listen address must not contain whitespace or newlines"
[[ -n "$LISTEN_ADDRESS" ]] || die "listen address must not be empty"

if (( !PORT_SET )); then
  [[ -t 0 ]] || die "--port and --password are required when stdin is not a terminal"
  read -r -p "Listen port [8388]: " PORT
  if [[ -z "$PORT" ]]; then PORT="8388"; fi
fi
validate_port

if (( !PASSWORD_SET )); then
  [[ -t 0 ]] || die "--port and --password are required when stdin is not a terminal"
  read -r -s -p "Password: " PASSWORD
  printf '\n' >&2
  read -r -s -p "Confirm password: " PASSWORD_CONFIRM
  printf '\n' >&2
  [[ "$PASSWORD" == "$PASSWORD_CONFIRM" ]] || die "passwords do not match"
  unset PASSWORD_CONFIRM
fi

[[ -n "$PASSWORD" ]] || die "password must not be empty"
[[ "$PASSWORD" != *$'\r'* && "$PASSWORD" != *$'\n'* ]] || die "password must not contain CR/LF"

CONFIG_PATH="$CONFIG_DIR/config.json"
UNIT_PATH="/etc/systemd/system/$SERVICE_NAME.service"
SERVICE_USER="$SERVICE_NAME"
SING_BOX_BIN_PATH="$SING_BOX_BIN_DIR/sing-box"

for command_name in id uname date mktemp mkdir cp mv rm chmod chown useradd userdel getent install; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command is missing: $command_name"
done
command -v systemctl >/dev/null 2>&1 || die "systemctl is required"
systemctl show-environment >/dev/null 2>&1 || die "systemd manager is unavailable"
command -v apt-get >/dev/null 2>&1 || die "apt-get is required"

case "$(uname -m)" in
  x86_64|amd64)
    RELEASE_ARCH="amd64"
    EXPECTED_SHA256="11cf6d5fb93c60525771bc5652b46b734ee033ef72831056735fc658243e1fdb"
    ;;
  aarch64|arm64)
    RELEASE_ARCH="arm64"
    EXPECTED_SHA256="7a899581e82252a6475591fcb50fbecd4faf61c2b2a8a9bc8546cc93239bc48d"
    ;;
  *)
    die "unsupported architecture '$(uname -m)'; sing-box glibc assets support amd64 and arm64"
    ;;
esac

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_DIR="$BACKUP_ROOT/$timestamp-$$"
install -d -m 0700 "$BACKUP_DIR"
if [[ -e "$CONFIG_PATH" ]]; then
  cp -a -- "$CONFIG_PATH" "$BACKUP_DIR/config.json"
  PREVIOUS_CONFIG=1
fi
if [[ -e "$UNIT_PATH" ]]; then
  cp -a -- "$UNIT_PATH" "$BACKUP_DIR/unit.service"
  PREVIOUS_UNIT=1
fi
if [[ -e "$SING_BOX_BIN_PATH" ]]; then
  cp -a -- "$SING_BOX_BIN_PATH" "$BACKUP_DIR/sing-box"
  PREVIOUS_BINARY=1
fi
if systemctl is-active --quiet "$SERVICE_NAME.service"; then
  PREVIOUS_ACTIVE=1
fi
if systemctl is-enabled --quiet "$SERVICE_NAME.service"; then
  PREVIOUS_ENABLED=1
fi

missing_commands=0
for command_name in curl tar sha256sum awk grep find python3 ss; do
  command -v "$command_name" >/dev/null 2>&1 || missing_commands=1
done
[[ -r /etc/ssl/certs/ca-certificates.crt ]] || missing_commands=1
if [[ "$missing_commands" == "1" ]]; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y curl ca-certificates tar gzip coreutils findutils grep python3 iproute2 mawk
fi
for command_name in curl tar sha256sum awk grep find python3 ss; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command is still missing: $command_name"
done

SING_BOX_URL="https://github.com/SagerNet/sing-box/releases/download/v$SING_BOX_VERSION/sing-box-$SING_BOX_VERSION-linux-$RELEASE_ARCH-glibc.tar.gz"
ARCHIVE_TMP="$(mktemp "/tmp/leikwan-sing-box-$SING_BOX_VERSION.XXXXXX.tar.gz")"
curl --fail --location --silent --show-error --retry 2 --connect-timeout 15 --max-time 180 "$SING_BOX_URL" -o "$ARCHIVE_TMP"
echo "$EXPECTED_SHA256  $ARCHIVE_TMP" | sha256sum -c -
EXTRACT_DIR="$(mktemp -d /tmp/leikwan-sing-box.XXXXXX)"
tar -xzf "$ARCHIVE_TMP" -C "$EXTRACT_DIR"
SING_BOX_SOURCE="$(find "$EXTRACT_DIR" -type f -name sing-box -print -quit)"
[[ -n "$SING_BOX_SOURCE" && -x "$SING_BOX_SOURCE" ]] || die "sing-box binary is missing from verified archive"
VERSION_OUTPUT="$("$SING_BOX_SOURCE" version)"
[[ "$VERSION_OUTPUT" == *"sing-box version $SING_BOX_VERSION"* ]] || die "sing-box version verification failed"

MUTATION_STARTED=1
if ! getent passwd "$SERVICE_USER" >/dev/null 2>&1; then
  useradd --system --user-group --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
  USER_CREATED=1
fi
SERVICE_GROUP="$(id -gn "$SERVICE_USER")"

install -d -m 0750 -o root -g root "$CONFIG_DIR"
CONFIG_TMP="$(mktemp "$CONFIG_DIR/.config.json.XXXXXX")"
SS_PASSWORD="$PASSWORD" python3 - "$CONFIG_TMP" "$LISTEN_ADDRESS" "$PORT" <<'PY'
import json
import os
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
payload = {
    "log": {"level": "warn", "timestamp": True},
    "inbounds": [
        {
            "type": "shadowsocks",
            "tag": "ss-in",
            "listen": sys.argv[2],
            "listen_port": int(sys.argv[3]),
            "method": "none",
            "password": os.environ["SS_PASSWORD"],
        }
    ],
    "outbounds": [{"type": "direct", "tag": "direct"}],
}
path.write_text(json.dumps(payload, ensure_ascii=True, indent=2) + "\n", encoding="utf-8")
PY
"$SING_BOX_SOURCE" check -c "$CONFIG_TMP"
chown "$SERVICE_USER:$SERVICE_GROUP" "$CONFIG_TMP"
chmod 0640 "$CONFIG_TMP"
mv -f -- "$CONFIG_TMP" "$CONFIG_PATH"
CONFIG_TMP=""

install -d -m 0755 -o root -g root "$SING_BOX_BIN_DIR"
BINARY_TMP="$(mktemp "$SING_BOX_BIN_DIR/.sing-box.XXXXXX")"
cp -- "$SING_BOX_SOURCE" "$BINARY_TMP"
chown root:root "$BINARY_TMP"
chmod 0755 "$BINARY_TMP"
mv -f -- "$BINARY_TMP" "$SING_BOX_BIN_PATH"
BINARY_TMP=""
rm -f -- "$ARCHIVE_TMP"
ARCHIVE_TMP=""
rm -rf -- "$EXTRACT_DIR"
EXTRACT_DIR=""

if [[ "$NO_START" == "0" && "$PREVIOUS_ACTIVE" == "1" ]]; then
  systemctl stop "$SERVICE_NAME.service"
fi

UNIT_TMP="$(mktemp "/etc/systemd/system/.$SERVICE_NAME.service.XXXXXX")"
cat > "$UNIT_TMP" <<UNIT
[Unit]
Description=Shadowsocks server (none method)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_GROUP
ExecStart=$SING_BOX_BIN_PATH run -c $CONFIG_PATH
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
UNIT
chmod 0644 "$UNIT_TMP"
mv -f -- "$UNIT_TMP" "$UNIT_PATH"
UNIT_TMP=""

systemctl daemon-reload
if [[ "$NO_START" == "1" ]]; then
  echo "installed $SERVICE_NAME.service without starting it"
else
  systemctl enable --now "$SERVICE_NAME.service"
  systemctl is-active --quiet "$SERVICE_NAME.service" || die "service is not active"
  if ! ss -H -lntu | awk -v port="$PORT" '$4 ~ (":" port "$|\\]:" port "$") { found=1 } END { exit !found }'; then
    die "service is active but no TCP/UDP listener was found on port $PORT"
  fi
  echo "started and verified $SERVICE_NAME.service"
fi

unset PASSWORD
echo "service: $SERVICE_NAME.service"
echo "config: $CONFIG_PATH"
echo "listen: $LISTEN_ADDRESS:$PORT"
echo "method: none (plaintext; traffic is not encrypted)"
echo "sing-box: $SING_BOX_VERSION"
echo "backup: $BACKUP_DIR"
