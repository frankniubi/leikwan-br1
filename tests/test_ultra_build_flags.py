import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
BUILD_SCRIPTS = [
    "scripts/build-ultra-linux.ps1",
    "scripts/build-ultra-macos.ps1",
    "scripts/build-ultra-windows.ps1",
    "scripts/build-ultra-android.ps1",
    "scripts/build-macos-client.sh",
]


class UltraRuntimeBuildFlagTests(unittest.TestCase):
    def test_garble_never_uses_tiny(self):
        # garble v0.17.0 -tiny produced a Linux runtime (2026.10.02.1) that
        # crashed every 15-90 s under external-UoT UDP load; -literals alone
        # ran clean on the same source. -tiny also suppresses panic output.
        for relative in BUILD_SCRIPTS:
            text = (ROOT / relative).read_text(encoding="utf-8")
            with self.subTest(script=relative):
                self.assertIn("-literals", text)
                self.assertIsNone(re.search(r"garble[^\n]*\s-tiny\b|-literals\s+-tiny\b", text),
                                  relative + " passes -tiny to garble")


if __name__ == "__main__":
    unittest.main()
