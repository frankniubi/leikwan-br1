# Forward 26 UDP UoT Sidepath Hotfix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a `forward 26`-only UDP hotfix that uses one striped UoT TCP leg per slave, merges packets on the sidecar, and leaves the current TCP bond runtime untouched.

**Architecture:** Keep the current `client -> slave -> sidecar` TCP bond path exactly as-is. Add a second, UDP-only runtime on the client that opens one plain-TCP ingress leg per published slave, sends framed UDP datagrams over those legs, and relies on a new sidecar merge runtime plus a shadow slave route keyed as `<route_key>.uot`. Gate everything through local agent config so no panel schema or UI changes are required.

**Tech Stack:** Python 3 agents and runtimes, existing `plain_tcp_mux` ingress, systemd-managed Linux services, JSON config payloads, `unittest`, `ss`, `journalctl`

---

## File Map

- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Create: `client-agent/leikwan_uot_bond.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Create: `client-agent/tests/test_uot_bond_runtime.py`
- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_slave_udp_uot_shadow_routes.py`
- Modify: `client-agent/tests/test_sidecar_dual_runtime_regression.py`
- Create: `sidecar-agent/leikwan_udp_uot_merge.py`
- Create: `sidecar-agent/tests/test_udp_uot_merge_runtime.py`
- Modify: `docs/BOND_OPERATIONS.md`
- Create: `config-examples/forward-26-udp-uot-sidepath.json`

## Runtime Constants

Use these exact initial values throughout the implementation:

- `enabled_forward_ids = [26]`
- `shadow_route_suffix = ".uot"`
- `sidecar_port = 8999`
- `reorder_window_packets = 4096`
- `reorder_window_bytes = 16777216`
- `reorder_timeout_ms = 25`
- `leg_reconnect_backoff_ms = [300, 1000, 3000]`

## Local Config Shape

All three agents use the same local-only config block inside their existing JSON config:

```json
{
  "udp_uot_sidepath": {
    "enabled_forward_ids": [26],
    "shadow_route_suffix": ".uot",
    "sidecar_port": 8999,
    "reorder_window_packets": 4096,
    "reorder_window_bytes": 16777216,
    "reorder_timeout_ms": 25
  }
}
```

The feature is off unless `forward_id` is listed in `enabled_forward_ids`.

### Task 1: Split Client Bond Rendering Into TCP Primary + UDP Auxiliary Unit

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Add the failing renderer and multi-unit lifecycle tests**

Add these tests to `client-agent/tests/test_mptcp_bond_regressions.py`:

```python
    def test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "udp_uot_sidepath": {
                    "enabled_forward_ids": [26],
                    "shadow_route_suffix": ".uot",
                    "sidecar_port": 8999,
                    "reorder_window_packets": 4096,
                    "reorder_window_bytes": 16777216,
                    "reorder_timeout_ms": 25,
                },
            },
        )

        self.assertIn("--protocol tcp --listen 0.0.0.0:11024", rendered["start_script"])
        self.assertNotIn("--protocol udp --listen 0.0.0.0:11024", rendered["start_script"])
        self.assertEqual(len(rendered["extra_units"]), 1)
        udp_unit = rendered["extra_units"][0]
        self.assertEqual(udp_unit["service_name"], "leikwan-client-26-udp-uot")
        self.assertIn("/usr/bin/python3 /usr/local/bin/leikwan-uot-bond.py --config /etc/leikwan/uot-bond-forward-26.json", udp_unit["service_unit"])
        config_file = next(item for item in udp_unit["files"] if item["path"] == "/etc/leikwan/uot-bond-forward-26.json")
        self.assertIn('"route_key": "a43547b6350f72cd.uot"', config_file["content"])
        self.assertIn('"session_token": "udp-token"', config_file["content"])

    def test_apply_forward_tracks_aux_units_and_stop_forward_removes_them(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            forward = {
                "forward_id": 26,
                "listen_port": 11024,
                "transport": "tcp+udp",
                "mode": "bond",
                "service_status": "active",
                "endpoints": [{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "a43547b6350f72cd"},
            }

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                return {
                    "service_name": "leikwan-client-26",
                    "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26.service"),
                    "service_unit": "[Unit]\nDescription=TCP\n",
                    "start_script_path": str(pathlib.Path(tmp_dir) / "leikwan-client-start-26.sh"),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                    "extra_units": [
                        {
                            "service_name": "leikwan-client-26-udp-uot",
                            "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service"),
                            "service_unit": "[Unit]\nDescription=UDP\n",
                            "start_script_path": "",
                            "start_script": "",
                            "files": [
                                {
                                    "path": str(pathlib.Path(tmp_dir) / "uot-bond-forward-26.json"),
                                    "content": "{}\n",
                                    "mode": 0o600,
                                }
                            ],
                        }
                    ],
                }

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(agent, "load_applied", return_value={}):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with mock.patch.object(agent, "delete_nft_rules"):
                            with mock.patch.object(agent, "add_nft_rules"):
                                with mock.patch.object(client_agent_module.subprocess, "run"):
                                    agent.apply_forward(forward)

            applied = json.loads((state_dir / "applied.json").read_text(encoding="utf-8"))
            self.assertEqual(
                [item["service_name"] for item in applied["26"]["units"]],
                ["leikwan-client-26", "leikwan-client-26-udp-uot"],
            )

            with mock.patch.object(client_agent_module.subprocess, "run"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    agent.stop_forward(26, delete_files=True)

            self.assertFalse((pathlib.Path(tmp_dir) / "leikwan-client-26.service").exists())
            self.assertFalse((pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service").exists())
            self.assertFalse((pathlib.Path(tmp_dir) / "uot-bond-forward-26.json").exists())
```

