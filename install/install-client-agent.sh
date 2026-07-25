#!/usr/bin/env bash
set -euo pipefail

INSTALLER_VERSION="2026.07.25.1"
MASTER_URL=""
ARTIFACT_BASE=""
CLIENT_ID=""
AGENT_TOKEN=""
CONFIG_DIR="/etc/leikwan-client-agent"
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-client-agent"
AUTO_INSTALL_MPTCP_KERNEL=0
REBOOT_AFTER_MPTCP_KERNEL_INSTALL=0
REQUIRE_MPTCP=0

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
  local required="$2"
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

  if [[ "$required" == "1" ]]; then
    return 1
  fi
  return 0
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

ensure_time_sync() {
  if command -v timedatectl >/dev/null 2>&1; then
    if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi '^yes$'; then
      return 0
    fi
  fi
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y ntp
    systemctl enable ntp.service >/dev/null 2>&1 || systemctl enable ntpd.service >/dev/null 2>&1 || true
    systemctl restart ntp.service >/dev/null 2>&1 || systemctl restart ntpd.service >/dev/null 2>&1 || true
  fi
}

current_installer_sha256() {
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
    --client-id) CLIENT_ID="$2"; shift 2 ;;
    --agent-token) AGENT_TOKEN="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --require-mptcp) REQUIRE_MPTCP=1; shift ;;
    --auto-install-mptcp-kernel) AUTO_INSTALL_MPTCP_KERNEL=1; shift ;;
    --reboot-after-mptcp-kernel-install) REBOOT_AFTER_MPTCP_KERNEL_INSTALL=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MASTER_URL" || -z "$CLIENT_ID" || -z "$AGENT_TOKEN" ]]; then
  echo "usage: install-client-agent.sh --master <url> --client-id <id> --agent-token <token>" >&2
  exit 1
fi

if [[ -z "$ARTIFACT_BASE" ]]; then
  ARTIFACT_BASE="$MASTER_URL"
fi

ensure_mptcp_ready "client-agent host" "$REQUIRE_MPTCP" || {
  echo "client-agent host: MPTCP was required but the running kernel does not support it." >&2
  exit 1
}
ensure_time_sync
install_wireguard_tools

STATE_DIR="/var/lib/leikwan-client-agent"
INSTALLER_SHA256="$(current_installer_sha256)"
mkdir -p "$CONFIG_DIR" "$BIN_DIR" "$STATE_DIR"

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
  mv "$tmp_path" "$path"
}

download_atomic "$ARTIFACT_BASE/artifacts/client-agent.py" "$BIN_DIR/leikwan-client-agent.py"
download_atomic "$ARTIFACT_BASE/artifacts/cfwg-latency-watchdog.py" "$BIN_DIR/cfwg_latency_watchdog.py"
if [[ "${LEIKWAN_SKIP_UPDATE_HELPER:-0}" != "1" ]]; then
  download_atomic "$ARTIFACT_BASE/artifacts/client-agent-update.sh" "$BIN_DIR/leikwan-client-agent-update"
fi
download_atomic "$ARTIFACT_BASE/artifacts/install-forward-runtime.py" "$BIN_DIR/install_forward_runtime.py"
download_atomic "$ARTIFACT_BASE/artifacts/forward-strategy.py" "$BIN_DIR/forward_strategy.py"
cat > "$BIN_DIR/leikwan-client-strategy" <<WRAPPER
#!/usr/bin/env bash
exec /usr/bin/python3 "$BIN_DIR/forward_strategy.py" "\$@"
WRAPPER
chmod 0755 "$BIN_DIR/leikwan-client-strategy"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-rate-limit.py" "$BIN_DIR/leikwan-rate-limit.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-mptcp-client.py" "$BIN_DIR/leikwan-mptcp-client.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"
download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot-bond.py" "$BIN_DIR/leikwan-uot-bond.py"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" "$BIN_DIR/leikwan-bond"
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64" "$STATE_DIR/leikwan-mptcp-relay"

cat > "$CONFIG_DIR/config.json" <<JSON
{
  "master_url": "$MASTER_URL",
  "artifact_base_url": "$ARTIFACT_BASE",
  "client_id": $CLIENT_ID,
  "agent_token": "$AGENT_TOKEN",
  "state_dir": "$STATE_DIR",
  "bond_bin": "/usr/local/bin/leikwan-bond",
  "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
  "mptcp_client_bin": "$BIN_DIR/leikwan-mptcp-client.py",
  "log_level": "INFO",
  "installer_version": "$INSTALLER_VERSION",
  "installer_sha256": "$INSTALLER_SHA256"
}
JSON

cat > "/etc/systemd/system/$SERVICE_NAME.service" <<UNIT
[Unit]
Description=Leikwan Client Agent
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 $BIN_DIR/leikwan-client-agent.py --config $CONFIG_DIR/config.json run
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable "$SERVICE_NAME.service"
systemctl restart "$SERVICE_NAME.service"

echo "installed $SERVICE_NAME"
