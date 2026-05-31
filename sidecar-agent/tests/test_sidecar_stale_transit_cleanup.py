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


sidecar_agent_module = load_module("sidecar_agent_stale_transit_under_test", "sidecar-agent/sidecar_agent.py")


class StaleTransitCleanupTests(unittest.TestCase):
    """Behavioral tests asserting real side-effects of stale lw-sc transit reaping.

    These devices are created live by the mptcp relay (NOT via wg-quick), so the
    cleanup MUST actually `ip link del` them; merely stopping the wg-quick unit and
    removing the conf is a no-op against the live kernel device.
    """

    def make_agent(self, wg_dir: pathlib.Path):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        tmp = pathlib.Path(tmp_dir.name)
        config_path = tmp / "agent.json"
        config_path.write_text(json.dumps({
            "state_dir": str(tmp),
            "service_name": "leikwan-bond-sidecar-5",
            "managed_iperf3_ports": [],
        }), encoding="utf-8")
        agent = sidecar_agent_module.SidecarAgent(config_path)
        wg_dir.mkdir(parents=True, exist_ok=True)
        # Route the /etc/wireguard system dir at the agent's wireguard_system_dir()
        # helper to a temp dir so conf side-effects are observable & sandboxed.
        agent.wireguard_system_dir = lambda: wg_dir  # type: ignore[assignment]
        return agent

    def _which_all(self, cmd):
        return "/usr/bin/" + cmd

    def _capture_run(self, recorded, live_interfaces):
        def fake_run(args, **kwargs):
            if isinstance(args, (list, tuple)):
                recorded.append(list(args))
                if list(args[:3]) == ["wg", "show", "interfaces"]:
                    return SimpleNamespace(returncode=0, stdout=" ".join(live_interfaces), stderr="")
            else:
                recorded.append(args)
            return SimpleNamespace(returncode=0, stdout="", stderr="")
        return fake_run

    def test_keep_none_deletes_all_live_transit_ifaces_and_confs(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            live = ["lw-sc-5-0", "lw-sc-5-1", "lw-sc-5-2"]
            for iface in live:
                (wg_dir / f"{iface}.conf").write_text("[Interface]\n", encoding="utf-8")
            recorded: list = []
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=self._which_all):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=self._capture_run(recorded, live)):
                    agent.cleanup_stale_mptcp_transit_interfaces(5, keep=None)

            for iface in live:
                self.assertIn(["ip", "link", "del", "dev", iface], recorded)
                self.assertFalse((wg_dir / f"{iface}.conf").exists())

    def test_keep_set_preserves_active_legs_and_reaps_only_extras(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            live = ["lw-sc-5-0", "lw-sc-5-1", "lw-sc-5-2", "lw-sc-5-3"]
            for iface in live:
                (wg_dir / f"{iface}.conf").write_text("[Interface]\n", encoding="utf-8")
            recorded: list = []
            keep = {"lw-sc-5-0", "lw-sc-5-1"}
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=self._which_all):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=self._capture_run(recorded, live)):
                    agent.cleanup_stale_mptcp_transit_interfaces(5, keep=keep)

            # Extras 2 and 3 are deleted.
            self.assertIn(["ip", "link", "del", "dev", "lw-sc-5-2"], recorded)
            self.assertIn(["ip", "link", "del", "dev", "lw-sc-5-3"], recorded)
            self.assertFalse((wg_dir / "lw-sc-5-2.conf").exists())
            self.assertFalse((wg_dir / "lw-sc-5-3.conf").exists())
            # Kept 0 and 1 are PRESERVED: no ip link del, conf intact.
            self.assertNotIn(["ip", "link", "del", "dev", "lw-sc-5-0"], recorded)
            self.assertNotIn(["ip", "link", "del", "dev", "lw-sc-5-1"], recorded)
            self.assertTrue((wg_dir / "lw-sc-5-0.conf").exists())
            self.assertTrue((wg_dir / "lw-sc-5-1.conf").exists())

    def test_conf_only_orphan_is_reaped_when_iface_not_live_and_not_kept(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            # lw-sc-5-9 has a conf but is NOT a live wg interface.
            (wg_dir / "lw-sc-5-9.conf").write_text("[Interface]\n", encoding="utf-8")
            recorded: list = []
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=self._which_all):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=self._capture_run(recorded, [])):
                    agent.cleanup_stale_mptcp_transit_interfaces(5, keep=set())

            self.assertFalse((wg_dir / "lw-sc-5-9.conf").exists())

    def test_conf_only_orphan_in_keep_is_preserved(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            (wg_dir / "lw-sc-5-9.conf").write_text("[Interface]\n", encoding="utf-8")
            recorded: list = []
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=self._which_all):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=self._capture_run(recorded, [])):
                    agent.cleanup_stale_mptcp_transit_interfaces(5, keep={"lw-sc-5-9"})

            self.assertTrue((wg_dir / "lw-sc-5-9.conf").exists())

    def test_ip_unavailable_falls_back_to_wg_quick_down(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            live = ["lw-sc-5-0"]
            (wg_dir / "lw-sc-5-0.conf").write_text("[Interface]\n", encoding="utf-8")
            recorded: list = []

            def which_no_ip(cmd):
                if cmd == "ip":
                    return None
                return "/usr/bin/" + cmd

            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=which_no_ip):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=self._capture_run(recorded, live)):
                    agent.cleanup_stale_mptcp_transit_interfaces(5, keep=set())

            self.assertNotIn(["ip", "link", "del", "dev", "lw-sc-5-0"], recorded)
            self.assertIn(["wg-quick", "down", "lw-sc-5-0"], recorded)
            self.assertFalse((wg_dir / "lw-sc-5-0.conf").exists())

    def test_never_raises_when_all_binaries_absent(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            (wg_dir / "lw-sc-5-9.conf").write_text("[Interface]\n", encoding="utf-8")
            with mock.patch.object(sidecar_agent_module.shutil, "which", return_value=None):
                # Must not raise even though wg/ip/systemctl are all missing.
                agent.cleanup_stale_mptcp_transit_interfaces(5, keep=set())
            # conf-only reaping still works without any binaries.
            self.assertFalse((wg_dir / "lw-sc-5-9.conf").exists())

    def test_default_keep_is_empty_set(self):
        with tempfile.TemporaryDirectory() as wg:
            wg_dir = pathlib.Path(wg)
            agent = self.make_agent(wg_dir)
            live = ["lw-sc-5-0"]
            (wg_dir / "lw-sc-5-0.conf").write_text("[Interface]\n", encoding="utf-8")
            recorded: list = []
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=self._which_all):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=self._capture_run(recorded, live)):
                    # No keep argument at all -> defaults to empty -> reaps everything.
                    agent.cleanup_stale_mptcp_transit_interfaces(5)
            self.assertIn(["ip", "link", "del", "dev", "lw-sc-5-0"], recorded)