- [ ] **Step 2: Run the tests to verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_apply_forward_tracks_aux_units_and_stop_forward_removes_them
```

Expected:

- first test fails with `KeyError: 'extra_units'` or still finds `--protocol udp` in the main start script
- second test fails because `apply_forward()` only stores one service and `stop_forward()` only removes one unit path

- [ ] **Step 3: Implement the local hotfix gate and auxiliary unit renderer**

In `client-agent/install_forward_runtime.py`, add these helpers near the other bond helpers:

```python
def udp_uot_sidepath_settings(forward: dict, agent_config: dict) -> dict | None:
    raw = agent_config.get("udp_uot_sidepath", {})
    if not isinstance(raw, dict):
        return None
    enabled_ids = {
        int(value)
        for value in raw.get("enabled_forward_ids", [])
        if str(value).strip().isdigit()
    }
    forward_id = int(forward.get("forward_id", 0) or 0)
    if forward_id not in enabled_ids:
        return None
    return {
        "shadow_route_suffix": str(raw.get("shadow_route_suffix", ".uot") or ".uot"),
        "sidecar_port": int(raw.get("sidecar_port", 8999) or 8999),
        "reorder_window_packets": int(raw.get("reorder_window_packets", 4096) or 4096),
        "reorder_window_bytes": int(raw.get("reorder_window_bytes", 16777216) or 16777216),
        "reorder_timeout_ms": int(raw.get("reorder_timeout_ms", 25) or 25),
    }


