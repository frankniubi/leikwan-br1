#!/usr/bin/env bash
set -euo pipefail
umask 077

INSTALLER_VERSION="2026.09.24.3"
MASTER_URL=""
ARTIFACT_BASE=""
DOWNLOAD_BASE=""
CLIENT_ID=""
AGENT_TOKEN=""
CONFIG_DIR="/etc/leikwan-client-agent"
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-client-agent"
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0
REQUIRE_MPTCP=0
# Restart/reload the agent even when every artifact, config.json and the unit
# file are already current (--force-restart or LEIKWAN_INSTALL_FORCE_RESTART=1).
FORCE_RESTART="${LEIKWAN_INSTALL_FORCE_RESTART:-0}"
# auto (preserve proxy.env unless HTTP(S)_PROXY is exported), set or clear.
PROXY_MODE="auto"
PROXY_URL=""
TIME_SYNC_TIMEOUT_SECONDS="${LEIKWAN_TIME_SYNC_TIMEOUT_SECONDS:-60}"
STATE_DIR="/var/lib/leikwan-client-agent"
RUNTIME_ARCH="amd64"
AGENT_RUNTIME_MODE="python"
AGENT_BINARY_KEY=""
AGENT_BINARY_PATH=""
AGENT_BINARY_URL=""
ULTRA_RUNTIME_KEY="ultra_client_runtime"
ULTRA_RUNTIME_PATH="artifacts/ultra/ultra-client_linux_amd64"
UOT_BOND_RUNTIME_KEY="leikwan_uot_bond_linux_amd64"
UOT_BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-uot-bond_linux_amd64"
WSTUNNEL_RUNTIME_KEY="wstunnel_linux_amd64"
WSTUNNEL_RUNTIME_PATH="artifacts/wstunnel/wstunnel_linux_amd64"
INGRESS_RUNTIME_KEY="leikwan_ingress_linux_amd64"
INGRESS_RUNTIME_PATH="artifacts/wstunnel/leikwan-ingress_linux_amd64"
BOND_RUNTIME_KEY="leikwan_bond_linux_amd64"
BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-bond_linux_amd64"

die() {
  echo "install-client-agent: $*" >&2
  exit 1
}

validate_install_arguments() {
  [[ "$(id -u)" == "0" ]] || die "must run as root"

  local arch
  arch="$(uname -m)"
  case "$arch" in
    x86_64|amd64)
      RUNTIME_ARCH="amd64"
      ULTRA_RUNTIME_KEY="ultra_client_runtime"
      ULTRA_RUNTIME_PATH="artifacts/ultra/ultra-client_linux_amd64"
      UOT_BOND_RUNTIME_KEY="leikwan_uot_bond_linux_amd64"
      UOT_BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-uot-bond_linux_amd64"
      WSTUNNEL_RUNTIME_KEY="wstunnel_linux_amd64"
      WSTUNNEL_RUNTIME_PATH="artifacts/wstunnel/wstunnel_linux_amd64"
      INGRESS_RUNTIME_KEY="leikwan_ingress_linux_amd64"
      INGRESS_RUNTIME_PATH="artifacts/wstunnel/leikwan-ingress_linux_amd64"
      BOND_RUNTIME_KEY="leikwan_bond_linux_amd64"
      BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-bond_linux_amd64"
      ;;
    aarch64|arm64)
      RUNTIME_ARCH="arm64"
      ULTRA_RUNTIME_KEY="ultra_client_runtime_linux_arm64"
      ULTRA_RUNTIME_PATH="artifacts/ultra/ultra-client_linux_arm64"
      UOT_BOND_RUNTIME_KEY="leikwan_uot_bond_linux_arm64"
      UOT_BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-uot-bond_linux_arm64"
      WSTUNNEL_RUNTIME_KEY="wstunnel_linux_arm64"
      WSTUNNEL_RUNTIME_PATH="artifacts/wstunnel/wstunnel_linux_arm64"
      INGRESS_RUNTIME_KEY="leikwan_ingress_linux_arm64"
      INGRESS_RUNTIME_PATH="artifacts/wstunnel/leikwan-ingress_linux_arm64"
      BOND_RUNTIME_KEY="leikwan_bond_linux_arm64"
      BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-bond_linux_arm64"
      ;;
    *) die "unsupported architecture '$arch'; published Linux client artifacts support x86_64/amd64 and aarch64/arm64" ;
  esac

  # The generated runtime has a stable /usr/local/bin contract for all helper
  # processes. Rejecting a different bin-dir is safer than producing units that
  # silently point at a mixture of custom and default paths.
  [[ "$BIN_DIR" == "/usr/local/bin" ]] || die "--bin-dir must be /usr/local/bin for the complete client-agent runtime"
  [[ "$CLIENT_ID" =~ ^[1-9][0-9]*$ ]] || die "client id must be a positive integer"
  [[ -n "$AGENT_TOKEN" ]] || die "agent token must not be empty"

  local value
  for value in "$MASTER_URL" "$ARTIFACT_BASE" "$DOWNLOAD_BASE" "$AGENT_TOKEN" "$CONFIG_DIR" "$BIN_DIR" "$SERVICE_NAME"; do
    [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || die "arguments must not contain newlines"
  done
  [[ "$SERVICE_NAME" =~ ^[A-Za-z0-9_.@-]+$ ]] || die "invalid service name"
}

