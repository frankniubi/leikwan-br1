#!/usr/bin/env bash
set -euo pipefail
umask 077

CONFIG_PATH="/etc/leikwan-sidecar/config.json"
REQUEST_TOKEN=""
STATE_PATH=""
ARTIFACT_BASE_OVERRIDE=""
VERSIONS_TMP=""
UPDATE_TMP=$(mktemp -d /tmp/leikwan-sidecar-update.XXXXXX)
TEMP_INSTALLER="$UPDATE_TMP/install.sh"
TEMP_UPDATE_HELPER="$UPDATE_TMP/update.sh"
TEMP_AGENT="$UPDATE_TMP/agent.py"
TEMP_AGENT_BINARY="$UPDATE_TMP/agent.bin"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config-path) CONFIG_PATH="$2"; shift 2 ;;
    --request-token) REQUEST_TOKEN="$2"; shift 2 ;;
    --state-path) STATE_PATH="$2"; shift 2 ;;
    --artifact-base) ARTIFACT_BASE_OVERRIDE="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

config_value() {
  python3 - "$CONFIG_PATH" "$1" "$2" <<'PY'
import json,pathlib,sys
try:
    value=json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8")).get(sys.argv[2],sys.argv[3])
except Exception:
    value=sys.argv[3]
print(value if value is not None else "")
PY
}

MASTER_URL="$(config_value master_url '')"
ARTIFACT_BASE="$(config_value artifact_base_url "$MASTER_URL")"
AGENT_TOKEN="$(config_value agent_token '')"
SERVICE_NAME="$(config_value service_name 'leikwan-bond-sidecar')"
STATE_DIR="$(config_value state_dir '/var/lib/leikwan-sidecar')"
CONFIG_DIR="$(dirname "$CONFIG_PATH")"
if [[ -n "$ARTIFACT_BASE_OVERRIDE" ]]; then
  ARTIFACT_BASE="$ARTIFACT_BASE_OVERRIDE"
fi
if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi
if [[ -z "$STATE_PATH" ]]; then
  STATE_PATH="${STATE_DIR%/}/agent_update_state.json"
fi

case "$(uname -m)" in
  x86_64|amd64) RUNTIME_ARCH=amd64 ;;
  aarch64|arm64) RUNTIME_ARCH=arm64 ;;
  *) RUNTIME_ARCH=unsupported ;;
esac
AGENT_BINARY_KEY="sidecar_agent_linux_${RUNTIME_ARCH}"

