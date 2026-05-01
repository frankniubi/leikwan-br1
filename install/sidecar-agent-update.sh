#!/usr/bin/env bash
set -euo pipefail

CONFIG_PATH="/etc/leikwan-sidecar/config.json"
REQUEST_TOKEN=""
STATE_PATH=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config-path) CONFIG_PATH="$2"; shift 2 ;;
    --request-token) REQUEST_TOKEN="$2"; shift 2 ;;
    --state-path) STATE_PATH="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

MASTER_URL=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("master_url", ""))
PY
)

AGENT_TOKEN=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("agent_token", ""))
PY
)

SERVICE_NAME=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("service_name", "leikwan-bond-sidecar"))
PY
)

STATE_DIR=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("state_dir", "/var/lib/leikwan-sidecar"))
PY
)

CONFIG_DIR="$(dirname "$CONFIG_PATH")"
AGENT_SERVICE_NAME="${SERVICE_NAME}-agent"
if [[ "$SERVICE_NAME" == *"bond-sidecar"* ]]; then
  MPTCP_SERVICE_NAME="${SERVICE_NAME/bond-sidecar/mptcp-sidecar}"
else
  MPTCP_SERVICE_NAME="${SERVICE_NAME}-mptcp"
fi

if [[ -z "${STATE_PATH}" ]]; then
  STATE_PATH="${STATE_DIR%/}/agent_update_state.json"
fi

write_update_state() {
  local status="$1"
  local error_message="${2:-}"
  python3 - "${STATE_PATH}" "${REQUEST_TOKEN}" "${status}" "${error_message}" "/usr/local/bin/leikwan-sidecar-agent.py" "/usr/local/bin/leikwan-bond" <<'PY'
import hashlib, json, pathlib, re, sys, time

def sha256(path: pathlib.Path) -> str:
    if not path.exists():
        return ""
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()

path = pathlib.Path(sys.argv[1])
request_token = sys.argv[2]
status = sys.argv[3]
error_message = sys.argv[4]
agent_path = pathlib.Path(sys.argv[5])
bond_path = pathlib.Path(sys.argv[6])
existing = {}
if path.exists():
    try:
        loaded = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(loaded, dict):
            existing = loaded
    except Exception:
        existing = {}
payload = dict(existing)
payload["request_token"] = request_token
payload["status"] = status
payload["last_attempt_at"] = time.time()
if error_message:
    payload["last_error"] = error_message
    payload["last_failed_at"] = time.time()
else:
    payload["last_error"] = ""
if status == "running":
    payload["last_started_at"] = time.time()
elif status == "success":
    version = ""
    if agent_path.exists():
        text = agent_path.read_text(encoding="utf-8")
        match = re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', text)
        version = match.group(1) if match else ""
    payload["last_completed_request_token"] = request_token
    payload["last_completed_at"] = time.time()
    payload["installed_version"] = version
    payload["installed_bond_sha256"] = sha256(bond_path)
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
PY
}

if [[ -z "${MASTER_URL}" || -z "${AGENT_TOKEN}" || -z "${SERVICE_NAME}" ]]; then
  write_update_state "error" "sidecar agent config missing required fields in ${CONFIG_PATH}"
  echo "sidecar agent config missing required fields in ${CONFIG_PATH}" >&2
  exit 1
fi

write_update_state "running"

if command -v systemctl >/dev/null 2>&1; then
  systemctl stop "${SERVICE_NAME}.service" || true
  systemctl stop "${MPTCP_SERVICE_NAME}.service" || true
  systemctl stop "${AGENT_SERVICE_NAME}.service" || true
fi

if ! curl --connect-timeout 15 --max-time 300 --retry 3 --retry-delay 2 -fsSL "${MASTER_URL%/}/artifacts/install-bond-sidecar.sh" -o /tmp/leikwan-install-bond-sidecar.sh; then
  if command -v systemctl >/dev/null 2>&1; then
    systemctl start "${AGENT_SERVICE_NAME}.service" || true
  fi
  write_update_state "error" "failed to download install-bond-sidecar.sh from ${MASTER_URL%/}/artifacts/install-bond-sidecar.sh"
  exit 1
fi

chmod +x /tmp/leikwan-install-bond-sidecar.sh
if LEIKWAN_SKIP_UPDATE_HELPER=1 /tmp/leikwan-install-bond-sidecar.sh --master "${MASTER_URL}" --agent-token "${AGENT_TOKEN}" --config-dir "${CONFIG_DIR}" --service-name "${SERVICE_NAME}" --skip-mptcp-check; then
  write_update_state "success"
else
  status=$?
  if command -v systemctl >/dev/null 2>&1; then
    systemctl start "${AGENT_SERVICE_NAME}.service" || true
  fi
  write_update_state "error" "install-bond-sidecar exited with status ${status}"
  exit "${status}"
fi
