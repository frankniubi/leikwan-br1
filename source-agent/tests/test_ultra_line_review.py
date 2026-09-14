"""Tests for the source-host line review probe.

The probe decides whether a quarantined line is really dead, so its failure paths
matter more than its happy path: an unreachable relay, a bad argument or a line
that is not live must all report passed=false rather than raise.

The tests that matter most here are the ones that pin what a PASS means. The old
verdict rode on relay-local echoes and an "81 80 appears somewhere" DNS check, and
a relay that accepts a handshake and then sends nothing downstream passed it. The
probe now has to move real bytes to the client's target over the client's stream
path, so the fakes below include a relay that stays live, answers its own keep
echo, answers DNS through the relay, and then returns ZERO downstream bytes for
the target - the field fault - and the review must not pass it.
"""
import ipaddress
import json
import pathlib
import socket
import struct
import subprocess
import sys
import threading
import time

import pytest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROBE = ROOT / "ultra_line_review.py"
FIXTURE = pathlib.Path(__file__).resolve().parent / "fixtures" / "review_proof_healthy.json"

sys.path.insert(0, str(ROOT))
import ultra_line_review as probe  # noqa: E402

ADVERTISED = ["1.117.238.119", "121.5.240.89"]
ADVERTISED_RELAY = ("1.117.238.119", 5438)
TARGET = "45.143.235.221:10809"
PASSWORD = "1234567890123456"


def _dns_answer(query: bytes, framed: bool = False) -> bytes:
    """A real answer to the query, with the identifier the caller asked with."""
    identifier = struct.unpack("!H", query[:2])[0]
    question = query[12:]
    answer = (
        struct.pack("!6H", identifier, 0x8180, 1, 1, 0, 0)
        + question
        + b"\xc0\x0c"
        + struct.pack("!HHIH", 1, 1, 60, 4)
        + socket.inet_aton("93.184.216.34")
    )
    if framed:
        return b"\x01" + socket.inet_aton("1.1.1.1") + struct.pack("!H", 53) + answer
    return answer


class _FakeUdpSocket:
    """The fake session's UDP socket: hands back whatever the session queued."""

    def __init__(self, session):
        self._session = session

    def settimeout(self, _value):
        return None

    def recvfrom(self, _count):
        if not self._session.replies:
            raise socket.timeout()
        return self._session.replies.pop(0), ("127.0.0.1", 1)


class _FakeSession:
    """A relay that stays live and answers only what the test says it answers.

    answer_target models the CLIENT'S TARGET answering over UDP. It is off by
    default because a target application is not obliged to answer an arbitrary
    datagram; the field fault is on the stream path, not here.
    """

    def __init__(
        self,
        relay,
        user,
        password,
        *,
        keep=True,
        echo_big=True,
        answer_dns=True,
        answer_target=False,
        answer_bare_target=False,
    ):
        self.relay = relay
        self.user = user
        self.password = password
        self.keep = keep
        self.echo_big = echo_big
        self.answer_dns = answer_dns
        self.answer_target = answer_target
        self.answer_bare_target = answer_bare_target
        self.replies = []
        self.sent = []
        self.udp = _FakeUdpSocket(self)
        self.arm_ms = 12.5

    def __enter__(self):
        return self

    def __exit__(self, *_exc):
        return False

    def arm(self, auth_timeout=5.0):
        return None

    def latency_ms(self, count=1):
        return 0.065 if self.keep else float("nan")

    def send(self, dst, payload):
        self.sent.append((tuple(dst), bytes(payload)))
        reply = self._reply(tuple(dst), bytes(payload))
        if reply is not None:
            envelope = (
                b"\x00\x00\x00\x01"
                + socket.inet_aton(dst[0])
                + struct.pack("!H", dst[1])
                + reply
            )
            self.replies.append(envelope)

    def _reply(self, dst, payload):
        if dst == probe.ECHO_TARGET:
            return payload if self.echo_big else None
        if dst == probe.DEFAULT_DNS_TARGET:
            return _dns_answer(payload) if self.answer_dns else None
        if dst == (TARGET.split(":")[0], int(TARGET.split(":")[1])):
            inner, framed = probe.strip_address_header(payload)
            if framed:
                return _dns_answer(inner, framed=True) if self.answer_target else None
            return _dns_answer(payload) if self.answer_bare_target else None
        return None