def render_udp_uot_aux_unit(
    forward: dict,
    bond: dict,
    endpoints: list[dict],
    agent_config: dict,
    settings: dict,
) -> dict:
    forward_id = int(forward["forward_id"])
    route_key = str(bond.get("route_key", forward.get("path_prefix", ""))).strip()
    shadow_route_key = route_key + str(settings["shadow_route_suffix"])
    config_path = f"/etc/leikwan/uot-bond-forward-{forward_id}.json"
    service_name = f"leikwan-client-{forward_id}-udp-uot"
    service_path = f"/etc/systemd/system/{service_name}.service"
    runtime_bin = str(agent_config.get("uot_bond_bin", "/usr/local/bin/leikwan-uot-bond.py") or "/usr/local/bin/leikwan-uot-bond.py")
    config_payload = {
        "forward_id": forward_id,
        "listen_udp": f"0.0.0.0:{int(forward['listen_port'])}",
        "route_key": shadow_route_key,
        "session_token": str(bond.get("udp_session_token", "") or ""),
        "reorder_window_packets": int(settings["reorder_window_packets"]),
        "reorder_window_bytes": int(settings["reorder_window_bytes"]),
        "reorder_timeout_ms": int(settings["reorder_timeout_ms"]),
        "legs": [
            {
                "endpoint_id": int(endpoint.get("endpoint_id", 0) or 0),
                "host": str(endpoint["host"]),
                "port": int(endpoint["port"]),
                "auth_token": str(endpoint.get("ingress_auth_token", "") or bond.get("ingress_auth_token", "")),
            }
            for endpoint in endpoints
        ],
    }
    service_unit = f"""[Unit]
Description=Leikwan Client {forward_id} UDP UoT
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 {runtime_bin} --config {config_path}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
    return {
        "service_name": service_name,
        "service_path": service_path,
        "service_unit": service_unit,
        "start_script_path": "",
        "start_script": "",
        "files": [
            {
                "path": config_path,
                "content": json.dumps(config_payload, ensure_ascii=False, indent=2) + "\n",
                "mode": 0o600,
            }
        ],
    }
```

Then in the `mode == "bond"` branch, change the bond rendering gate to:

```python
        sidepath = udp_uot_sidepath_settings(forward, agent_config)
        primary_transport = transport
        extra_units: list[dict] = []
        if sidepath is not None and transport in ("udp", "tcp+udp"):
            primary_transport = "tcp" if transport == "tcp+udp" else "tcp"
            extra_units.append(render_udp_uot_aux_unit(forward, bond, endpoints, agent_config, sidepath))
```

Use `primary_transport` everywhere the current bond branch decides whether to render TCP and/or UDP children, and add:

```python
            "extra_units": extra_units,
```

to the return value.

In `client-agent/client_agent.py`, replace the single-unit persistence with a list:

```python
        units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
        persisted_units: list[dict] = []

        for unit in units:
            service_name = str(unit["service_name"])
            service_path = pathlib.Path(str(unit["service_path"]))
            unit_changed = self.write_text_if_changed(service_path, str(unit["service_unit"]))
            start_script_path = pathlib.Path(str(unit["start_script_path"])) if str(unit.get("start_script_path", "")).strip() != "" else None
            script_changed = False
            if start_script_path is not None and str(unit.get("start_script", "")) != "":
                script_changed = self.write_text_if_changed(start_script_path, str(unit["start_script"]), mode=0o700)

            managed_paths = [str(service_path)]
            if start_script_path is not None:
                managed_paths.append(str(start_script_path))

            for file_entry in unit.get("files", []):
                extra_path = pathlib.Path(str(file_entry["path"]))
                self.write_text_if_changed(
                    extra_path,
                    str(file_entry.get("content", "")),
                    int(file_entry["mode"]) if "mode" in file_entry and file_entry.get("mode") is not None else None,
                )
                managed_paths.append(str(extra_path))

            if unit_changed or script_changed or not self.systemd_is_active(service_name):
                subprocess.run("systemctl daemon-reload", shell=True, check=False)
                subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
                subprocess.run(f"systemctl restart {service_name}.service", shell=True, check=False)

            persisted_units.append(
                {
                    "service_name": service_name,
                    "managed_paths": managed_paths,
                }
            )

        applied[str(forward_id)] = {
            "units": persisted_units,
            "managed_paths": sorted({path for unit in persisted_units for path in unit["managed_paths"]}),
            "updated_at": int(time.time()),
        }
```

Update `stop_forward()` to stop and remove every tracked unit before removing extra managed paths:

```python
        previous = applied.get(str(forward_id), {})
        units = previous.get("units", []) if isinstance(previous, dict) else []
        if not units:
            units = [{"service_name": f"leikwan-client-{forward_id}", "managed_paths": []}]

        for unit in units:
            service_name = str(unit.get("service_name", "") or "")
            if service_name == "":
                continue
            subprocess.run(f"systemctl stop {service_name}.service", shell=True, check=False)
            subprocess.run(f"systemctl disable {service_name}.service", shell=True, check=False)
            service_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
            if delete_files and service_path.exists():
                service_path.unlink()
```

- [ ] **Step 4: Run the tests to verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_apply_forward_tracks_aux_units_and_stop_forward_removes_them
python -m py_compile client-agent/install_forward_runtime.py client-agent/client_agent.py
```

Expected:

- both unit tests pass
- `py_compile` exits cleanly

- [ ] **Step 5: Commit**

```bash
git add client-agent/install_forward_runtime.py client-agent/client_agent.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: split forward 26 udp into auxiliary uot unit"
```

### Task 2: Implement The Client Striped UDP Runtime

**Files:**
- Create: `client-agent/leikwan_uot_bond.py`
- Create: `client-agent/tests/test_uot_bond_runtime.py`

- [ ] **Step 1: Add the failing runtime tests**

Create `client-agent/tests/test_uot_bond_runtime.py`:

```python
import asyncio
import importlib.util
import pathlib
import struct
import sys
import tempfile
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


uot_bond = load_module("leikwan_uot_bond_under_test", "client-agent/leikwan_uot_bond.py")


class UotBondRuntimeTests(unittest.TestCase):
    def test_encode_and_decode_data_frame_round_trip(self) -> None:
        frame = uot_bond.DataFrame(
            version=1,
            msg_type=uot_bond.MSG_DATA,
            flags=0,
            session_id=7,
            seq=11,
            payload=b"hello",
        )
        encoded = uot_bond.encode_frame(frame)
        decoded = uot_bond.decode_frame(encoded)
        self.assertEqual(decoded.session_id, 7)
        self.assertEqual(decoded.seq, 11)
        self.assertEqual(decoded.payload, b"hello")

    def test_merge_buffer_releases_in_order_and_skips_gap_after_timeout(self) -> None:
        buffer = uot_bond.MergeBuffer(packet_limit=16, byte_limit=4096, timeout_ms=1)
        self.assertEqual(buffer.push(4, 2, b"two"), [])
        ready = buffer.push(4, 1, b"one")
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in ready], [(4, 1, b"one"), (4, 2, b"two")])
        self.assertEqual(buffer.push(4, 4, b"four"), [])
        buffer.session_state[4].gap_started_at -= 1
        skipped = buffer.flush_timeouts(now_ms=buffer.session_state[4].gap_started_at + 10)
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in skipped], [(4, 4, b"four")])

    def test_weighted_round_robin_cycles_live_legs(self) -> None:
        selector = uot_bond.WeightedLegSelector(
            [
                uot_bond.LegState(endpoint_id=184, weight=1),
                uot_bond.LegState(endpoint_id=185, weight=1),
                uot_bond.LegState(endpoint_id=186, weight=1),
            ]
        )
        picks = [selector.pick_leg().endpoint_id for _ in range(6)]
        self.assertEqual(picks, [184, 185, 186, 184, 185, 186])

    def test_plain_tcp_mux_open_line_is_written_before_binary_frames(self) -> None:
        async def scenario() -> None:
            seen = {}

            async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
                seen["line"] = (await reader.readline()).decode("utf-8").strip()
                header = await reader.readexactly(4)
                frame_len = struct.unpack(">I", header)[0]
                seen["first_frame"] = await reader.readexactly(frame_len)
                writer.close()
                await writer.wait_closed()

            server = await asyncio.start_server(handler, "127.0.0.1", 0)
            port = server.sockets[0].getsockname()[1]
            try:
                reader, writer = await uot_bond.open_plain_tcp_leg("127.0.0.1", port, "rk.uot", "token-123")
                await uot_bond.write_leg_frame(
                    writer,
                    uot_bond.DataFrame(
                        version=1,
                        msg_type=uot_bond.MSG_AUTH,
                        flags=0,
                        session_id=0,
                        seq=0,
                        payload=b"udp-token",
                    ),
                )
                writer.close()
                await writer.wait_closed()
            finally:
                server.close()
                await server.wait_closed()

            self.assertEqual(seen["line"], "LEIKWAN-INGRESS 1 OPEN rk.uot token-123")
            decoded = uot_bond.decode_frame(seen["first_frame"])
            self.assertEqual(decoded.msg_type, uot_bond.MSG_AUTH)
            self.assertEqual(decoded.payload, b"udp-token")

        asyncio.run(scenario())
```

- [ ] **Step 2: Run the tests to verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime -v
```

Expected:

- import fails because `client-agent/leikwan_uot_bond.py` does not exist yet

- [ ] **Step 3: Implement the runtime module**

Create `client-agent/leikwan_uot_bond.py` with this structure:

```python
#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import dataclasses
import json
import socket
import struct
import time
from collections import deque
from typing import Any


FRAME_PREFIX = struct.Struct(">I")
FRAME_HEADER = struct.Struct(">BBHIQI")
MSG_AUTH = 0
MSG_OPEN = 1
MSG_DATA = 2
MSG_CLOSE = 3
MSG_KEEPALIVE = 4


@dataclasses.dataclass
class DataFrame:
    version: int
    msg_type: int
    flags: int
    session_id: int
    seq: int
    payload: bytes


@dataclasses.dataclass
class MergeItem:
    session_id: int
    seq: int
    payload: bytes


@dataclasses.dataclass
class SessionMergeState:
    next_expected_seq: int = 1
    buffered: dict[int, bytes] = dataclasses.field(default_factory=dict)
    gap_started_at: int | None = None
    buffered_bytes: int = 0


@dataclasses.dataclass
class LegState:
    endpoint_id: int
    weight: int = 1
    active: bool = True


def encode_frame(frame: DataFrame) -> bytes:
    payload = bytes(frame.payload)
    header = FRAME_HEADER.pack(frame.version, frame.msg_type, frame.flags, frame.session_id, frame.seq, len(payload))
    return header + payload


def decode_frame(payload: bytes) -> DataFrame:
    version, msg_type, flags, session_id, seq, payload_len = FRAME_HEADER.unpack(payload[: FRAME_HEADER.size])
    body = payload[FRAME_HEADER.size : FRAME_HEADER.size + payload_len]
    return DataFrame(version=version, msg_type=msg_type, flags=flags, session_id=session_id, seq=seq, payload=body)


class WeightedLegSelector:
    def __init__(self, legs: list[LegState]) -> None:
        self.sequence = deque([leg for leg in legs for _ in range(max(1, leg.weight))])

    def pick_leg(self) -> LegState:
        leg = self.sequence[0]
        self.sequence.rotate(-1)
        return leg


class MergeBuffer:
    def __init__(self, packet_limit: int, byte_limit: int, timeout_ms: int) -> None:
        self.packet_limit = packet_limit
        self.byte_limit = byte_limit
        self.timeout_ms = timeout_ms
        self.session_state: dict[int, SessionMergeState] = {}

    def push(self, session_id: int, seq: int, payload: bytes) -> list[MergeItem]:
        state = self.session_state.setdefault(session_id, SessionMergeState())
        if seq < state.next_expected_seq:
            return []
        if seq == state.next_expected_seq:
            ready = [MergeItem(session_id=session_id, seq=seq, payload=payload)]
            state.next_expected_seq += 1
            while state.next_expected_seq in state.buffered:
                body = state.buffered.pop(state.next_expected_seq)
                state.buffered_bytes -= len(body)
                ready.append(MergeItem(session_id=session_id, seq=state.next_expected_seq, payload=body))
                state.next_expected_seq += 1
            state.gap_started_at = None
            return ready
        if seq not in state.buffered:
            state.buffered[seq] = payload
            state.buffered_bytes += len(payload)
            if state.gap_started_at is None:
                state.gap_started_at = int(time.time() * 1000)
        return []

    def flush_timeouts(self, now_ms: int | None = None) -> list[MergeItem]:
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        released: list[MergeItem] = []
        for session_id, state in self.session_state.items():
            if state.gap_started_at is None:
                continue
            if now_ms - state.gap_started_at < self.timeout_ms:
                continue
            if not state.buffered:
                state.gap_started_at = None
                continue
            state.next_expected_seq = min(state.buffered)
            while state.next_expected_seq in state.buffered:
                body = state.buffered.pop(state.next_expected_seq)
                state.buffered_bytes -= len(body)
                released.append(MergeItem(session_id=session_id, seq=state.next_expected_seq, payload=body))
                state.next_expected_seq += 1
            state.gap_started_at = None
        return released


async def open_plain_tcp_leg(host: str, port: int, route_key: str, auth_token: str) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
    reader, writer = await asyncio.open_connection(host, port)
    writer.write(f"LEIKWAN-INGRESS 1 OPEN {route_key} {auth_token}\n".encode("utf-8"))
    await writer.drain()
    return reader, writer


async def write_leg_frame(writer: asyncio.StreamWriter, frame: DataFrame) -> None:
    encoded = encode_frame(frame)
    writer.write(FRAME_PREFIX.pack(len(encoded)))
    writer.write(encoded)
    await writer.drain()
```

Also include:

- JSON config loading from `--config`
- one `AUTH` frame written once per leg at startup using `session_token`
- local UDP listener on the configured `listen_udp`
- per-session `seq` allocation
- weighted round robin over active legs
- per-leg reconnect loop with capped backoff
- bounded merge on inbound frames
- sampled `udp-uot leg up`, `udp-uot leg down`, and `udp-uot reorder skip` logs
- a JSON stats file with `live_legs`, `sessions_active`, `datagrams_in`, `datagrams_out`, `datagrams_dropped_gap`, and `reorder_buffer_bytes`

- [ ] **Step 4: Run the tests to verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime -v
python -m py_compile client-agent/leikwan_uot_bond.py
```

Expected:

- all runtime tests pass
- `py_compile` exits cleanly

- [ ] **Step 5: Commit**

```bash
git add client-agent/leikwan_uot_bond.py client-agent/tests/test_uot_bond_runtime.py
git commit -m "feat: add client striped udp uot runtime"
```

### Task 3: Add Slave Shadow `.uot` Routes And Backends

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_slave_udp_uot_shadow_routes.py`

- [ ] **Step 1: Add the failing shadow-route tests**

Create `slave-agent/tests/test_slave_udp_uot_shadow_routes.py`:

```python
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


slave_agent_module = load_module("slave_agent_udp_uot_under_test", "slave-agent/slave_agent.py")


class SlaveUdpUotShadowRouteTests(unittest.TestCase):
    def make_agent(self) -> slave_agent_module.SlaveAgent:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        config_path = pathlib.Path(tmp_dir.name) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir.name) / "state"),
                    "systemd_dir": str(pathlib.Path(tmp_dir.name) / "systemd"),
                    "ingress_bin": "/usr/local/bin/leikwan-ingress",
                    "udp_uot_sidepath": {
                        "enabled_forward_ids": [26],
                        "shadow_route_suffix": ".uot",
                        "sidecar_port": 8999,
                    },
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_publish_task_creates_shadow_uot_service_for_enabled_forward(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        with mock.patch.object(agent, "ensure_nft_table"):
            with mock.patch.object(agent, "delete_nft_rules"):
                with mock.patch.object(agent, "add_nft_rules"):
                    with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                        agent.publish_task(task, payload)

        routes = agent.listener_routes(7001)
        route_keys = [item["route_key"] for item in routes]
        self.assertIn("a43547b6350f72cd", route_keys)
        self.assertIn("a43547b6350f72cd.uot", route_keys)
        uot_route = next(item for item in routes if item["route_key"] == "a43547b6350f72cd.uot")
        self.assertEqual(uot_route["backend_protocol"], "tcp_relay")
        self.assertEqual(uot_route["backend_port"] > 0, True)

    def test_shadow_uot_backend_targets_sidecar_8999(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        with mock.patch.object(agent, "ensure_nft_table"):
            with mock.patch.object(agent, "delete_nft_rules"):
                with mock.patch.object(agent, "add_nft_rules"):
                    with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                        agent.publish_task(task, payload)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        metadata = agent.load_service_metadata(shadow_name)
        self.assertEqual(metadata["target"], "138.252.162.120:8999")
        self.assertEqual(metadata["route_key"], "a43547b6350f72cd.uot")
        self.assertEqual(metadata["ingress_auth_token"], "token-123")
```

- [ ] **Step 2: Run the tests to verify RED**

Run:

```bash
python -m unittest slave-agent/tests/test_slave_udp_uot_shadow_routes.py -v
```

Expected:

- tests fail because no shadow service metadata is created and `listener_routes()` only exposes the primary route

- [ ] **Step 3: Implement the shadow-route helpers**

In `slave-agent/slave_agent.py`, add these helpers near the existing routing helpers:

```python
def udp_uot_sidepath_settings(self) -> dict[str, Any]:
    raw = self.config.get("udp_uot_sidepath", {})
    return raw if isinstance(raw, dict) else {}


def udp_uot_sidepath_enabled(self, task: dict[str, Any], payload: dict[str, Any]) -> bool:
    settings = self.udp_uot_sidepath_settings()
    enabled = {
        int(value)
        for value in settings.get("enabled_forward_ids", [])
        if str(value).strip().isdigit()
    }
    forward_id = int(task.get("forward_id", 0) or 0)
    transport_mode = str(payload.get("transport_mode", "tcp+udp") or "tcp+udp").strip().lower()
    ingress_protocol = str(payload.get("ingress_protocol", "") or "").strip().lower()
    return forward_id in enabled and "udp" in transport_mode and ingress_protocol == "plain_tcp_mux"


def udp_uot_shadow_service_name(service_name: str) -> str:
    return service_name + "-udp-uot"


def replace_target_port(target: str, new_port: int) -> str:
    host, _, port_text = str(target).rpartition(":")
    if host.strip() == "" or port_text.strip() == "":
        raise RuntimeError(f"invalid target host:port: {target}")
    return f"{host}:{new_port}"
```

In `publish_task()`, after writing the primary metadata and before `sync_listener(public_port)`, add:

```python
        if self.udp_uot_sidepath_enabled(task, payload):
            settings = self.udp_uot_sidepath_settings()
            shadow_service = udp_uot_shadow_service_name(service_name)
            shadow_backend_port = self.allocate_backend_port(shadow_service)
            shadow_target = replace_target_port(target, int(settings.get("sidecar_port", 8999) or 8999))
            shadow_suffix = str(settings.get("shadow_route_suffix", ".uot") or ".uot")
            shadow_route_key = route_key + shadow_suffix
            shadow_metadata = dict(metadata)
            shadow_metadata.update(
                {
                    "service_name": shadow_service,
                    "unit_path": str(self.service_unit_path(shadow_service)),
                    "backend_port": shadow_backend_port,
                    "target": shadow_target,
                    "path_prefix": shadow_route_key,
                    "route_key": shadow_route_key,
                    "transport_mode": "tcp",
                    "relay_protocols": [],
                    "relay_target": "",
                    "relay_service_name": "",
                    "relay_client_service_name": "",
                    "relay_client_port": 0,
                    "ingress_protocol": "plain_tcp_mux",
                    "backend_protocol": "tcp_relay",
                    "ingress_auth_token": ingress_auth_token,
                }
            )
            shadow_unit = self.render_plain_tcp_backend_unit(shadow_service, shadow_backend_port, shadow_target)
            shadow_unit_changed = self.write_text_if_changed(self.service_unit_path(shadow_service), shadow_unit)
            self.ensure_backend_service(shadow_service, shadow_unit_changed)
            self.save_service_metadata(shadow_service, shadow_metadata)
```

Also change `listener_routes()` so it preserves `backend_protocol`:

```python
                "backend_protocol": str(metadata.get("backend_protocol", "wss_backend") or "wss_backend"),
```

- [ ] **Step 4: Run the tests to verify GREEN**

Run:

```bash
python -m unittest slave-agent/tests/test_slave_udp_uot_shadow_routes.py -v
python -m py_compile slave-agent/slave_agent.py
```

Expected:

- both shadow-route tests pass
- `py_compile` exits cleanly

- [ ] **Step 5: Commit**

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_udp_uot_shadow_routes.py
git commit -m "feat: add slave shadow routes for udp uot sidepath"
```

### Task 4: Add The Sidecar UDP Merge Runtime And Sidecar Agent Unit

**Files:**
- Create: `sidecar-agent/leikwan_udp_uot_merge.py`
- Create: `sidecar-agent/tests/test_udp_uot_merge_runtime.py`
- Modify: `client-agent/tests/test_sidecar_dual_runtime_regression.py`
- Modify: `sidecar-agent/sidecar_agent.py`

- [ ] **Step 1: Add the failing sidecar agent and runtime tests**

Append this test to `client-agent/tests/test_sidecar_dual_runtime_regression.py`:

```python
    def test_sidecar_agent_creates_udp_uot_runtime_when_enabled(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "point-4-state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "bond-runtime.json"),
                        "udp_uot_sidepath": {
                            "enabled_forward_ids": [26],
                            "sidecar_port": 8999,
                            "reorder_window_packets": 4096,
                            "reorder_window_bytes": 16777216,
                            "reorder_timeout_ms": 25,
                        },
                    }
                ),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)
            management = {
                "bond_runtime": {
                    "bind_host": "0.0.0.0",
                    "listen_port": 9000,
                    "token_secret": "secret-123",
                }
            }

            with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                agent.apply_management_config(management, {})

            runtime_path = state_dir / "udp-uot-runtime.json"
            unit_path = pathlib.Path("/etc/systemd/system/leikwan-bond-sidecar-4-udp-uot.service")
            self.assertTrue(runtime_path.exists())
            payload = json.loads(runtime_path.read_text(encoding="utf-8"))
            self.assertEqual(payload["listen_port"], 8999)
            self.assertEqual(payload["token_secret"], "secret-123")
            self.assertEqual(payload["allowed_forward_ids"], [26])
```

Create `sidecar-agent/tests/test_udp_uot_merge_runtime.py`:

```python
import importlib.util
import pathlib
import sys
import tempfile
import time
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


merge_runtime = load_module("udp_uot_merge_under_test", "sidecar-agent/leikwan_udp_uot_merge.py")


class UdpUotMergeRuntimeTests(unittest.TestCase):
    def test_verify_session_token_recovers_forward_target(self) -> None:
        token = merge_runtime.build_test_token(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
                "expires_at": int(time.time()) + 60,
            },
            "secret-123",
        )
        claims = merge_runtime.verify_session_token(token, "secret-123", int(time.time()))
        self.assertEqual(claims["target_host"], "193.227.109.82")
        self.assertEqual(claims["target_port"], 5201)
        self.assertEqual(claims["forward_id"], 26)

    def test_merge_buffer_releases_in_order_and_drops_duplicates(self) -> None:
        buffer = merge_runtime.MergeBuffer(packet_limit=8, byte_limit=4096, timeout_ms=25)
        self.assertEqual(buffer.push(9, 2, b"two"), [])
        ready = buffer.push(9, 1, b"one")
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in ready], [(9, 1, b"one"), (9, 2, b"two")])
        duplicate = buffer.push(9, 1, b"dup")
        self.assertEqual(duplicate, [])
```

- [ ] **Step 2: Run the tests to verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_sidecar_dual_runtime_regression -v
python -m unittest sidecar-agent/tests/test_udp_uot_merge_runtime.py -v
```

Expected:

- sidecar dual runtime regression fails because no UDP runtime is written
- merge runtime tests fail because the module does not exist

- [ ] **Step 3: Implement the merge runtime**

Create `sidecar-agent/leikwan_udp_uot_merge.py` with this structure:

```python
#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import base64
import dataclasses
import hashlib
import hmac
import json
import pathlib
import socket
import struct
import time
from typing import Any


FRAME_PREFIX = struct.Struct(">I")
FRAME_HEADER = struct.Struct(">BBHIQI")
MSG_AUTH = 0
MSG_OPEN = 1
MSG_DATA = 2
MSG_CLOSE = 3
MSG_KEEPALIVE = 4


def decode_urlsafe(value: str) -> bytes:
    padding = "=" * ((4 - len(value) % 4) % 4)
    return base64.urlsafe_b64decode(value + padding)


def verify_session_token(token: str, secret: str, now: int) -> dict[str, Any]:
    payload_b64, signature_b64 = token.split(".", 1)
    payload_bytes = decode_urlsafe(payload_b64)
    expected_sig = hmac.new(secret.encode("utf-8"), payload_bytes, hashlib.sha256).digest()
    actual_sig = decode_urlsafe(signature_b64)
    if not hmac.compare_digest(expected_sig, actual_sig):
        raise RuntimeError("token signature verification failed")
    payload = json.loads(payload_bytes.decode("utf-8"))
    expires_at = int(payload.get("expires_at", 0) or 0)
    if expires_at > 0 and now > expires_at:
        raise RuntimeError("session token expired")
    return payload


def build_test_token(payload: dict[str, Any], secret: str) -> str:
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    payload_b64 = base64.urlsafe_b64encode(body).rstrip(b"=").decode("utf-8")
    sig = hmac.new(secret.encode("utf-8"), body, hashlib.sha256).digest()
    sig_b64 = base64.urlsafe_b64encode(sig).rstrip(b"=").decode("utf-8")
    return payload_b64 + "." + sig_b64
```

Also include:

- the same `MergeBuffer` logic used in the client runtime
- `AUTH` frame must be the first binary frame on a TCP leg
- `allowed_forward_ids` filter from config
- one upstream UDP socket per `session_id`
- reverse path that uses the same frame format back to the client
- sampled `udp-uot session opened`, `udp-uot gap timeout`, and `udp-uot leg disconnected` logs
- periodic stats-file writes to the configured `stats_path`

- [ ] **Step 4: Teach the sidecar agent to manage the extra unit**

In `sidecar-agent/sidecar_agent.py`, add these helpers:

```python
    def udp_uot_service_name(self) -> str:
        return self.service_name() + "-udp-uot"

    def udp_uot_runtime_config_path(self) -> pathlib.Path:
        primary = self.runtime_config_path()
        return primary.with_name("udp-uot-runtime.json")

    def udp_uot_start_script_path(self) -> pathlib.Path:
        return self.state_dir / "start-sidecar-udp-uot.sh"
```

Then inside `apply_management_config()` after the bond runtime block, add:

```python
        sidepath = self.config.get("udp_uot_sidepath", {})
        if not isinstance(sidepath, dict):
            sidepath = {}
        enabled_forward_ids = [
            int(value)
            for value in sidepath.get("enabled_forward_ids", [])
            if str(value).strip().isdigit()
        ]

        udp_service_name = self.udp_uot_service_name()
        udp_unit_path = self.unit_path_for(udp_service_name)
        udp_runtime_path = self.udp_uot_runtime_config_path()
        udp_start_script = self.udp_uot_start_script_path()

        if bond_runtime is not None and enabled_forward_ids:
            runtime_config = {
                "listen_host": str(bond_runtime.get("bind_host", "0.0.0.0") or "0.0.0.0"),
                "listen_port": int(sidepath.get("sidecar_port", 8999) or 8999),
                "token_secret": str(bond_runtime.get("token_secret", "") or ""),
                "allowed_forward_ids": enabled_forward_ids,
                "reorder_window_packets": int(sidepath.get("reorder_window_packets", 4096) or 4096),
                "reorder_window_bytes": int(sidepath.get("reorder_window_bytes", 16777216) or 16777216),
                "reorder_timeout_ms": int(sidepath.get("reorder_timeout_ms", 25) or 25),
                "stats_path": str(self.state_dir / "udp-uot-runtime-stats.json"),
            }
            runtime_changed = write_text_if_changed(udp_runtime_path, json.dumps(runtime_config, ensure_ascii=False, indent=2) + "\n", mode=0o600)
            start_script_lines = [
                "#!/usr/bin/env bash",
                "set -euo pipefail",
                f'exec /usr/bin/python3 /usr/local/bin/leikwan-udp-uot-merge.py --config {udp_runtime_path}',
            ]
            start_changed = write_text_if_changed(udp_start_script, "\n".join(start_script_lines) + "\n", mode=0o755)
            unit_body = f"""[Unit]
