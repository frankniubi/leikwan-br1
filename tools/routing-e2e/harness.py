#!/usr/bin/env python3
"""Runtime proof that an app-built sing-box config routes real connections as its rules say.

The config comes straight from the app's own builder (Android RoutingE2eConfigDumpTest /
iOS RoutingE2EConfigDumpTests). This harness

  1. runs `sing-box check` on the config as built,
  2. rewrites only what a CI box cannot provide -- the TUN inbound becomes a loopback mixed
     (SOCKS5/HTTP) inbound with the same tag, every DNS server becomes a `hosts` server with the
     same tag that answers the test domains with a local marker address, rule-sets point at the
     bundled ios/RuleSets copies, and the cache file is dropped -- while asserting that
     route.rules / route.final / route.default_domain_resolver are byte-for-byte unchanged,
  3. starts two markers: a SOCKS5 server on the proxy node's address (127.0.0.1:1081) that
     answers `MARKER-PROXY <target>` and never dials out, and HTTP servers on the direct marker
     addresses that answer `MARKER-DIRECT <port>`,
  4. runs `sing-box run`, sends one SOCKS5 CONNECT + HTTP GET per case through the inbound and
     classifies the answer as P (proxy marker), D (direct marker) or R (refused / closed with no
     bytes), cross-checking sing-box's own `router: match[i] ... => route(tag)|reject` log line.

Stdlib only. Exit status 0 = every case matched its expectation, 1 = anything else.
"""

from __future__ import annotations

import argparse
import copy
import hashlib
import ipaddress
import json
import os
import re
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

INBOUND_HOST = "127.0.0.1"
INBOUND_PORT = 17890
CASE_TIMEOUT = 3.0
RULESET_PLACEHOLDER = "/__RULESETS__"
ROUTE_KEYS = ("rules", "final", "default_domain_resolver")
ANSI = re.compile(r"\x1b\[[0-9;]*m")
MATCH_LINE = re.compile(r"router: match\[(\d+)\](.*?)=>\s*(.+?)\s*$")


class HarnessError(Exception):
    pass


def log(msg: str) -> None:
    print(f"[routing-e2e] {msg}", flush=True)


def canonical(value) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def is_ip(value: str) -> bool:
    try:
        ipaddress.ip_address(value)
        return True
    except ValueError:
        return False


# --------------------------------------------------------------------------------------------
# Cases
# --------------------------------------------------------------------------------------------

def load_cases(path: Path, platform: str, scenario: str) -> tuple[dict, list[dict]]:
    doc = json.loads(path.read_text(encoding="utf-8"))
    cases = []
    seen = set()
    for raw in doc["cases"]:
        case_id = raw["id"]
        if case_id in seen:
            raise HarnessError(f"duplicate case id {case_id}")
        seen.add(case_id)
        expect = raw["expect"]
        note = raw.get("note", "")
        override = raw.get("override", {}).get(platform)
        if override:
            if not override.get("note"):
                raise HarnessError(f"case {case_id}: override for {platform} has no note")
            expect = override["expect"]
            note = override["note"]
        expected = expect[scenario]
        if expected not in ("P", "D", "R"):
            raise HarnessError(f"case {case_id}: bad expectation {expected!r}")
        cases.append({
            "id": case_id,
            "host": raw["host"],
            "port": int(raw["port"]),
            "host_header": raw.get("host_header"),
            "expected": expected,
            "overridden": bool(override),
            "note": note,
        })
    return doc, cases


def test_domains(cases: list[dict]) -> list[str]:
    domains: list[str] = []
    for case in cases:
        for name in (case["host"], case["host_header"]):
            if name and not is_ip(name) and name.lower() not in domains:
                domains.append(name.lower())
    return domains


# --------------------------------------------------------------------------------------------
# Config rewrite
# --------------------------------------------------------------------------------------------

def load_sha256sums(rulesets: Path) -> dict[str, str]:
    sums = {}
    sums_file = rulesets / "SHA256SUMS"
    if not sums_file.is_file():
        raise HarnessError(f"{sums_file} missing")
    for line in sums_file.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        digest, name = line.split(None, 1)
        sums[name.lstrip("*")] = digest.lower()
    return sums