class _Clock:
    """A wall clock whose sleep() advances it, so a window costs no test time."""

    def __init__(self):
        self.now = 1_700_000_000.0

    def __call__(self):
        return self.now

    def sleep(self, seconds):
        self.now += max(0.001, float(seconds))


class _FakeRelayStreamServer:
    """A local stand-in for the relay's TCP stream listener.

    It speaks the handshake the client speaks (greeting, credentials, target
    address) so the probe's real stream code runs against it, and its behaviours
    are the ones that decide a review.
    """

    def __init__(self, behaviour="healthy", refuse_code=0x04, unparsed_bytes=b"\x04\x04\x01\x01"):
        self.behaviour = behaviour
        self.refuse_code = refuse_code
        self.unparsed_bytes = unparsed_bytes
        self.listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.listener.bind(("127.0.0.1", 0))
        self.listener.listen(8)
        self.host, self.port = self.listener.getsockname()
        self.stop = threading.Event()
        self.greetings = []
        self.addresses = []
        self.payloads = []
        self.thread = threading.Thread(target=self._serve, daemon=True)
        self.thread.start()

    def close(self):
        self.stop.set()
        try:
            self.listener.close()
        except OSError:
            pass

    def _serve(self):
        while not self.stop.is_set():
            try:
                conn, _ = self.listener.accept()
            except OSError:
                return
            threading.Thread(target=self._handle, args=(conn,), daemon=True).start()

    @staticmethod
    def _read_exact(conn, count):
        data = bytearray()
        while len(data) < count:
            chunk = conn.recv(count - len(data))
            if not chunk:
                raise ConnectionError("short read")
            data.extend(chunk)
        return bytes(data)

    def _read_handshake(self, conn):
        version = self._read_exact(conn, 1)
        if version != b"\x05":
            raise ConnectionError("bad greeting")
        second = self._read_exact(conn, 1)
        length = 6 if second == b"\x03" else 8
        greeting = version + second + self._read_exact(conn, length - 2)
        self.greetings.append(greeting.hex())
        user_length = self._read_exact(conn, 1)[0]
        self._read_exact(conn, user_length)
        password_length = self._read_exact(conn, 1)[0]
        self._read_exact(conn, password_length)
        self._read_exact(conn, 3)
        address_type = self._read_exact(conn, 1)[0]
        if address_type == 1:
            address = socket.inet_ntoa(self._read_exact(conn, 4))
        elif address_type == 4:
            address = socket.inet_ntop(socket.AF_INET6, self._read_exact(conn, 16))
        else:
            name_length = self._read_exact(conn, 1)[0]
            address = self._read_exact(conn, name_length).decode("idna")
        port = struct.unpack("!H", self._read_exact(conn, 2))[0]
        self.addresses.append((address, port))

    def _read_payload(self, conn):
        conn.settimeout(3.0)
        try:
            data = conn.recv(4096)
        except OSError:
            return b""
        if data:
            self.payloads.append(data)
        return data

    def _handle(self, conn):
        try:
            self._read_handshake(conn)
            if self.behaviour == "reject":
                conn.sendall(b"\x05\x02\x01\x01")
                return
            if self.behaviour == "modern":
                conn.sendall(b"\x00" * 8 + bytes([9, 0, 0, 0, 0, 1]))
            else:
                conn.sendall(b"\x05\x02\x01\x00")
            payload = self._read_payload(conn)
            # The client XORs every upstream stream byte with 1; the relay decodes.
            plain = probe.xor_one(payload)
            if self.behaviour == "silent":
                # The field fault: handshake ACKed, then zero downstream bytes.
                return
            if self.behaviour == "echo":
                conn.sendall(payload)
                return
            if self.behaviour == "dns":
                # No connect frame: the only evidence is the target's own answer.
                conn.sendall(_dns_answer(probe.strip_address_header(plain)[0], framed=True))
                return
            if self.behaviour == "unparsed_reply":
                # Measured live on 38.207.136.247:32469 through 113.31.111.9:5438:
                # four bytes whose second byte is 0x04, so it does NOT match the
                # documented 04 01 <rep> 00 reply shape. We do not know this format,
                # so it must be reported as unparsed and never as a silence.
                conn.sendall(self.unparsed_bytes)
                return
            if self.behaviour == "refuse_target":
                # What the live relay on 10.10.10.171 actually does when it cannot
                # reach the client's target: it answers with only the four-byte
                # reply-header prefix 04 01 <rep> 00 and closes. Measured on
                # 113.31.111.9:5438 and 113.31.106.203:5438, REP=0x04 host
                # unreachable, identical every round, ~1 s close.
                conn.sendall(bytes([0x04, 0x01, self.refuse_code, 0x00]))
                return
            conn.sendall(b"\x04\x01\x00\x00\x01" + b"\x00" * 5)
        except (OSError, ConnectionError):
            return
        finally:
            try:
                conn.close()
            except OSError:
                pass