class StaleTransitCleanupWiringTests(unittest.TestCase):
    """End-to-end apply_management wiring tests for the keep-set plumbing."""

    def make_agent(self):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        tmp = pathlib.Path(tmp_dir.name)
        config_path = tmp / "agent.json"
        config_path.write_text(json.dumps({
            "state_dir": str(tmp),
            "service_name": "leikwan-bond-sidecar-5",
            "managed_iperf3_ports": [],
        }), encoding="utf-8")
        agent = sidecar_agent_module.SidecarAgent(config_path)
        (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
        (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
        (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
        return agent

    def test_landmine_mixed_point_keeps_active_transit_at_static_cleanup_site(self):
        """A point that serves BOTH a static lws* uplink AND a published mptcp transit
        underlay must NOT have its active transit leg deleted at the static-uplink
        cleanup site. The active published name must appear in the keep-set."""
        agent = self.make_agent()
        cleanup_calls: list[tuple[int, frozenset]] = []

        def record_cleanup(point_id, keep=None):
            cleanup_calls.append((point_id, frozenset(keep or set())))

        def fake_run(args, **kwargs):
            return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

        with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                with mock.patch.object(agent, "ensure_relay_binary", return_value=None):
                    with mock.patch.object(agent, "cleanup_stale_mptcp_transit_interfaces", side_effect=record_cleanup):
                        agent.apply_management({
                            "config_patch": {"managed_iperf3_ports": []},
                            "sidecar_uplinks": [
                                {
                                    "enabled": True,
                                    "interface_name": "lws5c2n19p",
                                    "sidecar_point_id": 5,
                                    "listen_port": 14970,
                                    "local_address": "10.240.8.1",
                                    "peer_address": "10.240.8.2",
                                    "slave_peer_public_key": "slave-pub",
                                    "outer_transport": "udp",
                                }
                            ],
                            "bond_runtime": None,
                            "mptcp_runtime": {
                                "listen_port": 39000,
                                "token_secret": "secret",
                                "underlays": [
                                    {"interface_name": "lw-sc-5-0", "listen_port": 39001},
                                ],
                            },
                        })

        # The static-uplink cleanup site (point 5) must carry the published transit
        # name in its keep-set so the live leg is preserved.
        static_site_calls = [c for c in cleanup_calls if c[0] == 5 and "lw-sc-5-0" in c[1]]
        self.assertTrue(
            static_site_calls,
            f"expected a cleanup(point=5, keep contains lw-sc-5-0); got {cleanup_calls}",
        )

    def test_active_mptcp_branch_reaps_with_published_names(self):
        """The new cleanup call inside the active mptcp branch must pass the
        currently-published underlay interface_names as keep (the accumulation fix)."""
        agent = self.make_agent()
        cleanup_calls: list[tuple[int, frozenset]] = []

        def record_cleanup(point_id, keep=None):
            cleanup_calls.append((point_id, frozenset(keep or set())))

        def fake_run(args, **kwargs):
            return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

        with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                with mock.patch.object(agent, "ensure_relay_binary", return_value=None):
                    with mock.patch.object(agent, "cleanup_stale_mptcp_transit_interfaces", side_effect=record_cleanup):
                        agent.apply_management({
                            "bond_runtime": None,
                            "mptcp_runtime": {
                                "listen_port": 39000,
                                "token_secret": "secret",
                                "underlays": [
                                    {"interface_name": "lw-sc-5-0", "listen_port": 39001},
                                    {"interface_name": "lw-sc-5-1", "listen_port": 39002},
                                ],
                            },
                        })

        # Find the active-branch cleanup call (point 5 with both published names).
        matching = [
            c for c in cleanup_calls
            if c[0] == 5 and {"lw-sc-5-0", "lw-sc-5-1"}.issubset(c[1])
        ]
        self.assertTrue(
            matching,
            f"expected cleanup(point=5, keep={{lw-sc-5-0, lw-sc-5-1}}); got {cleanup_calls}",
        )


if __name__ == "__main__":
    unittest.main()
