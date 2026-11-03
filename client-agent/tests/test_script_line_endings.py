"""Packaging guardrails for scripts that are executed directly on Linux hosts."""

from __future__ import annotations

import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class ScriptLineEndingTests(unittest.TestCase):
    def test_linux_entrypoints_do_not_have_crlf_shebangs(self) -> None:
        """A CRLF shebang makes /usr/bin/env look for ``python3\r``."""

        scripts = [
            REPO_ROOT / "client-agent" / "client_agent.py",
            REPO_ROOT / "client-agent" / "install_forward_runtime.py",
        ]
        # Release mirrors are git-ignored and only exist on the release
        # machine; check them there, but never let their absence fail CI.
        release_mirrors = [
            REPO_ROOT / "artifacts" / "client-agent.py",
            REPO_ROOT / "artifacts" / "install-forward-runtime.py",
        ]
        scripts.extend(mirror for mirror in release_mirrors if mirror.is_file())

        offenders: list[str] = []
        for script in scripts:
            first_line = script.read_bytes().splitlines(keepends=True)[0]
            if first_line.endswith(b"\r\n"):
                offenders.append(str(script.relative_to(REPO_ROOT)))

        self.assertEqual([], offenders)
