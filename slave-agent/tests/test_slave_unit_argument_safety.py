from __future__ import annotations

import importlib.util
import pathlib
import sys
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "slave-agent" / "slave_agent.py"
SPEC = importlib.util.spec_from_file_location("slave_agent_unit_argument_safety", MODULE_PATH)
slave_agent = importlib.util.module_from_spec(SPEC)
assert SPEC is not None and SPEC.loader is not None
sys.modules[SPEC.name] = slave_agent
SPEC.loader.exec_module(slave_agent)


class SlaveUnitArgumentSafetyTests(unittest.TestCase):
    def test_shell_arg_rejects_ascii_control_bytes(self) -> None:
        for codepoint in [*range(0x00, 0x20), 0x7F]:
            with self.subTest(codepoint=codepoint):
                value = f"prefix{chr(codepoint)}suffix"
                with self.assertRaisesRegex(RuntimeError, "unsafe unit argument"):
                    slave_agent.shell_arg(value)

    def test_shell_arg_preserves_normal_network_and_path_values(self) -> None:
        self.assertEqual('"10.0.0.5:443"', slave_agent.shell_arg("10.0.0.5:443"))
        self.assertEqual('"[2001:db8::5]:443"', slave_agent.shell_arg("[2001:db8::5]:443"))
        self.assertEqual('"edge.example.test"', slave_agent.shell_arg("edge.example.test"))
        self.assertEqual(
            '"C:\\\\Program Files\\\\leikwan\\\\unit"',
            slave_agent.shell_arg(r"C:\Program Files\leikwan\unit"),
        )


if __name__ == "__main__":
    unittest.main()