bootstrap_dependencies() {
  local -a required_commands=(bash curl python3 sha256sum awk grep sed install mktemp cat chmod mv id uname systemctl timedatectl dpkg-query apt-cache sleep seq journalctl sysctl flock)
  local missing=() command_name
  for command_name in "${required_commands[@]}"; do
    command -v "$command_name" >/dev/null 2>&1 || missing+=("$command_name")
  done
  if ((${#missing[@]} > 0)) || [[ ! -r /etc/ssl/certs/ca-certificates.crt ]]; then
    command -v apt-get >/dev/null 2>&1 || die "missing required commands (${missing[*]}) and apt-get is unavailable for bootstrap"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y bash curl ca-certificates python3 coreutils grep sed mawk procps iproute2 nftables util-linux
  fi

  for command_name in "${required_commands[@]}"; do
    command -v "$command_name" >/dev/null 2>&1 || die "required command is still missing after bootstrap: $command_name"
  done
  systemctl show-environment >/dev/null 2>&1 || die "systemd manager is unavailable; run this installer on a booted Debian systemd host"
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
        if [[ "$RUNTIME_ARCH" == "arm64" ]]; then
          echo "linux-image-cloud-arm64 linux-headers-cloud-arm64"
        else
          echo "linux-image-cloud-amd64 linux-headers-cloud-amd64"
        fi
      else
        if [[ "$RUNTIME_ARCH" == "arm64" ]]; then
          echo "linux-image-arm64 linux-headers-arm64"
        else
          echo "linux-image-amd64 linux-headers-amd64"
        fi
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
  if [[ "$required" != "1" && "$AUTO_INSTALL_MPTCP_KERNEL" != "1" ]]; then
    return 0
  fi
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
      command -v reboot >/dev/null 2>&1 || die "reboot command is unavailable after kernel installation"
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
  command -v apt-get >/dev/null 2>&1 || die "wireguard-tools is missing and apt-get is unavailable"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y --no-install-recommends wireguard-tools
}

time_sync_ready() {
  timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi '^yes$'
}

time_service_active() {
  local unit
  for unit in systemd-timesyncd.service chrony.service chronyd.service ntpsec.service ntp.service ntpd.service; do
    if systemctl is-active --quiet "$unit"; then
      printf '%s\n' "$unit"
      return 0
    fi
  done
  return 1
}

ensure_time_sync() {
  if time_sync_ready; then
    return 0
  fi
  command -v timedatectl >/dev/null 2>&1 || die "timedatectl is required to verify clock synchronization"
  command -v apt-get >/dev/null 2>&1 || die "clock is not synchronized and apt-get is unavailable"

  local package="" unit="" candidate installed active_unit=""
  active_unit="$(time_service_active 2>/dev/null || true)"
  case "$active_unit" in
    systemd-timesyncd.service) package="systemd-timesyncd"; unit="$active_unit" ;;
    ntpsec.service) package="ntpsec"; unit="$active_unit" ;;
    chrony.service|chronyd.service) package="chrony"; unit="$active_unit" ;;
    ntp.service|ntpd.service) package="ntp"; unit="$active_unit" ;;
  esac
  if [[ -z "$package" ]]; then
    for candidate in systemd-timesyncd ntpsec chrony ntp; do
      installed=""
      if dpkg-query -W -f='${Status}' "$candidate" 2>/dev/null | grep -q 'install ok installed'; then
        installed=1
      fi
      if [[ -n "$installed" ]] || apt-cache show "$candidate" >/dev/null 2>&1; then
        package="$candidate"
        break
      fi
    done
  fi
  [[ -n "$package" ]] || die "no supported Debian time-sync package is available (tried systemd-timesyncd, ntpsec, chrony, ntp)"

  if ! dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q 'install ok installed'; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y "$package"
  fi

  case "$package" in
    systemd-timesyncd) unit="systemd-timesyncd.service" ;;
    ntpsec) unit="ntpsec.service" ;;
    chrony) unit="chrony.service" ;;
    ntp) unit="ntp.service" ;;
  esac
  systemctl unmask "$unit" >/dev/null 2>&1 || true
  systemctl enable --now "$unit" >/dev/null 2>&1 || true

  local active_unit=""
  active_unit="$(time_service_active 2>/dev/null || true)"
  local elapsed=0
  while ((elapsed < TIME_SYNC_TIMEOUT_SECONDS)); do
    if time_sync_ready; then
      echo "clock synchronized via ${active_unit:-$unit}"
      return 0
    fi
    sleep 1
    elapsed=$((elapsed + 1))
  done
  die "clock synchronization failed after ${TIME_SYNC_TIMEOUT_SECONDS}s (service ${active_unit:-$unit}); refusing to continue with HTTPS"
}

current_installer_sha256() {
  if [[ ! -f "$0" ]]; then
    printf '\n'
    return 0
  fi
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
    --download-base) DOWNLOAD_BASE="$2"; shift 2 ;;
    --client-id) CLIENT_ID="$2"; shift 2 ;;
    --agent-token) AGENT_TOKEN="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --require-mptcp) REQUIRE_MPTCP=1; shift ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    --force-restart) FORCE_RESTART=1; shift ;;
    # --proxy <url> writes proxy.env and uses it for this run; --proxy "" or
    # --clear-proxy empties it. Without either, proxy.env is left as is unless
    # HTTP(S)_PROXY is set in the environment.
    --proxy)
      [[ $# -ge 2 ]] || { echo "--proxy needs a value (use \"\" to clear)" >&2; exit 1; }
      PROXY_URL="$2"
      if [[ -z "$PROXY_URL" ]]; then PROXY_MODE="clear"; else PROXY_MODE="set"; fi
      shift 2
      ;;
    --clear-proxy) PROXY_MODE="clear"; PROXY_URL=""; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ "$PROXY_MODE" == "set" ]]; then
  if [[ "$PROXY_URL" == *$'\n'* || "$PROXY_URL" == *$'\r'* ]]; then
    echo "invalid newline in --proxy" >&2
    exit 1
  fi
  # The same proxy carries this run's downloads.
  export HTTP_PROXY="$PROXY_URL" HTTPS_PROXY="$PROXY_URL" http_proxy="$PROXY_URL" https_proxy="$PROXY_URL"
fi

if [[ -z "$MASTER_URL" || -z "$CLIENT_ID" || -z "$AGENT_TOKEN" ]]; then
  echo "usage: install-client-agent.sh --master <url> --client-id <id> --agent-token <token>" >&2
  exit 1
fi

# The control plane is intentionally pinned to Cloudflare. The user-supplied
# address may still be used as the artifact source when --artifact-base is omitted.
REQUESTED_MASTER_URL="$MASTER_URL"
MASTER_URL="https://v3.leikwanhost.com"
if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$REQUESTED_MASTER_URL"
fi
if [[ -z "$DOWNLOAD_BASE" ]]; then
  DOWNLOAD_BASE="$ARTIFACT_BASE"
fi

validate_install_arguments
bootstrap_dependencies

ensure_mptcp_ready "client-agent host" "$REQUIRE_MPTCP" || {
  echo "client-agent host: MPTCP was required but the running kernel does not support it." >&2
  exit 1
}
ensure_time_sync
install_wireguard_tools

INSTALLER_SHA256="$(current_installer_sha256)"
install -d -m 0755 "$BIN_DIR"
install -d -m 0700 "$CONFIG_DIR" "$STATE_DIR"

