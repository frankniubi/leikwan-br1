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

class _FakeSession:
    """A relay that stays live and answers keep echoes, but drops large payloads."""

    def __init__(self, *args, **kwargs):
        self.arm_ms = 1.0

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def arm(self, auth_timeout=5.0):
        return None

    def latency_ms(self, count=1):
        return 1.0


def test_big_packet_failures_only_sink_the_review_when_required(monkeypatch):
    monkeypatch.setattr(probe.N, "NativeSession", _FakeSession)
    monkeypatch.setattr(probe, "probe_udp_big", lambda session, size, timeout: (False, 0))
    monkeypatch.setattr(probe, "probe_dns", lambda session, timeout: True)

    lenient = probe.review(("127.0.0.1", 1), "USER1", "PASS1", 3, interval=1.0, big_bytes=1400,
                           require_big_packet=False, auth_timeout=1.0)
    assert lenient["udp_failed"] == 0, "a dropped large payload is not a liveness failure"
    assert lenient["big_failed"] > 0
    assert lenient["passed"] is True, "a large payload may be dropped by the provider path"

    strict = probe.review(("127.0.0.1", 1), "USER1", "PASS1", 3, interval=1.0, big_bytes=1400,
                          require_big_packet=True, auth_timeout=1.0)
    assert strict["passed"] is False, "with require_big_packet the drop must sink the review"


def test_keep_echo_failures_sink_the_review(monkeypatch):
    class Dead(_FakeSession):
        def latency_ms(self, count=1):
            return float("nan")

    monkeypatch.setattr(probe.N, "NativeSession", Dead)
    monkeypatch.setattr(probe, "probe_udp_big", lambda session, size, timeout: (True, size))
    monkeypatch.setattr(probe, "probe_dns", lambda session, timeout: True)

    result = probe.review(("127.0.0.1", 1), "USER1", "PASS1", 3, interval=1.0, big_bytes=1400,
                          require_big_packet=False, auth_timeout=1.0)
    assert result["udp_failed"] > 0
    assert result["passed"] is False, "a line that stops answering is not usable"