def resolve_rule_set_file(tag: str, rulesets: Path, sums: dict[str, str]) -> Path:
    name = f"{tag}.srs"
    path = rulesets / name
    if not path.is_file():
        raise HarnessError(f"rule-set {tag}: {path} not bundled")
    want = sums.get(name)
    if want is None:
        raise HarnessError(f"rule-set {tag}: {name} not listed in SHA256SUMS")
    got = hashlib.sha256(path.read_bytes()).hexdigest()
    if got != want:
        raise HarnessError(f"rule-set {tag}: sha256 {got} != SHA256SUMS {want}")
    return path.resolve()


def map_rule_sets(route: dict, rulesets: Path) -> list[str]:
    """Point every route.rule_set entry at the bundled copy. Returns a description per entry."""
    entries = route.get("rule_set") or []
    if not entries:
        return []
    sums = load_sha256sums(rulesets)
    notes = []
    for index, entry in enumerate(entries):
        tag = entry.get("tag")
        kind = entry.get("type")
        if not tag:
            raise HarnessError(f"route.rule_set[{index}] has no tag")
        if entry.get("format", "binary") != "binary":
            raise HarnessError(f"rule-set {tag}: format {entry.get('format')} unsupported")
        if kind == "remote":
            url = entry.get("url", "")
            basename = url.rstrip("/").rsplit("/", 1)[-1]
            if basename != f"{tag}.srs":
                raise HarnessError(f"rule-set {tag}: remote url basename {basename!r} != {tag}.srs ({url})")
            source = f"remote {url}"
        elif kind == "local":
            path = entry.get("path", "")
            if Path(path).name != f"{tag}.srs":
                raise HarnessError(f"rule-set {tag}: local path {path!r} is not <dir>/{tag}.srs")
            source = f"local {path}"
        else:
            raise HarnessError(f"rule-set {tag}: type {kind!r} unsupported")
        resolved = resolve_rule_set_file(tag, rulesets, sums)
        entries[index] = {"tag": tag, "type": "local", "format": "binary", "path": str(resolved)}
        notes.append(f"{tag}: {source} -> {resolved}")
    return notes


def resolve_placeholder_only(config: dict, rulesets: Path) -> dict:
    """The config as built, with only the iOS rule-set directory placeholder made real."""
    pristine = copy.deepcopy(config)
    for entry in (pristine.get("route") or {}).get("rule_set") or []:
        path = entry.get("path")
        if entry.get("type") == "local" and isinstance(path, str) and path.startswith(RULESET_PLACEHOLDER + "/"):
            entry["path"] = str(rulesets.resolve() / path[len(RULESET_PLACEHOLDER) + 1:])
    return pristine