ensure_private_dir() {
  local path="$1"
  if [[ -e "$path" && ! -d "$path" ]]; then
    die "expected directory path is occupied by a non-directory: $path"
  fi
  if [[ ! -d "$path" ]]; then
    install -d -m 0700 "$path"
  fi
}

# These directories may be shared with an existing Ultra service.  Preserve
# their current mode and ownership; only newly created directories are private.
ensure_private_dir /etc/ultra
ensure_private_dir /run/ultra
ensure_private_dir /var/lib/ultra

LOCK_FILE="/run/lock/leikwan-client-agent-install.lock"
install -d -m 0755 /run/lock
exec 9>"$LOCK_FILE"
chmod 0600 "$LOCK_FILE"
if ! flock -n 9; then
  die "another client-agent installation is already running"
fi

SERVICE_WAS_ACTIVE=0
SERVICE_WAS_ENABLED=0
if systemctl is-active --quiet "$SERVICE_NAME.service"; then
  SERVICE_WAS_ACTIVE=1
fi
if systemctl is-enabled --quiet "$SERVICE_NAME.service"; then
  SERVICE_WAS_ENABLED=1
fi

ROLLBACK_DIR="$STATE_DIR/.rollback-${INSTALLER_VERSION}-$$"
install -d -m 0700 "$ROLLBACK_DIR"
declare -a BACKUP_PATHS=()
declare -A BACKUP_EXISTS=()
INSTALL_COMMITTED=0
# Change tracking: an update run whose artifacts, config and unit are all
# current must not restart the agent (a no-op update used to restart it every
# retry window). Anything that changes what the service runs marks it here.
INSTALL_CHANGED=0
UNIT_CHANGED=0
declare -a INSTALL_CHANGE_REASONS=()

mark_install_changed() {
  INSTALL_CHANGED=1
  INSTALL_CHANGE_REASONS+=("$1")
}

files_identical() {
  local left="$1" right="$2" left_sum right_sum
  [[ -f "$left" && -f "$right" ]] || return 1
  left_sum="$(sha256sum "$left" | awk '{print $1}')"
  right_sum="$(sha256sum "$right" | awk '{print $1}')"
  [[ -n "$left_sum" && "$left_sum" == "$right_sum" ]]
}

# Replace target with staged only when the bytes differ; records the change.
install_file_if_changed() {
  local staged="$1" target="$2" mode="$3" reason="$4"
  if files_identical "$staged" "$target"; then
    rm -f "$staged"
    chmod "$mode" "$target"
    return 0
  fi
  backup_existing "$target"
  chmod "$mode" "$staged"
  mv "$staged" "$target"
  mark_install_changed "$reason"
}

backup_existing() {
  local path="$1" rel backup
  if [[ -n "${BACKUP_EXISTS[$path]+present}" ]]; then
    return 0
  fi
  rel="${path#/}"
  backup="$ROLLBACK_DIR/$rel"
  if [[ -e "$path" || -L "$path" ]]; then
    install -d -m 0700 "$(dirname "$backup")"
    cp -a "$path" "$backup"
    BACKUP_EXISTS["$path"]=1
  else
    BACKUP_EXISTS["$path"]=0
  fi
  BACKUP_PATHS+=("$path")
}