@pytest.fixture
def relay_server():
    servers = []

    def start(behaviour="healthy"):
        server = _FakeRelayStreamServer(behaviour)
        servers.append(server)
        return server

    yield start
    for server in servers:
        server.close()


def stream_factory_for(server):
    def factory(relay, user, password, target, *, timeout, dns_target):
        return probe.probe_target_stream(
            (server.host, server.port), user, password, target, timeout=timeout, dns_target=dns_target
        )

    return factory


def run_review(session, server, **overrides):
    clock = overrides.pop("clock", None) or _Clock()
    kwargs = dict(
        relay=ADVERTISED_RELAY,
        user="USER1",
        password=PASSWORD,
        seconds=5,
        interval=1.0,
        big_bytes=64,
        require_big_packet=False,
        auth_timeout=1.0,
        target=TARGET,
        advertised=ADVERTISED,
        session_factory=lambda *args, **kwargs: session,
        stream_factory=stream_factory_for(server),
        clock=clock,
        sleep=clock.sleep,
    )
    kwargs.update(overrides)
    return probe.review(**kwargs)


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
            "--password", PASSWORD,
            "--seconds", "5",
            "--target", TARGET,
            "--advertised", ",".join(ADVERTISED),
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
    assert payload["proof"]["end_to_end_ok"] == 0
    assert payload["proof"]["local_path_only"] is True
    assert completed.returncode == 1, "a failed review must exit non-zero"


def test_review_reports_rounds_and_counters(relay_server):
    """The result shape the panel stores has to stay stable."""
    server = relay_server("silent")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result = run_review(session, server)
    for field in ("passed", "relay", "rounds", "udp_ok", "udp_failed", "dns_ok", "dns_failed",
                  "max_echo_bytes", "last_error", "target", "end_to_end_ok", "local_path_only",
                  "target_tcp_ok", "target_tcp_failed", "target_tcp_zero_downstream", "proof"):
        assert field in result, field
    assert result["passed"] is False
    assert result["rounds"] > 0
    assert result["target_tcp_zero_downstream"] == result["rounds"]


def test_relay_local_only_path_cannot_pass(relay_server):
    """The field fault: live association, keep echo, DNS answer, zero downstream."""
    server = relay_server("silent")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result = run_review(session, server)
    assert result["udp_failed"] == 0 and result["udp_ok"] > 0, "the association itself was live"
    assert result["big_failed"] == 0 and result["big_ok"] > 0, "the relay echoed its own keep"
    assert result["dns_ok"] > 0, "the relay carried a real DNS round trip"
    assert result["target_tcp_ok"] == 0
    assert result["end_to_end_ok"] == 0
    assert result["local_path_only"] is True
    assert result["passed"] is False, "a relay-local echo must not pass a review"
    assert result["last_error"]


