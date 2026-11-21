import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_frozen_env", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)

MEIPASS = "/tmp/_MEIabc123"
BOOTLOADER_ENV = {
    "_PYI_ARCHIVE_FILE": "/usr/local/bin/leikwan-client-agent",
    "_PYI_APPLICATION_HOME_DIR": MEIPASS,
    "_PYI_PARENT_PROCESS_LEVEL": "1",
    "_PYI_LINUX_PROCESS_NAME": "leikwan-client-agent",
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
        # leikwan_client_binary.main() and client_agent.main() both call it; the
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
            # Same inheritance path as the agent's subprocess.run("systemctl ...").
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

            def check(self, live=False):
                return {"ok": True}

        with mock.patch.dict(os.environ, {"LD_LIBRARY_PATH": MEIPASS}):
            os.environ.pop("LD_LIBRARY_PATH_ORIG", None)
            with mock.patch.object(sys, "frozen", True, create=True), \
                    mock.patch.object(sys, "argv", ["leikwan-client-agent", "check"]), \
                    mock.patch.object(MODULE, "ClientAgent", FakeAgent), \
                    mock.patch("builtins.print"):
                self.assertEqual(MODULE.main(), 0)
        self.assertIsNone(seen["ld_library_path"])

    def test_binary_entry_sanitizes_before_dispatch(self):
        source = (ROOT / "client-agent" / "leikwan_client_binary.py").read_text(encoding="utf-8")
        main_body = source.split("def main() -> int:", 1)[1]
        sanitize_at = main_body.index("_client_agent.sanitize_frozen_child_environment()")
        self.assertLess(sanitize_at, main_body.index("_dispatch(alias)"))
        self.assertLess(sanitize_at, main_body.index("_client_agent.main()"))


if __name__ == "__main__":
    unittest.main()
