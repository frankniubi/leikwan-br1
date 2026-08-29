#!/usr/bin/env bash
set -euo pipefail
umask 077
CONFIG_PATH="/etc/leikwan-client-agent/config.json"
REQUEST_TOKEN=""
STATE_PATH=""
SERVICE_NAME="leikwan-client-agent"
UPDATE_UNIT_PREFIX="leikwan-client-agent-update"
FORWARD_UNITS=()
FORWARD_UNITS_ACTIVE=()
SERVICE_WAS_ACTIVE=0
TEMP_INSTALLER="/tmp/leikwan-install-client-agent.sh"
TEMP_UPDATE_HELPER="/tmp/leikwan-client-agent-update.sh"
VERSIONS_TMP=""

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

restore_previous_runtime_state() {
  local status="${1:-0}" unit
  if ! command -v systemctl >/dev/null 2>&1; then
    return 0
  fi
  if [[ "$status" -ne 0 ]]; then
    if [[ "$SERVICE_WAS_ACTIVE" == "1" ]]; then
      systemctl start "${SERVICE_NAME}.service" >/dev/null 2>&1 || true
    else
      systemctl stop "${SERVICE_NAME}.service" >/dev/null 2>&1 || true
    fi
  fi
  for unit in "${FORWARD_UNITS_ACTIVE[@]}"; do
    [[ -n "$unit" ]] || continue
    systemctl start "$unit" >/dev/null 2>&1 || true
  done
}

cleanup_update() {
  local status=$?
  trap - EXIT
  restore_previous_runtime_state "$status"
  rm -f "$TEMP_INSTALLER" "$TEMP_UPDATE_HELPER"
  [[ -z "${VERSIONS_TMP:-}" ]] || rm -f "$VERSIONS_TMP"
  exit "$status"
}

leikwan_protected_download() {
  local url="$1"
  local path="$2"
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
USER_AGENT = "Leikwan-Client-Agent-Update/strict-doh"


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
    cmd = ["curl", "--connect-timeout", "15", "--max-time", "300", "--retry", "3", "--retry-delay", "2", "-fsSL", url]
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

if [[ -z "${ARTIFACT_BASE}" ]]; then
  ARTIFACT_BASE="${MASTER_URL}"
fi

if [[ -z "${MASTER_URL}" || -z "${CLIENT_ID}" || -z "${AGENT_TOKEN}" ]]; then
  write_update_state "error" "client agent config missing required fields in ${CONFIG_PATH}"
  echo "client agent config missing required fields in ${CONFIG_PATH}" >&2
  exit 1
fi

write_update_state "running"
trap cleanup_update EXIT

if command -v systemctl >/dev/null 2>&1; then
  if systemctl is-active --quiet "${SERVICE_NAME}.service"; then
    SERVICE_WAS_ACTIVE=1
  fi
  mapfile -t FORWARD_UNITS < <(systemctl list-units 'leikwan-client-*.service' --all --no-legend --no-pager 2>/dev/null | awk '{ unit=$1; if (unit !~ /^[[:alnum:]_.@-]+\.service$/) { sub(/^[^[:alnum:]_.@-]+/, "", unit); if (unit == "") unit=$2 } print unit }' | grep -E '^leikwan-client-[^[:space:]]+\.service$' | grep -v "^${SERVICE_NAME}\.service$" | grep -v "^${UPDATE_UNIT_PREFIX}" || true)
  for unit in "${FORWARD_UNITS[@]}"; do
    [[ -n "${unit}" ]] || continue
    if systemctl is-active --quiet "$unit"; then
      FORWARD_UNITS_ACTIVE+=("$unit")
    fi
    systemctl stop "$unit" || true
  done
  systemctl stop "${SERVICE_NAME}.service" || true
fi

if ! leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh" "$TEMP_INSTALLER"; then
  write_update_state "error" "failed to download install-client-agent.sh from ${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh"
  exit 1
fi

VERSIONS_TMP=$(mktemp)
if ! leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/versions.json" "$VERSIONS_TMP"; then
  write_update_state "error" "failed to download artifact manifest"
  exit 1
fi
read -r EXPECTED_INSTALLER_SHA256 EXPECTED_UPDATE_SHA256 < <(python3 - "$VERSIONS_TMP" <<'PY'
import json
import pathlib
import sys

payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
entry = payload.get("client_agent_installer", {})
value = entry.get("sha256", "") if isinstance(entry, dict) else ""
if not isinstance(value, str) or len(value) != 64 or any(char not in "0123456789abcdefABCDEF" for char in value):
    raise SystemExit("missing client_agent_installer sha256")
update_entry = payload.get("client_agent_update", {})
update_value = update_entry.get("sha256", "") if isinstance(update_entry, dict) else ""
if not isinstance(update_value, str) or len(update_value) != 64 or any(char not in "0123456789abcdefABCDEF" for char in update_value):
    raise SystemExit("missing client_agent_update sha256")
print(value.lower(), update_value.lower())
PY
)
rm -f "$VERSIONS_TMP"
VERSIONS_TMP=""
if ! echo "$EXPECTED_INSTALLER_SHA256  $TEMP_INSTALLER" | sha256sum -c - >/dev/null 2>&1; then
  write_update_state "error" "installer sha256 mismatch"
  exit 1
fi

chmod +x "$TEMP_INSTALLER"
if LEIKWAN_SKIP_UPDATE_HELPER=1 "$TEMP_INSTALLER" --master "${MASTER_URL}" --artifact-base "${ARTIFACT_BASE}" --client-id "${CLIENT_ID}" --agent-token "${AGENT_TOKEN}" --config-dir "/etc/leikwan-client-agent"; then
  if ! leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/client-agent-update.sh" "$TEMP_UPDATE_HELPER"; then
    write_update_state "error" "failed to refresh client-agent-update.sh from ${ARTIFACT_BASE%/}/artifacts/client-agent-update.sh"
    exit 1
  fi
  if ! echo "$EXPECTED_UPDATE_SHA256  $TEMP_UPDATE_HELPER" | sha256sum -c - >/dev/null 2>&1; then
    write_update_state "error" "update helper sha256 mismatch"
    exit 1
  fi
  chmod 0755 "$TEMP_UPDATE_HELPER"
  mv "$TEMP_UPDATE_HELPER" /usr/local/bin/leikwan-client-agent-update
  write_update_state "success"
else
  status=$?
  write_update_state "error" "install-client-agent exited with status ${status}"
  exit "${status}"
fi
