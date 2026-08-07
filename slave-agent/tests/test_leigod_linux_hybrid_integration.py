import base64
import hashlib
import hmac
import importlib.util
import json
import os
import pathlib
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import textwrap
import threading
import time
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
BOND_BIN = REPO_ROOT / "artifacts" / "wstunnel" / "leikwan-bond_linux_amd64"
PATHD_BIN = REPO_ROOT / "artifacts" / "wstunnel" / "leikwan-pathd_linux_amd64"
INGRESS_BIN = REPO_ROOT / "artifacts" / "wstunnel" / "leikwan-ingress_linux_amd64"
CLIENT_AGENT_SOURCE = REPO_ROOT / "client-agent" / "client_agent.py"
SLAVE_RELAY_SOURCE = REPO_ROOT / "slave-agent" / "leigod_forward_relay.py"
REVERSE_BANNER = b"reverse-path-ready\n"
REVERSE_PAYLOAD = bytes((index * 17) % 251 for index in range(256 * 1024))
try:
    SOAK_SECONDS = max(0, int(os.environ.get("LEIKWAN_HYBRID_SOAK_SECONDS", "0") or 0))
except ValueError:
    SOAK_SECONDS = 0


def free_port(start: int = 0, end: int = 0) -> int:
    candidates = range(start, end + 1) if start > 0 and end >= start else [0]
    for port in candidates:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            try:
                listener.bind(("127.0.0.1", port))
            except OSError:
                continue
            return int(listener.getsockname()[1])
    raise RuntimeError(f"no free TCP port in {start}..{end}")


def recv_exact(stream: socket.socket, size: int) -> bytes:
    chunks = bytearray()
    while len(chunks) < size:
        chunk = stream.recv(size - len(chunks))
        if not chunk:
            raise RuntimeError(f"socket closed after {len(chunks)} of {size} bytes")
        chunks.extend(chunk)
    return bytes(chunks)


def wait_until(predicate, timeout: float, label: str, interval: float = 0.05):
    deadline = time.monotonic() + timeout
    last_error = None
    while time.monotonic() < deadline:
        try:
            value = predicate()
            if value:
                return value
        except (OSError, ValueError, json.JSONDecodeError) as exc:
            last_error = exc
        time.sleep(interval)
    detail = f": {last_error}" if last_error is not None else ""
    raise AssertionError(f"timed out waiting for {label}{detail}")


def port_is_open(port: int) -> bool:
    try:
        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
            return True
    except OSError:
        return False


def process_is_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False


def process_rss_kib(pid: int) -> int:
    status = pathlib.Path(f"/proc/{pid}/status").read_text(encoding="ascii")
    match = next((line for line in status.splitlines() if line.startswith("VmRSS:")), "")
    fields = match.split()
    if len(fields) < 2:
        raise RuntimeError(f"VmRSS missing for pid {pid}")
    return int(fields[1])


def direct_child_pids(pid: int) -> list[int]:
    children = pathlib.Path(f"/proc/{pid}/task/{pid}/children").read_text(encoding="ascii").strip()
    return [int(value) for value in children.split()] if children else []


def process_cmdline(pid: int) -> str:
    return pathlib.Path(f"/proc/{pid}/cmdline").read_bytes().replace(b"\0", b" ").decode("utf-8", errors="replace")


class EchoTarget:
    def __init__(self, port: int):
        self.port = port
        self.listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.listener.bind(("127.0.0.1", port))
        self.listener.listen()
        self.listener.settimeout(0.2)
        self.stop_event = threading.Event()
        self.lock = threading.Lock()
        self.total_connections = 0
        self.active_connections = 0
        self.max_active_connections = 0
        self.received_bytes = 0
        self.sessions: list[dict[str, object]] = []
        self.workers: list[threading.Thread] = []
        self.thread = threading.Thread(target=self._serve, name="hybrid-target", daemon=True)
        self.thread.start()

    def _serve(self) -> None:
        while not self.stop_event.is_set():
            try:
                stream, _peer = self.listener.accept()
            except socket.timeout:
                continue
            except OSError:
                return
            with self.lock:
                self.total_connections += 1
                self.active_connections += 1
                self.max_active_connections = max(self.max_active_connections, self.active_connections)
            worker = threading.Thread(target=self._handle, args=(stream,), daemon=True)
            self.workers.append(worker)
            worker.start()

    def _handle(self, stream: socket.socket) -> None:
        digest = hashlib.sha256()
        byte_count = 0
        try:
            stream.settimeout(20)
            stream.sendall(REVERSE_BANNER)
            while True:
                chunk = stream.recv(65536)
                if not chunk:
                    break
                digest.update(chunk)
                byte_count += len(chunk)
                with self.lock:
                    self.received_bytes += len(chunk)
            stream.sendall(REVERSE_PAYLOAD)
            try:
                stream.shutdown(socket.SHUT_WR)
            except OSError:
                pass
        finally:
            with self.lock:
                self.sessions.append({"bytes": byte_count, "sha256": digest.hexdigest()})
                self.active_connections -= 1
            stream.close()

    def stop(self) -> None:
        self.stop_event.set()
        self.listener.close()
        self.thread.join(timeout=2)
        for worker in self.workers:
            worker.join(timeout=2)