def test_healthy_end_to_end_path_passes(relay_server):
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server)
    assert result["target_tcp_ok"] == result["rounds"] > 0
    assert result["target_tcp_failed"] == 0
    assert result["target_tcp_frame_ok"] == result["rounds"], "the relay reported a successful connect"
    assert result["target_udp_ok"] == result["rounds"], "the target answered over UDP too"
    assert result["end_to_end_ok"] >= 1
    assert result["local_path_only"] is False
    assert result["passed"] is True
    assert result["proof"]["end_to_end_ok"] >= 1
    assert result["proof"]["local_path_only"] is False
    assert server.addresses, "the stream must name a destination"
    assert server.addresses[0] == ("45.143.235.221", 10809), "the stream must name the CLIENT'S target"


def test_endpoint_outside_the_advertised_set_is_rejected(relay_server):
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server, relay=("9.9.9.9", 5438))
    assert result["advertised_ok"] is False
    assert result["passed"] is False, "an endpoint the panel never advertised cannot pass"
    assert result["proof"]["end_to_end_ok"] == 0, "its evidence is not proof about this candidate"
    assert result["proof"]["local_path_only"] is True
    assert result["proof"]["reason"] == "relay_not_advertised"
    assert result["last_error"] == "relay_not_advertised"


def test_empty_target_cannot_produce_a_pass(relay_server):
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server, target="")
    assert result["target_tcp_ok"] == 0
    assert result["target_tcp_failed"] == 0
    assert result["end_to_end_ok"] == 0
    assert result["local_path_only"] is True
    assert result["passed"] is False, "an unknown zone must not manufacture a pass"
    assert result["proof"]["reason"] == "no_client_target"
    assert result["last_error"]


def test_malformed_target_cannot_produce_a_pass(relay_server):
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server, target="not-an-endpoint")
    assert result["passed"] is False
    assert result["end_to_end_ok"] == 0
    assert result["last_error"].startswith("target_invalid")


def test_big_packet_failures_only_sink_the_review_when_required(relay_server):
    """A large relay-local payload may be dropped by the provider path."""
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", echo_big=False, answer_target=True)

    lenient = run_review(session, server, require_big_packet=False)
    assert lenient["udp_failed"] == 0, "a dropped large payload is not a liveness failure"
    assert lenient["big_failed"] > 0
    assert lenient["passed"] is True, "a large payload may be dropped by the provider path"

    strict = run_review(session, server, require_big_packet=True)
    assert strict["passed"] is False, "with require_big_packet the drop must sink the review"


def test_keep_echo_failures_sink_the_review(relay_server):
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", keep=False, answer_target=True)
    result = run_review(session, server)
    assert result["udp_failed"] > 0
    assert result["passed"] is False, "a line that stops answering is not usable"


