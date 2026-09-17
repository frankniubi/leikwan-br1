#!/usr/bin/env bash
# Installs the operator-owned external UoT sidecar (Go) that fronts the Ultra
# runtime's external_uot carrier.  Wire compatible with
# sidecar-agent/leikwan_external_uot.py; built from tools/uot-sidecar.
#
# Idempotent: re-running upgrades the binary in place and keeps an existing
# /etc/leikwan-external-uot.json unless --config points at a new one.
set -euo pipefail

INSTALLER_VERSION="2026.09.17.1"
ARTIFACT_BASE=""
BIN_DIR="/usr/local/bin"
CONFIG_DIR="/etc"
CONFIG_SOURCE=""
LISTEN_PORT="7443"
LOCAL_BIN=""
FORCE_START=""
FORCE_NO_START=""
SKIP_SYSTEMD="${LEIKWAN_SKIP_SYSTEMD:-0}"
CHECK_ONLY=0
STRICT_CHECK=0
UNINSTALL=0
PASSTHROUGH=()

# Canonical name for the operator-owned Go executable.  Keep the historical
# name as a compatibility symlink so older operator commands continue to work.
BIN_NAME="leikwan-external-uot-go"
LEGACY_BIN_NAME="leikwan-external-uot"
CFG_NAME="leikwan-external-uot.json"
UNIT_NAME="leikwan-external-uot.service"

