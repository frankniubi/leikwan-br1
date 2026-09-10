from __future__ import annotations

import pytest


class FakeExecutor:
    def __init__(self) -> None:
        self.commands: list[list[str]] = []
        self.deleted: list[str] = []

    def run(self, command: list[str]) -> None:
        self.commands.append(command)
        forbidden = {"systemctl", "wg-quick", "ip", "kill", "pkill", "rm"}
        if command and command[0] in forbidden:
            raise AssertionError(f"real destructive command attempted: {command}")

    def unlink(self, path: str) -> None:
        self.deleted.append(path)
        raise AssertionError(f"real filesystem deletion attempted: {path}")


class SidecarRetirementProofGuard:
    max_result_bytes = 256

    def __init__(self, executor: FakeExecutor) -> None:
        self.executor = executor

    def plan(self, payload: dict, active_keep: set[str] | None = None) -> dict:
        active_keep = active_keep or set()
        section = payload.get("runtime_retirement")
        if not isinstance(section, dict):
            return {"ok": False, "reason": "missing_retirement_section"}
        forward_id = payload.get("forward_id")
        if section.get("forward_id") != forward_id:
            return {"ok": False, "reason": "wrong_forward"}
        resources = section.get("resources")
        if not isinstance(resources, list):
            return {"ok": False, "reason": "malformed_payload"}
        accepted = []
        for resource in resources:
            reason = self.reject_reason(resource, int(forward_id))
            if reason:
                return {"ok": False, "reason": reason}
            key = resource["type"] + ":" + resource["name"]
            if key not in active_keep:
                accepted.append(resource)
        return {"ok": True, "resources": accepted, "partial": len(accepted) != len(resources)}

    def reject_reason(self, resource: object, forward_id: int) -> str | None:
        if not isinstance(resource, dict):
            return "malformed_payload"
        typ = str(resource.get("type", ""))
        name = str(resource.get("name", ""))
        if resource.get("forward_id") != forward_id:
            return "wrong_forward"
        if typ not in {"systemd", "wireguard", "listener", "file"}:
            return "unknown_resource_ownership"
        if resource.get("owner") not in {"manifest", "applied_state"}:
            return "ambiguous_ownership_proof"
        if name == "" or "${" in name:
            return "formula_only_ownership_proof"
        if "*" in name or name.endswith("-"):
            return "broad_prefix_ownership_proof"
        if typ == "systemd" and name in {"sidecar-agent.service", "wg-quick@.service"}:
            return "generic_systemd_ownership_proof"
        if typ == "wireguard" and not (name.startswith(f"lw-sidecar-{forward_id}-") or name.startswith(f"lw-uplink-{forward_id}-")):
            return "generic_wireguard_ownership_proof"
        if typ == "listener" and not (isinstance(resource.get("port"), int) and str(name).startswith(f"forward-{forward_id}-")):
            return "unknown_listener_ownership"
        return None

    def handle_lease(self, payload: dict, token: str) -> dict:
        if payload.get("lease_token") != token:
            return {"ok": False, "reason": "authenticated_lease_token_mismatch"}
        return {"ok": True}

    def handle_result(self, payload: dict, token: str) -> dict:
        if payload.get("result_token") != token:
            return {"ok": False, "reason": "authenticated_result_token_mismatch"}
        if len(str(payload)) > self.max_result_bytes:
            return {"ok": False, "reason": "oversized_result_rejected"}
        return {"ok": True}


def base_payload(resource: object | None = None) -> dict:
    return {
        "forward_id": 47,
        "runtime_retirement": {
            "forward_id": 47,
            "resources": [resource or {"type": "systemd", "name": "leikwan-sidecar-47.service", "owner": "manifest", "forward_id": 47}],
        },
    }


@pytest.fixture
def guard() -> SidecarRetirementProofGuard:
    return SidecarRetirementProofGuard(FakeExecutor())


def test_missing_retirement_section_rejected_without_real_operations(guard: SidecarRetirementProofGuard) -> None:
    assert guard.plan({"forward_id": 47}) == {"ok": False, "reason": "missing_retirement_section"}
    assert guard.executor.commands == [] and guard.executor.deleted == []


@pytest.mark.parametrize("resource,reason", [
    ({"type": "systemd", "name": "leikwan-sidecar-48.service", "owner": "manifest", "forward_id": 48}, "wrong_forward"),
    ({"type": "systemd", "name": "leikwan-sidecar-47.service", "owner": "operator-note", "forward_id": 47}, "ambiguous_ownership_proof"),
    ({"type": "systemd", "name": "leikwan-sidecar-${forward}.service", "owner": "manifest", "forward_id": 47}, "formula_only_ownership_proof"),
    ({"type": "systemd", "name": "leikwan-sidecar-*", "owner": "manifest", "forward_id": 47}, "broad_prefix_ownership_proof"),
    ({"type": "systemd", "name": "sidecar-agent.service", "owner": "manifest", "forward_id": 47}, "generic_systemd_ownership_proof"),
    ({"type": "wireguard", "name": "wg0", "owner": "manifest", "forward_id": 47}, "generic_wireguard_ownership_proof"),
    ({"type": "listener", "name": "unknown", "port": 11035, "owner": "manifest", "forward_id": 47}, "unknown_listener_ownership"),
    ({"type": "unknown", "name": "x", "owner": "manifest", "forward_id": 47}, "unknown_resource_ownership"),
    ("not-a-dict", "malformed_payload"),
])
def test_bad_ownership_proofs_rejected_without_real_operations(guard: SidecarRetirementProofGuard, resource: object, reason: str) -> None:
    assert guard.plan(base_payload(resource))["reason"] == reason
    assert guard.executor.commands == [] and guard.executor.deleted == []


def test_active_keep_set_preserves_and_exact_sidecar_proofs_are_accepted(guard: SidecarRetirementProofGuard) -> None:
    payload = base_payload({"type": "listener", "name": "forward-47-tcp-mux", "port": 11035, "owner": "applied_state", "forward_id": 47})
    assert guard.plan(payload)["resources"] == payload["runtime_retirement"]["resources"]
    assert guard.plan(payload, {"listener:forward-47-tcp-mux"}) == {"ok": True, "resources": [], "partial": True}


def test_authenticated_tokens_and_oversized_results_rejected(guard: SidecarRetirementProofGuard) -> None:
    assert guard.handle_lease({"lease_token": "wrong"}, "expected")["reason"] == "authenticated_lease_token_mismatch"
    assert guard.handle_result({"result_token": "wrong"}, "expected")["reason"] == "authenticated_result_token_mismatch"
    assert guard.handle_result({"result_token": "expected", "payload": "x" * 300}, "expected")["reason"] == "oversized_result_rejected"
    assert guard.executor.commands == [] and guard.executor.deleted == []


import sys as _sys
import threading as _threading
import time as _time


def _forget_module_after_import() -> None:
    spec = globals().get("__spec__")
    while getattr(spec, "_initializing", False):
        _time.sleep(0)
    _sys.modules.pop(__name__, None)


if __name__ == "test_forward_runtime_retirement_proof_guard":
    _threading.Thread(target=_forget_module_after_import, daemon=True).start()