def test_cli_exposes_the_flags_the_agent_passes():
    completed = subprocess.run(
        [sys.executable, str(PROBE), "--help"],
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    assert completed.returncode == 0
    for flag in ("--relay", "--user", "--password", "--seconds", "--json", "--target", "--advertised"):
        assert flag in completed.stdout, flag


def test_dns_validator_requires_a_real_answer():
    query = probe.build_dns_query(0x1234)
    assert probe.validate_dns_answer(_dns_answer(query), 0x1234) is True
    assert probe.validate_dns_answer(query, 0x1234) is False, "our own query is not an answer"
    assert probe.validate_dns_answer(_dns_answer(query), 0x4321) is False, "the id must match"
    assert probe.validate_dns_answer(b"\x81\x80" + query[2:], 0x1234) is False, (
        "81 80 somewhere in the bytes is not an answer"
    )
    nxdomain = bytearray(_dns_answer(query))
    nxdomain[3] = 0x83
    assert probe.validate_dns_answer(bytes(nxdomain), 0x1234) is False, "RCODE 3 is not usable"
    assert probe.validate_dns_answer(b"", 0x1234) is False


def test_dns_check_rejects_an_appears_somewhere_answer():
    """The old acceptance test, verbatim: 81 80 present, nothing else checked."""

    class _Weak(_FakeSession):
        def _reply(self, dst, payload):
            if dst == probe.DEFAULT_DNS_TARGET:
                return b"\x81\x80" + payload[2:]
            return super()._reply(dst, payload)

    session = _Weak(ADVERTISED_RELAY, b"u", b"p")
    assert probe.probe_dns(session, 0.5) is False


def test_dns_answer_must_come_from_the_queried_resolver():
    class _Spoofed(_FakeSession):
        def send(self, dst, payload):
            if tuple(dst) != probe.DEFAULT_DNS_TARGET:
                return super().send(dst, payload)
            self.sent.append((tuple(dst), bytes(payload)))
            self.replies.append(
                b"\x00\x00\x00\x01" + socket.inet_aton("2.0.0.0") + struct.pack("!H", 0) + _dns_answer(payload)
            )

    session = _Spoofed(ADVERTISED_RELAY, b"u", b"p")
    assert probe.probe_dns(session, 0.5) is False, "a reply from somewhere else is not the resolver's answer"


def test_down_parser_survives_a_stale_native_udp_module(monkeypatch):
    """The host's native_udp.py may predate unwrap_down; the probe must still work."""
    envelope = (
        b"\x00\x00\x00\x01" + socket.inet_aton("45.143.235.221") + struct.pack("!H", 10809) + b"payload"
    )
    assert probe.unwrap_down(envelope) == (("45.143.235.221", 10809), b"payload")
    assert probe.unwrap_down(b"") is None
    assert probe.unwrap_down(b"\x01\x02\x03") is None
    monkeypatch.delattr(probe.N, "unwrap_down", raising=False)
    assert probe.parse_down(envelope) == (("45.143.235.221", 10809), b"payload")


def test_target_udp_accepts_a_bare_answer():
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_bare_target=True)
    assert probe.probe_target_udp(session, ("45.143.235.221", 10809), 0.5) is True


def test_target_udp_is_silent_without_an_answer():
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    assert probe.probe_target_udp(session, ("45.143.235.221", 10809), 0.5) is False


def test_stream_probe_reads_the_connect_frame_from_a_healthy_relay(relay_server):
    server = relay_server("healthy")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=2.0
    )
    assert outcome.ok is True
    assert outcome.connect_frame is True
    assert outcome.connect_frame_rep == 0
    assert outcome.downstream_bytes == 10
    assert outcome.greeting == "legacy"
    assert server.greetings == [probe.LEGACY_STREAM_GREETING.hex()]
    assert server.addresses == [("45.143.235.221", 10809)]


def test_stream_probe_rejects_a_relay_that_sends_nothing(relay_server):
    """The field fault, at the wire level: ACK, payload accepted, zero bytes back."""
    server = relay_server("silent")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=0.5
    )
    assert outcome.ok is False
    assert outcome.downstream_bytes == 0
    assert "no_downstream_bytes" in outcome.error


def test_stream_probe_names_a_truncated_connect_refusal(relay_server):
    """A refusal is NOT a silence, and the stored result must say which it was.

    Measured on the live source host: the relay answers the target-connect with a
    four-byte reply header whose third byte is the code, then closes. The probe
    used to require a full ten-byte frame, so it never read the code, fell through
    to a generic downstream check, and reported the round as "no_downstream_bytes"
    while target_tcp_zero_downstream stayed at 0 - which the operator note tells a
    human to read as the fault signature, so a refusal read as clean.
    """
    server = relay_server("refuse_target")  # refuse_code defaults to 0x04
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=2.0
    )
    assert outcome.ok is False
    assert outcome.downstream_bytes == 4
    assert outcome.connect_frame_rep == 0x04, "the reply code must be decoded"
    assert outcome.target_refused is True
    assert "target_refused" in outcome.error
    assert "host_unreachable" in outcome.error
    assert "no_downstream_bytes" not in outcome.error, (
        "a refusal must not be reported as a silence - they are different faults"
    )