Description=Leikwan UDP UoT Sidepath
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/bin/bash {udp_start_script}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
            unit_changed = write_text_if_changed(udp_unit_path, unit_body)
            if unit_changed or runtime_changed or start_changed:
                subprocess.run("systemctl daemon-reload", shell=True, check=False)
            if unit_changed or runtime_changed or start_changed or not self.systemd_is_active(udp_service_name):
                subprocess.run(f"systemctl enable {udp_service_name}.service", shell=True, check=False)
                subprocess.run(f"systemctl restart {udp_service_name}.service", shell=True, check=False)
        else:
            self.disable_runtime(udp_service_name, udp_unit_path, udp_start_script, udp_runtime_path)
```

- [ ] **Step 5: Run the tests to verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_sidecar_dual_runtime_regression -v
python -m unittest sidecar-agent/tests/test_udp_uot_merge_runtime.py -v
python -m py_compile sidecar-agent/sidecar_agent.py sidecar-agent/leikwan_udp_uot_merge.py
```

Expected:

- sidecar dual runtime regression passes
- merge runtime tests pass
- `py_compile` exits cleanly

- [ ] **Step 6: Commit**

```bash
git add sidecar-agent/sidecar_agent.py sidecar-agent/leikwan_udp_uot_merge.py sidecar-agent/tests/test_udp_uot_merge_runtime.py client-agent/tests/test_sidecar_dual_runtime_regression.py
git commit -m "feat: add sidecar udp uot merge runtime"
```

