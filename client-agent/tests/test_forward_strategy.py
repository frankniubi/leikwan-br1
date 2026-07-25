import importlib.util
import pathlib
import sys

import pytest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


forward_strategy = load_module("forward_strategy_under_test", "client-agent/forward_strategy.py")
runtime = load_module("forward_strategy_runtime_under_test", "client-agent/install_forward_runtime.py")
client_agent = load_module("forward_strategy_client_agent_under_test", "client-agent/client_agent.py")


def sample_forward() -> dict:
    return {
        "forward_id": 77,
        "listen_port": 11024,
        "target": "162.245.221.78:80",
        "sni": "edge.example.test",
        "path_prefix": "bond/77",
        "transport": "tcp+udp",
        "mode": "bond",
        "backend_mode": "leigod_mode",
        "endpoints": [
            {
                "host": "127.0.0.1",
                "port": 7001,
                "ingress_auth_token": "test-auth",
            }
        ],
        "bonding": {
            "remote_target": "127.0.0.1:7002",
            "session_token": "tcp-token",
            "udp_session_token": "udp-token",
            "ingress_mode": "plain_tcp_mux",
            "route_key": "bond/77",
        },
    }


def test_store_defaults_to_latency_best_and_persists_canonical_value(tmp_path):
    store = forward_strategy.ForwardStrategyStore(tmp_path, panel_id="panel-a", client_id=9)
    assert store.get(77) == "latency_best"
    store.set(77, "random")
    assert store.get(77) == "random"
    assert store.runtime_path(77).read_text(encoding="utf-8") == "random\n"


def test_store_rejects_unknown_strategy(tmp_path):
    store = forward_strategy.ForwardStrategyStore(tmp_path, panel_id="panel-a", client_id=9)
    with pytest.raises(ValueError):
        store.set(77, "race")


def test_bond_runtime_receives_local_strategy_file():
    rendered = runtime.render_forward_runtime(
        sample_forward(),
        {"client_id": 9, "bond_bin": "/usr/local/bin/leikwan-bond"},
    )
    script = rendered["start_script"]
    expected = "/var/lib/leikwan-client-agent/forward-strategies/77.strategy"
    assert script.count(f"--strategy-file {expected}") == 2
    assert script.count("--default-strategy latency_best") == 2
    assert f"printf 'latency_best\\n' > {expected}" in script


def test_check_reads_strategy_locally_without_panel_fields(tmp_path):
    store = forward_strategy.ForwardStrategyStore(tmp_path)
    store.set(77, "speed_max")
    agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
    agent.state_dir = tmp_path

    assert agent.local_forward_strategies() == {"77": "speed_max"}

    runtime_source = (REPO_ROOT / "client-agent/client_agent.py").read_text(encoding="utf-8")
    heartbeat = runtime_source[runtime_source.index('f"{master}/api/client/heartbeat"') :]
    heartbeat = heartbeat[: heartbeat.index("now = time.time()")]
    assert '"strategy"' not in heartbeat
    assert '"client_strategy"' not in heartbeat