write_update_state() {
  local status="$1"
  local error_message="${2:-}"
  python3 - "$STATE_PATH" "$REQUEST_TOKEN" "$status" "$error_message" "$CONFIG_PATH" "/usr/local/bin/leikwan-bond" <<'PY'
import hashlib,json,pathlib,platform,re,subprocess,sys,time

def sha256(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else ""

path=pathlib.Path(sys.argv[1])
request_token,status,error_message=sys.argv[2:5]
config_path=pathlib.Path(sys.argv[5])
bond_path=pathlib.Path(sys.argv[6])
config={}
try:
    loaded=json.loads(config_path.read_text(encoding="utf-8"))
    if isinstance(loaded,dict): config=loaded
except Exception:
    config={}
runtime_mode=str(config.get("runtime_binary_mode","python") or "python").strip().lower()
configured_path=str(config.get("sidecar_agent_bin","") or "").strip()
agent_path=pathlib.Path(configured_path or (
    "/usr/local/bin/leikwan-sidecar-agent" if runtime_mode=="frozen"
    else "/usr/local/bin/leikwan-sidecar-agent.py"
))
existing={}
if path.exists():
    try:
        loaded=json.loads(path.read_text(encoding="utf-8"))
        if isinstance(loaded,dict): existing=loaded
    except Exception:
        existing={}
payload=dict(existing)
payload["request_token"]=request_token
payload["status"]=status
payload["last_attempt_at"]=time.time()
if error_message:
    payload["last_error"]=error_message
    payload["last_failed_at"]=time.time()
else:
    payload["last_error"]=""
if status=="running":
    payload["last_started_at"]=time.time()
elif status=="success":
    version=""
    if agent_path.exists():
        if runtime_mode=="frozen":
            try:
                probe=subprocess.run([str(agent_path),"--version"],text=True,capture_output=True,timeout=10,check=False)
                version=probe.stdout.strip().splitlines()[0] if probe.returncode==0 and probe.stdout.strip() else ""
            except Exception:
                version=""
        else:
            try:
                match=re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"',agent_path.read_text(encoding="utf-8"))
                version=match.group(1) if match else ""
            except (OSError,UnicodeError):
                version=""
    payload["last_completed_request_token"]=request_token
    payload["last_completed_at"]=time.time()
    payload["installed_version"]=version
    payload["installed_agent_sha256"]=sha256(agent_path)
    payload["installed_bond_sha256"]=sha256(bond_path)
    payload["runtime_binary_mode"]=runtime_mode
    payload["agent_architecture"]=platform.machine().strip().lower()
path.parent.mkdir(parents=True,exist_ok=True)
path.write_text(json.dumps(payload,ensure_ascii=False,indent=2),encoding="utf-8")
PY
}

cleanup_update() {
  local status=$?
  trap - EXIT
  rm -f "$TEMP_INSTALLER" "$TEMP_UPDATE_HELPER" "$TEMP_AGENT" "$TEMP_AGENT_BINARY"
  [[ -z "$VERSIONS_TMP" ]] || rm -f "$VERSIONS_TMP"
  rmdir "$UPDATE_TMP" 2>/dev/null || true
  exit "$status"
}

download_artifact() {
  curl --connect-timeout 15 --max-time 300 --retry 3 --retry-delay 2 -fsSL "$1" -o "$2"
}

resolve_artifact_url() {
  if [[ "$1" =~ ^https?:// ]]; then
    printf '%s\n' "$1"
  else
    printf '%s/%s\n' "${ARTIFACT_BASE%/}" "${1#/}"
  fi
}

if [[ -z "$MASTER_URL" || -z "$AGENT_TOKEN" || -z "$SERVICE_NAME" ]]; then
  write_update_state "error" "sidecar agent config missing required fields in ${CONFIG_PATH}"
  echo "sidecar agent config missing required fields in ${CONFIG_PATH}" >&2
  exit 1
fi
if [[ "$RUNTIME_ARCH" == "unsupported" ]]; then
  write_update_state "error" "unsupported sidecar agent architecture: $(uname -m)"
  exit 1
fi

mkdir -p "$STATE_DIR"
exec 9>"${STATE_DIR%/}/agent-update.lock"
flock -n 9 || { rmdir "$UPDATE_TMP"; exit 75; }
write_update_state "running"
trap cleanup_update EXIT

VERSIONS_TMP="$UPDATE_TMP/versions.json"
if ! download_artifact "${ARTIFACT_BASE%/}/artifacts/versions.json" "$VERSIONS_TMP"; then
  write_update_state "error" "failed to download artifact manifest"
  exit 1
fi

read -r INSTALLER_URL INSTALLER_SHA256 SOURCE_URL SOURCE_SHA256 SOURCE_VERSION BINARY_URL BINARY_SHA256 BINARY_VERSION UPDATE_URL UPDATE_SHA256 < <(
  python3 - "$VERSIONS_TMP" "$AGENT_BINARY_KEY" <<'PY'
import json,pathlib,sys
m=json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
def field(key,name,default="-"):
    entry=m.get(key,{})
    value=entry.get(name,default) if isinstance(entry,dict) else default
    return str(value or default)
print(
    field("bond_sidecar_installer","artifact_url","/artifacts/install-bond-sidecar.sh"),
    field("bond_sidecar_installer","sha256"),
    field("sidecar_agent","artifact_url","/artifacts/sidecar-agent.py"),
    field("sidecar_agent","sha256"),
    field("sidecar_agent","version"),
    field(sys.argv[2],"artifact_url"),
    field(sys.argv[2],"sha256"),
    field(sys.argv[2],"version"),
    field("sidecar_agent_update","artifact_url"),
    field("sidecar_agent_update","sha256"),
)
PY
)

for pair in "installer:$INSTALLER_SHA256" "source:$SOURCE_SHA256"; do
  label="${pair%%:*}"
  digest="${pair#*:}"
  if [[ ! "$digest" =~ ^[0-9a-fA-F]{64}$ ]]; then
    write_update_state "error" "missing ${label} sha256 in artifact manifest"
    exit 1
  fi
done

if ! download_artifact "$(resolve_artifact_url "$INSTALLER_URL")" "$TEMP_INSTALLER"; then
  write_update_state "error" "failed to preflight install-bond-sidecar.sh"
  exit 1
fi
if ! echo "$INSTALLER_SHA256  $TEMP_INSTALLER" | sha256sum -c - >/dev/null 2>&1; then
  write_update_state "error" "sidecar installer sha256 mismatch"
  exit 1
fi
if ! download_artifact "$(resolve_artifact_url "$SOURCE_URL")" "$TEMP_AGENT"; then
  write_update_state "error" "failed to preflight sidecar agent source"
  exit 1
fi

if [[ "$BINARY_URL" != "-" || "$BINARY_SHA256" != "-" || "$BINARY_VERSION" != "-" ]]; then
  if [[ "$BINARY_URL" == "-" || "$BINARY_VERSION" == "-" || ! "$BINARY_SHA256" =~ ^[0-9a-fA-F]{64}$ ]]; then
    write_update_state "error" "incomplete ${AGENT_BINARY_KEY} manifest entry"
    exit 1
  fi
  if ! download_artifact "$(resolve_artifact_url "$BINARY_URL")" "$TEMP_AGENT_BINARY"; then
    write_update_state "error" "failed to preflight ${AGENT_BINARY_KEY}"
    exit 1
  fi
  if [[ ! -s "$TEMP_AGENT_BINARY" ]]; then
    write_update_state "error" "downloaded ${AGENT_BINARY_KEY} is empty"
    exit 1
  fi
  chmod 0755 "$TEMP_AGENT_BINARY"
  if [[ ! -x "$TEMP_AGENT_BINARY" ]]; then
    write_update_state "error" "downloaded ${AGENT_BINARY_KEY} is not executable"
    exit 1
  fi
else
  TEMP_AGENT_BINARY=""
fi

if [[ "$UPDATE_URL" != "-" || "$UPDATE_SHA256" != "-" ]]; then
  if [[ "$UPDATE_URL" == "-" || ! "$UPDATE_SHA256" =~ ^[0-9a-fA-F]{64}$ ]]; then
    write_update_state "error" "incomplete sidecar_agent_update manifest entry"
    exit 1
  fi
  if ! download_artifact "$(resolve_artifact_url "$UPDATE_URL")" "$TEMP_UPDATE_HELPER"; then
    write_update_state "error" "failed to preflight sidecar update helper"
    exit 1
  fi
  if ! echo "$UPDATE_SHA256  $TEMP_UPDATE_HELPER" | sha256sum -c - >/dev/null 2>&1; then
    write_update_state "error" "sidecar update helper sha256 mismatch"
    exit 1
  fi
  chmod 0755 "$TEMP_UPDATE_HELPER"
  if [[ ! -x "$TEMP_UPDATE_HELPER" ]]; then
    write_update_state "error" "downloaded sidecar update helper is not executable"
    exit 1
  fi
fi

if ! python3 - "$VERSIONS_TMP" "$TEMP_INSTALLER" "$TEMP_AGENT" "$TEMP_AGENT_BINARY" "$REQUEST_TOKEN" "$AGENT_BINARY_KEY" <<'PYGUARD'
import ast,hashlib,json,pathlib,re,subprocess,sys
m=json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
installer=pathlib.Path(sys.argv[2]).read_bytes()
installer_entry=m.get("bond_sidecar_installer",{})
if hashlib.sha256(installer).hexdigest()!=installer_entry.get("sha256"): raise SystemExit("installer hash differs from manifest")
match=re.search(rb'INSTALLER_VERSION="([^"]+)"',installer)
if not match or match.group(1).decode()!=installer_entry.get("version"): raise SystemExit("installer version differs from manifest")
source=pathlib.Path(sys.argv[3]).read_bytes()
source_entry=m.get("sidecar_agent",{})
if hashlib.sha256(source).hexdigest()!=source_entry.get("sha256"): raise SystemExit("source hash differs from manifest")
source_version=None
for node in ast.parse(source).body:
    if isinstance(node,ast.Assign) and any(isinstance(t,ast.Name) and t.id=="AGENT_VERSION" for t in node.targets): source_version=ast.literal_eval(node.value)
if not source_version or source_version!=source_entry.get("version"): raise SystemExit("source version differs from manifest")
binary_path=pathlib.Path(sys.argv[4]) if sys.argv[4] else None
target=source_entry
version=source_version
if binary_path is not None:
    target=m.get(sys.argv[6],{})
    if hashlib.sha256(binary_path.read_bytes()).hexdigest()!=target.get("sha256"): raise SystemExit("binary hash differs from manifest")
    probe=subprocess.run([str(binary_path),"--version"],text=True,capture_output=True,timeout=15,check=False)
    version=probe.stdout.strip().splitlines()[0] if probe.returncode==0 and probe.stdout.strip() else None
    if not version or version!=target.get("version"): raise SystemExit("binary version differs from manifest")
    if version!=source_version: raise SystemExit("source and binary versions differ")
token=sys.argv[5]
if token.startswith("auto-release:"):
    fields=dict(part.split("=",1) for part in token[len("auto-release:"):].split("|") if "=" in part)
    if fields.get("agent",version)!=version: raise SystemExit("agent differs from requested version")
    if fields.get("agent_sha256",target["sha256"]).lower()!=target["sha256"].lower(): raise SystemExit("agent differs from requested hash")
PYGUARD
then
  write_update_state "error" "sidecar agent preflight mismatch; keeping current services"
  exit 1
fi

chmod 0755 "$TEMP_INSTALLER"
if LEIKWAN_SKIP_UPDATE_HELPER=1 "$TEMP_INSTALLER" --master "$MASTER_URL" --artifact-base "$ARTIFACT_BASE" --agent-token "$AGENT_TOKEN" --config-dir "$CONFIG_DIR" --service-name "$SERVICE_NAME" --skip-mptcp-check; then
  if [[ -s "$TEMP_UPDATE_HELPER" ]]; then
    chmod 0755 "$TEMP_UPDATE_HELPER"
    mv "$TEMP_UPDATE_HELPER" /usr/local/bin/leikwan-sidecar-update
  fi
  write_update_state "success"
else
  status=$?
  write_update_state "error" "install-bond-sidecar exited with status ${status}"
  exit "$status"
fi
