#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]


class LinuxArm64InstallerTest(unittest.TestCase):
    def test_manifest_publishes_complete_arm64_runtime_set(self) -> None:
        manifest = json.loads((ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))
        required = {
            "client_agent_installer_linux_arm64": "artifacts/install-client-agent-linux-arm64",
            "ultra_client_runtime_linux_arm64": "artifacts/ultra/ultra-client_linux_arm64",
            "leikwan_bond_linux_arm64": "artifacts/wstunnel/leikwan-bond_linux_arm64",
            "leikwan_ingress_linux_arm64": "artifacts/wstunnel/leikwan-ingress_linux_arm64",
            "leikwan_uot_bond_linux_arm64": "artifacts/wstunnel/leikwan-uot-bond_linux_arm64",
            "wstunnel_linux_arm64": "artifacts/wstunnel/wstunnel_linux_arm64",
        }
        for key, relative in required.items():
            with self.subTest(key=key):
                entry = manifest.get(key)
                self.assertIsInstance(entry, dict)
                self.assertEqual(relative, entry.get("path"))
                self.assertEqual("arm64", entry.get("architecture"))
                path = ROOT / relative
                self.assertTrue(path.is_file(), path)
                self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), entry.get("sha256"))
                self.assertEqual(path.stat().st_size, entry.get("size_bytes"))

    def test_installer_selects_arm64_artifacts_without_changing_api_paths(self) -> None:
        source = (ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        self.assertRegex(source, r"aarch64\|arm64")
        for key in (
            "ultra_client_runtime_linux_arm64",
            "leikwan_uot_bond_linux_arm64",
            "wstunnel_linux_arm64",
            "leikwan_ingress_linux_arm64",
            "leikwan_bond_linux_arm64",
        ):
            self.assertIn(key, source)
        self.assertIn("--download-base", source)
        self.assertIn("/api/client/configs/pull", (ROOT / "client-agent" / "client_agent.py").read_text(encoding="utf-8"))

    def test_single_file_arm64_installer_source_and_artifact(self) -> None:
        source = ROOT / "tools" / "linux-arm64-installer" / "main.go"
        self.assertTrue(source.is_file())
        text = source.read_text(encoding="utf-8")
        self.assertIn("embed.FS", text)
        self.assertIn("GOARCH", text)
        artifact = ROOT / "artifacts" / "install-client-agent-linux-arm64"
        self.assertTrue(artifact.is_file(), artifact)
        self.assertGreater(artifact.stat().st_size, 1024 * 1024)
        self.assertEqual(hashlib.sha256(artifact.read_bytes()).hexdigest(), json.loads(
            (ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8")
        )["client_agent_installer_linux_arm64"]["sha256"])

    def test_binary_installer_has_a_download_route_and_client_manifest_entry(self) -> None:
        bootstrap = (ROOT / "master" / "src" / "Bootstrap.php").read_text(encoding="utf-8")
        controller = (ROOT / "master" / "src" / "Controllers" / "ArtifactController.php").read_text(encoding="utf-8")
        self.assertIn("/artifacts/install-client-agent-linux-arm64", bootstrap)
        self.assertIn("installClientAgentLinuxArm64", controller)
        self.assertIn("'client_agent_installer_linux_arm64'", controller)

    def test_linux_agent_reports_architecture_for_arch_specific_runtime_updates(self) -> None:
        agent = (ROOT / "client-agent" / "client_agent.py").read_text(encoding="utf-8")
        controller = (ROOT / "master" / "src" / "Controllers" / "ClientAgentController.php").read_text(encoding="utf-8")
        self.assertIn("x-device-architecture", agent)
        self.assertIn("ultra_client_runtime_linux_arm64", controller)
        self.assertIn("leikwan_bond_linux_arm64", controller)
        self.assertIn("wstunnel_linux_arm64", controller)


if __name__ == "__main__":
    unittest.main()