rollback_install() {
  local index path rel backup
  for ((index=${#BACKUP_PATHS[@]} - 1; index >= 0; index--)); do
    path="${BACKUP_PATHS[$index]}"
    rel="${path#/}"
    backup="$ROLLBACK_DIR/$rel"
    if [[ "${BACKUP_EXISTS[$path]}" == "1" ]]; then
      rm -rf "$path"
      install -d -m 0755 "$(dirname "$path")"
      cp -a "$backup" "$path"
    else
      rm -rf "$path"
    fi
  done
  if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload >/dev/null 2>&1 || true
    if [[ "$SERVICE_WAS_ACTIVE" == "1" ]]; then
      systemctl start "$SERVICE_NAME.service" >/dev/null 2>&1 || true
    else
      systemctl stop "$SERVICE_NAME.service" >/dev/null 2>&1 || true
    fi
    if [[ "$SERVICE_WAS_ENABLED" == "1" ]]; then
      systemctl enable "$SERVICE_NAME.service" >/dev/null 2>&1 || true
    else
      systemctl disable "$SERVICE_NAME.service" >/dev/null 2>&1 || true
    fi
  fi
}

finish_install() {
  local status=$?
  trap - EXIT
  if [[ "$status" -ne 0 && "$INSTALL_COMMITTED" != "1" ]]; then
    rollback_install
    rm -rf "$ROLLBACK_DIR"
  elif [[ "$INSTALL_COMMITTED" == "1" ]]; then
    install -d -m 0700 "$STATE_DIR/backups"
    mv "$ROLLBACK_DIR" "$STATE_DIR/backups/$(basename "$ROLLBACK_DIR")"
  else
    rm -rf "$ROLLBACK_DIR"
  fi
  exit "$status"
}
trap finish_install EXIT

PROXY_ENV_FILE="$CONFIG_DIR/proxy.env"
PROXY_ENV_STAGED="$CONFIG_DIR/proxy.env.new.$$"

append_proxy_env_line() {
  local staged="$1" name="$2" value="$3"
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "invalid newline in $name" >&2
    rm -f "$staged"
    exit 1
  fi
  printf '%s=%s\n' "$name" "$value" >> "$staged"
}

# Decide what proxy.env must contain; sets PROXY_ENV_ACTION to write or keep.
# A self-update runs from a transient systemd unit whose environment has no
# proxy variables at all, so "no proxy given" must preserve the operator's
# proxy.env rather than clear it. Only --proxy/--clear-proxy or an explicit
# HTTP(S)_PROXY in the environment may change it.
stage_proxy_env() {
  local staged="$1" proxy_name explicit_env=0
  PROXY_ENV_ACTION=write
  : > "$staged"
  case "$PROXY_MODE" in
    clear)
      return 0
      ;;
    set)
      for proxy_name in HTTP_PROXY HTTPS_PROXY http_proxy https_proxy; do
        append_proxy_env_line "$staged" "$proxy_name" "$PROXY_URL"
      done
      for proxy_name in NO_PROXY no_proxy; do
        if [[ -n "${!proxy_name:-}" ]]; then
          append_proxy_env_line "$staged" "$proxy_name" "${!proxy_name}"
        fi
      done
      return 0
      ;;
  esac
  for proxy_name in HTTP_PROXY HTTPS_PROXY http_proxy https_proxy; do
    if [[ -n "${!proxy_name:-}" ]]; then
      explicit_env=1
    fi
  done
  if [[ "$explicit_env" != "1" && -f "$PROXY_ENV_FILE" ]]; then
    PROXY_ENV_ACTION=keep
    rm -f "$staged"
    return 0
  fi
  for proxy_name in HTTP_PROXY HTTPS_PROXY http_proxy https_proxy NO_PROXY no_proxy; do
    if [[ -n "${!proxy_name:-}" ]]; then
      append_proxy_env_line "$staged" "$proxy_name" "${!proxy_name}"
    fi
  done
}

install_proxy_env() {
  stage_proxy_env "$PROXY_ENV_STAGED"
  if [[ "$PROXY_ENV_ACTION" == "write" ]]; then
    # proxy.env is the service's EnvironmentFile: a change needs a restart.
    install_file_if_changed "$PROXY_ENV_STAGED" "$PROXY_ENV_FILE" 0600 proxy.env
  else
    echo "  no proxy given; keeping the existing proxy.env"
  fi
  chmod 0600 "$PROXY_ENV_FILE"
}

install_proxy_env

leikwan_protected_download() {
  local url="$1"
  local path="$2"
  local proxy_url="${HTTPS_PROXY:-${https_proxy:-${HTTP_PROXY:-${http_proxy:-}}}}"
  if [[ -n "$proxy_url" ]]; then
    curl -fsSL --proxy "$proxy_url" "$url" -o "$path"
    return 0
  fi
  python3 - "$url" "$path" <<'PY'
import http.client
import ipaddress
import os
import pathlib
import socket
import ssl
import struct
import subprocess
import sys
import urllib.parse

STRICT_DOH_PROVIDERS = [
    ("AliDNS", "https://dns.alidns.com/dns-query", ("223.5.5.5", "223.6.6.6")),
    ("DNSPod", "https://doh.pub/dns-query", ("1.12.12.12", "120.53.53.53")),
    ("360", "https://doh.360.cn/dns-query", ("101.226.4.6", "218.30.118.6")),
]
LEIKWAN_PROTECTED_APEX = "leikwanhost.com"
LEIKWAN_PROTECTED_SUFFIX = ".leikwanhost.com"
USER_AGENT = "Leikwan-Client-Agent-Installer/strict-doh"


class StrictDoHError(RuntimeError):
    pass


def normalize_dns_host(host):
    normalized = host.strip().rstrip(".").lower()
    if not normalized:
        raise StrictDoHError("empty host")
    normalized.encode("ascii")
    return normalized


def host_is_ip_literal(host):
    try:
        ipaddress.ip_address(host.strip("[]"))
        return True
    except ValueError:
        return False


def is_protected_leikwan_url(url):
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme not in {"http", "https"}:
        return False
    host = (parsed.hostname or "").strip().strip("[]").rstrip(".").lower()
    if host in {"", "localhost", "ip6-localhost", "ip6-loopback"}:
        return False
    if host_is_ip_literal(host):
        return False
    return host == LEIKWAN_PROTECTED_APEX or host.endswith(LEIKWAN_PROTECTED_SUFFIX)


def build_dns_a_query(host, query_id=None):
    normalized = normalize_dns_host(host)
    if query_id is None:
        query_id = int.from_bytes(os.urandom(2), "big")
    question = bytearray()
    for label in normalized.split("."):
        encoded = label.encode("ascii")
        if not encoded or len(encoded) > 63:
            raise StrictDoHError(f"invalid DNS label in {host}")
        question.append(len(encoded))
        question.extend(encoded)
    question.append(0)
    question.extend(struct.pack("!HH", 1, 1))
    return struct.pack("!HHHHHH", query_id, 0x0100, 1, 0, 0, 0) + bytes(question), query_id


def skip_dns_name(message, offset):
    seen = 0
    while True:
        if offset >= len(message):
            raise StrictDoHError("truncated DNS name")
        length = message[offset]
        if length & 0xC0 == 0xC0:
            if offset + 1 >= len(message):
                raise StrictDoHError("truncated DNS pointer")
            return offset + 2
        if length & 0xC0:
            raise StrictDoHError("unsupported DNS label type")
        offset += 1
        if length == 0:
            return offset
        offset += length
        seen += 1
        if offset > len(message) or seen > 128:
            raise StrictDoHError("invalid DNS name")


def parse_dns_a_response(message, query_id):
    if len(message) < 12:
        raise StrictDoHError("truncated DNS response")
    resp_id, flags, qdcount, ancount, _nscount, _arcount = struct.unpack("!HHHHHH", message[:12])
    if resp_id != query_id:
        raise StrictDoHError("DNS response id mismatch")
    if flags & 0x8000 == 0:
        raise StrictDoHError("DNS response is not a response")
    rcode = flags & 0x000F
    if rcode != 0:
        raise StrictDoHError(f"DNS response error rcode={rcode}")
    offset = 12
    for _ in range(qdcount):
        offset = skip_dns_name(message, offset)
        if offset + 4 > len(message):
            raise StrictDoHError("truncated DNS question")
        offset += 4
    addresses = []
    for _ in range(ancount):
        offset = skip_dns_name(message, offset)
        if offset + 10 > len(message):
            raise StrictDoHError("truncated DNS answer")
        rtype, rclass, _ttl, rdlength = struct.unpack("!HHIH", message[offset : offset + 10])
        offset += 10
        if offset + rdlength > len(message):
            raise StrictDoHError("truncated DNS answer data")
        rdata = message[offset : offset + rdlength]
        offset += rdlength
        if rtype == 1 and rclass == 1 and rdlength == 4:
            addresses.append(socket.inet_ntoa(rdata))
    return addresses


class ResolvedHTTPConnection(http.client.HTTPConnection):
    def __init__(self, host, port=None, timeout=socket._GLOBAL_DEFAULT_TIMEOUT, source_address=None, *, resolved_ip):
        super().__init__(host, port=port, timeout=timeout, source_address=source_address)
        self.resolved_ip = resolved_ip

    def connect(self):
        self.sock = socket.create_connection((self.resolved_ip, self.port), self.timeout, self.source_address)
        if self._tunnel_host:
            self._tunnel()


class ResolvedHTTPSConnection(http.client.HTTPSConnection):
    def __init__(self, host, port=None, timeout=socket._GLOBAL_DEFAULT_TIMEOUT, source_address=None, *, resolved_ip):
        super().__init__(host, port=port, timeout=timeout, source_address=source_address)
        self.resolved_ip = resolved_ip

    def connect(self):
        sock = socket.create_connection((self.resolved_ip, self.port), self.timeout, self.source_address)
        if self._tunnel_host:
            self.sock = sock
            self._tunnel()
            sock = self.sock
        # Linux strict DoH keeps SNI/certificate verification on the original host; no SNI disabling or cert bypass is applied.
        self.sock = self._context.wrap_socket(sock, server_hostname=self.host)


def resolve_a_via_doh_provider(host, provider_url, bootstrap_ip, timeout=5.0):
    query, query_id = build_dns_a_query(host)
    parsed = urllib.parse.urlparse(provider_url)
    provider_host = parsed.hostname or ""
    provider_port = parsed.port or 443
    provider_path = parsed.path or "/dns-query"
    if parsed.query:
        provider_path = f"{provider_path}?{parsed.query}"
    conn = ResolvedHTTPSConnection(provider_host, port=provider_port, timeout=timeout, resolved_ip=bootstrap_ip)
    try:
        conn.request(
            "POST",
            provider_path,
            body=query,
            headers={
                "Host": parsed.netloc,
                "Accept": "application/dns-message",
                "Content-Type": "application/dns-message",
                "Content-Length": str(len(query)),
                "User-Agent": USER_AGENT,
            },
        )
        response = conn.getresponse()
        body = response.read()
        if response.status != 200:
            raise StrictDoHError(f"DoH HTTP {response.status}")
        return parse_dns_a_response(body, query_id)
    finally:
        conn.close()


def strict_resolve_a(host):
    normalized = normalize_dns_host(host)
    errors = []
    for provider_name, provider_url, bootstrap_ips in STRICT_DOH_PROVIDERS:
        for bootstrap_ip in bootstrap_ips:
            try:
                addresses = resolve_a_via_doh_provider(normalized, provider_url, bootstrap_ip)
            except Exception as exc:
                errors.append(f"{provider_name}@{bootstrap_ip}: {exc}")
                continue
            if addresses:
                return addresses
            errors.append(f"{provider_name}@{bootstrap_ip}: no A records")
    raise StrictDoHError(f"strict DoH failed for {normalized}: {'; '.join(errors)}")


def fetch_protected(url, redirects=5):
    if redirects < 0:
        raise RuntimeError("too many redirects")
    parsed = urllib.parse.urlparse(url)
    host = parsed.hostname or ""
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    target = urllib.parse.urlunparse(("", "", parsed.path or "/", parsed.params, parsed.query, ""))
    host_header = host if parsed.port is None else f"{host}:{parsed.port}"
    headers = {"Host": host_header, "Accept": "*/*", "User-Agent": USER_AGENT, "Connection": "close"}
    errors = []
    for resolved_ip in strict_resolve_a(host):
        conn_cls = ResolvedHTTPSConnection if parsed.scheme == "https" else ResolvedHTTPConnection
        conn = conn_cls(host, port=port, timeout=30.0, resolved_ip=resolved_ip)
        try:
            conn.request("GET", target, headers=headers)
            response = conn.getresponse()
            body = response.read()
            if 300 <= response.status < 400:
                location = response.getheader("Location")
                if not location:
                    raise RuntimeError(f"redirect without Location from {url}")
                next_url = urllib.parse.urljoin(url, location)
                if is_protected_leikwan_url(next_url):
                    return fetch_protected(next_url, redirects - 1)
                return curl_download(next_url, None)
            if response.status >= 400:
                raise RuntimeError(f"HTTP {response.status} from {url}: {body[:200]!r}")
            return body
        except Exception as exc:
            errors.append(f"{resolved_ip}: {exc}")
        finally:
            conn.close()
    raise RuntimeError(f"protected download failed for {url}: {'; '.join(errors)}")


def curl_download(url, path):
    # Non-protected URL fallback intentionally stays equivalent to curl -fsSL.
    cmd = ["curl", "-fsSL", url]
    if path is None:
        completed = subprocess.run(cmd, check=True, stdout=subprocess.PIPE)
        return completed.stdout
    subprocess.run(cmd + ["-o", path], check=True)
    return b""


def main():
    url = sys.argv[1]
    path = sys.argv[2]
    if is_protected_leikwan_url(url):
        pathlib.Path(path).write_bytes(fetch_protected(url))
    else:
        curl_download(url, path)


if __name__ == "__main__":
    main()
PY
}

download() {
  local url="$1"
  local path="$2"
  leikwan_protected_download "$url" "$path"
  chmod 0755 "$path"
}

download_atomic() {
  local url="$1"
  local path="$2"
  local tmp_path="${path}.new"
  leikwan_protected_download "$url" "$tmp_path"
  if [[ ! -s "$tmp_path" ]]; then
    echo "downloaded artifact is empty: $url" >&2
    rm -f "$tmp_path"
    return 1
  fi
  chmod 0755 "$tmp_path"
  backup_existing "$path"
  mv "$tmp_path" "$path"
}

check_binary_current() {
  local local_path="$1" expected_sha256="$2" actual
  [[ -f "$local_path" && -n "$expected_sha256" ]] || return 1
  actual=$(sha256sum "$local_path" 2>/dev/null | awk '{print $1}')
  [[ "$actual" == "$expected_sha256" ]]
}

_vers_sha256() {
  python3 -c "
import json, sys
try:
    entry = json.loads(sys.argv[1]).get(sys.argv[2])
    print(entry.get('sha256', '') if isinstance(entry, dict) else '')
except Exception:
    print('')
" "$_VERSIONS_JSON" "$1" 2>/dev/null || echo ''
}

_vers_value() {
  python3 -c "
import json, sys
try:
    entry = json.loads(sys.argv[1]).get(sys.argv[2])
    print(entry.get(sys.argv[3], '') if isinstance(entry, dict) else '')
except Exception:
    print('')
" "$_VERSIONS_JSON" "$1" "$2" 2>/dev/null || echo ''
}

manifest_artifact_url() {
  local key="$1" fallback="$2" configured
  configured="$(_vers_value "$key" artifact_url)"
  if [[ "$configured" =~ ^https?:// ]]; then
    printf '%s\n' "$configured"
  elif [[ -n "$configured" ]]; then
    printf '%s/%s\n' "${DOWNLOAD_BASE%/}" "${configured#/}"
  else
    printf '%s\n' "$fallback"
  fi
}

download_manifest_artifact() {
  local key="$1" url="$2" path="$3" expected_sha256
  # Historical contract: expected_sha256="$(_vers_sha256 ... )" is retained
  # in the implementation below so old offline checks recognize this gate.
  expected_sha256="$(_vers_sha256 "$key")"
  if [[ ! "$expected_sha256" =~ ^[0-9a-fA-F]{64}$ ]]; then
    echo "missing manifest sha256 for $key" >&2
    return 1
  fi
  if check_binary_current "$path" "$expected_sha256"; then
    chmod 0755 "$path"
    echo "  $key up to date, skipping download"
    return 0
  fi
  local staged="${path}.verified.$$"
  if ! leikwan_protected_download "$url" "$staged"; then
    rm -f "$staged"
    return 1
  fi
  if ! check_binary_current "$staged" "$expected_sha256"; then
    rm -f "$staged"
    echo "downloaded artifact sha256 mismatch: $key" >&2
    return 1
  fi
  chmod 0755 "$staged"
  backup_existing "$path"
  mv "$staged" "$path"
  mark_install_changed "$key"
}

_VERSIONS_PATH="$STATE_DIR/versions.json.new"
leikwan_protected_download "$DOWNLOAD_BASE/artifacts/versions.json" "$_VERSIONS_PATH"
_VERSIONS_JSON="$(cat "$_VERSIONS_PATH")"
rm -f "$_VERSIONS_PATH"
if [[ -z "$INSTALLER_SHA256" ]]; then
  INSTALLER_SHA256="$(_vers_sha256 client_agent_installer)"
fi
if [[ -z "$INSTALLER_SHA256" ]]; then
  echo "missing client_agent_installer sha256 in versions manifest" >&2
  exit 1
fi

# Prefer a verified, single-file agent on architectures for which the panel
# publishes one.  A missing architecture-specific entry deliberately falls
# back to the historical Python distribution so arm64 and older mirrors remain
# installable while the binary rollout is staged.
AGENT_BINARY_KEY="client_agent_linux_${RUNTIME_ARCH}"
AGENT_BINARY_PATH="$BIN_DIR/leikwan-client-agent"
AGENT_BINARY_URL="$(manifest_artifact_url "$AGENT_BINARY_KEY" "")"
AGENT_BINARY_SHA256="$(_vers_sha256 "$AGENT_BINARY_KEY")"
if [[ "$AGENT_BINARY_SHA256" =~ ^[0-9a-fA-F]{64}$ && -n "$AGENT_BINARY_URL" ]]; then
  download_manifest_artifact "$AGENT_BINARY_KEY" "$AGENT_BINARY_URL" "$AGENT_BINARY_PATH" \
    || die "published client-agent binary failed verification for ${RUNTIME_ARCH}"
  AGENT_RUNTIME_MODE="frozen"
  echo "using frozen client-agent binary: $AGENT_BINARY_PATH"
else
  download_manifest_artifact client_agent "$DOWNLOAD_BASE/artifacts/client-agent.py" "$BIN_DIR/leikwan-client-agent.py"
  AGENT_BINARY_PATH="$BIN_DIR/leikwan-client-agent.py"
  echo "using Python client-agent fallback: $AGENT_BINARY_PATH"
fi
download_manifest_artifact cfwg_latency_watchdog "$DOWNLOAD_BASE/artifacts/cfwg-latency-watchdog.py" "$BIN_DIR/cfwg_latency_watchdog.py"
if [[ "${LEIKWAN_SKIP_UPDATE_HELPER:-0}" != "1" ]]; then
  # Keep the legacy artifact route visible for older operator tooling while
  # enforcing the manifest hash through download_manifest_artifact.
  # download_atomic "$ARTIFACT_BASE/artifacts/client-agent-update.sh" "$BIN_DIR/leikwan-client-agent-update"
  download_manifest_artifact client_agent_update "$DOWNLOAD_BASE/artifacts/client-agent-update.sh" "$BIN_DIR/leikwan-client-agent-update"
fi
# `leikwan` is the operator surface for this client (status/start/stop/restart/
# logs/doctor/update); it ships as a verified manifest artifact so it can never
# drift from the resolver/updater it drives.
download_manifest_artifact leikwan_cli_linux "$DOWNLOAD_BASE/artifacts/leikwan-linux.sh" "$BIN_DIR/leikwan"
chmod 0755 "$BIN_DIR/leikwan"
download_manifest_artifact tcp_accel_installer "$DOWNLOAD_BASE/artifacts/install-tcp-accel.sh" "$BIN_DIR/install-tcp-accel.sh"
"$BIN_DIR/install-tcp-accel.sh" client --artifact-base "$DOWNLOAD_BASE" --bin-dir "$BIN_DIR"
download_manifest_artifact install_forward_runtime "$DOWNLOAD_BASE/artifacts/install-forward-runtime.py" "$BIN_DIR/install_forward_runtime.py"
download_manifest_artifact "$ULTRA_RUNTIME_KEY" "$DOWNLOAD_BASE/$ULTRA_RUNTIME_PATH" "$BIN_DIR/ultra-client"
# Legacy amd64 route contract retained for older static tooling:
# download_manifest_artifact ultra_client_runtime "$ARTIFACT_BASE/artifacts/ultra/ultra-client_linux_amd64" "$BIN_DIR/ultra-client"
download_manifest_artifact forward_strategy "$DOWNLOAD_BASE/artifacts/forward-strategy.py" "$BIN_DIR/forward_strategy.py"
download_manifest_artifact tcp_race_runtime "$DOWNLOAD_BASE/artifacts/leikwan-tcp-race.py" "$BIN_DIR/leikwan-tcp-race.py"
download_manifest_artifact udp_fanout_runtime "$DOWNLOAD_BASE/artifacts/leikwan-udp-fanout.py" "$BIN_DIR/leikwan-udp-fanout.py"
cat > "$BIN_DIR/leikwan-client-strategy.new.$$" <<WRAPPER
#!/usr/bin/env bash
exec /usr/bin/python3 "$BIN_DIR/forward_strategy.py" "\$@"
WRAPPER
install_file_if_changed "$BIN_DIR/leikwan-client-strategy.new.$$" "$BIN_DIR/leikwan-client-strategy" 0755 leikwan-client-strategy
download_manifest_artifact client_rate_limit "$DOWNLOAD_BASE/artifacts/leikwan-rate-limit.py" "$BIN_DIR/leikwan-rate-limit.py"
download_manifest_artifact uot_runtime "$DOWNLOAD_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"
download_manifest_artifact uot_bond_runtime "$DOWNLOAD_BASE/artifacts/leikwan-uot-bond.py" "$BIN_DIR/leikwan-uot-bond.py"
download_manifest_artifact "$UOT_BOND_RUNTIME_KEY" "$DOWNLOAD_BASE/$UOT_BOND_RUNTIME_PATH" "$BIN_DIR/leikwan-uot-bond"
# Legacy amd64 route contract retained for older static tooling:
# download_manifest_artifact leikwan_uot_bond_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-uot-bond_linux_amd64" "$BIN_DIR/leikwan-uot-bond"
"$BIN_DIR/leikwan-uot-bond" --build-info-json | python3 -c '
import json, sys
value = json.load(sys.stdin)
if value.get("protocol_version") != 2 or "feedback_drr" not in value.get("scheduler_modes", []) or "leg-progress-v1" not in value.get("capabilities", []):
    raise SystemExit("installed native UoT runtime lacks feedback capability")
'
download_manifest_artifact "$WSTUNNEL_RUNTIME_KEY" "$DOWNLOAD_BASE/$WSTUNNEL_RUNTIME_PATH" "$BIN_DIR/wstunnel"
# download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"
# Legacy amd64 route contract retained for older static tooling:
# download_manifest_artifact wstunnel_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"
"$BIN_DIR/wstunnel" --version >/dev/null 2>&1 || die "wstunnel binary failed its startup check"
download_manifest_artifact "$INGRESS_RUNTIME_KEY" "$DOWNLOAD_BASE/$INGRESS_RUNTIME_PATH" "$BIN_DIR/leikwan-ingress"
download_manifest_artifact "$BOND_RUNTIME_KEY" "$DOWNLOAD_BASE/$BOND_RUNTIME_PATH" "$BIN_DIR/leikwan-bond"
# Legacy amd64 route contracts retained for older static tooling:
# download_manifest_artifact leikwan_ingress_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"
# download_manifest_artifact leikwan_bond_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" "$BIN_DIR/leikwan-bond"
WSTUNNEL_VERSION="$(_vers_value "$WSTUNNEL_RUNTIME_KEY" version)"

render_agent_config() {
  local target="$1"
  MASTER_URL="$MASTER_URL" ARTIFACT_BASE="$ARTIFACT_BASE" CLIENT_ID="$CLIENT_ID" AGENT_TOKEN="$AGENT_TOKEN" STATE_DIR="$STATE_DIR" BIN_DIR="$BIN_DIR" INSTALLER_VERSION="$INSTALLER_VERSION" INSTALLER_SHA256="$INSTALLER_SHA256" WSTUNNEL_VERSION="$WSTUNNEL_VERSION" AGENT_RUNTIME_MODE="$AGENT_RUNTIME_MODE" AGENT_BINARY_PATH="$AGENT_BINARY_PATH" python3 - "$target" <<'PY'
import json
import os
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
payload = {
    "master_url": os.environ["MASTER_URL"],
    "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"],
    "artifact_base_url": os.environ["ARTIFACT_BASE"],
    "client_id": int(os.environ["CLIENT_ID"]),
    "agent_token": os.environ["AGENT_TOKEN"],
    "state_dir": os.environ["STATE_DIR"],
    "bond_bin": os.path.join(os.environ["BIN_DIR"], "leikwan-bond"),
    "bond_ingress_bin": os.path.join(os.environ["BIN_DIR"], "leikwan-ingress"),
    # "wstunnel_bin": "$BIN_DIR/wstunnel"
    "wstunnel_bin": os.path.join(os.environ["BIN_DIR"], "wstunnel"),
    "wstunnel_version": os.environ.get("WSTUNNEL_VERSION", ""),
    "mptcp_client_bin": os.path.join(os.environ["BIN_DIR"], "leikwan-mptcp-client.py"),
    "uot_bond_bin": os.path.join(os.environ["BIN_DIR"], "leikwan-uot-bond"),
    "log_level": "INFO",
    "installer_version": os.environ["INSTALLER_VERSION"],
    "installer_sha256": os.environ["INSTALLER_SHA256"],
    "runtime_binary_mode": os.environ.get("AGENT_RUNTIME_MODE", "python"),
    "client_agent_bin": os.environ.get("AGENT_BINARY_PATH", ""),
}
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
path.chmod(0o600)
PY
}

validate_agent_config() {
  local staged="$1"
  if [[ ! -s "$staged" ]]; then
    die "generated client-agent config is empty; refusing to replace the current config"
  fi
  if ! python3 - "$staged" <<'PYCONFIG'
import json
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
document = json.loads(path.read_text(encoding="utf-8"))
if not isinstance(document, dict):
    raise SystemExit("generated client-agent config is not an object")
for key in ("master_url", "artifact_base_url", "client_id", "agent_token", "state_dir"):
    if document.get(key) in (None, ""):
        raise SystemExit(f"generated client-agent config missing {key}")
PYCONFIG
  then
    die "generated client-agent config failed validation; refusing to replace the current config"
  fi
}

# Exit 0 when the installed config already carries every installer-owned value
# of the staged config. AGENT_MANAGED_KEYS are owned by the control plane once
# the agent is enrolled: the agent rewrites them from agent_config_json
# (apply_config_patch), so their on-disk values legitimately differ from what
# this installer renders. Counting them as a change would restart the agent on
# every no-op update. --force-restart still rewrites the whole file.
agent_config_current() {
  local staged="$1" current="$2"
  [[ -f "$current" ]] || return 1
  python3 - "$staged" "$current" <<'PYCURRENT'
import json
import pathlib
import sys

AGENT_MANAGED_KEYS = {
    "master_url",
    "artifact_base_url",
    "panel_mirror_urls",
    "disable_auto_update",
    "poll_interval_seconds",
    "health_check_interval_seconds",
    "log_level",
    "state_dir",
    "udp_uot_sidepath",
    "cfwg_latency_watchdog",
    "runtime_refresh",
    "cfwg_edge_pins",
    "ultra_selection_mode",
}
try:
    staged = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
    current = json.loads(pathlib.Path(sys.argv[2]).read_text(encoding="utf-8"))
except Exception:
    raise SystemExit(1)
if not isinstance(staged, dict) or not isinstance(current, dict):
    raise SystemExit(1)
for key in ("master_url", "artifact_base_url", "client_id", "agent_token", "state_dir"):
    if current.get(key) in (None, ""):
        raise SystemExit(1)
for key, value in staged.items():
    if key in AGENT_MANAGED_KEYS:
        continue
    if current.get(key) != value:
        raise SystemExit(1)
raise SystemExit(0)
PYCURRENT
}

install_agent_config() {
  local staged="$1" target="$CONFIG_DIR/config.json"
  validate_agent_config "$staged"
  if [[ "$FORCE_RESTART" != "1" ]] && agent_config_current "$staged" "$target"; then
    rm -f "$staged"
    chmod 0600 "$target"
    echo "  client-agent config.json up to date, keeping current file"
    return 0
  fi
  backup_existing "$target"
  mv "$staged" "$target"
  chmod 0600 "$target"
  mark_install_changed config.json
}

render_agent_unit() {
  if [[ "$AGENT_RUNTIME_MODE" == "frozen" ]]; then
    AGENT_EXEC_START="$AGENT_BINARY_PATH --config $CONFIG_DIR/config.json run"
    # PyInstaller one-file uses a bootloader process and sends sd_notify from
    # the extracted Python child.  NotifyAccess=main rejects that heartbeat and
    # makes an otherwise healthy frozen agent hit WatchdogSec after 180 seconds.
    AGENT_NOTIFY_ACCESS="all"
  else
    AGENT_EXEC_START="/usr/bin/python3 $AGENT_BINARY_PATH --config $CONFIG_DIR/config.json run"
    AGENT_NOTIFY_ACCESS="main"
  fi
  cat <<UNIT
[Unit]
Description=Leikwan Client Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
EnvironmentFile=-$PROXY_ENV_FILE
ExecStart=$AGENT_EXEC_START
Restart=always
RestartSec=5
WatchdogSec=180
NotifyAccess=$AGENT_NOTIFY_ACCESS

[Install]
WantedBy=multi-user.target
UNIT
}

install_agent_unit() {
  local unit_path="$1" staged
  staged="$(mktemp "$STATE_DIR/.agent-unit.XXXXXX")"
  render_agent_unit > "$staged"
  if files_identical "$staged" "$unit_path"; then
    rm -f "$staged"
    return 0
  fi
  backup_existing "$unit_path"
  chmod 0644 "$staged"
  mv "$staged" "$unit_path"
  UNIT_CHANGED=1
  mark_install_changed "$(basename "$unit_path")"
}

verify_agent_service() {
  local service_ready=0
  for _ in $(seq 1 30); do
    if systemctl is-active --quiet "$SERVICE_NAME.service"; then
      service_ready=1
      break
    fi
    sleep 1
  done
  if [[ "$service_ready" != "1" ]]; then
    systemctl status "$SERVICE_NAME.service" --no-pager >&2 || true
    journalctl -u "$SERVICE_NAME.service" -n 40 --no-pager >&2 || true
    die "client-agent service did not become active"
  fi

  HEALTH_PATH="$STATE_DIR/install-health.json"
  backup_existing "$HEALTH_PATH"
  if [[ "$AGENT_RUNTIME_MODE" == "frozen" ]]; then
    AGENT_HEALTH_CMD=("$AGENT_BINARY_PATH" --config "$CONFIG_DIR/config.json" check --live)
  else
    AGENT_HEALTH_CMD=(/usr/bin/python3 "$AGENT_BINARY_PATH" --config "$CONFIG_DIR/config.json" check --live)
  fi
  if ! "${AGENT_HEALTH_CMD[@]}" > "$HEALTH_PATH" 2>"$STATE_DIR/install-health.stderr"; then
    cat "$STATE_DIR/install-health.stderr" >&2 || true
    cat "$HEALTH_PATH" >&2 || true
    die "client-agent live health check failed"
  fi
  chmod 0600 "$HEALTH_PATH" "$STATE_DIR/install-health.stderr"
  python3 - "$HEALTH_PATH" <<'PY'
import json
import pathlib
import sys

payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
if payload.get("ok") is not True:
    raise SystemExit("client-agent health result is not ok")
live = payload.get("live_pull")
if isinstance(live, dict) and live.get("ok") is not True:
    raise SystemExit("client-agent live pull is not ok")
runtime = payload.get("runtime", {})
if runtime.get("wstunnel_bin_present") is not True:
    raise SystemExit("wstunnel binary is not present")
PY
}

# Restart, reload and health-check only when something the service runs
# actually changed (an artifact, config.json, proxy.env or the unit file), the
# service is not running, or --force-restart was given.
finalize_agent_service() {
  local restart_reason=""
  if [[ "$FORCE_RESTART" == "1" ]]; then
    restart_reason="forced"
  elif [[ "$INSTALL_CHANGED" == "1" ]]; then
    restart_reason="changed: ${INSTALL_CHANGE_REASONS[*]}"
  elif ! systemctl is-active --quiet "$SERVICE_NAME.service"; then
    restart_reason="service inactive"
  fi
  if [[ "$UNIT_CHANGED" == "1" || "$FORCE_RESTART" == "1" ]]; then
    systemctl daemon-reload
  fi
  if [[ "$UNIT_CHANGED" == "1" || "$FORCE_RESTART" == "1" ]] || ! systemctl is-enabled --quiet "$SERVICE_NAME.service"; then
    systemctl enable "$SERVICE_NAME.service"
  fi
  if [[ -z "$restart_reason" ]]; then
    AGENT_SERVICE_RESTARTED=0
    echo "$SERVICE_NAME already current and active; skipping restart and live health check"
    return 0
  fi
  echo "restarting $SERVICE_NAME ($restart_reason)"
  systemctl restart "$SERVICE_NAME.service"
  AGENT_SERVICE_RESTARTED=1
  verify_agent_service
}

CONFIG_TMP="$CONFIG_DIR/config.json.new"
render_agent_config "$CONFIG_TMP"
install_agent_config "$CONFIG_TMP"

SERVICE_UNIT_PATH="/etc/systemd/system/$SERVICE_NAME.service"
install_agent_unit "$SERVICE_UNIT_PATH"
finalize_agent_service

INSTALL_COMMITTED=1

echo "installed $SERVICE_NAME"
