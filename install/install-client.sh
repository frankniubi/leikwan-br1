#!/usr/bin/env bash
set -euo pipefail
umask 077

INSTALLER_VERSION="2026.09.24.2"
MASTER_URL=""
ARTIFACT_BASE=""
FORWARD_ID=""
TOKEN=""
SERVICE_NAME=""
BIN_DIR="/usr/local/bin"
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0
SKIP_MPTCP_CHECK=0

# Arch-dependent runtime selection. Same convention as
# install-client-agent.sh: the installer resolves every artifact key and path
# from this matrix and verifies each download against artifacts/versions.json,
# so amd64 and arm64 hosts follow one code path.
RUNTIME_ARCH="amd64"
UOT_BOND_RUNTIME_KEY="leikwan_uot_bond_linux_amd64"
UOT_BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-uot-bond_linux_amd64"
WSTUNNEL_RUNTIME_KEY="wstunnel_linux_amd64"
WSTUNNEL_RUNTIME_PATH="artifacts/wstunnel/wstunnel_linux_amd64"
INGRESS_RUNTIME_KEY="leikwan_ingress_linux_amd64"
INGRESS_RUNTIME_PATH="artifacts/wstunnel/leikwan-ingress_linux_amd64"
BOND_RUNTIME_KEY="leikwan_bond_linux_amd64"
BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-bond_linux_amd64"
# The MPTCP relay has no arm64 build yet; arm64 hosts install without it.
MPTCP_RELAY_RUNTIME_KEY="leikwan_mptcp_relay_linux_amd64"
MPTCP_RELAY_RUNTIME_PATH="artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64"

die() {
  echo "install-client: $*" >&2
  exit 1
}

bootstrap_dependencies() {
  local -a required_commands=(bash curl python3 sha256sum awk grep sed install mktemp cat chmod mv id uname systemctl timedatectl dpkg-query apt-cache sleep seq journalctl sysctl)
  local missing=() command_name
  for command_name in "${required_commands[@]}"; do
    command -v "$command_name" >/dev/null 2>&1 || missing+=("$command_name")
  done
  if ((${#missing[@]} > 0)) || [[ ! -r /etc/ssl/certs/ca-certificates.crt ]]; then
    command -v apt-get >/dev/null 2>&1 || die "missing required commands (${missing[*]}) and apt-get is unavailable"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y bash curl ca-certificates python3 coreutils grep sed mawk procps iproute2 nftables
  fi
  for command_name in "${required_commands[@]}"; do
    command -v "$command_name" >/dev/null 2>&1 || die "required command is still missing: $command_name"
  done
  systemctl show-environment >/dev/null 2>&1 || die "systemd manager is unavailable"
}

validate_host() {
  [[ "$(id -u)" == "0" ]] || die "must run as root"
  local arch
  arch="$(uname -m)"
  case "$arch" in
    x86_64|amd64)
      RUNTIME_ARCH="amd64"
      UOT_BOND_RUNTIME_KEY="leikwan_uot_bond_linux_amd64"
      UOT_BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-uot-bond_linux_amd64"
      WSTUNNEL_RUNTIME_KEY="wstunnel_linux_amd64"
      WSTUNNEL_RUNTIME_PATH="artifacts/wstunnel/wstunnel_linux_amd64"
      INGRESS_RUNTIME_KEY="leikwan_ingress_linux_amd64"
      INGRESS_RUNTIME_PATH="artifacts/wstunnel/leikwan-ingress_linux_amd64"
      BOND_RUNTIME_KEY="leikwan_bond_linux_amd64"
      BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-bond_linux_amd64"
      MPTCP_RELAY_RUNTIME_KEY="leikwan_mptcp_relay_linux_amd64"
      MPTCP_RELAY_RUNTIME_PATH="artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64"
      ;;
    aarch64|arm64)
      RUNTIME_ARCH="arm64"
      UOT_BOND_RUNTIME_KEY="leikwan_uot_bond_linux_arm64"
      UOT_BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-uot-bond_linux_arm64"
      WSTUNNEL_RUNTIME_KEY="wstunnel_linux_arm64"
      WSTUNNEL_RUNTIME_PATH="artifacts/wstunnel/wstunnel_linux_arm64"
      INGRESS_RUNTIME_KEY="leikwan_ingress_linux_arm64"
      INGRESS_RUNTIME_PATH="artifacts/wstunnel/leikwan-ingress_linux_arm64"
      BOND_RUNTIME_KEY="leikwan_bond_linux_arm64"
      BOND_RUNTIME_PATH="artifacts/wstunnel/leikwan-bond_linux_arm64"
      # No arm64 MPTCP relay artifact is published yet.
      MPTCP_RELAY_RUNTIME_KEY=""
      MPTCP_RELAY_RUNTIME_PATH=""
      ;;
    *) die "unsupported architecture '$arch'; published Linux client artifacts support x86_64/amd64 and aarch64/arm64" ;;
  esac
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
  local flavour="amd64"
  [[ "$RUNTIME_ARCH" == "arm64" ]] && flavour="arm64"
  case "${ID:-}" in
    debian)
      if uname -r | grep -q 'cloud'; then
        echo "linux-image-cloud-$flavour linux-headers-cloud-$flavour"
      else
        echo "linux-image-$flavour linux-headers-$flavour"
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

  return 1
}

