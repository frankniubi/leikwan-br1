#!/usr/bin/env python3
"""Single-file distribution entry point for the Linux sidecar agent."""

from __future__ import annotations

import importlib
import pathlib
import sys
from collections.abc import Callable

# The sidecar keeps the legacy LeiGod UoT helper in client-agent for source
# installs.  Add that sibling only for an unfrozen checkout; PyInstaller gets
# the same directory through the explicit --paths flag in the build script.
_SIDECAR_DIR = pathlib.Path(__file__).resolve().parent
if not getattr(sys, "frozen", False):
    _CLIENT_DIR = _SIDECAR_DIR.parent / "client-agent"
    if _CLIENT_DIR.is_dir() and str(_CLIENT_DIR) not in sys.path:
        sys.path.insert(0, str(_CLIENT_DIR))

# Explicit imports make all argv[0]-selected sidecar runtimes part of the
# frozen binary instead of leaving them as separately downloaded .py files.
import leikwan_mptcp_sidecar as _leikwan_mptcp_sidecar  # noqa: F401
import leikwan_external_uot as _leikwan_external_uot  # noqa: F401
import leikwan_udp_uot_merge as _leikwan_udp_uot_merge  # noqa: F401
import leikwan_uot as _leikwan_uot  # noqa: F401
import leikwan_wss_mux as _leikwan_wss_mux  # noqa: F401
import sidecar_agent as _sidecar_agent


SIDECAR_DISPATCH: dict[str, tuple[str, str]] = {
    "leikwan-external-uot.py": ("leikwan_external_uot", "main"),
    "leikwan-mptcp-sidecar.py": ("leikwan_mptcp_sidecar", "main"),
    "leikwan-udp-uot-merge.py": ("leikwan_udp_uot_merge", "main"),
    "leikwan-uot.py": ("leikwan_uot", "main"),
    "leikwan-wss-mux.py": ("leikwan_wss_mux", "main"),
}


def _dispatch(alias: str) -> int:
    module_name, function_name = SIDECAR_DISPATCH[alias]
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
        print(str(getattr(_sidecar_agent, "AGENT_VERSION", "unknown")))
        return 0
    if alias in SIDECAR_DISPATCH:
        return _dispatch(alias)
    return int(_sidecar_agent.main())


if __name__ == "__main__":
    raise SystemExit(main())
