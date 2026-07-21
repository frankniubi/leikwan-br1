#!/usr/bin/env bash
set -euo pipefail
CONFIG_PATH="/etc/leikwan-client-agent/config.json"
REQUEST_TOKEN=""
STATE_PATH=""
SERVICE_NAME="leikwan-client-agent"
UPDATE_UNIT_PREFIX="leikwan-client-agent-update"

while [[ $# -gt 0 ]]; do
  case "$1" in
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

ARTIFACT_BASE=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("artifact_base_url") or data.get("master_url", ""))
PY
)

CLIENT_ID=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("client_id", ""))
PY
)

AGENT_TOKEN=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("agent_token", ""))
PY
)

STATE_DIR=$(python3 - "${CONFIG_PATH}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
print(data.get("state_dir", "/var/lib/leikwan-client-agent"))
PY
)

if [[ -z "${STATE_PATH}" ]]; then
  STATE_PATH="${STATE_DIR%/}/agent_update_state.json"
fi

write_update_state() {
  local status="$1"
  local error_message="${2:-}"
  python3 - "${STATE_PATH}" "${REQUEST_TOKEN}" "${status}" "${error_message}" "/usr/local/bin/leikwan-client-agent.py" <<'PY'
import json, pathlib, re, sys, time
path = pathlib.Path(sys.argv[1])
request_token = sys.argv[2]
status = sys.argv[3]
error_message = sys.argv[4]
agent_path = pathlib.Path(sys.argv[5])
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
payload.setdefault("last_download_bytes", 0)
payload.setdefault("download_skip", False)
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
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
PY
}

if [[ -z "${ARTIFACT_BASE}" ]]; then
  ARTIFACT_BASE="${MASTER_URL}"
fi

if [[ -z "${MASTER_URL}" || -z "${CLIENT_ID}" || -z "${AGENT_TOKEN}" ]]; then
  write_update_state "error" "client agent config missing required fields in ${CONFIG_PATH}"
  echo "client agent config missing required fields in ${CONFIG_PATH}" >&2
  exit 1
fi

write_update_state "running"

if command -v systemctl >/dev/null 2>&1; then
  systemctl stop "${SERVICE_NAME}.service" || true
  mapfile -t FORWARD_UNITS < <(systemctl list-units 'leikwan-client-*.service' --all --no-legend --no-pager 2>/dev/null | awk '{ unit=$1; if (unit !~ /^[[:alnum:]_.@-]+\.service$/) { sub(/^[^[:alnum:]_.@-]+/, "", unit); if (unit == "") unit=$2 } print unit }' | grep -E '^leikwan-client-[^[:space:]]+\.service$' | grep -v "^${SERVICE_NAME}\.service$" | grep -v "^${UPDATE_UNIT_PREFIX}" || true)
  for unit in "${FORWARD_UNITS[@]}"; do
    [[ -n "${unit}" ]] || continue
    systemctl stop "$unit" || true
  done
fi

if ! curl --connect-timeout 15 --max-time 300 --retry 3 --retry-delay 2 -fsSL "${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh" -o /tmp/leikwan-install-client-agent.sh; then
  if command -v systemctl >/dev/null 2>&1; then
    systemctl start "${SERVICE_NAME}.service" || true
  fi
  write_update_state "error" "failed to download install-client-agent.sh from ${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh"
  exit 1
fi

chmod +x /tmp/leikwan-install-client-agent.sh
if LEIKWAN_SKIP_UPDATE_HELPER=1 /tmp/leikwan-install-client-agent.sh --master "${MASTER_URL}" --artifact-base "${ARTIFACT_BASE}" --client-id "${CLIENT_ID}" --agent-token "${AGENT_TOKEN}" --config-dir "/etc/leikwan-client-agent"; then
  write_update_state "success"
else
  status=$?
  if command -v systemctl >/dev/null 2>&1; then
    systemctl start "${SERVICE_NAME}.service" || true
  fi
  write_update_state "error" "install-client-agent exited with status ${status}"
  exit "${status}"
fi