install_wireguard_tools() {
  if command -v wg >/dev/null 2>&1 && command -v wg-quick >/dev/null 2>&1; then
    return 0
  fi
  command -v apt-get >/dev/null 2>&1 || die "wireguard-tools is missing and apt-get is unavailable"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y wireguard-tools
}

ensure_time_sync() {
  command -v timedatectl >/dev/null 2>&1 || die "timedatectl is required to verify clock synchronization"
  if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi '^yes$'; then
    return 0
  fi
  command -v apt-get >/dev/null 2>&1 || die "clock is not synchronized and apt-get is unavailable"
  local package="" unit="" candidate elapsed=0 active_unit=""
  for unit in systemd-timesyncd.service chrony.service chronyd.service ntpsec.service ntp.service ntpd.service; do
    if systemctl is-active --quiet "$unit"; then
      active_unit="$unit"
      break
    fi
  done
  case "$active_unit" in
    systemd-timesyncd.service) package="systemd-timesyncd" ;;
    ntpsec.service) package="ntpsec" ;;
    chrony.service|chronyd.service) package="chrony" ;;
    ntp.service|ntpd.service) package="ntp" ;;
  esac
  if [[ -z "$package" ]]; then
    for candidate in systemd-timesyncd ntpsec chrony ntp; do
      if dpkg-query -W -f='${Status}' "$candidate" 2>/dev/null | grep -q 'install ok installed' || apt-cache show "$candidate" >/dev/null 2>&1; then
        package="$candidate"
        break
      fi
    done
  fi
  [[ -n "$package" ]] || die "no supported Debian time-sync package is available"
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
  while ((elapsed < 60)); do
    if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi '^yes$'; then
      return 0
    fi
    sleep 1
    elapsed=$((elapsed + 1))
  done
  die "clock synchronization failed after 60s (service $unit)"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) MASTER_URL="$2"; shift 2 ;;
    --artifact-base) ARTIFACT_BASE="$2"; shift 2 ;;
    --forward-id) FORWARD_ID="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    --skip-mptcp-check) SKIP_MPTCP_CHECK=1; shift ;;
    --version) echo "$INSTALLER_VERSION"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$FORWARD_ID" || -z "$TOKEN" ]]; then
  echo "usage: install-client.sh --master <url> --forward-id <id> --token <token>" >&2
  echo "       install-client.sh --version   # prints $INSTALLER_VERSION" >&2
  exit 1
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi
if [[ -z "$SERVICE_NAME" ]]; then
  SERVICE_NAME="leikwan-client-$FORWARD_ID"
fi

validate_host
bootstrap_dependencies
ensure_time_sync

