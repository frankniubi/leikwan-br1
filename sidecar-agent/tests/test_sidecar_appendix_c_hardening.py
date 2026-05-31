import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


sidecar_agent = load_module("sidecar_agent_appendix_c_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarAppendixCHardeningTests(unittest.TestCase):
    def make_agent(self):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        tmp = pathlib.Path(tmp_dir.name)
        config_path = tmp / "agent.json"
        config_path.write_text(
            json.dumps(
                {
                    "state_dir": str(tmp),
                    "service_name": "leikwan-bond-sidecar-12",
                    "managed_iperf3_ports": [],
                }
            ),
            encoding="utf-8",
        )
        return sidecar_agent.SidecarAgent(config_path)

    # ------------------------------------------------------------------
    # BUG-1: empty-feed authoritative-wipe guard
    # ------------------------------------------------------------------
    def test_absent_sidecar_uplinks_section_preserves_live_slave_transit_backend(self):
        """A payload that omits 'sidecar_uplinks' must NOT sweep live slave-transit
        backends nor zero the uplink state (NO-OP preserve)."""
        agent = self.make_agent()
        agent.save_managed_cf_metadata(
            [
                {
                    "service_name": "leikwan-cf-slave-lws5c1n12p",
                    "backend_port": 26012,
                    "path_prefix": "lws-5-1-12-p",
                    "target": "127.0.0.1:14980",
                    "interface_name": "lws5c1n12p",
                    "backend_type": "slave_transit_cf_wg",
                }
            ]
        )
        agent.save_sidecar_uplink_state(
            {"slaves": {"lws5c1n12p": {"interface_name": "lws5c1n12p"}}}
        )
        disabled: list[str] = []

        # No 'sidecar_uplinks' key at all -> section ABSENT -> must be a NO-OP.
        with mock.patch.object(
            agent,
            "disable_runtime",
            side_effect=lambda service, *_a, **_k: disabled.append(service),
        ):
            agent.apply_management({"bond_runtime": None, "mptcp_runtime": None})

        self.assertNotIn("leikwan-cf-slave-lws5c1n12p", disabled)
        metadata = json.loads(agent.cf_managed_metadata_path().read_text(encoding="utf-8"))
        self.assertTrue(
            any(item.get("service_name") == "leikwan-cf-slave-lws5c1n12p" for item in metadata)
        )
        # State must be preserved, not overwritten with {} or {"slaves": {}}.
        state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
        self.assertIn("lws5c1n12p", state.get("slaves", {}))

    def test_present_empty_sidecar_uplinks_section_is_authoritative_teardown(self):
        """An explicit empty 'sidecar_uplinks' list IS authoritative: tear down live
        slave-transit backends (via the orphan sweep) and persist an empty state.

        The slave-transit backend here is an orphan (registered in cf-managed but
        NOT tracked in the persisted uplink state), so the only path that can tear
        it down is cleanup_orphan_slave_transit_cf_backends -- which BUG-1 gates on
        the section being present. With a present-empty list it MUST fire."""
        agent = self.make_agent()
        agent.save_managed_cf_metadata(
            [
                {
                    "service_name": "leikwan-cf-slave-lws5c1n12p",
                    "backend_port": 26012,
                    "path_prefix": "lws-5-1-12-p",
                    "target": "127.0.0.1:14980",
                    "interface_name": "lws5c1n12p",
                    "backend_type": "slave_transit_cf_wg",
                }
            ]
        )
        # Empty persisted uplink state -> the backend is an orphan only the sweep
        # can reap.
        agent.save_sidecar_uplink_state({"slaves": {}})
        disabled: list[str] = []

        with mock.patch.object(
            agent,
            "disable_runtime",
            side_effect=lambda service, *_a, **_k: disabled.append(service),
        ):
            agent.apply_management(
                {"sidecar_uplinks": [], "bond_runtime": None, "mptcp_runtime": None}
            )

        self.assertIn("leikwan-cf-slave-lws5c1n12p", disabled)
        metadata = json.loads(agent.cf_managed_metadata_path().read_text(encoding="utf-8"))
        self.assertFalse(
            any(item.get("service_name") == "leikwan-cf-slave-lws5c1n12p" for item in metadata)
        )
        state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
        self.assertEqual({"slaves": {}}, state)

    def test_present_empty_sidecar_uplinks_tears_down_state_tracked_uplink(self):
        """An explicit empty 'sidecar_uplinks' list also tears down an uplink that
        is tracked in the persisted state (via remove_slave_sidecar_uplink) and
        persists an empty state."""
        agent = self.make_agent()
        agent.save_sidecar_uplink_state(
            {"slaves": {"lws5c1n12p": {"interface_name": "lws5c1n12p"}}}
        )
        removed: list[str] = []

        with mock.patch.object(
            agent,
            "remove_slave_sidecar_uplink",
            side_effect=lambda uplink: removed.append(str(uplink.get("interface_name", ""))),
        ):
            agent.apply_management(
                {"sidecar_uplinks": [], "bond_runtime": None, "mptcp_runtime": None}
            )

        self.assertIn("lws5c1n12p", removed)
        state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
        self.assertEqual({"slaves": {}}, state)

    def test_absent_self_leg_section_preserves_live_self_leg_state(self):
        """A payload that omits 'self_leg_client_peers' must NOT remove live self-leg
        peers nor zero the self-leg state."""
        agent = self.make_agent()
        agent.save_self_leg_client_peer_state(
            {"interfaces": {"lw-self-1": {"interface_name": "lw-self-1"}}}
        )
        removed: list[str] = []

        with mock.patch.object(
            agent,
            "remove_self_leg_client_peer",
            side_effect=lambda peer: removed.append(str(peer.get("interface_name", ""))),
        ):
            agent.apply_management({"bond_runtime": None, "mptcp_runtime": None})

        self.assertEqual([], removed)
        state = json.loads(
            agent.self_leg_client_peer_state_path().read_text(encoding="utf-8")
        )
        self.assertIn("lw-self-1", state.get("interfaces", {}))

    def test_present_empty_self_leg_section_is_authoritative_teardown(self):
        agent = self.make_agent()
        agent.save_self_leg_client_peer_state(
            {"interfaces": {"lw-self-1": {"interface_name": "lw-self-1"}}}
        )
        removed: list[str] = []

        with mock.patch.object(
            agent,
            "remove_self_leg_client_peer",
            side_effect=lambda peer: removed.append(str(peer.get("interface_name", ""))),
        ):
            agent.apply_management(
                {
                    "self_leg_client_peers": [],
                    "bond_runtime": None,
                    "mptcp_runtime": None,
                }
            )

        self.assertEqual(["lw-self-1"], removed)
        state = json.loads(
            agent.self_leg_client_peer_state_path().read_text(encoding="utf-8")
        )
        self.assertEqual({"interfaces": {}}, state)

    # ------------------------------------------------------------------
    # BUG-2: per-uplink isolation
    # ------------------------------------------------------------------
    def test_one_failing_uplink_does_not_abort_reconcile_of_later_uplinks(self):
        """If apply_slave_sidecar_uplink raises for one uplink, the reconcile must
        continue to the next uplink and the later cf_runtime section must still run."""
        agent = self.make_agent()
        applied: list[str] = []

        def fake_apply(uplink):
            iface = str(uplink.get("interface_name", ""))
            if iface == "lws4c2n01p":
                raise RuntimeError("no free sidecar cf backend port available")
            applied.append(iface)

        management = {
            "sidecar_uplinks": [
                {"enabled": True, "interface_name": "lws4c2n01p", "sidecar_point_id": 4},
                {"enabled": True, "interface_name": "lws4c2n02p", "sidecar_point_id": 4},
            ],
            "cf_runtime": {
                "listen_host": "0.0.0.0",
                "listen_port": 443,
                "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                "tls_key_path": "/etc/ssl/cf/privkey.pem",
                "routes": [
                    {
                        "forward_id": 47,
                        "service_name": "cf-forward-47",
                        "path_prefix": "base",
                        "target": "127.0.0.1:8899",
                    }
                ],
            },
            "bond_runtime": None,
            "mptcp_runtime": None,
        }

        with mock.patch.object(agent, "apply_slave_sidecar_uplink", side_effect=fake_apply):
            with mock.patch.object(agent, "cleanup_stale_mptcp_transit_interfaces"):
                with mock.patch.object(agent, "allocate_cf_backend_port", return_value=26050):
                    with mock.patch.object(agent, "systemd_available", return_value=False):
                        agent.apply_management(management)

        # The second uplink was still reconciled despite the first raising.
        self.assertIn("lws4c2n02p", applied)
        # The later cf_runtime section still ran and produced the routes file.
        routes_path = agent.state_dir / "cf-routes-443.json"
        self.assertTrue(routes_path.exists())
        routes = json.loads(routes_path.read_text(encoding="utf-8"))["routes"]
        self.assertTrue(any(r["service_name"] == "cf-forward-47" for r in routes))

    def test_cf_backend_port_exhaustion_skips_one_route_but_renders_the_rest(self):
        """allocate_cf_backend_port raising RuntimeError for one route must not abort
        the whole cf_runtime loop; other routes still render."""
        agent = self.make_agent()

        def fake_allocate(service_name, reserved=None):
            if service_name == "cf-forward-90":
                raise RuntimeError("no free sidecar cf backend port available")
            return 26060

        management = {
            "cf_runtime": {
                "listen_host": "0.0.0.0",
                "listen_port": 443,
                "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                "tls_key_path": "/etc/ssl/cf/privkey.pem",
                "routes": [
                    {
                        "forward_id": 90,
                        "service_name": "cf-forward-90",
                        "path_prefix": "doomed",
                        "target": "127.0.0.1:8001",
                    },
                    {
                        "forward_id": 91,
                        "service_name": "cf-forward-91",
                        "path_prefix": "survivor",
                        "target": "127.0.0.1:8002",
                    },
                ],
            },
            "bond_runtime": None,
            "mptcp_runtime": None,
        }

        with mock.patch.object(agent, "allocate_cf_backend_port", side_effect=fake_allocate):
            with mock.patch.object(agent, "systemd_available", return_value=False):
                agent.apply_management(management)

        routes_path = agent.state_dir / "cf-routes-443.json"
        self.assertTrue(routes_path.exists())
        rendered = json.loads(routes_path.read_text(encoding="utf-8"))["routes"]
        service_names = {r["service_name"] for r in rendered}
        self.assertIn("cf-forward-91", service_names)
        self.assertNotIn("cf-forward-90", service_names)

    def test_reconcile_cf_backend_port_exhaustion_skips_one_slave_but_keeps_rest(self):
        """reconcile_cf_backend_port raising for one slave-transit backend must not
        abort the loop; healthy slave-transit backends still render."""
        agent = self.make_agent()
        agent.save_managed_cf_metadata(
            [
                {
                    "service_name": "leikwan-cf-slave-lwsbad",
                    "backend_port": 26012,
                    "path_prefix": "p-bad",
                    "target": "127.0.0.1:14980",
                    "interface_name": "lwsbad",
                    "backend_type": "slave_transit_cf_wg",
                },
                {
                    "service_name": "leikwan-cf-slave-lwsok",
                    "backend_port": 26013,
                    "path_prefix": "p-ok",
                    "target": "127.0.0.1:14981",
                    "interface_name": "lwsok",
                    "backend_type": "slave_transit_cf_wg",
                },
            ]
        )

        def fake_reconcile(service_name, backend_port, reserved=None):
            if service_name == "leikwan-cf-slave-lwsbad":
                raise RuntimeError("no free sidecar cf backend port available")
            return backend_port

        management = {
            "cf_runtime": {
                "listen_host": "0.0.0.0",
                "listen_port": 443,
                "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                "tls_key_path": "/etc/ssl/cf/privkey.pem",
                "routes": [
                    {
                        "forward_id": 47,
                        "service_name": "cf-forward-47",
                        "path_prefix": "base",
                        "target": "127.0.0.1:8899",
                    }
                ],
            },
            "bond_runtime": None,
            "mptcp_runtime": None,
        }

        with mock.patch.object(agent, "reconcile_cf_backend_port", side_effect=fake_reconcile):
            with mock.patch.object(agent, "allocate_cf_backend_port", return_value=26050):
                with mock.patch.object(agent, "systemd_available", return_value=False):
                    agent.apply_management(management)

        rendered = json.loads(
            (agent.state_dir / "cf-routes-443.json").read_text(encoding="utf-8")
        )["routes"]
        service_names = {r["service_name"] for r in rendered}
        self.assertIn("leikwan-cf-slave-lwsok", service_names)
        self.assertNotIn("leikwan-cf-slave-lwsbad", service_names)

    # ------------------------------------------------------------------
    # BUG-3: guard private.key read in ensure_wireguard_identity
    # ------------------------------------------------------------------
    def test_ensure_wireguard_identity_returns_empty_when_private_key_unreadable(self):
        """An unreadable private.key must yield a degraded ("") identity rather than
        raising OSError out of ensure_wireguard_identity."""
        agent = self.make_agent()
        agent.wireguard_dir().mkdir(parents=True, exist_ok=True)
        # private.key exists but reading it raises OSError; public.key absent so we
        # fall through to the private-key read path.
        agent.wireguard_private_key_path().write_text("unreadable\n", encoding="utf-8")

        original_read_text = pathlib.Path.read_text
        private_key_path = agent.wireguard_private_key_path()

        def fake_read_text(self, *args, **kwargs):
            if self == private_key_path:
                raise OSError("simulated unreadable private key")
            return original_read_text(self, *args, **kwargs)

        def fake_which(command: str):
            return "/usr/bin/wg" if command in ("wg",) else None

        with mock.patch.object(sidecar_agent.shutil, "which", side_effect=fake_which):
            with mock.patch.object(pathlib.Path, "read_text", fake_read_text):
                identity = agent.ensure_wireguard_identity()

        self.assertEqual("", identity)

    def test_heartbeat_is_degraded_when_private_key_unreadable(self):
        """End-to-end: an unreadable private key degrades the heartbeat instead of
        crashing the agent."""
        agent = self.make_agent()
        agent.wireguard_dir().mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("unreadable\n", encoding="utf-8")

        original_read_text = pathlib.Path.read_text
        private_key_path = agent.wireguard_private_key_path()

        def fake_read_text(self, *args, **kwargs):
            if self == private_key_path:
                raise OSError("simulated unreadable private key")
            return original_read_text(self, *args, **kwargs)

        def fake_which(command: str):
            return "/usr/bin/wg" if command in ("wg",) else None

        with mock.patch.object(sidecar_agent.shutil, "which", side_effect=fake_which):
            with mock.patch.object(pathlib.Path, "read_text", fake_read_text):
                with mock.patch.object(
                    sidecar_agent.subprocess,
                    "run",
                    return_value=SimpleNamespace(returncode=0, stdout="", stderr=""),
                ):
                    payload = agent.build_heartbeat_payload()

        self.assertEqual("degraded", payload["status"])
        self.assertEqual("wireguard_identity_missing", payload["health_error"])

    # ------------------------------------------------------------------
    # C.6c: prune cf-routes-*.json regardless of cf_runtime
    # ------------------------------------------------------------------
    def test_swept_slave_transit_backend_row_is_pruned_from_routes_file_without_cf_runtime(self):
        """When cf_runtime is absent and a slave-transit uplink goes away, its row
        must be removed from cf-routes-<port>.json (C.6c)."""
        agent = self.make_agent()
        # Seed a routes file (as cf mux would have persisted earlier) containing the
        # slave-transit row, plus a regular forward row that must be preserved.
        routes_path = agent.cf_routes_path(2053)
        routes_path.write_text(
            json.dumps(
                {
                    "routes": [
                        {
                            "service_name": "leikwan-cf-slave-lwsstale",
                            "path_prefix": "p-stale",
                            "backend_host": "127.0.0.1",
                            "backend_port": 26012,
                            "interface_name": "lwsstale",
                            "backend_type": "slave_transit_cf_wg",
                        },
                        {
                            "service_name": "cf-forward-47",
                            "path_prefix": "base",
                            "backend_host": "127.0.0.1",
                            "backend_port": 26013,
                        },
                    ]
                }
            ),
            encoding="utf-8",
        )
        agent.save_managed_cf_metadata(
            [
                {
                    "service_name": "leikwan-cf-slave-lwsstale",
                    "backend_port": 26012,
                    "interface_name": "lwsstale",
                    "backend_type": "slave_transit_cf_wg",
                },
                {
                    "service_name": "cf-forward-47",
                    "backend_port": 26013,
                    "backend_type": "forward_cf",
                },
            ]
        )

        with mock.patch.object(agent, "disable_runtime"):
            # Authoritative present-empty uplink list with no cf_runtime: the stale
            # slave-transit backend must be swept AND pruned from the routes file.
            agent.cleanup_orphan_slave_transit_cf_backends(set())

        remaining = json.loads(routes_path.read_text(encoding="utf-8"))["routes"]
        service_names = {r["service_name"] for r in remaining}
        self.assertNotIn("leikwan-cf-slave-lwsstale", service_names)
        self.assertIn("cf-forward-47", service_names)


if __name__ == "__main__":
    unittest.main()