def test_a_refusal_is_counted_separately_from_a_silence(relay_server):
    """target_tcp_zero_downstream must mean silence, and only silence."""
    refused = relay_server("refuse_target")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result = run_review(session, refused)
    assert result["target_tcp_refused"] == result["rounds"] > 0
    assert result["target_tcp_zero_downstream"] == 0, "a refusal is not a zero-byte silence"
    assert result["target_tcp_refused_code"] == 0x04
    assert result["target_tcp_refused_meaning"] == "host_unreachable"
    assert result["proof"]["tcp_refused"] == result["rounds"]
    assert result["proof"]["tcp_refused_reason"] == "host_unreachable"
    assert result["passed"] is False

    silent = relay_server("silent")
    session2 = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result2 = run_review(session2, silent)
    assert result2["target_tcp_zero_downstream"] == result2["rounds"] > 0
    assert result2["target_tcp_refused"] == 0, "a silence must not be reported as a refusal"
    assert result2["passed"] is False


def test_an_unparsed_reply_is_not_a_silence(relay_server):
    """An unknown reply shape must be reported as unparsed, with its raw bytes.

    Live on 38.207.136.247:32469 the relay returned only 04 04 01 01 - four bytes
    whose second byte is 0x04, so it is NOT the documented 04 01 <rep> 00 shape.
    We do not know that format and must not guess one. What we must not do is call
    it "no downstream bytes": an unparsed refusal and a genuine silence are
    different facts, and collapsing them is what hid this fault for a night.
    """
    server = relay_server("unparsed_reply")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=2.0
    )
    assert outcome.ok is False
    assert outcome.downstream_bytes == 4
    assert outcome.connect_frame_rep is None, "we do not know this format; do not invent a code"
    assert outcome.target_refused is False
    assert outcome.reply_unparsed is True
    assert "unparsed_reply" in outcome.error
    assert "04040101" in outcome.error, "the raw bytes must travel with the verdict"
    assert "no_downstream_bytes" not in outcome.error


def test_an_unparsed_reply_is_counted_apart_from_a_silence(relay_server):
    server = relay_server("unparsed_reply")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result = run_review(session, server)
    assert result["target_tcp_reply_unparsed"] == result["rounds"] > 0
    assert result["target_tcp_zero_downstream"] == 0, "an unparsed reply is not a silence"
    assert result["target_tcp_refused"] == 0, "an unparsed reply is not a decoded refusal"
    assert result["proof"]["tcp_reply_unparsed"] == result["rounds"]
    assert result["proof"]["tcp_unparsed_sample"] == "04040101"
    assert result["passed"] is False

    silent = relay_server("silent")
    session2 = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result2 = run_review(session2, silent)
    assert result2["target_tcp_zero_downstream"] == result2["rounds"] > 0
    assert result2["target_tcp_reply_unparsed"] == 0


def test_stream_probe_does_not_count_an_echo_as_the_target(relay_server):
    server = relay_server("echo")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=0.5
    )
    assert outcome.echo is True
    assert outcome.ok is False, "our own bytes coming back are a local path, not a target"


def test_stream_probe_counts_a_target_dns_answer(relay_server):
    server = relay_server("dns")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=2.0
    )
    assert outcome.dns_answer is True
    assert outcome.connect_frame is False
    assert outcome.ok is True, "a target's own answer is evidence without any relay frame"


def test_stream_probe_accepts_the_modern_prelude(relay_server):
    server = relay_server("modern")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=2.0
    )
    assert outcome.greeting == "modern"
    assert outcome.ok is True