### Task 5: Document The Local Override And Host Rollout Procedure

**Files:**
- Create: `config-examples/forward-26-udp-uot-sidepath.json`
- Modify: `docs/BOND_OPERATIONS.md`

- [ ] **Step 1: Add the example config file**

Create `config-examples/forward-26-udp-uot-sidepath.json`:

```json
{
  "udp_uot_sidepath": {
    "enabled_forward_ids": [26],
    "shadow_route_suffix": ".uot",
    "sidecar_port": 8999,
    "reorder_window_packets": 4096,
    "reorder_window_bytes": 16777216,
    "reorder_timeout_ms": 25
  }
}
```

- [ ] **Step 2: Document exact host edits and restart commands**

Append this section to `docs/BOND_OPERATIONS.md`:

```md
## Forward 26 UDP UoT sidepath hotfix

This hotfix is local-only. It does not require panel schema changes.

Apply the `udp_uot_sidepath` block to:

- client `/etc/leikwan-client-agent/config.json`
- published Guangzhou slave `/etc/leikwan-slave-agent/config.json`
- sidecar point config `/etc/leikwan-sidecar/config.json`

Required values:

    {
      "udp_uot_sidepath": {
        "enabled_forward_ids": [26],
        "shadow_route_suffix": ".uot",
        "sidecar_port": 8999,
        "reorder_window_packets": 4096,
        "reorder_window_bytes": 16777216,
        "reorder_timeout_ms": 25
      }
    }

Restart sequence:

    systemctl restart leikwan-bond-sidecar-4
    systemctl restart leikwan-slave-agent
    systemctl restart leikwan-client-agent
    systemctl restart leikwan-client-26
    systemctl restart leikwan-client-26-udp-uot

Rollback:

    systemctl stop leikwan-client-26-udp-uot
    systemctl disable leikwan-client-26-udp-uot
    systemctl stop leikwan-bond-sidecar-4-udp-uot
    systemctl disable leikwan-bond-sidecar-4-udp-uot
```