[[ "$FORWARD_ID" =~ ^[1-9][0-9]*$ ]] || die "forward id must be a positive integer"
[[ "$BIN_DIR" =~ ^/[A-Za-z0-9._+@%/-]+$ ]] || die "bin-dir must be an absolute path containing only safe unit path characters"
[[ "$SERVICE_NAME" =~ ^[A-Za-z0-9_.@-]+$ ]] || die "invalid service name"
for value in "$MASTER_URL" "$ARTIFACT_BASE" "$TOKEN" "$SERVICE_NAME" "$BIN_DIR"; do
  [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || die "arguments must not contain newlines"
done
install -d -m 0755 "$BIN_DIR" /etc/leikwan
install -d -m 0700 /var/lib/leikwan-client-agent
install_wireguard_tools

STATE_DIR="/var/lib/leikwan-client-agent"
ROLLBACK_DIR="$STATE_DIR/.rollback-${FORWARD_ID}-${SERVICE_NAME}-$$"
BACKUP_MANIFEST="$ROLLBACK_DIR/paths.tsv"
install -d -m 0700 "$ROLLBACK_DIR"
: > "$BACKUP_MANIFEST"
TMP_CONFIG=""
VERSIONS_TMP=""
INSTALL_COMMITTED=0

backup_existing() {
  local path="$1" rel backup exists
  [[ -n "$path" ]] || return 0
  if awk -F '\t' -v wanted="$path" '$1 == wanted { found=1 } END { exit found ? 0 : 1 }' "$BACKUP_MANIFEST"; then
    return 0
  fi
  rel="${path#/}"
  backup="$ROLLBACK_DIR/$rel"
  if [[ -e "$path" || -L "$path" ]]; then
    install -d -m 0700 "$(dirname "$backup")"
    cp -a "$path" "$backup"
    exists=1
  else
    exists=0
  fi
  printf '%s\t%s\n' "$path" "$exists" >> "$BACKUP_MANIFEST"
}

rollback_install() {
  local -a records=() reversed=()
  local record path exists rel backup index
  if [[ -f "$BACKUP_MANIFEST" ]]; then
    mapfile -t records < "$BACKUP_MANIFEST"
  fi
  for ((index=${#records[@]} - 1; index >= 0; index--)); do
    reversed+=("${records[$index]}")
  done
  for record in "${reversed[@]}"; do
    IFS=$'\t' read -r path exists <<< "$record"
    [[ -n "$path" ]] || continue
    rel="${path#/}"
    backup="$ROLLBACK_DIR/$rel"
    if [[ "$exists" == "1" ]]; then
      rm -rf "$path"
      install -d -m 0755 "$(dirname "$path")"
      cp -a "$backup" "$path"
    else
      rm -rf "$path"
    fi
  done
  systemctl daemon-reload >/dev/null 2>&1 || true
}

cleanup_legacy_install() {
  local status=$?
  trap - EXIT
  [[ -z "$TMP_CONFIG" ]] || rm -f "$TMP_CONFIG"
  [[ -z "$VERSIONS_TMP" ]] || rm -f "$VERSIONS_TMP"
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
trap cleanup_legacy_install EXIT

VERSIONS_JSON="$(curl -fsSL "$ARTIFACT_BASE/artifacts/versions.json")"
download_verified_artifact() {
  local key="$1" url="$2" path="$3" expected tmp
  expected="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2], {}).get("sha256", ""))' "$VERSIONS_JSON" "$key")"
  [[ "$expected" =~ ^[0-9a-fA-F]{64}$ ]] || { echo "missing manifest sha256 for $key" >&2; return 1; }
  tmp="${path}.new"
  curl -fsSL "$url" -o "$tmp"
  echo "$expected  $tmp" | sha256sum -c - >/dev/null
  chmod 0755 "$tmp"
  backup_existing "$path"
  mv "$tmp" "$path"
}

download_verified_artifact install_forward_runtime "$ARTIFACT_BASE/artifacts/install-forward-runtime.py" "$BIN_DIR/install_forward_runtime.py"
download_verified_artifact mptcp_client_runtime "$ARTIFACT_BASE/artifacts/leikwan-mptcp-client.py" "$BIN_DIR/leikwan-mptcp-client.py"
download_verified_artifact uot_runtime "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"
download_verified_artifact uot_bond_runtime "$ARTIFACT_BASE/artifacts/leikwan-uot-bond.py" "$BIN_DIR/leikwan-uot-bond.py"
download_verified_artifact "$UOT_BOND_RUNTIME_KEY" "$ARTIFACT_BASE/$UOT_BOND_RUNTIME_PATH" "$BIN_DIR/leikwan-uot-bond"
"$BIN_DIR/leikwan-uot-bond" --build-info-json | python3 -c '
import json, sys
value = json.load(sys.stdin)
if value.get("protocol_version") != 2 or "feedback_drr" not in value.get("scheduler_modes", []) or "leg-progress-v1" not in value.get("capabilities", []):
    raise SystemExit("installed native UoT runtime lacks feedback capability")
'
download_verified_artifact client_rate_limit "$ARTIFACT_BASE/artifacts/leikwan-rate-limit.py" "$BIN_DIR/leikwan-rate-limit.py"
download_verified_artifact "$WSTUNNEL_RUNTIME_KEY" "$ARTIFACT_BASE/$WSTUNNEL_RUNTIME_PATH" "$BIN_DIR/wstunnel"
"$BIN_DIR/wstunnel" --version >/dev/null 2>&1 || die "wstunnel binary failed its startup check"
download_verified_artifact "$INGRESS_RUNTIME_KEY" "$ARTIFACT_BASE/$INGRESS_RUNTIME_PATH" "$BIN_DIR/leikwan-ingress"
download_verified_artifact "$BOND_RUNTIME_KEY" "$ARTIFACT_BASE/$BOND_RUNTIME_PATH" "$BIN_DIR/leikwan-bond"
if [[ -n "$MPTCP_RELAY_RUNTIME_PATH" ]]; then
  download_verified_artifact "$MPTCP_RELAY_RUNTIME_KEY" "$ARTIFACT_BASE/$MPTCP_RELAY_RUNTIME_PATH" "$STATE_DIR/leikwan-mptcp-relay"
else
  echo "note: no $RUNTIME_ARCH MPTCP relay artifact is published; installing without the relay helper" >&2
fi
chmod 0755 "$BIN_DIR/install_forward_runtime.py" "$BIN_DIR/leikwan-mptcp-client.py" "$BIN_DIR/leikwan-uot.py" "$BIN_DIR/leikwan-uot-bond.py" "$BIN_DIR/leikwan-uot-bond" "$BIN_DIR/leikwan-rate-limit.py" "$BIN_DIR/leikwan-ingress" "$BIN_DIR/leikwan-bond"

# --- WireGuard watchdog ---
download_verified_artifact wg_watchdog "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" "$BIN_DIR/leikwan-wg-watchdog.sh"
chmod 0755 "$BIN_DIR/leikwan-wg-watchdog.sh"
backup_existing /etc/systemd/system/leikwan-wg-watchdog.service
cat > /etc/systemd/system/leikwan-wg-watchdog.service <<'WDUNIT'
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=$BIN_DIR/leikwan-wg-watchdog.sh
WDUNIT
backup_existing /etc/systemd/system/leikwan-wg-watchdog.timer
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
systemctl enable --now leikwan-wg-watchdog.timer
systemctl is-active --quiet leikwan-wg-watchdog.timer

TMP_CONFIG="$(mktemp)"
curl -fsSL --get --data-urlencode "token=$TOKEN" "$MASTER_URL/api/user/forwards/$FORWARD_ID/client-config" -o "$TMP_CONFIG"

FORWARD_MODE="$(python3 - "$TMP_CONFIG" <<'PY'
import json
import pathlib
import sys

payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8-sig"))
config = payload.get("config", {})
print(str(config.get("mode", "single")))
PY
)"

if [[ "$FORWARD_MODE" == "mptcp" && "$SKIP_MPTCP_CHECK" != "1" ]]; then
  ensure_mptcp_ready "client" || {
    echo "client: forward $FORWARD_ID requires an MPTCP-capable running kernel. Re-run with --auto-install-mptcp-kernel if you want the installer to add a newer kernel package." >&2
    exit 1
  }
  install_wireguard_tools
fi

FORWARD_ID="$FORWARD_ID" SERVICE_NAME="$SERVICE_NAME" BIN_DIR="$BIN_DIR" CONFIG_JSON="$TMP_CONFIG" ROLLBACK_DIR="$ROLLBACK_DIR" BACKUP_MANIFEST="$BACKUP_MANIFEST" python3 - <<'PY'
import json
import os
import pathlib
import shutil
import subprocess
import sys

sys.path.insert(0, os.environ["BIN_DIR"])
from install_forward_runtime import render_forward_runtime  # type: ignore

payload = json.loads(pathlib.Path(os.environ["CONFIG_JSON"]).read_text(encoding="utf-8"))
forward = payload["config"]
bin_dir = pathlib.Path(os.environ["BIN_DIR"])
rollback_root = pathlib.Path(os.environ["ROLLBACK_DIR"])
backup_manifest = pathlib.Path(os.environ["BACKUP_MANIFEST"])

def backup_path(path: pathlib.Path) -> None:
    path = pathlib.Path(path)
    key = str(path)
    if backup_manifest.exists():
        for row in backup_manifest.read_text(encoding="utf-8").splitlines():
            if row.split("\t", 1)[0] == key:
                return
    destination = rollback_root / key.lstrip("/")
    if path.exists() or path.is_symlink():
        destination.parent.mkdir(parents=True, exist_ok=True)
        if path.is_dir() and not path.is_symlink():
            shutil.copytree(path, destination, symlinks=True)
        else:
            shutil.copy2(path, destination, follow_symlinks=False)
        exists = "1"
    else:
        exists = "0"
    with backup_manifest.open("a", encoding="utf-8") as handle:
        handle.write(f"{key}\t{exists}\n")

def write_file(path: pathlib.Path, content: str, mode=None) -> None:
    backup_path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    if mode is not None:
        path.chmod(mode)

rendered = render_forward_runtime(
    forward,
      {
        "bond_bin": str(bin_dir / "leikwan-bond"),
        "bond_ingress_bin": str(bin_dir / "leikwan-ingress"),
        "wstunnel_bin": str(bin_dir / "wstunnel"),
        "mptcp_client_bin": str(bin_dir / "leikwan-mptcp-client.py"),
        "uot_bond_bin": str(bin_dir / "leikwan-uot-bond"),
    },
)

units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
for unit in units:
    write_file(pathlib.Path(str(unit["service_path"])), str(unit["service_unit"]), 0o644)
    for file_entry in unit.get("files", []):
        if not isinstance(file_entry, dict) or "path" not in file_entry:
            continue
        mode = file_entry.get("mode")
        write_file(
            pathlib.Path(str(file_entry["path"])),
            str(file_entry.get("content", "")),
            int(mode) if mode is not None else None,
        )
    if unit.get("start_script_path") and unit.get("start_script"):
        write_file(pathlib.Path(str(unit["start_script_path"])), str(unit["start_script"]), 0o700)

subprocess.run(["systemctl", "daemon-reload"], check=True)
unit_names = [str(unit["service_name"]) for unit in units]
for name in unit_names:
    subprocess.run(["systemctl", "enable", f"{name}.service"], check=True)
for name in unit_names:
    subprocess.run(["systemctl", "restart", f"{name}.service"], check=True)
for name in unit_names:
    subprocess.run(["systemctl", "is-active", "--quiet", f"{name}.service"], check=True)
print(rendered["service_name"])
PY

TMP_CONFIG=""
INSTALL_COMMITTED=1
echo "installed $SERVICE_NAME"
