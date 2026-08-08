#!/usr/bin/env bash
set -euo pipefail

MASTER_URL=""
ARTIFACT_BASE=""
FORWARD_ID=""
TOKEN=""
SERVICE_NAME=""
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
    --forward-id) FORWARD_ID="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    --skip-mptcp-check) SKIP_MPTCP_CHECK=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$FORWARD_ID" || -z "$TOKEN" ]]; then
  echo "usage: install-client.sh --master <url> --forward-id <id> --token <token>" >&2
  exit 1
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi
if [[ -z "$SERVICE_NAME" ]]; then
  SERVICE_NAME="leikwan-client-$FORWARD_ID"
fi

mkdir -p "$BIN_DIR" /etc/leikwan

VERSIONS_JSON="$(curl -fsSL "$ARTIFACT_BASE/artifacts/versions.json")"
download_verified_artifact() {
  local key="$1" url="$2" path="$3" expected tmp
  expected="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2], {}).get("sha256", ""))' "$VERSIONS_JSON" "$key")"
  [[ "$expected" =~ ^[0-9a-fA-F]{64}$ ]] || { echo "missing manifest sha256 for $key" >&2; return 1; }
  tmp="${path}.new"
  curl -fsSL "$url" -o "$tmp"
  echo "$expected  $tmp" | sha256sum -c - >/dev/null
  chmod 0755 "$tmp"
  mv "$tmp" "$path"
}

curl -fsSL "$ARTIFACT_BASE/artifacts/install-forward-runtime.py" -o "$BIN_DIR/install_forward_runtime.py"
curl -fsSL "$ARTIFACT_BASE/artifacts/leikwan-mptcp-client.py" -o "$BIN_DIR/leikwan-mptcp-client.py"
curl -fsSL "$ARTIFACT_BASE/artifacts/leikwan-uot.py" -o "$BIN_DIR/leikwan-uot.py"
curl -fsSL "$ARTIFACT_BASE/artifacts/leikwan-uot-bond.py" -o "$BIN_DIR/leikwan-uot-bond.py"
download_verified_artifact leikwan_uot_bond_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-uot-bond_linux_amd64" "$BIN_DIR/leikwan-uot-bond"
"$BIN_DIR/leikwan-uot-bond" --build-info-json | python3 -c '
import json, sys
value = json.load(sys.stdin)
if value.get("protocol_version") != 2 or "feedback_drr" not in value.get("scheduler_modes", []) or "leg-progress-v1" not in value.get("capabilities", []):
    raise SystemExit("installed native UoT runtime lacks feedback capability")
'
curl -fsSL "$ARTIFACT_BASE/artifacts/leikwan-rate-limit.py" -o "$BIN_DIR/leikwan-rate-limit.py"
curl -fsSL "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" -o "$BIN_DIR/leikwan-ingress"
curl -fsSL "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" -o "$BIN_DIR/leikwan-bond"
chmod 0755 "$BIN_DIR/install_forward_runtime.py" "$BIN_DIR/leikwan-mptcp-client.py" "$BIN_DIR/leikwan-uot.py" "$BIN_DIR/leikwan-uot-bond.py" "$BIN_DIR/leikwan-uot-bond" "$BIN_DIR/leikwan-rate-limit.py" "$BIN_DIR/leikwan-ingress" "$BIN_DIR/leikwan-bond"

# --- WireGuard watchdog ---
curl -fsSL "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" -o "$BIN_DIR/leikwan-wg-watchdog.sh"
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

TMP_CONFIG="$(mktemp)"
curl -fsSL "$MASTER_URL/api/user/forwards/$FORWARD_ID/client-config?token=$TOKEN" -o "$TMP_CONFIG"

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

FORWARD_ID="$FORWARD_ID" SERVICE_NAME="$SERVICE_NAME" BIN_DIR="$BIN_DIR" CONFIG_JSON="$TMP_CONFIG" python3 - <<'PY'
import json
import os
import pathlib
import subprocess
import sys

sys.path.insert(0, os.environ["BIN_DIR"])
from install_forward_runtime import render_forward_runtime  # type: ignore

payload = json.loads(pathlib.Path(os.environ["CONFIG_JSON"]).read_text(encoding="utf-8"))
forward = payload["config"]
bin_dir = pathlib.Path(os.environ["BIN_DIR"])
rendered = render_forward_runtime(
    forward,
    {
        "bond_bin": str(bin_dir / "leikwan-bond"),
        "bond_ingress_bin": str(bin_dir / "leikwan-ingress"),
        "mptcp_client_bin": str(bin_dir / "leikwan-mptcp-client.py"),
        "uot_bond_bin": str(bin_dir / "leikwan-uot-bond"),
    },
)

service_path = pathlib.Path(rendered["service_path"])
service_path.parent.mkdir(parents=True, exist_ok=True)
service_path.write_text(rendered["service_unit"], encoding="utf-8")

for file_entry in rendered.get("files", []):
    path = pathlib.Path(str(file_entry["path"]))
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(str(file_entry.get("content", "")), encoding="utf-8")
    if os.name != "nt" and file_entry.get("mode") is not None:
        path.chmod(int(file_entry["mode"]))

if rendered.get("start_script_path") and rendered.get("start_script"):
    start_path = pathlib.Path(str(rendered["start_script_path"]))
    start_path.parent.mkdir(parents=True, exist_ok=True)
    start_path.write_text(str(rendered["start_script"]), encoding="utf-8")
    if os.name != "nt":
        start_path.chmod(0o700)

subprocess.run("systemctl daemon-reload", shell=True, check=False)
subprocess.run(f"systemctl enable {rendered['service_name']}.service", shell=True, check=False)
subprocess.run(f"systemctl restart {rendered['service_name']}.service", shell=True, check=False)
print(rendered["service_name"])
PY

rm -f "$TMP_CONFIG"
echo "installed $SERVICE_NAME"