usage() {
  cat <<'USAGE'
Usage:
  install-external-uot.sh --artifact-base <url> [options]

Installs the Go external UoT sidecar plus its systemd unit.  The forward list
(forward_id -> secret|token, optional target allow-list) lives in
/etc/leikwan-external-uot.json and is supplied with --config, normally by the
panel/operator bundle.  Without a config the service is installed but left
disabled so the host exposes no useless listener.

Options:
  --artifact-base <url>   artifact store root (defaults to $LEIKWAN_ARTIFACT_BASE)
  --bin-dir <dir>         binary directory (default /usr/local/bin)
  --config-dir <dir>      config directory (default /etc)
  --config <path|url>     sidecar config to install (0600); URL is fetched
  --listen-port <port>    port that the config must listen on (default 7443)
  --bin <path>            offline install from a local binary
  --start / --no-start    force service enable+start / force keep disabled
  --skip-systemd          do not touch systemd (test harnesses)
  --check-only            read-only audit of the installed sidecar, then exit
  --strict                with --check-only, return non-zero for an unhealthy
                          install (missing config/binary, invalid config, or
                          service/listener mismatch)
  --uninstall             stop+disable+remove the unit (binary/config kept)
  --print-token --secret <s> --forward-id <n> --target <host:port>
                          passthrough to the sidecar token helper
  -h | --help             this help
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --artifact-base) ARTIFACT_BASE="${2%/}"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --config) CONFIG_SOURCE="$2"; shift 2 ;;
    --listen-port) LISTEN_PORT="$2"; shift 2 ;;
    --bin) LOCAL_BIN="$2"; shift 2 ;;
    --start) FORCE_START=1; shift ;;
    --no-start) FORCE_NO_START=1; shift ;;
    --skip-systemd) SKIP_SYSTEMD=1; shift ;;
    --check-only) CHECK_ONLY=1; shift ;;
    --strict) STRICT_CHECK=1; shift ;;
    --uninstall) UNINSTALL=1; shift ;;
    --print-token|--secret|--secret-file|--forward-id|--target|--version)
      PASSTHROUGH+=("$1"); shift
      if [[ $# -gt 0 && "$1" != --* ]]; then PASSTHROUGH+=("$1"); shift; fi ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

BIN_PATH="$BIN_DIR/$BIN_NAME"
LEGACY_BIN_PATH="$BIN_DIR/$LEGACY_BIN_NAME"
CFG_PATH="$CONFIG_DIR/$CFG_NAME"
UNIT_PATH="/etc/systemd/system/$UNIT_NAME"

need_root() { [[ "$(id -u)" == "0" ]] || { echo "run as root: sudo bash $0" >&2; exit 1; }; }
have() { command -v "$1" >/dev/null 2>&1; }

existing_bin_path() {
  if [[ -x "$BIN_PATH" ]]; then
    printf '%s\n' "$BIN_PATH"
  elif [[ -x "$LEGACY_BIN_PATH" ]]; then
    printf '%s\n' "$LEGACY_BIN_PATH"
  else
    printf '%s\n' "$BIN_PATH"
  fi
}

sidecar_version() {
  local path
  path="$(existing_bin_path)"
  "$path" --version 2>/dev/null | head -1 || echo unknown
}
forward_count() {
  python3 - "$1" <<'PY'
import json, sys
try:
    doc = json.load(open(sys.argv[1]))
except Exception:
    print(-1); raise SystemExit(0)
forwards = doc.get('forwards') or doc.get('forward_bindings') or {}
print(len(forwards) if isinstance(forwards, (dict, list)) else -1)
PY
}

listener_present() {
  local port="$1"
  if ! have ss; then
    return 1
  fi
  ss -lntH 2>/dev/null | awk -v port=":$port" '$4 ~ port"$" || $4 ~ port" " {found=1} END {exit(found ? 0 : 1)}'
}

strict_audit() {
  local failures=0 forwards active enabled listening config_ok audit_bin
  audit_bin="$(existing_bin_path)"
  if [[ -x "$audit_bin" ]]; then
    echo "binary: $audit_bin present version=$(sidecar_version) sha256=$(sha256sum "$audit_bin" | awk '{print $1}')"
  else
    echo "binary: $BIN_PATH MISSING"
    failures=$((failures + 1))
  fi
  forwards=-1
  if [[ -f "$CFG_PATH" ]]; then
    forwards="$(forward_count "$CFG_PATH")"
    config_ok=0
    if [[ "$forwards" != "-1" && -x "$audit_bin" ]] && "$audit_bin" --check-config --config "$CFG_PATH" >/dev/null 2>&1; then
      config_ok=1
    fi
    echo "config: $CFG_PATH forwards=$forwards valid=$config_ok mode=$(stat -c %a "$CFG_PATH" 2>/dev/null || echo unknown)"
    if [[ "$config_ok" != "1" ]]; then failures=$((failures + 1)); fi
  else
    echo "config: $CFG_PATH MISSING"
    failures=$((failures + 1))
  fi
  active="unknown"; enabled="unknown"; listening=0
  if have systemctl; then
    active="$(systemctl is-active "$UNIT_NAME" 2>/dev/null || true)"
    enabled="$(systemctl is-enabled "$UNIT_NAME" 2>/dev/null || true)"
  fi
  if [[ "$forwards" -gt 0 ]] 2>/dev/null; then
    [[ "$active" == "active" ]] || failures=$((failures + 1))
    [[ "$enabled" == "enabled" ]] || failures=$((failures + 1))
    if listener_present "$LISTEN_PORT"; then listening=1; else failures=$((failures + 1)); fi
  else
    [[ "$active" != "active" ]] || failures=$((failures + 1))
    [[ "$enabled" != "enabled" ]] || failures=$((failures + 1))
  fi
  echo "service: active=$active enabled=$enabled listening=$listening expected=$([[ "$forwards" -gt 0 ]] 2>/dev/null && echo active || echo disabled)"
  if [[ "$failures" -eq 0 ]]; then
    echo "health: PASS"
    return 0
  fi
  echo "health: FAIL failures=$failures"
  return 1
}

# --- passthrough helpers (token derivation, version): no root, no install ---
if [[ ${#PASSTHROUGH[@]} -gt 0 ]]; then
  PASSTHROUGH_BIN="$(existing_bin_path)"
  if [[ -x "$PASSTHROUGH_BIN" ]]; then exec "$PASSTHROUGH_BIN" "${PASSTHROUGH[@]}"; fi
  if [[ -n "$LOCAL_BIN" && -x "$LOCAL_BIN" ]]; then exec "$LOCAL_BIN" "${PASSTHROUGH[@]}"; fi
  echo "sidecar binary not present yet; install first or pass --bin <path>" >&2
  exit 1
fi

# --- read-only audit --------------------------------------------------------
if [[ "$CHECK_ONLY" == "1" ]]; then
  if [[ "$STRICT_CHECK" == "1" ]]; then
    strict_audit
    exit $?
  fi
  CHECK_BIN="$(existing_bin_path)"
  if [[ -x "$CHECK_BIN" ]]; then echo "binary: $CHECK_BIN present version=$(sidecar_version)"; else echo "binary: $BIN_PATH MISSING"; fi
  if [[ -f "$CFG_PATH" ]]; then
    echo "config: $CFG_PATH forwards=$(forward_count "$CFG_PATH") mode=$(stat -c %a "$CFG_PATH")"
    [[ -x "$CHECK_BIN" ]] && "$CHECK_BIN" --check-config --config "$CFG_PATH" | head -1
  else
    echo "config: $CFG_PATH MISSING"
  fi
  if have systemctl; then
    echo "active: $(systemctl is-active "$UNIT_NAME" 2>/dev/null || true)"
    echo "enabled: $(systemctl is-enabled "$UNIT_NAME" 2>/dev/null || true)"
  fi
  if have ss; then ss -lntp 2>/dev/null | grep ":$LISTEN_PORT" || echo "port $LISTEN_PORT: not listening"; fi
  exit 0
fi

need_root

if [[ "$UNINSTALL" == "1" ]]; then
  if [[ "$SKIP_SYSTEMD" != "1" ]] && have systemctl; then systemctl disable --now "$UNIT_NAME" >/dev/null 2>&1 || true; fi
  rm -f "$UNIT_PATH"
  if [[ "$SKIP_SYSTEMD" != "1" ]] && have systemctl; then systemctl daemon-reload; fi
  echo "uninstalled $UNIT_NAME (binary and config kept)"
  exit 0
fi

if [[ -z "$ARTIFACT_BASE" ]]; then ARTIFACT_BASE="${LEIKWAN_ARTIFACT_BASE:-}"; fi
if [[ -z "$ARTIFACT_BASE" && -z "$LOCAL_BIN" ]]; then
  echo "either --artifact-base or --bin is required" >&2; usage >&2; exit 2
fi

case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) echo "unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac
ARTIFACT_KEY="uot_sidecar_linux_${ARCH}"
ARTIFACT_URL="$ARTIFACT_BASE/artifacts/uot-sidecar/uot-sidecar_linux_${ARCH}"

mkdir -p "$BIN_DIR" "$CONFIG_DIR"
BACKUP_DIR="/root/backups/leikwan-external-uot-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

fetch() { curl -fsSL --retry 3 --retry-delay 2 "$1"; }

VERSIONS_JSON=""
if [[ -n "$ARTIFACT_BASE" ]]; then VERSIONS_JSON="$(fetch "$ARTIFACT_BASE/artifacts/versions.json" 2>/dev/null || true)"; fi
expected_sha=""
if [[ -n "$VERSIONS_JSON" ]]; then
  expected_sha="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2], {}).get("sha256", ""))' "$VERSIONS_JSON" "$ARTIFACT_KEY")"
fi

# --- binary ----------------------------------------------------------------
TMP_BIN="$BIN_PATH.new"
if [[ -n "$LOCAL_BIN" ]]; then
  install -m 0755 "$LOCAL_BIN" "$TMP_BIN"
else
  fetch "$ARTIFACT_URL" > "$TMP_BIN"
  chmod 0755 "$TMP_BIN"
fi
if [[ ! -s "$TMP_BIN" || ! -x "$TMP_BIN" ]]; then
  echo "downloaded sidecar binary is missing or not executable: $TMP_BIN" >&2
  rm -f "$TMP_BIN"
  exit 1
fi
ACTUAL_SHA="$(sha256sum "$TMP_BIN" | awk '{print $1}')"
if [[ "$expected_sha" =~ ^[0-9a-fA-F]{64}$ ]]; then
  if [[ "$expected_sha" != "$ACTUAL_SHA" ]]; then
    echo "sha256 mismatch for $ARTIFACT_KEY: manifest=$expected_sha actual=$ACTUAL_SHA" >&2
    rm -f "$TMP_BIN"; exit 1
  fi
  echo "sha256 ok ($ARTIFACT_KEY $ACTUAL_SHA)"
else
  echo "warning: no manifest sha256 for $ARTIFACT_KEY; installed unverified bytes" >&2
fi
if ! "$TMP_BIN" --version >/dev/null 2>&1; then
  echo "downloaded sidecar binary failed --version; refusing replacement" >&2
  rm -f "$TMP_BIN"
  exit 1
fi
if [[ -f "$CFG_PATH" ]] && ! "$TMP_BIN" --check-config --config "$CFG_PATH" >/dev/null 2>&1; then
  echo "existing config failed validation with downloaded sidecar; refusing replacement" >&2
  rm -f "$TMP_BIN"
  exit 1
fi
if [[ -x "$BIN_PATH" ]]; then cp -a "$BIN_PATH" "$BACKUP_DIR/$BIN_NAME"; fi
if [[ -e "$LEGACY_BIN_PATH" || -L "$LEGACY_BIN_PATH" ]]; then cp -a "$LEGACY_BIN_PATH" "$BACKUP_DIR/$LEGACY_BIN_NAME"; fi
mv "$TMP_BIN" "$BIN_PATH"
[[ -x "$BIN_PATH" ]] || { echo "installed binary lost execute permission" >&2; exit 1; }
"$BIN_PATH" --version >/dev/null || { echo "installed binary failed --version" >&2; exit 1; }
# Keep the old executable name usable while making the Go path authoritative.
if [[ "$LEGACY_BIN_PATH" != "$BIN_PATH" ]]; then
  ln -sfn "$(basename "$BIN_PATH")" "$LEGACY_BIN_PATH"
fi
echo "installed $BIN_PATH version=$(sidecar_version)"

# --- config ----------------------------------------------------------------
if [[ -n "$CONFIG_SOURCE" ]]; then
  TMP_CFG="$CFG_PATH.new"
  if [[ "$CONFIG_SOURCE" =~ ^https?:// ]]; then fetch "$CONFIG_SOURCE" > "$TMP_CFG"; else install -m 0600 "$CONFIG_SOURCE" "$TMP_CFG"; fi
  if ! "$BIN_PATH" --check-config --config "$TMP_CFG" >/dev/null; then
    echo "rejected config from $CONFIG_SOURCE (--check-config failed)" >&2; rm -f "$TMP_CFG"; exit 1
  fi
  if [[ -f "$CFG_PATH" ]]; then cp -a "$CFG_PATH" "$BACKUP_DIR/$CFG_NAME"; fi
  chmod 0600 "$TMP_CFG"; mv "$TMP_CFG" "$CFG_PATH"
  echo "installed config from $CONFIG_SOURCE"
elif [[ ! -f "$CFG_PATH" ]]; then
  cat > "$CFG_PATH" <<JSON
{
  "listen_host": "0.0.0.0",
  "listen_port": $LISTEN_PORT,
  "forwards": {}
}
JSON
  chmod 0600 "$CFG_PATH"
  echo "wrote empty config $CFG_PATH; add forwards before enabling the service"
fi

# Always validate the effective config, including upgrades that keep an
# existing file. This prevents a stale or malformed config from replacing a
# healthy process and only failing later as a client-side UDP probe error.
if [[ ! -x "$BIN_PATH" ]] || ! "$BIN_PATH" --check-config --config "$CFG_PATH" >/dev/null; then
  echo "rejected effective config $CFG_PATH (--check-config failed)" >&2
  exit 1
fi

FORWARDS="$(forward_count "$CFG_PATH")"
if [[ "$FORWARDS" == "-1" ]]; then echo "config $CFG_PATH is not valid JSON" >&2; exit 1; fi
echo "config=$CFG_PATH forwards=$FORWARDS port=$LISTEN_PORT"

# --- systemd unit ----------------------------------------------------------
if [[ -f "$UNIT_PATH" ]]; then cp -a "$UNIT_PATH" "$BACKUP_DIR/$UNIT_NAME"; fi
cat > "$UNIT_PATH" <<UNIT
[Unit]
Description=Leikwan External UoT Sidecar
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=$BIN_PATH --config $CFG_PATH
Restart=always
RestartSec=5
LimitNOFILE=65535
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT

SHOULD_START=0
if [[ -n "$FORCE_START" ]]; then SHOULD_START=1
elif [[ -n "$FORCE_NO_START" ]]; then SHOULD_START=0
elif [[ "$FORWARDS" -gt 0 ]]; then SHOULD_START=1
fi

if [[ "$SKIP_SYSTEMD" == "1" ]] || ! have systemctl; then
  echo "systemd skipped; start manually: $BIN_PATH --config $CFG_PATH"
else
  systemctl daemon-reload
  if [[ "$SHOULD_START" == "1" ]]; then
    systemctl enable "$UNIT_NAME" >/dev/null 2>&1 || true
    systemctl restart "$UNIT_NAME"
    sleep 1
    ACTIVE="$(systemctl is-active "$UNIT_NAME" || true)"
    echo "service=$ACTIVE enabled=$(systemctl is-enabled "$UNIT_NAME" 2>/dev/null || true)"
    if [[ "$ACTIVE" != "active" ]]; then
      echo "service failed to start; last log lines:" >&2
      journalctl -u "$UNIT_NAME" -n 20 --no-pager >&2 2>/dev/null || true
      exit 1
    fi
  else
    systemctl disable "$UNIT_NAME" >/dev/null 2>&1 || true
    systemctl stop "$UNIT_NAME" >/dev/null 2>&1 || true
    echo "service left disabled (no forwards yet); enable with: systemctl enable --now $UNIT_NAME"
  fi
  if have ss && [[ "$SHOULD_START" == "1" ]]; then
    listener_present "$LISTEN_PORT" || { echo "port $LISTEN_PORT is not listening" >&2; exit 1; }
  fi
fi

cat <<RECEIPT
EXTERNAL-UOT-INSTALL-OK
  installer_version: $INSTALLER_VERSION
  binary:  $BIN_PATH
  sha256:  $ACTUAL_SHA
  version: $(sidecar_version)
  config:  $CFG_PATH (forwards=$FORWARDS)
  unit:    $UNIT_PATH
  backup:  $BACKUP_DIR
  rollback: cp -a $BACKUP_DIR/$BIN_NAME $BIN_PATH && cp -a $BACKUP_DIR/$CFG_NAME $CFG_PATH && systemctl restart $UNIT_NAME
RECEIPT
