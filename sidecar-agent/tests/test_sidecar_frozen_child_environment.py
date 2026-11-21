import importlib.util
import json
import os
import pathlib
import re
import subprocess
import sys
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


MODULE = load_module("sidecar_agent_frozen_env_under_test", "sidecar-agent/sidecar_agent.py")

MEIPASS = "/tmp/_MEIabc123"
BOOTLOADER_ENV = {
    "_PYI_ARCHIVE_FILE": "/usr/local/bin/leikwan-sidecar-agent",
    "_PYI_APPLICATION_HOME_DIR": MEIPASS,
    "_PYI_PARENT_PROCESS_LEVEL": "1",
    "_PYI_LINUX_PROCESS_NAME": "leikwan-sidecar-agent",
}


class FrozenChildEnvironmentTests(unittest.TestCase):
    def test_frozen_restores_original_library_path(self):
        env = {
            "PATH": "/usr/bin",
            "LD_LIBRARY_PATH": f"{MEIPASS}:/opt/vendor/lib",
            "LD_LIBRARY_PATH_ORIG": "/opt/vendor/lib",
            **BOOTLOADER_ENV,
        }

        self.assertTrue(MODULE.sanitize_frozen_child_environment(env, frozen=True))

        self.assertEqual(env, {"PATH": "/usr/bin", "LD_LIBRARY_PATH": "/opt/vendor/lib"})

    def test_frozen_restores_empty_original_value(self):
        env = {"LD_LIBRARY_PATH": MEIPASS, "LD_LIBRARY_PATH_ORIG": ""}

        self.assertTrue(MODULE.sanitize_frozen_child_environment(env, frozen=True))

        self.assertEqual(env, {"LD_LIBRARY_PATH": ""})

    def test_frozen_without_original_removes_library_path(self):
        env = {"PATH": "/usr/bin", "LD_LIBRARY_PATH": MEIPASS, **BOOTLOADER_ENV}

        self.assertTrue(MODULE.sanitize_frozen_child_environment(env, frozen=True))

        self.assertEqual(env, {"PATH": "/usr/bin"})

    def test_frozen_is_idempotent(self):
        # leikwan_sidecar_binary.main() and sidecar_agent.main() both call it; the
        # second call must not drop the host value the first one restored.
        env = {"LD_LIBRARY_PATH": f"{MEIPASS}:/opt/vendor/lib", "LD_LIBRARY_PATH_ORIG": "/opt/vendor/lib"}
        self.assertTrue(MODULE.sanitize_frozen_child_environment(env, frozen=True, bundle_dir=MEIPASS))

        self.assertFalse(MODULE.sanitize_frozen_child_environment(env, frozen=True, bundle_dir=MEIPASS))
        self.assertEqual(env, {"LD_LIBRARY_PATH": "/opt/vendor/lib"})

    def test_frozen_only_strips_value_that_starts_with_bundle_dir(self):
        env = {"LD_LIBRARY_PATH": "/opt/vendor/lib"}

        self.assertFalse(MODULE.sanitize_frozen_child_environment(env, frozen=True, bundle_dir=MEIPASS))
        self.assertEqual(env, {"LD_LIBRARY_PATH": "/opt/vendor/lib"})

        env = {"LD_LIBRARY_PATH": MEIPASS}
        self.assertTrue(MODULE.sanitize_frozen_child_environment(env, frozen=True, bundle_dir=MEIPASS))
        self.assertEqual(env, {})

    def test_not_frozen_leaves_environment_untouched(self):
        env = {
            "PATH": "/usr/bin",
            "LD_LIBRARY_PATH": "/opt/vendor/lib",
            "LD_LIBRARY_PATH_ORIG": "/something/else",
            **BOOTLOADER_ENV,
        }
        before = dict(env)

        self.assertFalse(MODULE.sanitize_frozen_child_environment(env, frozen=False))

        self.assertEqual(env, before)

    def test_defaults_follow_sys_frozen_and_os_environ(self):
        patched = {"LD_LIBRARY_PATH": MEIPASS, "LD_LIBRARY_PATH_ORIG": "/opt/vendor/lib", **BOOTLOADER_ENV}
        with mock.patch.dict(os.environ, patched):
            with mock.patch.object(sys, "frozen", False, create=True):
                self.assertFalse(MODULE.sanitize_frozen_child_environment())
                self.assertEqual(os.environ["LD_LIBRARY_PATH"], MEIPASS)
            with mock.patch.object(sys, "frozen", True, create=True):
                self.assertTrue(MODULE.sanitize_frozen_child_environment())
            self.assertEqual(os.environ["LD_LIBRARY_PATH"], "/opt/vendor/lib")
            self.assertNotIn("LD_LIBRARY_PATH_ORIG", os.environ)
            for name in BOOTLOADER_ENV:
                self.assertNotIn(name, os.environ)

    def test_children_spawned_after_sanitize_inherit_clean_environment(self):
        patched = {"LD_LIBRARY_PATH": MEIPASS, **BOOTLOADER_ENV}
        with mock.patch.dict(os.environ, patched):
            os.environ.pop("LD_LIBRARY_PATH_ORIG", None)
            with mock.patch.object(sys, "frozen", True, create=True):
                MODULE.sanitize_frozen_child_environment()
            # Same inheritance path as the sidecar's subprocess.run("systemctl ...").
            result = subprocess.run(
                [sys.executable, "-c", "import json, os; print(json.dumps(dict(os.environ)))"],
                text=True,
                capture_output=True,
                check=True,
            )
        child_env = json.loads(result.stdout)
        self.assertNotIn("LD_LIBRARY_PATH", child_env)
        for name in BOOTLOADER_ENV:
            self.assertNotIn(name, child_env)

    def test_main_sanitizes_before_any_command_runs(self):
        seen = {}

        class FakeAgent:
            def __init__(self, _config_path):
                seen["ld_library_path"] = os.environ.get("LD_LIBRARY_PATH")

            def check(self):
                return {"ok": True}

        with mock.patch.dict(os.environ, {"LD_LIBRARY_PATH": MEIPASS}):
            os.environ.pop("LD_LIBRARY_PATH_ORIG", None)
            with mock.patch.object(sys, "frozen", True, create=True),                     mock.patch.object(sys, "argv", ["leikwan-sidecar-agent", "check"]),                     mock.patch.object(MODULE, "SidecarAgent", FakeAgent),                     mock.patch("builtins.print"):
                self.assertEqual(MODULE.main(), 0)
        self.assertIsNone(seen["ld_library_path"])

    def test_binary_entry_sanitizes_before_dispatch(self):
        source = (REPO_ROOT / "sidecar-agent" / "leikwan_sidecar_binary.py").read_text(encoding="utf-8")
        main_body = source.split("def main() -> int:", 1)[1]
        sanitize_at = main_body.index("_sidecar_agent.sanitize_frozen_child_environment()")
        self.assertLess(sanitize_at, main_body.index("_dispatch(alias)"))
        self.assertLess(sanitize_at, main_body.index("_sidecar_agent.main()"))

    def test_every_frozen_entry_point_sanitizes(self):
        # scripts/build-python-agent-binaries.sh freezes exactly these entries;
        # a new frozen binary must call the sanitizer too.
        build_script = (REPO_ROOT / "scripts" / "build-python-agent-binaries.sh").read_text(encoding="utf-8")
        entries = {
            "client-agent/leikwan_client_binary.py": "_client_agent.sanitize_frozen_child_environment()",
            "sidecar-agent/leikwan_sidecar_binary.py": "_sidecar_agent.sanitize_frozen_child_environment()",
        }
        built = set(re.findall(r'^\s+"([A-Za-z0-9_./-]+\.py)" "[A-Za-z0-9_./-]+" \\\s*$', build_script, re.MULTILINE))
        self.assertEqual(built, set(entries))
        for relative_path, call in entries.items():
            source = (REPO_ROOT / relative_path).read_text(encoding="utf-8")
            self.assertIn(call, source.split("def main() -> int:", 1)[1], relative_path)

if __name__ == "__main__":
    unittest.main()
