#!/usr/bin/env python3
"""Single-file distribution entry point for the Linux client agent.

PyInstaller freezes this module together with every Python helper used by the
client runtime.  The installed executable is also linked under the historical
helper names, so generated systemd units keep working without shipping a
directory of executable Python files.
"""

from __future__ import annotations

import importlib
import pathlib
import sys
from collections.abc import Callable

# Keep these imports explicit.  PyInstaller cannot infer modules selected by
# the argv[0] dispatcher or by client_agent's optional watchdog import.
import cfwg_latency_watchdog as _cfwg_latency_watchdog  # noqa: F401
import client_agent as _client_agent
import forward_strategy as _forward_strategy
import install_forward_runtime as _install_forward_runtime  # noqa: F401
import leikwan_lease_seal as _leikwan_lease_seal  # noqa: F401  (lazy import in client_agent)
import leikwan_mptcp_client as _leikwan_mptcp_client
import leikwan_netns as _leikwan_netns
import leikwan_port_bridge as _leikwan_port_bridge
import leikwan_rate_limit as _leikwan_rate_limit
import leikwan_tcp_race as _leikwan_tcp_race
import leikwan_udp_fanout as _leikwan_udp_fanout
import leikwan_uot as _leikwan_uot
import leikwan_uot_bond as _leikwan_uot_bond


CLIENT_DISPATCH: dict[str, tuple[str, str]] = {
    "forward_strategy.py": ("forward_strategy", "main"),
    "leikwan-client-strategy": ("forward_strategy", "main"),
    "leikwan-client-strategy.py": ("forward_strategy", "main"),
    "leikwan-mptcp-client.py": ("leikwan_mptcp_client", "main"),
    "leikwan-netns.py": ("leikwan_netns", "main"),
    "leikwan-port-bridge.py": ("leikwan_port_bridge", "main"),
    "leikwan-rate-limit.py": ("leikwan_rate_limit", "main"),
    "leikwan-tcp-race.py": ("leikwan_tcp_race", "main"),
    "leikwan-udp-fanout.py": ("leikwan_udp_fanout", "main"),
    "leikwan-uot.py": ("leikwan_uot", "main"),
    "leikwan-uot-bond.py": ("leikwan_uot_bond", "main"),
}


def _binary_version() -> str:
    return str(getattr(_client_agent, "AGENT_VERSION", "unknown"))


def _dispatch(alias: str) -> int:
    module_name, function_name = CLIENT_DISPATCH[alias]
    module = importlib.import_module(module_name)
    function: Callable[[], object] = getattr(module, function_name)
    result = function()
    return int(result) if isinstance(result, int) else 0


def main() -> int:
    alias = pathlib.Path(sys.argv[0]).name
    if len(sys.argv) >= 3 and sys.argv[1] == "--dispatch-as":
        alias = pathlib.Path(sys.argv[2]).name
        del sys.argv[1:3]
    if any(argument in {"--version", "version"} for argument in sys.argv[1:]):
        print(_binary_version())
        return 0
    if alias in CLIENT_DISPATCH:
        return _dispatch(alias)
    # Preserve the original client-agent CLI exactly, including --config and
    # the run/check subcommands.
    return int(_client_agent.main())


if __name__ == "__main__":
    raise SystemExit(main())