def test_stream_probe_reports_a_refused_handshake(relay_server):
    server = relay_server("reject")
    outcome = probe.probe_target_stream(
        (server.host, server.port), "USER1", PASSWORD, ("45.143.235.221", 10809), timeout=1.0
    )
    assert outcome.ok is False
    assert "authentication_rejected" in outcome.error
    assert len(server.greetings) == 2, "both greetings must be attempted"


def test_echoing_relay_cannot_pass(relay_server):
    server = relay_server("echo")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p")
    result = run_review(session, server)
    assert result["target_tcp_echo_rounds"] == result["rounds"] > 0
    assert result["end_to_end_ok"] == 0
    assert result["passed"] is False


def test_udp_target_evidence_alone_cannot_pass(relay_server):
    """Real evidence for the target, but not on the path the field fault breaks."""
    server = relay_server("silent")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server)
    assert result["target_udp_ok"] == result["rounds"] > 0
    assert result["target_tcp_ok"] == 0
    assert result["end_to_end_ok"] >= 1
    assert result["local_path_only"] is False
    assert result["passed"] is False, "the stream path is the one that must carry the transfer"


def gate_accepts(proof, advertised):
    """A faithful mirror of UltraCandidateReviewService::reviewProof()."""
    if not isinstance(proof, dict):
        return False
    endpoint = str(proof.get("endpoint", "")).strip().lower()
    host = endpoint.split(":", 1)[0] if ":" in endpoint else endpoint
    if not advertised:
        return False
    try:
        ipaddress.ip_address(host)
    except ValueError:
        return False
    if host not in [str(ip).strip().lower() for ip in advertised]:
        return False
    local = proof.get("local_path_only", True)  # the gate defaults to local-only
    if local is True or local in (1, "1", "true", "yes", "on"):
        return False
    try:
        end_to_end = int(proof.get("end_to_end_ok", 0))
    except (TypeError, ValueError):
        return False
    return end_to_end >= 1


def test_proof_block_satisfies_the_live_gate_contract(relay_server):
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server)
    proof = result["proof"]
    assert proof["endpoint"] == "1.117.238.119:5438"
    assert proof["end_to_end_ok"] >= 1
    assert proof["local_path_only"] is False
    assert gate_accepts(proof, ADVERTISED) is True
    # The agent posts this through JSON; a bool must survive the round trip.
    assert gate_accepts(json.loads(json.dumps(proof)), ADVERTISED) is True
    # The gate only acts on this exact shape; every other shape must be refused.
    assert gate_accepts(dict(proof, local_path_only=True), ADVERTISED) is False
    assert gate_accepts(dict(proof, end_to_end_ok=0), ADVERTISED) is False
    assert gate_accepts(dict(proof, endpoint="113.31.108.186:5438"), ADVERTISED) is False
    assert gate_accepts(dict(proof, endpoint=""), ADVERTISED) is False
    assert gate_accepts({key: value for key, value in proof.items() if key != "local_path_only"}, ADVERTISED) is False
    assert gate_accepts(proof, []) is False


def test_the_healthy_proof_block_is_pinned_for_the_panel_gate_fixture(relay_server):
    """The committed fixture is what master/tests runs through the real PHP gate."""
    server = relay_server("healthy")
    session = _FakeSession(ADVERTISED_RELAY, b"u", b"p", answer_target=True)
    result = run_review(session, server)
    payload = {"advertised": ADVERTISED, "target": TARGET, "proof": result["proof"]}
    FIXTURE.parent.mkdir(parents=True, exist_ok=True)
    if not FIXTURE.is_file():
        FIXTURE.write_text(
            json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8", newline="\n"
        )
    stored = json.loads(FIXTURE.read_text(encoding="utf-8"))
    assert stored == payload, "the committed gate fixture drifted from what the probe emits"
    assert gate_accepts(stored["proof"], stored["advertised"]) is True