FAKE_PROVIDER = r'''#!/usr/bin/env python3
import os
import pathlib
import re
import signal
import socket
import sys
import threading
import time

stop = threading.Event()
listener = None
rate = max(1024, int(os.environ.get("LEIKWAN_TEST_PROVIDER_RATE_BPS", "65536")))
marker_dir = pathlib.Path(os.environ["LEIKWAN_TEST_PROVIDER_MARKER_DIR"])
marker_dir.mkdir(parents=True, exist_ok=True)
(marker_dir / "provider.pid").write_text(str(os.getpid()), encoding="ascii")

def exact(stream, size):
    data = bytearray()
    while len(data) < size:
        chunk = stream.recv(size - len(data))
        if not chunk:
            raise RuntimeError("unexpected EOF")
        data.extend(chunk)
    return bytes(data)

def pump(source, destination):
    try:
        while not stop.is_set():
            chunk = source.recv(16384)
            if not chunk:
                break
            time.sleep(len(chunk) / rate)
            destination.sendall(chunk)
    except OSError:
        pass
    finally:
        try:
            destination.shutdown(socket.SHUT_WR)
        except OSError:
            pass

def handle(inbound):
    outbound = None
    try:
        version, count = exact(inbound, 2)
        methods = exact(inbound, count)
        if version != 5 or 0 not in methods:
            return
        inbound.sendall(b"\x05\x00")
        version, command, reserved, kind = exact(inbound, 4)
        if version != 5 or command != 1 or reserved != 0:
            return
        if kind == 1:
            host = socket.inet_ntoa(exact(inbound, 4))
        elif kind == 3:
            length = exact(inbound, 1)[0]
            host = exact(inbound, length).decode("ascii")
        elif kind == 4:
            host = socket.inet_ntop(socket.AF_INET6, exact(inbound, 16))
        else:
            return
        port = int.from_bytes(exact(inbound, 2), "big")
        outbound = socket.create_connection((host, port), timeout=5)
        outbound.settimeout(None)
        inbound.sendall(b"\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00")
        with (marker_dir / "connections.log").open("a", encoding="ascii") as marker:
            marker.write(f"{host}:{port}\n")
        left = threading.Thread(target=pump, args=(inbound, outbound), daemon=True)
        right = threading.Thread(target=pump, args=(outbound, inbound), daemon=True)
        left.start()
        right.start()
        left.join()
        right.join()
    except (OSError, RuntimeError, ValueError):
        pass
    finally:
        inbound.close()
        if outbound is not None:
            outbound.close()

def shutdown(_signum, _frame):
    stop.set()
    if listener is not None:
        try:
            listener.close()
        except OSError:
            pass

signal.signal(signal.SIGTERM, shutdown)
signal.signal(signal.SIGINT, shutdown)
config_path = pathlib.Path(sys.argv[sys.argv.index("--config") + 1])
config = config_path.read_text(encoding="utf-8")
match = re.search(r"socks_port\s*=\s*(\d+)", config)
if match is None:
    raise SystemExit("socks_port missing")
port = int(match.group(1))
listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
listener.bind(("127.0.0.1", port))
listener.listen()
listener.settimeout(0.2)
while not stop.is_set():
    try:
        stream, _peer = listener.accept()
    except socket.timeout:
        continue
    except OSError:
        break
    threading.Thread(target=handle, args=(stream,), daemon=True).start()
'''


CLIENT_AGENT_RSS_WORKER = r'''
import importlib.util
import json
import pathlib
import signal
import sys
import time

source = pathlib.Path(sys.argv[1])
config_path = pathlib.Path(sys.argv[2])
ready_path = pathlib.Path(sys.argv[3])
payload_path = pathlib.Path(sys.argv[4])
spec = importlib.util.spec_from_file_location("leikwan_client_agent_soak", source)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
payload = json.loads(payload_path.read_text(encoding="utf-8"))
metrics = {"pulls": 0, "watches": 0, "heartbeats": 0, "desired_builds": 0}

def write_metrics():
    ready_path.write_text(json.dumps(metrics, sort_keys=True), encoding="ascii")

def fake_http_with_headers(method, url, token, *args, **kwargs):
    if "/configs/watch?" in url:
        metrics["watches"] += 1
        write_metrics()
        time.sleep(5.0)
        return ({"revision": int(payload["config_revision"]), "changed": False}, {})
    if "/configs/pull?" in url:
        metrics["pulls"] += 1
        write_metrics()
        return (payload, {"etag": f'"contract-{payload["config_revision"]}"'})
    raise RuntimeError(f"unexpected HTTP request {method} {url}")

def fake_http(method, url, token, body=None, *args, **kwargs):
    if url.endswith("/api/client/heartbeat"):
        metrics["heartbeats"] += 1
        write_metrics()
        return {"ok": True}
    raise RuntimeError(f"unexpected HTTP request {method} {url}")

module.http_json_with_headers = fake_http_with_headers
module.http_json = fake_http
agent = module.ClientAgent(config_path, http_client=fake_http_with_headers, command_runner=lambda _parts: 0)

def reconcile_pathd(pull_payload):
    agent.build_pathd_desired(pull_payload)
    metrics["desired_builds"] += 1
    write_metrics()
    return True

agent.reconcile_pathd = reconcile_pathd
agent.apply_management_state = lambda _client_meta: None
agent.reconcile_global_mptcp_state = lambda _forwards: None
agent.run_diagnostics_if_requested = lambda *_args, **_kwargs: None
agent.collect_usage_totals = lambda: {"bytes_in": 0, "bytes_out": 0}
agent.ensure_wireguard_identity = lambda: ""
agent.time_sync_state = lambda: {}
agent.running_kernel_supports_mptcp = lambda: False
agent.mptcp_capability = lambda: {}
agent.ensure_runtime_health = lambda _forwards: None

def stop(_signum, _frame):
    agent.stop_event.set()

signal.signal(signal.SIGTERM, stop)
signal.signal(signal.SIGINT, stop)
agent.sync_once()
write_metrics()
agent.run()
'''