def rewrite_config(config: dict, domains: list[str], hosts_ip: str, rulesets: Path, workdir: Path) -> tuple[dict, list[str]]:
    out = copy.deepcopy(config)
    notes: list[str] = []
    route_before = {k: copy.deepcopy((config.get("route") or {}).get(k)) for k in ROUTE_KEYS}

    out["log"] = {"level": "debug", "timestamp": True}

    inbounds = out.get("inbounds") or []
    tun_indexes = [i for i, inbound in enumerate(inbounds) if inbound.get("type") == "tun"]
    if len(tun_indexes) != 1:
        raise HarnessError(f"expected exactly one tun inbound, found {len(tun_indexes)}")
    tun = inbounds[tun_indexes[0]]
    inbounds[tun_indexes[0]] = {
        "type": "mixed",
        "tag": tun.get("tag", "tun-in"),
        "listen": INBOUND_HOST,
        "listen_port": INBOUND_PORT,
    }
    notes.append(f"inbound {tun.get('tag')}: tun -> mixed {INBOUND_HOST}:{INBOUND_PORT}")

    socks = [o for o in out.get("outbounds") or [] if o.get("type") == "socks"]
    if len(socks) != 1:
        raise HarnessError(f"expected exactly one socks outbound (the proxy node), found {len(socks)}")
    proxy = socks[0]
    if proxy.get("server") != "127.0.0.1" or int(proxy.get("server_port", 0)) != 1081:
        raise HarnessError(f"proxy outbound must be socks 127.0.0.1:1081, got {proxy.get('server')}:{proxy.get('server_port')}")
    notes.append(f"proxy outbound {proxy.get('tag')}: socks {proxy['server']}:{proxy['server_port']} (unchanged)")

    dns = out.get("dns")
    if not dns or not dns.get("servers"):
        raise HarnessError("config has no dns.servers")
    hosts_file = workdir / "empty-hosts"
    hosts_file.write_text("", encoding="utf-8")
    predefined = {domain: [hosts_ip] for domain in domains}
    for index, server in enumerate(dns["servers"]):
        tag = server.get("tag")
        if not tag:
            raise HarnessError(f"dns.servers[{index}] has no tag")
        dns["servers"][index] = {
            "type": "hosts",
            "tag": tag,
            "path": [str(hosts_file)],
            "predefined": predefined,
        }
        notes.append(f"dns server {tag}: {server.get('type')} {server.get('server', '')} -> hosts ({len(domains)} test domains -> {hosts_ip})")
    dns["rules"] = []

    route = out.get("route")
    if not route:
        raise HarnessError("config has no route")
    notes.extend(map_rule_sets(route, rulesets))

    experimental = out.get("experimental")
    if isinstance(experimental, dict) and "cache_file" in experimental:
        experimental.pop("cache_file")
        notes.append("experimental.cache_file dropped")
        if not experimental:
            out.pop("experimental")

    route_after = {k: route.get(k) for k in ROUTE_KEYS}
    if canonical(route_before) != canonical(route_after):
        raise HarnessError("rewrite changed route.rules/final/default_domain_resolver")
    digest = hashlib.sha256(canonical(route_after).encode("utf-8")).hexdigest()
    notes.append(f"route digest (rules+final+default_domain_resolver, unchanged): sha256:{digest}")
    return out, notes


# --------------------------------------------------------------------------------------------
# sing-box
# --------------------------------------------------------------------------------------------

TUN_PRIVILEGE_HINTS = ("operation not permitted", "permission denied", "/dev/net/tun", "configure tun", "open tun", "tun interface")


def singbox_check(singbox: Path, config_path: Path, label: str, allow_tun_privilege: bool) -> tuple[bool, str]:
    proc = subprocess.run([str(singbox), "check", "-c", str(config_path)], capture_output=True, text=True, timeout=60)
    output = (proc.stdout + proc.stderr).strip()
    if proc.returncode == 0:
        log(f"sing-box check ({label}): OK")
        return True, output
    lowered = output.lower()
    non_root_linux = sys.platform.startswith("linux") and hasattr(os, "geteuid") and os.geteuid() != 0
    if allow_tun_privilege and non_root_linux and any(hint in lowered for hint in TUN_PRIVILEGE_HINTS):
        log(f"WARNING sing-box check ({label}) failed on TUN privileges as non-root (not a config error): {output}")
        return True, output
    log(f"sing-box check ({label}) FAILED (exit {proc.returncode}):\n{output}")
    return False, output


def wait_for_port(host: str, port: int, proc: subprocess.Popen, deadline_s: float) -> None:
    deadline = time.monotonic() + deadline_s
    while time.monotonic() < deadline:
        if proc.poll() is not None:
            raise HarnessError(f"sing-box exited early with status {proc.returncode}")
        try:
            with socket.create_connection((host, port), timeout=0.5):
                return
        except OSError:
            time.sleep(0.2)
    raise HarnessError(f"sing-box inbound {host}:{port} not accepting after {deadline_s}s")


# --------------------------------------------------------------------------------------------
# Markers
# --------------------------------------------------------------------------------------------

def recv_exact(conn: socket.socket, size: int) -> bytes:
    data = b""
    while len(data) < size:
        chunk = conn.recv(size - len(data))
        if not chunk:
            raise ConnectionError("eof")
        data += chunk
    return data


def read_http_request(conn: socket.socket, limit: int = 65536) -> bytes:
    data = b""
    while b"\r\n\r\n" not in data and len(data) < limit:
        chunk = conn.recv(4096)
        if not chunk:
            break
        data += chunk
    return data


def request_path(raw: bytes) -> str:
    try:
        first = raw.split(b"\r\n", 1)[0].decode("latin-1")
        return first.split(" ")[1]
    except Exception:
        return ""


