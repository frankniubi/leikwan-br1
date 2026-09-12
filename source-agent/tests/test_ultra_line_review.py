"""Tests for the source-host line review probe.

The probe decides whether a quarantined line is really dead, so its failure
paths matter more than its happy path: an unreachable relay, a bad argument or a
line that is not live must all report passed=false rather than raise.
"""
import json
import pathlib
import subprocess
import sys

import pytest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROBE = ROOT / "ultra_line_review.py"

sys.path.insert(0, str(ROOT))
import ultra_line_review as probe  # noqa: E402


def test_parse_relay_accepts_host_port():
    assert probe.parse_relay("1.2.3.4:5438") == ("1.2.3.4", 5438)


@pytest.mark.parametrize("value", ["1.2.3.4", "1.2.3.4:", ":5438", "1.2.3.4:abc"])
def test_parse_relay_rejects_malformed_values(value):
    with pytest.raises(Exception):
        probe.parse_relay(value)


def test_probe_reports_failure_for_unreachable_relay():
    completed = subprocess.run(
        [
            sys.executable,
            str(PROBE),
            "--relay", "127.0.0.1:1",
            "--user", "USER1",
            "--password", "PASS1",
            "--seconds", "5",
            "--json",
        ],
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    lines = [line for line in completed.stdout.strip().splitlines() if line.strip()]
    assert lines, completed.stderr
    payload = json.loads(lines[-1])
    assert payload["passed"] is False
    assert payload["relay"] == "127.0.0.1:1"
    assert payload["last_error"], "a failed review must explain itself"
    assert completed.returncode == 1, "a failed review must exit non-zero"


def test_review_reports_rounds_and_counters():
    """The result shape the panel stores has to stay stable."""
    result = probe.review(("127.0.0.1", 1), "USER1", "PASS1", 5, interval=1.0, big_bytes=64,
                          require_big_packet=False, auth_timeout=1.0)
    for field in ("passed", "relay", "rounds", "udp_ok", "udp_failed", "dns_ok", "dns_failed",
                  "max_echo_bytes", "last_error"):
        assert field in result, field
    assert result["passed"] is False