@unittest.skipUnless(sys.platform.startswith("linux"), "real Linux release binaries require Linux/WSL")
class LinuxHybridLeigodIntegrationTests(unittest.TestCase):
    def setUp(self) -> None:
        for binary in (BOND_BIN, PATHD_BIN, INGRESS_BIN):
            self.assertTrue(binary.is_file(), f"missing release binary {binary}")
            self.assertTrue(os.access(binary, os.X_OK), f"release binary is not executable {binary}")
        self.root = pathlib.Path(tempfile.mkdtemp(prefix="leikwan-hybrid-integration-"))
        self.processes: list[tuple[str, subprocess.Popen, object]] = []
        self.target = None

    def tearDown(self) -> None:
        self._stop_processes()
        if self.target is not None:
            self.target.stop()
            self.target = None
        shutil.rmtree(self.root, ignore_errors=True)

    @staticmethod
    def _control_plane_pull_fixture() -> dict:
        env = os.environ.copy()
        env["LEIKWAN_EMIT_HYBRID_PULL_FIXTURE"] = "1"
        exported = subprocess.run(
            ["php", str(REPO_ROOT / "master" / "tests" / "leigod_linux_hybrid_contract_test.php")],
            cwd=REPO_ROOT,
            env=env,
            text=True,
            capture_output=True,
            check=True,
        )
        return json.loads(exported.stdout)

    def test_control_plane_pull_contract_builds_pathd_desired(self) -> None:
        payload = self._control_plane_pull_fixture()
        config_path = self.root / "client-agent-contract.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "http://127.0.0.1",
                    "client_id": 20,
                    "agent_token": "contract-only",
                    "device_id": "linux-device-a",
                    "state_dir": str(self.root / "client-state"),
                    "pathd_runtime_dir": str(self.root / "pathd-runtime"),
                    "leigod_bin": str(self.root / "provider-runtime"),
                }
            ),
            encoding="utf-8",
        )
        spec = importlib.util.spec_from_file_location("leikwan_client_agent_contract", CLIENT_AGENT_SOURCE)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader if spec is not None else None)
        module = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = module
        spec.loader.exec_module(module)
        desired = module.ClientAgent(config_path).build_pathd_desired(payload)

        self.assertEqual(42, desired["generation"])
        self.assertEqual([451], [row["line_id"] for row in desired["local_leases"]])
        self.assertEqual(7, desired["local_leases"][0]["lease_generation"])
        self.assertEqual(1, len(desired["forwards"]))
        forward = desired["forwards"][0]
        self.assertEqual("198.51.100.50:7443", forward["egress"])
        self.assertGreaterEqual(len(forward["legs"]), 2)
        self.assertEqual({"client:20:linux-device-a", "node:101", "node:102"}, {
            row["execution_group"] for row in forward["legs"]
        })
        self.assertNotIn("sidecar", json.dumps(desired).lower())

    def _start_process(self, name: str, command: list[str], env: dict[str, str] | None = None) -> subprocess.Popen:
        log_handle = (self.root / f"{name}.log").open("wb")
        process = subprocess.Popen(
            command,
            cwd=self.root,
            env=env,
            stdout=log_handle,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        self.processes.append((name, process, log_handle))
        return process

    def _stop_processes(self) -> None:
        for _name, process, _log in reversed(self.processes):
            if process.poll() is None:
                try:
                    os.killpg(process.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
        for _name, process, log_handle in reversed(self.processes):
            try:
                process.wait(timeout=6)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=3)
            log_handle.close()
        self.processes.clear()

    def _log_tails(self) -> str:
        tails = []
        for name, _process, log_handle in self.processes:
            log_handle.flush()
            path = pathlib.Path(log_handle.name)
            lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
            tails.append(f"[{name}]\n" + "\n".join(lines[-40:]))
        return "\n".join(tails)

    @staticmethod
    def _sign_target(secret: str, target_port: int, forward_id: int, ttl_seconds: int = 300) -> str:
        now = int(time.time())
        payload = json.dumps(
            {
                "target_host": "127.0.0.1",
                "target_port": target_port,
                "target_protocol": "tcp",
                "service_id": 9,
                "forward_id": forward_id,
                "issued_at": now,
                "expires_at": now + max(60, ttl_seconds),
            },
            separators=(",", ":"),
        ).encode("utf-8")
        signature = hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).digest()
        encode = lambda value: base64.urlsafe_b64encode(value).rstrip(b"=").decode("ascii")
        return f"{encode(payload)}.{encode(signature)}"

    @staticmethod
    def _atomic_json(path: pathlib.Path, payload: dict) -> None:
        temporary = path.with_suffix(".new")
        temporary.write_text(json.dumps(payload, sort_keys=True, separators=(",", ":")), encoding="utf-8")
        os.replace(temporary, path)

    def _start_slave_relay(
        self,
        ports: dict[str, int],
        provider: pathlib.Path,
    ) -> dict[str, pathlib.Path | int]:
        slave_marker_dir = self.root / "slave-provider-markers"
        slave_session = self.root / "slave-session.json"
        slave_provider_config = self.root / "slave-provider.toml"
        slave_session.write_text('{"session":"slave-integration"}', encoding="utf-8")
        slave_provider_config.write_text(f"socks_port = {ports['slave_socks']}\n", encoding="utf-8")
        slave_provider_env = os.environ.copy()
        slave_provider_env.update(
            {
                "LEIKWAN_TEST_PROVIDER_MARKER_DIR": str(slave_marker_dir),
                "LEIKWAN_TEST_PROVIDER_RATE_BPS": str(64 * 1024 * 1024),
            }
        )
        slave_provider = self._start_process(
            "slave-provider",
            [str(provider), "up", "--session", str(slave_session), "--config", str(slave_provider_config)],
            env=slave_provider_env,
        )
        wait_until(lambda: port_is_open(ports["slave_socks"]), 5, "slave provider SOCKS listener")

        relay_config = self.root / "slave-relay.json"
        relay_state = self.root / "slave-relay-state.json"
        relay_config.write_text(
            json.dumps(
                {
                    "mode": "bond",
                    "placement": "linux_hybrid",
                    "listen_host": "127.0.0.1",
                    "listen_port": ports["backend"],
                    "target_host": "127.0.0.1",
                    "target_port": ports["aggregator"],
                    "transport": "tcp",
                    "candidates": [
                        {
                            "combination_id": 17,
                            "line_id": 453,
                            "leg_route_id": 1004531,
                            "socks_host": "127.0.0.1",
                            "socks_port": ports["slave_socks"],
                            "sort_order": 10,
                        }
                    ],
                    "policy": {"connect_timeout_seconds": 3},
                },
                sort_keys=True,
            ),
            encoding="utf-8",
        )
        slave_relay = self._start_process(
            "slave-relay",
            [
                sys.executable,
                str(SLAVE_RELAY_SOURCE),
                "--config",
                str(relay_config),
                "--state-file",
                str(relay_state),
                "--log-level",
                "INFO",
            ],
        )
        wait_until(lambda: port_is_open(ports["backend"]), 5, "production slave relay listener")
        return {
            "provider_pid": slave_provider.pid,
            "relay_pid": slave_relay.pid,
            "state_path": relay_state,
            "marker_dir": slave_marker_dir,
        }

    @staticmethod
    def _slave_relay_connect_successes(state_path: pathlib.Path) -> int:
        payload = json.loads(state_path.read_text(encoding="utf-8"))
        candidates = payload.get("candidates", [])
        if not candidates:
            return 0
        return int(candidates[0].get("bond_route", {}).get("connect_successes", 0) or 0)

    def _desired(
        self,
        generation: int,
        expiry: int,
        ports: dict[str, int],
        provider: pathlib.Path,
        secret: str,
        ingress_auth: str,
        token_ttl_seconds: int = 300,
    ) -> dict:
        session_json = '{"session":"integration"}'
        config_toml = f"socks_port = {ports['socks']}\n"
        return {
            "generation": generation,
            "provider": {
                "binary": str(provider),
                "session_json": session_json,
                "session_sha256": hashlib.sha256(session_json.encode()).hexdigest(),
                "local_socks_port_min": 20000,
                "local_socks_port_max": 29999,
            },
            "auth_refs": {"slave-316": ingress_auth},
            "local_leases": [
                {
                    "line_id": 451,
                    "lease_id": "00000000-0000-4000-8000-000000000451",
                    "lease_generation": 7,
                    "max_lanes": 1,
                    "config_sha256": hashlib.sha256(config_toml.encode()).hexdigest(),
                    "config_toml": config_toml,
                    "expires_at_unix": expiry,
                    "socks_addr": f"127.0.0.1:{ports['socks']}",
                }
            ],
            "forwards": [
                {
                    "forward_id": 17,
                    "listen": f"127.0.0.1:{ports['public']}",
                    "egress": f"127.0.0.1:{ports['aggregator']}",
                    "session_token": self._sign_target(secret, ports["target"], 17, token_ttl_seconds),
                    "legs": [
                        {
                            "uri": f"socks5://127.0.0.1:{ports['socks']}?leg_route_id=1004511&line_id=451&lease_generation=7",
                            "execution_group": "client:20:linux-device-a",
                            "line_id": 451,
                            "lease_id": "00000000-0000-4000-8000-000000000451",
                            "lease_generation": 7,
                            "leg_route_id": 1004511,
                        },
                        {
                            "uri": f"ingress+tcp://127.0.0.1:{ports['ingress']}?leg_route_id=1004531&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
                            "execution_group": "node:101",
                            "line_id": 453,
                            "lease_id": "00000000-0000-4000-8000-000000000453",
                            "lease_generation": 3,
                            "leg_route_id": 1004531,
                        },
                    ],
                    "policy": {
                        "chunk_size": 32768,
                        "max_window_bytes": 1048576,
                        "retransmit_ms": 700,
                        "join_timeout_ms": 500,
                        "connect_timeout_ms": 3000,
                        "preferred_initial_legs": 2,
                        "minimum_initial_legs": 2,
                        "initial_leg_wait_ms": 1000,
                        "leg_reconnect_backoff_ms": 30000,
                        "leg_reconnect_backoff_max_ms": 30000,
                        "interactive_chunk_bytes": 4096,
                        "latency_guard_bytes": 32768,
                        "interactive_rtt_slack_ms": 30,
                        "warmup_trigger_bytes": 8192,
                        "warmup_slice_bytes": 16384,
                        "warmup_leg_delay_ms": 5,
                        "bulk_promote_bytes": 32768,
                        "leg_cooldown_ms": 30000,
                        "throughput_min_sample_bytes": 1024,
                        "throughput_min_sample_ms": 1,
                        "adaptive_line_admission": True,
                        "throughput_min_mbps": 10.0,
                        "throughput_relative_floor": 0.9,
                        "throughput_bad_windows": 1,
                        "throughput_recovery_windows": 2,
                        "throughput_quarantine_ms": 30000,
                        "throughput_probe_interval_ms": 500,
                        "throughput_probe_bytes": 16384,
                        "latency_first": True,
                        "udp_primary_only": False,
                        "default_strategy": "speed_max",
                    },
                }
            ],
        }

    def _status(self, status_path: pathlib.Path) -> dict:
        return json.loads(status_path.read_text(encoding="utf-8"))

    def test_real_process_flat_hybrid_reload_exclusion_revocation_and_cleanup(self) -> None:
        allocated_ports: set[int] = set()

        def allocate_port(start: int = 0, end: int = 0) -> int:
            for _attempt in range(32):
                port = free_port(start, end)
                if port not in allocated_ports:
                    allocated_ports.add(port)
                    return port
            raise RuntimeError("failed to allocate a unique test port")

        ports = {
            "target": allocate_port(),
            "aggregator": allocate_port(),
            "public": allocate_port(),
            "backend": allocate_port(),
            "ingress": allocate_port(7000, 9000),
            "socks": allocate_port(20000, 29999),
        }
        ports["slave_socks"] = allocate_port(ports["socks"] + 1, 29999)
        self.assertEqual(len(ports), len(set(ports.values())))
        secret = "integration-target-secret"
        ingress_auth = "integration-ingress-auth"
        provider = self.root / "fake-provider"
        provider.write_text(textwrap.dedent(FAKE_PROVIDER), encoding="utf-8")
        provider.chmod(0o700)
        marker_dir = self.root / "provider-markers"
        status_path = self.root / "status.json"
        desired_path = self.root / "desired.json"
        snapshot_path = self.root / "bond-health.json"

        self.target = EchoTarget(ports["target"])
        aggregator_env = os.environ.copy()
        aggregator_env["LEIKWAN_BOND_STATE_DIR"] = str(self.root / "aggregator-state")
        self._start_process(
            "aggregator",
            [
                str(BOND_BIN),
                "server",
                "--listen",
                f"127.0.0.1:{ports['aggregator']}",
                "--token-secret",
                secret,
                "--adaptive-line-admission",
                "--throughput-min-mbps",
                "10",
                "--throughput-relative-floor",
                "0.9",
                "--throughput-min-sample-bytes",
                "1024",
                "--throughput-min-sample-ms",
                "1",
                "--throughput-bad-windows",
                "1",
                "--throughput-recovery-windows",
                "2",
                "--throughput-quarantine-ms",
                "30000",
                "--throughput-probe-interval-ms",
                "500",
                "--throughput-probe-bytes",
                "16384",
            ],
            env=aggregator_env,
        )
        wait_until(lambda: port_is_open(ports["aggregator"]), 5, "aggregator listener")
        slave_runtime = self._start_slave_relay(ports, provider)
        slave_relay_state = pathlib.Path(slave_runtime["state_path"])

        routes_path = self.root / "routes.json"
        routes_path.write_text(
            json.dumps(
                {
                    "routes": [
                        {
                            "route_key": "fixed",
                            "backend_host": "127.0.0.1",
                            "backend_port": ports["backend"],
                            "service_name": "flat-hybrid-integration",
                            "ingress_protocol": "plain_tcp_mux",
                            "ingress_auth_token": ingress_auth,
                        }
                    ]
                }
            ),
            encoding="utf-8",
        )
        self._start_process(
            "ingress",
            [str(INGRESS_BIN), "mux", "--listen", f"127.0.0.1:{ports['ingress']}", "--routes-file", str(routes_path)],
        )
        wait_until(lambda: port_is_open(ports["ingress"]), 5, "authenticated ingress listener")

        expiry = int(time.time()) + 35
        desired = self._desired(1, expiry, ports, provider, secret, ingress_auth)
        self._atomic_json(desired_path, desired)
        pathd_env = os.environ.copy()
        pathd_env.update(
            {
                "RUST_LOG": "info",
                "LEIKWAN_BOND_SNAPSHOT_PATH": str(snapshot_path),
                "LEIKWAN_BOND_FORWARD_ID": "17",
                "LEIKWAN_BOND_OBSERVER_ID": "20",
                "LEIKWAN_TEST_PROVIDER_MARKER_DIR": str(marker_dir),
                "LEIKWAN_TEST_PROVIDER_RATE_BPS": "262144",
            }
        )
        pathd = self._start_process(
            "pathd",
            [str(PATHD_BIN), "--config", str(desired_path), "--status", str(status_path)],
            env=pathd_env,
        )

        def generation_one_ready():
            status = self._status(status_path)
            return (
                status
                if status.get("generation") == 1
                and status.get("status") == "ready"
                and status.get("forwards", {}).get("17", {}).get("listener") == "ready"
                and status.get("local_lines", [{}])[0].get("tcp_status") == "ok"
                else None
            )

        ready = wait_until(
            generation_one_ready,
            10,
            "pathd generation 1",
        )
        self.assertEqual(1, ready["generation"])
        provider_pid = int((marker_dir / "provider.pid").read_text(encoding="ascii"))
        self.assertTrue(process_is_alive(provider_pid))

        client = socket.create_connection(("127.0.0.1", ports["public"]), timeout=8)
        client.settimeout(20)
        self.assertEqual(REVERSE_BANNER, recv_exact(client, len(REVERSE_BANNER)))

        def active_session_status():
            status = self._status(status_path)
            return status if status.get("forwards", {}).get("17", {}).get("sessions") == 1 else None

        active_status = wait_until(active_session_status, 5, "active pathd stream count")
        self.assertLessEqual(abs(int(time.time()) - int(active_status["observed_at_unix"])), 3)
        upload = bytes(index % 251 for index in range(1024 * 1024))
        client.sendall(upload)
        wait_until(lambda: self.target.received_bytes >= len(upload), 15, "ordered target upload")

        last_snapshot: dict = {}

        def excluded_snapshot():
            snapshot = json.loads(snapshot_path.read_text(encoding="utf-8"))
            last_snapshot.clear()
            last_snapshot.update(snapshot)
            legs = {int(row["leg_route_id"]): row for row in snapshot.get("legs", [])}
            slow = legs.get(1004511)
            fast = legs.get(1004531)
            if not slow or not fast:
                return None
            slow_tcp = slow.get("tcp", {})
            if (
                slow_tcp.get("bulk_admission") == "quarantined"
                and float(slow_tcp.get("throughput_mbps_ewma", 0)) < 10.0
                and int(slow_tcp.get("qualifying_samples", 0)) >= 1
                and int(slow_tcp.get("probe_remaining_bytes", 0)) <= 16384
            ):
                return snapshot
            return None

        try:
            snapshot = wait_until(excluded_snapshot, 8, "sub-10 Mbps bulk exclusion")
        except AssertionError as exc:
            raise AssertionError(
                f"{exc}; last snapshot={json.dumps(last_snapshot, sort_keys=True)}; logs={self._log_tails()}"
            ) from exc
        self.assertEqual("quarantined", {row["leg_route_id"]: row for row in snapshot["legs"]}[1004511]["tcp"]["bulk_admission"])
        wait_until(
            lambda: self._slave_relay_connect_successes(slave_relay_state) >= 1,
            5,
            "production slave relay LBND route",
        )
        self.assertTrue((marker_dir / "connections.log").is_file())
        self.assertTrue((pathlib.Path(slave_runtime["marker_dir"]) / "connections.log").is_file())

        time.sleep(0.5)
        before_probe = json.loads(snapshot_path.read_text(encoding="utf-8"))
        before_legs = {int(row["leg_route_id"]): row for row in before_probe["legs"]}
        followup_bulk = bytes((250 - index % 251) for index in range(512 * 1024))
        client.sendall(followup_bulk)
        wait_until(
            lambda: self.target.received_bytes >= len(upload) + len(followup_bulk),
            15,
            "post-exclusion target upload",
        )

        def bounded_probe_snapshot():
            current = json.loads(snapshot_path.read_text(encoding="utf-8"))
            legs = {int(row["leg_route_id"]): row for row in current.get("legs", [])}
            if 1004511 not in legs or 1004531 not in legs:
                return None
            slow_delta = int(legs[1004511]["scheduled_bytes"]) - int(before_legs[1004511]["scheduled_bytes"])
            fast_delta = int(legs[1004531]["scheduled_bytes"]) - int(before_legs[1004531]["scheduled_bytes"])
            if fast_delta > 0 and slow_delta <= 16384 + 32768:
                return current, slow_delta, fast_delta
            return None

        _post_exclusion, slow_delta, fast_delta = wait_until(
            bounded_probe_snapshot,
            5,
            "bounded slow-lane probe budget",
        )
        self.assertLess(slow_delta, fast_delta)

        desired["generation"] = 2
        self._atomic_json(desired_path, desired)
        os.kill(pathd.pid, signal.SIGHUP)

        def generation_two_ready():
            status = self._status(status_path)
            return status if status.get("generation") == 2 and status.get("status") == "ready" else None

        wait_until(
            generation_two_ready,
            8,
            "pathd generation 2 reload",
        )
        self.assertEqual(provider_pid, int((marker_dir / "provider.pid").read_text(encoding="ascii")))
        after_reload = b"same-session-after-reload"
        client.sendall(after_reload)
        wait_until(
            lambda: self.target.received_bytes >= len(upload) + len(followup_bulk) + len(after_reload),
            8,
            "same-session post-reload upload",
        )
        self.assertEqual(1, self.target.total_connections)
        client.shutdown(socket.SHUT_WR)
        self.assertEqual(REVERSE_PAYLOAD, recv_exact(client, len(REVERSE_PAYLOAD)))
        client.close()
        wait_until(lambda: self.target.active_connections == 0, 8, "first target session close")
        wait_until(
            lambda: status
            if (status := self._status(status_path)).get("forwards", {}).get("17", {}).get("sessions") == 0
            else None,
            5,
            "closed pathd stream count",
        )

        def local_lease_revoked():
            status = self._status(status_path)
            return (
                status
                if status.get("generation") == 2
                and status.get("status") == "degraded"
                and status.get("local_lines") == []
                and status.get("forwards", {}).get("17", {}).get("listener") == "ready"
                else None
            )

        revoked = wait_until(
            local_lease_revoked,
            42,
            "local lease expiry cleanup",
        )
        self.assertEqual([], revoked["local_lines"])
        wait_until(lambda: not process_is_alive(provider_pid), 8, "provider child exit")
        self.assertFalse(port_is_open(ports["socks"]))

        fallback = socket.create_connection(("127.0.0.1", ports["public"]), timeout=8)
        fallback.settimeout(10)
        self.assertEqual(REVERSE_BANNER, recv_exact(fallback, len(REVERSE_BANNER)))
        fallback_payload = b"slave-only-after-local-revocation"
        fallback.sendall(fallback_payload)
        fallback.shutdown(socket.SHUT_WR)
        self.assertEqual(REVERSE_PAYLOAD, recv_exact(fallback, len(REVERSE_PAYLOAD)))
        fallback.close()
        wait_until(lambda: self.target.active_connections == 0, 8, "fallback target session close")
        self.assertEqual(2, self.target.total_connections)
        self.assertEqual(1, self.target.max_active_connections)
        self.assertGreaterEqual(self._slave_relay_connect_successes(slave_relay_state), 1)

        first_expected = upload + followup_bulk + after_reload
        wait_until(lambda: len(self.target.sessions) >= 2, 5, "target session summaries")
        self.assertEqual(len(first_expected), self.target.sessions[0]["bytes"])
        self.assertEqual(hashlib.sha256(first_expected).hexdigest(), self.target.sessions[0]["sha256"])
        self.assertEqual(hashlib.sha256(fallback_payload).hexdigest(), self.target.sessions[1]["sha256"])

        self._stop_processes()
        self.target.stop()
        self.target = None
        for name, port in ports.items():
            self.assertFalse(port_is_open(port), f"listener residue on {name}:{port}")
        self.assertFalse(process_is_alive(provider_pid))
        self.assertFalse(process_is_alive(int(slave_runtime["provider_pid"])))
        self.assertFalse(process_is_alive(int(slave_runtime["relay_pid"])))
        shutil.rmtree(self.root)
        self.assertFalse(self.root.exists())

    @unittest.skipUnless(SOAK_SECONDS > 0, "set LEIKWAN_HYBRID_SOAK_SECONDS to run the RSS soak")
    def test_two_local_lease_children_bounded_rss_soak(self) -> None:
        allocated_ports: set[int] = set()

        def allocate_port(start: int = 0, end: int = 0) -> int:
            for _attempt in range(32):
                port = free_port(start, end)
                if port not in allocated_ports:
                    allocated_ports.add(port)
                    return port
            raise RuntimeError("failed to allocate a unique soak port")

        ports = {
            "target": allocate_port(),
            "aggregator": allocate_port(),
            "public": allocate_port(),
            "backend": allocate_port(),
            "ingress": allocate_port(7000, 9000),
            "socks": allocate_port(20000, 29999),
        }
        ports["socks2"] = allocate_port(ports["socks"] + 1, 29999)
        ports["slave_socks"] = allocate_port(ports["socks2"] + 1, 29999)
        secret = "soak-target-secret"
        ingress_auth = "soak-ingress-auth"
        provider = self.root / "fake-provider"
        provider.write_text(textwrap.dedent(FAKE_PROVIDER), encoding="utf-8")
        provider.chmod(0o700)
        marker_dir = self.root / "provider-markers"
        status_path = self.root / "status.json"
        desired_path = self.root / "desired.json"

        self.target = EchoTarget(ports["target"])
        aggregator_env = os.environ.copy()
        aggregator_env["LEIKWAN_BOND_STATE_DIR"] = str(self.root / "aggregator-state")
        self._start_process(
            "aggregator",
            [
                str(BOND_BIN),
                "server",
                "--listen",
                f"127.0.0.1:{ports['aggregator']}",
                "--token-secret",
                secret,
                "--adaptive-line-admission",
                "--throughput-min-mbps",
                "10",
                "--throughput-relative-floor",
                "0.35",
            ],
            env=aggregator_env,
        )
        wait_until(lambda: port_is_open(ports["aggregator"]), 5, "soak aggregator listener")
        slave_runtime = self._start_slave_relay(ports, provider)

        routes_path = self.root / "routes.json"
        routes_path.write_text(
            json.dumps(
                {
                    "routes": [
                        {
                            "route_key": "fixed",
                            "backend_host": "127.0.0.1",
                            "backend_port": ports["backend"],
                            "service_name": "flat-hybrid-soak",
                            "ingress_protocol": "plain_tcp_mux",
                            "ingress_auth_token": ingress_auth,
                        }
                    ]
                }
            ),
            encoding="utf-8",
        )
        self._start_process(
            "ingress",
            [str(INGRESS_BIN), "mux", "--listen", f"127.0.0.1:{ports['ingress']}", "--routes-file", str(routes_path)],
        )
        wait_until(lambda: port_is_open(ports["ingress"]), 5, "soak ingress listener")

        desired = self._desired(
            1,
            int(time.time()) + SOAK_SECONDS + 120,
            ports,
            provider,
            secret,
            ingress_auth,
            token_ttl_seconds=SOAK_SECONDS + 300,
        )
        second_config = f"socks_port = {ports['socks2']}\n"
        desired["local_leases"].append(
            {
                "line_id": 452,
                "lease_id": "00000000-0000-4000-8000-000000000452",
                "lease_generation": 9,
                "max_lanes": 1,
                "config_sha256": hashlib.sha256(second_config.encode()).hexdigest(),
                "config_toml": second_config,
                "expires_at_unix": int(time.time()) + SOAK_SECONDS + 120,
                "socks_addr": f"127.0.0.1:{ports['socks2']}",
            }
        )
        desired["forwards"][0]["legs"].insert(
            1,
            {
                "uri": f"socks5://127.0.0.1:{ports['socks2']}?leg_route_id=1004521&line_id=452&lease_generation=9",
                "execution_group": "client:20:linux-device-a",
                "line_id": 452,
                "lease_id": "00000000-0000-4000-8000-000000000452",
                "lease_generation": 9,
                "leg_route_id": 1004521,
            },
        )
        desired["forwards"][0]["policy"]["preferred_initial_legs"] = 3
        desired["forwards"][0]["policy"]["minimum_initial_legs"] = 3
        self._atomic_json(desired_path, desired)

        pathd_env = os.environ.copy()
        pathd_env.update(
            {
                "RUST_LOG": "warn",
                "LEIKWAN_TEST_PROVIDER_MARKER_DIR": str(marker_dir),
                "LEIKWAN_TEST_PROVIDER_RATE_BPS": str(8 * 1024 * 1024),
            }
        )
        pathd = self._start_process(
            "pathd",
            [str(PATHD_BIN), "--config", str(desired_path), "--status", str(status_path)],
            env=pathd_env,
        )

        def soak_ready():
            status = self._status(status_path)
            local_lines = status.get("local_lines", [])
            return (
                status
                if status.get("generation") == 1
                and status.get("status") == "ready"
                and status.get("forwards", {}).get("17", {}).get("listener") == "ready"
                and len(local_lines) == 2
                and all(row.get("tcp_status") == "ok" for row in local_lines)
                else None
            )

        wait_until(soak_ready, 12, "two local provider children and hybrid listener")

        def current_provider_pids() -> list[int]:
            return sorted(
                child
                for child in direct_child_pids(pathd.pid)
                if str(provider) in process_cmdline(child)
            )

        provider_pids = wait_until(
            lambda: pids if len(pids := current_provider_pids()) == 2 else None,
            10,
            "two provider child processes",
        )

        control_payload = self._control_plane_pull_fixture()
        control_expiry = time.strftime(
            "%Y-%m-%dT%H:%M:%SZ",
            time.gmtime(time.time() + SOAK_SECONDS + 120),
        )
        for lease in control_payload.get("linux_path_leases", {}).get("lines", []):
            lease["expires_at"] = control_expiry
            lease["ready_deadline_at"] = control_expiry
        control_payload["config_revision"] = 1
        control_payload["client"] = {
            "desired_pathd_version": "soak",
            "desired_pathd_sha256": hashlib.sha256(PATHD_BIN.read_bytes()).hexdigest(),
        }
        control_payload_path = self.root / "client-agent-pull.json"
        control_payload_path.write_text(json.dumps(control_payload), encoding="utf-8")

        client_config = self.root / "client-agent.json"
        client_ready = self.root / "client-agent.ready"
        client_config.write_text(
            json.dumps(
                {
                    "master_url": "http://127.0.0.1",
                    "client_id": 20,
                    "agent_token": "soak-only",
                    "device_id": "linux-device-a",
                    "state_dir": str(self.root / "client-state"),
                    "pathd_runtime_dir": str(self.root),
                    "pathd_bin": str(PATHD_BIN),
                    "leigod_bin": str(provider),
                    "poll_interval_seconds": 5,
                    "health_check_interval_seconds": 5,
                }
            ),
            encoding="utf-8",
        )
        client_agent = self._start_process(
            "client-agent",
            [
                sys.executable,
                "-c",
                textwrap.dedent(CLIENT_AGENT_RSS_WORKER),
                str(CLIENT_AGENT_SOURCE),
                str(client_config),
                str(client_ready),
                str(control_payload_path),
            ],
        )

        def full_client_agent_ready():
            if not client_ready.is_file() or client_agent.poll() is not None:
                return None
            metrics = json.loads(client_ready.read_text(encoding="ascii"))
            return metrics if metrics.get("desired_builds", 0) >= 1 and metrics.get("heartbeats", 0) >= 1 else None

        wait_until(full_client_agent_ready, 10, "full client-agent watch/pull/reconcile worker")

        provider_roles = {f"provider_{index + 1}": pid for index, pid in enumerate(provider_pids)}
        slave_provider_pid = int(slave_runtime["provider_pid"])
        slave_relay_pid = int(slave_runtime["relay_pid"])

        def capture_rss() -> dict[str, int]:
            self.assertIsNone(pathd.poll(), "pathd exited during soak")
            self.assertIsNone(client_agent.poll(), "client-agent exited during soak")
            self.assertEqual(provider_pids, current_provider_pids(), "provider child set changed during soak")
            sample = {
                "client_agent": process_rss_kib(client_agent.pid),
                "pathd": process_rss_kib(pathd.pid),
            }
            for role, pid in provider_roles.items():
                sample[role] = process_rss_kib(pid)
            sample["slave_provider"] = process_rss_kib(slave_provider_pid)
            sample["slave_relay"] = process_rss_kib(slave_relay_pid)
            sample["providers_total"] = sample["slave_provider"] + sum(sample[role] for role in provider_roles)
            return sample

        traffic_stop = threading.Event()
        traffic_errors: list[str] = []
        traffic_stats = {"sessions": 0, "upload_bytes": 0, "reverse_bytes": 0}
        soak_started = time.monotonic()
        soak_deadline = soak_started + SOAK_SECONDS

        def run_mixed_traffic() -> None:
            small = bytes(index % 251 for index in range(4096))
            bulk = bytes((250 - index % 251) for index in range(256 * 1024))
            try:
                while time.monotonic() < soak_deadline and not traffic_stop.is_set():
                    stream = socket.create_connection(("127.0.0.1", ports["public"]), timeout=8)
                    stream.settimeout(20)
                    self.assertEqual(REVERSE_BANNER, recv_exact(stream, len(REVERSE_BANNER)))
                    segment_deadline = min(soak_deadline, time.monotonic() + min(20.0, max(2.0, SOAK_SECONDS / 2)))
                    chunk_index = 0
                    while time.monotonic() < segment_deadline and not traffic_stop.is_set():
                        payload = bulk if chunk_index % 5 == 4 else small
                        stream.sendall(payload)
                        traffic_stats["upload_bytes"] += len(payload)
                        chunk_index += 1
                        traffic_stop.wait(0.5)
                    stream.shutdown(socket.SHUT_WR)
                    self.assertEqual(REVERSE_PAYLOAD, recv_exact(stream, len(REVERSE_PAYLOAD)))
                    traffic_stats["reverse_bytes"] += len(REVERSE_PAYLOAD)
                    traffic_stats["sessions"] += 1
                    stream.close()
            except Exception as exc:  # noqa: BLE001
                traffic_errors.append(repr(exc))

        samples = [capture_rss()]
        traffic_thread = threading.Thread(target=run_mixed_traffic, name="hybrid-soak-traffic", daemon=True)
        traffic_thread.start()
        next_sample_at = soak_started + 5.0
        try:
            while time.monotonic() < soak_deadline:
                if traffic_errors:
                    self.fail(f"mixed soak traffic failed: {traffic_errors[0]}")
                now = time.monotonic()
                if now >= next_sample_at:
                    samples.append(capture_rss())
                    next_sample_at += 5.0
                time.sleep(min(0.5, max(0.01, soak_deadline - now)))
        finally:
            traffic_stop.set()
            traffic_thread.join(timeout=30)
        self.assertFalse(traffic_thread.is_alive(), "mixed traffic worker did not stop")
        self.assertEqual([], traffic_errors)
        samples.append(capture_rss())

        client_metrics = json.loads(client_ready.read_text(encoding="ascii"))
        self.assertGreaterEqual(int(client_metrics.get("pulls", 0)), 1)
        self.assertGreaterEqual(int(client_metrics.get("watches", 0)), 1)
        self.assertGreaterEqual(int(client_metrics.get("heartbeats", 0)), 1)
        self.assertGreaterEqual(int(client_metrics.get("desired_builds", 0)), 1)

        roles = ["client_agent", "pathd", "providers_total", "slave_provider", "slave_relay", *provider_roles]
        rss_summary = {}
        for role in roles:
            series = [sample[role] for sample in samples]
            tail = series[len(series) // 2 :]
            strictly_monotonic = len(tail) >= 3 and all(right > left for left, right in zip(tail, tail[1:]))
            rss_summary[role] = {
                "initial": series[0],
                "peak": max(series),
                "final": series[-1],
                "increment": max(series) - series[0],
                "strict_tail_growth": strictly_monotonic,
            }

        summary = {
            "duration_seconds": round(time.monotonic() - soak_started, 1),
            "sample_count": len(samples),
            "traffic": traffic_stats,
            "client_agent_metrics": client_metrics,
            "provider_pids": provider_pids,
            "rss_kib": rss_summary,
            "increment_limit_kib": 128 * 1024,
        }
        print("LEIKWAN_HYBRID_SOAK_SUMMARY=" + json.dumps(summary, sort_keys=True))
        self.assertGreaterEqual(traffic_stats["sessions"], 1)
        self.assertGreater(traffic_stats["upload_bytes"], 0)
        self.assertGreater(traffic_stats["reverse_bytes"], 0)
        for role, row in rss_summary.items():
            self.assertLessEqual(row["increment"], 128 * 1024, f"{role} RSS grew beyond 128 MiB")
            self.assertFalse(row["strict_tail_growth"], f"{role} RSS grew monotonically in the tail")

        self._stop_processes()
        self.target.stop()
        self.target = None
        for name, port in ports.items():
            self.assertFalse(port_is_open(port), f"soak listener residue on {name}:{port}")
        for pid in provider_pids:
            self.assertFalse(process_is_alive(pid), f"provider process residue pid={pid}")
        self.assertFalse(process_is_alive(slave_provider_pid))
        self.assertFalse(process_is_alive(slave_relay_pid))
        shutil.rmtree(self.root)
        self.assertFalse(self.root.exists())


if __name__ == "__main__":
    unittest.main()