def http_reply(conn: socket.socket, body: str) -> None:
    payload = body.encode("utf-8")
    head = (
        "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
        f"Content-Length: {len(payload)}\r\nConnection: close\r\n\r\n"
    ).encode("ascii")
    conn.sendall(head + payload)


class ThreadingTCP(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True
    allow_reuse_address = True


class Recorder:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.events: list[dict] = []

    def add(self, event: dict) -> None:
        with self.lock:
            self.events.append(event)

    def since(self, index: int) -> list[dict]:
        with self.lock:
            return list(self.events[index:])

    def __len__(self) -> int:
        with self.lock:
            return len(self.events)


def make_socks_marker(recorder: Recorder):
    class Handler(socketserver.BaseRequestHandler):
        def handle(self) -> None:
            conn = self.request
            conn.settimeout(10)
            event = {"marker": "proxy"}
            try:
                ver, count = recv_exact(conn, 2)
                if ver != 5:
                    return
                methods = recv_exact(conn, count)
                if 2 in methods:
                    conn.sendall(b"\x05\x02")
                    sub_ver, ulen = recv_exact(conn, 2)
                    user = recv_exact(conn, ulen).decode("utf-8", "replace")
                    (plen,) = recv_exact(conn, 1)
                    recv_exact(conn, plen)
                    event["auth"] = f"user/pass ({user})"
                    conn.sendall(b"\x01\x00")
                elif 0 in methods:
                    conn.sendall(b"\x05\x00")
                    event["auth"] = "none"
                else:
                    conn.sendall(b"\x05\xff")
                    return
                ver, cmd, _rsv, atyp = recv_exact(conn, 4)
                if atyp == 1:
                    host = socket.inet_ntop(socket.AF_INET, recv_exact(conn, 4))
                elif atyp == 4:
                    host = "[" + socket.inet_ntop(socket.AF_INET6, recv_exact(conn, 16)) + "]"
                elif atyp == 3:
                    (length,) = recv_exact(conn, 1)
                    host = recv_exact(conn, length).decode("utf-8", "replace")
                else:
                    return
                port = int.from_bytes(recv_exact(conn, 2), "big")
                target = f"{host}:{port}"
                event["target"] = target
                if cmd != 1:
                    conn.sendall(b"\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00")
                    return
                conn.sendall(b"\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00")
                raw = read_http_request(conn)
                event["path"] = request_path(raw)
                http_reply(conn, f"MARKER-PROXY {target}\n")
            except (OSError, ConnectionError, ValueError):
                pass
            finally:
                recorder.add(event)

    return Handler


def make_direct_marker(recorder: Recorder, ip: str, port: int):
    class Handler(socketserver.BaseRequestHandler):
        def handle(self) -> None:
            conn = self.request
            conn.settimeout(10)
            event = {"marker": "direct", "listen": f"{ip}:{port}"}
            try:
                raw = read_http_request(conn)
                event["path"] = request_path(raw)
                http_reply(conn, f"MARKER-DIRECT {port}\n")
            except OSError:
                pass
            finally:
                recorder.add(event)

    return Handler


# --------------------------------------------------------------------------------------------
# Client
# --------------------------------------------------------------------------------------------

def socks_connect_request(host: str, port: int) -> bytes:
    try:
        addr = ipaddress.ip_address(host)
    except ValueError:
        encoded = host.encode("idna")
        return b"\x05\x01\x00\x03" + bytes([len(encoded)]) + encoded + port.to_bytes(2, "big")
    if addr.version == 4:
        return b"\x05\x01\x00\x01" + addr.packed + port.to_bytes(2, "big")
    return b"\x05\x01\x00\x04" + addr.packed + port.to_bytes(2, "big")


def run_case(case: dict) -> dict:
    """One SOCKS5 CONNECT + HTTP GET through the sing-box inbound. Returns outcome + evidence."""
    host, port = case["host"], case["port"]
    header_host = case["host_header"] or (f"[{host}]" if ":" in host else host)
    result = {"observed": "?", "detail": ""}
    deadline = time.monotonic() + CASE_TIMEOUT

    def remaining() -> float:
        return max(0.05, deadline - time.monotonic())

    try:
        conn = socket.create_connection((INBOUND_HOST, INBOUND_PORT), timeout=remaining())
    except OSError as exc:
        result["detail"] = f"inbound connect failed: {exc}"
        return result
    try:
        conn.settimeout(remaining())
        conn.sendall(b"\x05\x01\x00")
        if recv_exact(conn, 2) != b"\x05\x00":
            result["detail"] = "inbound refused no-auth SOCKS5"
            return result
        conn.sendall(socks_connect_request(host, port))
        try:
            ver, rep, _rsv, atyp = recv_exact(conn, 4)
        except ConnectionError:
            result.update(observed="R", detail="closed during SOCKS5 reply")
            return result
        if rep != 0:
            result.update(observed="R", detail=f"SOCKS5 reply code {rep}")
            return result
        recv_exact(conn, {1: 4, 4: 16}.get(atyp, 0) or recv_exact(conn, 1)[0])
        recv_exact(conn, 2)
        request = (
            f"GET /case/{case['id']} HTTP/1.1\r\nHost: {header_host}\r\n"
            "User-Agent: leikwan-routing-e2e\r\nAccept: */*\r\nConnection: close\r\n\r\n"
        )
        conn.sendall(request.encode("ascii"))
        data = b""
        error = ""
        while True:
            conn.settimeout(remaining())
            try:
                chunk = conn.recv(4096)
            except socket.timeout:
                error = "timeout"
                break
            except OSError as exc:
                error = f"{type(exc).__name__}: {exc}"
                break
            if not chunk:
                break
            data += chunk
        text = data.decode("utf-8", "replace")
        if "MARKER-PROXY" in text:
            line = text[text.index("MARKER-PROXY"):].splitlines()[0]
            result.update(observed="P", detail=line)
        elif "MARKER-DIRECT" in text:
            line = text[text.index("MARKER-DIRECT"):].splitlines()[0]
            result.update(observed="D", detail=line)
        elif not data and error != "timeout":
            result.update(observed="R", detail=f"SOCKS5 ok, then closed with no bytes ({error or 'EOF'})")
        elif error == "timeout":
            result["detail"] = f"timeout after {CASE_TIMEOUT}s ({len(data)} bytes)"
        else:
            result["detail"] = f"unexpected reply: {text[:120]!r}"
        return result
    except (OSError, ConnectionError) as exc:
        if isinstance(exc, ConnectionResetError):
            result.update(observed="R", detail="connection reset")
        else:
            result["detail"] = f"{type(exc).__name__}: {exc}"
        return result
    finally:
        conn.close()


# --------------------------------------------------------------------------------------------
# Log cross-check
# --------------------------------------------------------------------------------------------

def outcome_for_action(action: str, outbound_types: dict[str, str]) -> str | None:
    if action.startswith("reject"):
        return "R"
    match = re.match(r"route\(([^,)]+)", action)
    if not match:
        return None
    kind = outbound_types.get(match.group(1))
    if kind == "socks":
        return "P"
    if kind == "direct":
        return "D"
    return None


def log_verdict(lines: list[str], outbound_types: dict[str, str], final_tag: str, rules: list) -> dict:
    decisive = None
    for line in lines:
        m = MATCH_LINE.search(ANSI.sub("", line))
        if not m:
            continue
        action = m.group(3)
        if action in ("sniff", "hijack-dns", "resolve") or action.startswith("sniff(") or action.startswith("resolve("):
            continue
        decisive = (int(m.group(1)), m.group(2).strip(), action)
    if decisive is None:
        outcome = outcome_for_action(f"route({final_tag})", outbound_types)
        return {"log_outcome": outcome, "log_rule": f"final -> {final_tag}", "log_exact": False}
    index, description, action = decisive
    rule_json = canonical(rules[index]) if 0 <= index < len(rules) else description
    return {
        "log_outcome": outcome_for_action(action, outbound_types),
        "log_rule": f"match[{index}] {rule_json} => {action}",
        "log_exact": True,
    }


# --------------------------------------------------------------------------------------------
# Main
# --------------------------------------------------------------------------------------------

def write_summary(platform: str, scenario: str, version: str, rows: list[dict], notes: list[str], failed: bool) -> str:
    title = f"### Routing E2E: {platform} {scenario} ({'FAIL' if failed else 'PASS'})"
    lines = [title, "", f"sing-box: `{version}`", ""]
    lines.append("| case | destination | expected | observed | sing-box decision | evidence | result |")
    lines.append("|---|---|---|---|---|---|---|")
    for row in rows:
        dest = f"{row['host']}:{row['port']}"
        if row.get("host_header"):
            dest += f" (Host {row['host_header']})"
        expected = row["expected"] + (" (override)" if row["overridden"] else "")
        evidence = row["detail"].replace("|", "\\|")
        decision = (row.get("log_rule") or "").replace("|", "\\|")
        if len(decision) > 140:
            decision = decision[:137] + "..."
        lines.append(f"| {row['id']} | {dest} | {expected} | {row['observed']} | `{decision}` | {evidence} | {row['verdict']} |")
    lines.append("")
    lines.append("<details><summary>config rewrite</summary>")
    lines.append("")
    lines.extend(f"- {note}" for note in notes)
    lines.append("")
    lines.append("</details>")
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    parser.add_argument("--config", required=True, type=Path, help="app-built sing-box config JSON")
    parser.add_argument("--scenario", required=True, choices=["s1", "s2"])
    parser.add_argument("--platform", required=True, choices=["android", "ios"])
    parser.add_argument("--singbox", type=Path, help="sing-box binary (not needed with --rewrite-only)")
    parser.add_argument("--rulesets", required=True, type=Path, help="ios/RuleSets directory")
    parser.add_argument("--cases", required=True, type=Path, help="cases.json")
    parser.add_argument("--out", required=True, type=Path, help="output directory for logs/results")
    parser.add_argument("--rewrite-only", action="store_true", help="write the rewritten config and stop")
    args = parser.parse_args()

    out = args.out.resolve()
    out.mkdir(parents=True, exist_ok=True)
    prefix = f"{args.platform}-{args.scenario}"

    config = json.loads(args.config.read_text(encoding="utf-8"))
    doc, cases = load_cases(args.cases, args.platform, args.scenario)
    domains = test_domains(cases)
    hosts_ip = doc["dns_hosts_target"]
    direct_ips = doc["direct_marker"]["ips"]
    direct_ports = doc["direct_marker"]["ports"]
    if hosts_ip not in direct_ips:
        raise HarnessError("dns_hosts_target must be one of direct_marker.ips")

    rewritten, notes = rewrite_config(config, domains, hosts_ip, args.rulesets, out)
    rewritten_path = out / f"{prefix}.rewritten.json"
    rewritten_path.write_text(json.dumps(rewritten, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    for note in notes:
        log(note)
    log(f"rewritten config: {rewritten_path}")
    if args.rewrite_only:
        return 0

    if not args.singbox:
        raise HarnessError("--singbox is required unless --rewrite-only")
    singbox = args.singbox.resolve()
    version = subprocess.run([str(singbox), "version"], capture_output=True, text=True).stdout.strip()
    log(f"sing-box: {version.splitlines()[0] if version else '?'}")

    pristine_path = out / f"{prefix}.pristine-check.json"
    pristine_path.write_text(json.dumps(resolve_placeholder_only(config, args.rulesets), indent=2), encoding="utf-8")
    ok_pristine, _ = singbox_check(singbox, pristine_path, "config as built", allow_tun_privilege=True)
    ok_rewritten, _ = singbox_check(singbox, rewritten_path, "rewritten", allow_tun_privilege=False)
    if not (ok_pristine and ok_rewritten):
        return 1

    route = rewritten["route"]
    rules = route.get("rules") or []
    outbound_types = {o["tag"]: o["type"] for o in rewritten.get("outbounds") or [] if "tag" in o}
    final_tag = route.get("final") or next(iter(outbound_types))
    # Guard against a swapped artifact: S1 must fall through to the proxy, S2 to direct.
    want_final = {"s1": "socks", "s2": "direct"}[args.scenario]
    if outbound_types.get(final_tag) != want_final:
        log(f"ERROR route.final {final_tag!r} is a {outbound_types.get(final_tag)!r} outbound; scenario {args.scenario} needs {want_final}")
        return 1

    recorder = Recorder()
    servers: list[socketserver.TCPServer] = []
    singbox_proc = None
    log_file = None
    log_path = out / f"{prefix}.sing-box.log"
    rows: list[dict] = []
    failed = False
    try:
        servers.append(ThreadingTCP(("127.0.0.1", 1081), make_socks_marker(recorder)))
        for ip in direct_ips:
            for port in direct_ports:
                try:
                    servers.append(ThreadingTCP((ip, port), make_direct_marker(recorder, ip, port)))
                except OSError as exc:
                    raise HarnessError(f"cannot bind direct marker {ip}:{port} ({exc}); add the address to lo first") from exc
        for server in servers:
            threading.Thread(target=server.serve_forever, daemon=True).start()
        log(f"markers: socks 127.0.0.1:1081, direct {', '.join(f'{ip}:{p}' for ip in direct_ips for p in direct_ports)}")

        workdir = Path(tempfile.mkdtemp(prefix="sing-box-run-", dir=str(out)))
        log_file = open(log_path, "w", encoding="utf-8")
        singbox_proc = subprocess.Popen(
            [str(singbox), "run", "-c", str(rewritten_path), "-D", str(workdir)],
            stdout=log_file, stderr=subprocess.STDOUT,
        )
        wait_for_port(INBOUND_HOST, INBOUND_PORT, singbox_proc, 20)
        time.sleep(0.5)
        log(f"sing-box running (pid {singbox_proc.pid}), inbound {INBOUND_HOST}:{INBOUND_PORT}")

        for case in cases:
            log_file.flush()
            offset = log_path.stat().st_size
            marker_mark = len(recorder)
            result = run_case(case)
            time.sleep(0.3)
            with open(log_path, "r", encoding="utf-8", errors="replace") as handle:
                handle.seek(offset)
                case_lines = handle.read().splitlines()
            verdict = log_verdict(case_lines, outbound_types, final_tag, rules)
            markers = recorder.since(marker_mark)
            row = {**case, **result, **verdict, "markers": markers, "log_lines": case_lines}
            problems = []
            if result["observed"] != case["expected"]:
                problems.append(f"expected {case['expected']}, observed {result['observed']}")
            if verdict["log_outcome"] is None:
                log(f"WARNING {case['id']}: could not map sing-box decision '{verdict['log_rule']}'")
            elif result["observed"] in ("P", "D", "R") and verdict["log_outcome"] != result["observed"]:
                if verdict["log_exact"]:
                    problems.append(f"sing-box logged {verdict['log_rule']} but the marker saw {result['observed']}")
                else:
                    log(f"WARNING {case['id']}: no match[] line; implied {verdict['log_rule']} disagrees with {result['observed']}")
            row["verdict"] = "PASS" if not problems else "FAIL: " + "; ".join(problems)
            failed = failed or bool(problems)
            rows.append(row)
            log(f"{case['id']:<32} {case['host']}:{case['port']:<6} expected {case['expected']} observed {result['observed']}"
                f"  [{verdict['log_rule']}]  {result['detail']}  -> {row['verdict']}")

        if singbox_proc.poll() is not None:
            failed = True
            log(f"sing-box exited during the run (status {singbox_proc.returncode})")
    finally:
        if singbox_proc is not None and singbox_proc.poll() is None:
            singbox_proc.terminate()
            try:
                singbox_proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                singbox_proc.kill()
                singbox_proc.wait()
        for server in servers:
            server.shutdown()
            server.server_close()
        if log_file is not None:
            log_file.close()

    if len(rows) != len(cases):
        failed = True
    summary = write_summary(args.platform, args.scenario, version.splitlines()[0] if version else "?", rows, notes, failed)
    (out / f"{prefix}.summary.md").write_text(summary, encoding="utf-8")
    step_summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if step_summary:
        with open(step_summary, "a", encoding="utf-8") as handle:
            handle.write(summary + "\n")
    results = {
        "platform": args.platform,
        "scenario": args.scenario,
        "sing_box_version": version,
        "config": str(args.config),
        "rewrite": notes,
        "passed": not failed,
        "cases": rows,
    }
    (out / f"{prefix}.results.json").write_text(json.dumps(results, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    passed = sum(1 for row in rows if row["verdict"] == "PASS")
    log(f"{prefix}: {passed}/{len(cases)} cases passed -> {'PASS' if not failed else 'FAIL'}")
    return 1 if failed else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except HarnessError as exc:
        log(f"ERROR: {exc}")
        sys.exit(1)
