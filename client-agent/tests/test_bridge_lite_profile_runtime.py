import importlib.util
import json
import pathlib
import sys


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_module("client_agent_bridge_lite_profile_runtime_under_test", "client-agent/client_agent.py")


def write_config(tmp_path: pathlib.Path) -> pathlib.Path:
    path = tmp_path / "config.json"
    path.write_text(json.dumps({
        "state_dir": str(tmp_path / "state"),
        "master_url": "https://bridge-lite.example.test",
        "agent_token": "secret",
        "client_id": 99,
        "log_level": "INFO",
    }), encoding="utf-8")
    return path


def test_bridge_lite_profile_filters_paid_nodes_when_disallowed(tmp_path):
    agent = client_agent.ClientAgent(write_config(tmp_path))
    profile = {"profile_id": 9, "allow_paid_lines": False, "selected_carriers": ["ct"]}
    candidates = [
        {"id": 1, "carrier": "ct", "pricing_class": "free", "priority": 10, "probe_latency_ms": 30},
        {"id": 2, "carrier": "ct", "pricing_class": "paid", "priority": 5, "probe_latency_ms": 10},
        {"id": 3, "carrier": "cu", "pricing_class": "free", "priority": 1, "probe_latency_ms": 5},
    ]
    filtered = agent.bridge_lite_filter_candidate_nodes(profile, candidates)
    assert [item["id"] for item in filtered] == [1]


def test_bridge_lite_profile_selects_lowest_latency_allowed_node(tmp_path):
    agent = client_agent.ClientAgent(write_config(tmp_path))
    selected = agent.bridge_lite_select_best_node([
        {"id": 11, "carrier": "ct", "pricing_class": "free", "priority": 100, "probe_latency_ms": 20.5},
        {"id": 12, "carrier": "ct", "pricing_class": "free", "priority": 5, "probe_latency_ms": 8.1},
    ])
    assert selected["id"] == 12


def test_bridge_lite_runtime_status_payload_is_profile_scoped(tmp_path):
    agent = client_agent.ClientAgent(write_config(tmp_path))
    payload = agent.bridge_lite_runtime_status_payload(
        profile={"profile_id": 7, "profile_name": "linux-main"},
        selected_node={"id": 12, "carrier": "ct", "pricing_class": "paid"},
        status="healthy",
    )
    assert payload["profile_id"] == 7
    assert payload["profile_name"] == "linux-main"
    assert payload["selected_entry"] == 12
    assert payload["selected_carrier"] == "ct"
    assert payload["used_paid_line"] is True