- [ ] **Step 3: Verify docs and JSON**

Run:

```bash
python - <<'PY'
import json, pathlib
path = pathlib.Path("config-examples/forward-26-udp-uot-sidepath.json")
json.loads(path.read_text(encoding="utf-8"))
print("ok")
PY
rg -n "Forward 26 UDP UoT sidepath hotfix|leikwan-client-26-udp-uot|shadow_route_suffix" docs/BOND_OPERATIONS.md config-examples/forward-26-udp-uot-sidepath.json
```

Expected:

- JSON validation prints `ok`
- `rg` finds the new section and config keys

- [ ] **Step 4: Commit**

```bash
git add config-examples/forward-26-udp-uot-sidepath.json docs/BOND_OPERATIONS.md
git commit -m "docs: add forward 26 udp uot rollout guide"
```

### Task 6: Run The Full Local Regression Set And Live Hotfix Verification

**Files:**
- Modify: none
- Test: live environment only

- [ ] **Step 1: Run the local regression set**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions -v
python -m unittest client-agent.tests.test_uot_bond_runtime -v
python -m unittest slave-agent/tests/test_slave_udp_uot_shadow_routes.py -v
python -m unittest client-agent.tests.test_sidecar_dual_runtime_regression -v
python -m unittest sidecar-agent/tests/test_udp_uot_merge_runtime.py -v
python -m py_compile client-agent/install_forward_runtime.py client-agent/client_agent.py client-agent/leikwan_uot_bond.py slave-agent/slave_agent.py sidecar-agent/sidecar_agent.py sidecar-agent/leikwan_udp_uot_merge.py
```

Expected:

- all unit tests pass
- all Python modules compile

- [ ] **Step 2: Build and stage the runtime files**

Copy to panel artifact storage and target hosts:

```bash
cp client-agent/leikwan_uot_bond.py /tmp/leikwan-uot-bond.py
cp sidecar-agent/leikwan_udp_uot_merge.py /tmp/leikwan-udp-uot-merge.py
```

On the client host, install:

```bash
install -m 0755 /tmp/leikwan-uot-bond.py /usr/local/bin/leikwan-uot-bond.py
```

On the sidecar host, install:

```bash
install -m 0755 /tmp/leikwan-udp-uot-merge.py /usr/local/bin/leikwan-udp-uot-merge.py
```

- [ ] **Step 3: Verify runtime shape after sync**

On the client:

```bash
systemctl status leikwan-client-26 --no-pager -n 20
systemctl status leikwan-client-26-udp-uot --no-pager -n 20
ss -ltnp | grep 11024
ss -lunp | grep 11024
cat /etc/leikwan/uot-bond-forward-26.json
```

Expected:

- TCP service is active
- UDP UoT service is active
- `11024/tcp` and `11024/udp` are owned by different runtimes
- config JSON contains the `.uot` route key and `udp-token`

- [ ] **Step 4: Verify slave and sidecar convergence**

On one slave:

```bash
cat /var/lib/leikwan-slave/listeners/routes-7001.json
systemctl status leikwan-tcp-mux-7001 --no-pager -n 10
systemctl status wstunnel-svc-1-184-udp-uot --no-pager -n 10
```

On the sidecar:

```bash
systemctl status leikwan-bond-sidecar-4-udp-uot --no-pager -n 20
ss -ltnp | grep 8999
cat /var/lib/leikwan-sidecar-4/udp-uot-runtime.json
```

Expected:

- slave route file contains both the primary route and the `.uot` route
- sidecar UDP merge service listens on `8999`

- [ ] **Step 5: Verify functional UDP, degradation, and recovery**

Run on the client:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -u -t 10
journalctl -u leikwan-client-26-udp-uot --no-pager -n 120 --since "3 min ago"
```

Then kill one slave leg and rerun:

```bash
systemctl stop wstunnel-svc-1-184-udp-uot
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -u -t 10
systemctl start wstunnel-svc-1-184-udp-uot
sleep 5
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -u -t 10
```

Success criteria:

- TCP service never restarts during the UDP tests
- UDP throughput degrades when one leg is removed but the flow continues
- UDP leg rejoins without restarting the whole client UDP service

- [ ] **Step 6: Record rollout evidence**

Collect and save:

```bash
journalctl -u leikwan-client-26 --no-pager -n 120 > /tmp/forward26-tcp.log
journalctl -u leikwan-client-26-udp-uot --no-pager -n 120 > /tmp/forward26-udp-uot.log
journalctl -u leikwan-bond-sidecar-4-udp-uot --no-pager -n 120 > /tmp/forward26-sidecar-udp-uot.log
```

Expected:

- the logs show independent TCP and UDP service health
- UDP logs contain leg up/down and reorder behavior
