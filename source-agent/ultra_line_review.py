#!/usr/bin/env python3
"""ultra_line_review - decide whether a line the panel quarantined is actually dead.

The panel quarantines a candidate from client reports, and a client on a broken
local network reports healthy lines as dead. This runs on the source host, which
sees the line from the provider side.

WHAT COUNTS AS EVIDENCE (the part that changed)

The old probe's verdict rode on two measurements that never leave the relay:

  udp - a datagram echoed back by the relay itself (the keep echo and the
        large-payload echo to the relay-local ECHO_TARGET 2.0.0.0:0);
  dns - a query through the relay to a public resolver, accepted as soon as the
        bytes 81 80 appeared somewhere in some datagram.

Both are compatible with a relay that answers its own echo and delivers nothing
to a client - which is exactly the fault this probe exists to find. The client's
streams to some relays are accepted (the greeting is ACKed) and then zero
downstream bytes arrive before the relay closes. A pass built on relay-local
echoes is therefore close to uninformative about the client's data path, and it
is no longer sufficient for one. The evidence ladder is now:

  diagnostics - recorded, never sufficient on their own
    * keep echo and large-payload echo to 2.0.0.0:0: relay-local by definition;
    * a strictly validated DNS answer through the relay from a real resolver
      (transaction id, response bit, RCODE 0, the question that was asked).
      This does prove the relay carries real downstream bytes, but not to the
      destination the failing clients were reaching;
    * a datagram sent to the client's target (both the bare query and the
      address-header-framed one), counted only on a strictly validated answer.

  verdict - required for passed=true
    * the relay greeting is ACKed on a freshly opened TCP stream, and
    * the stream names the CLIENT'S TARGET as its destination, and
    * real downstream bytes arrive for that stream, in every round of the
      window.

That last one is the same path and the same wire shape the client's failing
streams use (relay greeting -> auth ACK -> target connect -> downstream data), so
"the relay accepted the handshake and sent nothing back" fails the review instead
of passing it. A round whose downstream bytes are an echo of the bytes the probe
itself sent does not count either: that is a local path, not a target.

The advertised relay set and the client target are handed down by the panel. The
panel stores relay IPs only, so the probe pairs an advertised IP with the port
from the line material it acquired. An endpoint outside the advertised set cannot
produce a discriminating proof, and an empty target (unknown zone, or a zone with
no live forward) cannot produce a pass at all.

The probe reports what it saw and lets the panel decide; it never talks to the
panel itself.

Usage:
  ultra_line_review.py --relay 1.2.3.4:5438 --user U --password P --seconds 600 --target 45.143.235.221:10809 --advertised 1.2.3.4,5.6.7.8 --json

Exit code is 0 only for a pass.
"""
from __future__ import annotations

import argparse
import dataclasses
import json
import socket
import struct
import time
from typing import Callable, Optional, Sequence, Tuple

import native_udp as N

Addr = Tuple[str, int]

# The relay-local echo target: the relay itself answers here, so it is diagnostic
# evidence about the association and never evidence about a client's data path.
ECHO_TARGET: Addr = ("2.0.0.0", 0)

# The public resolver used for the strict through-the-relay round trip.
DEFAULT_DNS_TARGET: Addr = ("1.1.1.1", 53)
DNS_NAME = ("example", "com")
DNS_TYPE_A = 1
DNS_CLASS_IN = 1
# Kept because the weak check this constant belonged to produced the historical
# results; the strict check below is what the probe now uses.
DNS_ANSWER_FLAG = b"\x81\x80"

# The relay's TCP stream greeting ("buildHandshakeWithGreeting", tools/ultra-client
# main.go:158-178). The client prefers the legacy three-method greeting for a
# fixed-target stream and falls back to the modern one, so the probe does the same.
LEGACY_STREAM_GREETING = bytes([0x05, 0x03, 0x02, 0x80, 0x83, 0x01])
MODERN_STREAM_GREETING = bytes([0x05, 0x05, 0x00, 0x01, 0x02, 0x80, 0x83, 0x01])
STREAM_COMMAND_TARGET_CONNECT = 0x01
SOCKS_VERSION = 0x05
# 05 02 01 00: the relay accepted the greeting and the credentials.
STREAM_AUTH_ACK = b"\x05\x02\x01\x00"
# The modern relay answers with eight zero bytes plus six bytes of stream
# metadata instead of the SOCKS-shaped ACK.
STREAM_PRELUDE_SIZE = 14
# The relay's target-connect result frame (04 01 <rep> 00 01 <bound addr>).
CONNECT_FRAME_SIZE = 10
# The relay may answer with only the reply header and no bound address - that is
# what the live relay on the source host does when it cannot reach the target
# (measured: exactly four bytes, 04 01 04 00, then close). The refusal is still
# the relay's verdict about the target and must be read, not discarded.
CONNECT_REPLY_PREFIX_SIZE = 4
# The smallest downstream payload that counts as a CARRIED TRANSFER. Below this a
# non-frame reply is protocol noise we do not understand, not evidence: the live
# carried transfers measured 316 bytes (an HTTP response) and the live failures
# measured 0 or 4, so a handful of bytes is never a delivered payload. Without a
# floor, any stray four bytes would satisfy "payload arrived" and pass a review.
MIN_DELIVERED_PAYLOAD_BYTES = 8
# SOCKS5 reply codes, so a result says "host unreachable" instead of a hex byte.
SOCKS_REPLY_MEANINGS = {
    0x00: "succeeded",
    0x01: "general_failure",
    0x02: "connection_not_allowed",
    0x03: "network_unreachable",
    0x04: "host_unreachable",
    0x05: "connection_refused",
    0x06: "ttl_expired",
    0x07: "command_not_supported",
    0x08: "address_type_not_supported",
}
MAX_DOWNSTREAM_BYTES = 4096
# Downstream bytes kept in the result JSON for a human reading a review.
DOWNSTREAM_SAMPLE_BYTES = 24


class StreamHandshakeError(Exception):
    """The relay refused or could not complete the stream handshake."""

    def __init__(self, code: str, detail: str = ""):
        super().__init__(code if not detail else code + ": " + detail)
        self.code = code
        self.detail = detail


@dataclasses.dataclass
class StreamRound:
    """One attempt to move real bytes to the client's target over the relay."""

    ok: bool = False
    downstream_bytes: int = 0
    connect_frame: bool = False
    connect_frame_rep: Optional[int] = None
    # The relay answered the target-connect with a refusal code. This is NOT the
    # same fault as a relay that goes silent: it is an explicit verdict about the
    # target, and the two must stay distinguishable in the stored result.
    target_refused: bool = False
    target_refused_code: Optional[int] = None
    target_refused_meaning: str = ""
    # The reply did not match the documented frame shape and we do not know its
    # format. It is recorded as unparsed WITH its raw bytes: an unparsed reply must
    # never be collapsed into "the relay said nothing", because that collapse is
    # what hid a per-target refusal behind a silence-shaped counter.
    reply_unparsed: bool = False
    # The relay reported a non-zero code but delivered no payload: reachable-looking,
    # nothing carried. Kept apart from a refusal because the code does not
    # discriminate (see classify_stream_downstream).
    connect_no_payload: bool = False
    dns_answer: bool = False
    echo: bool = False
    handshake_ms: Optional[int] = None
    close_ms: Optional[int] = None
    error: str = ""
    retryable: bool = False
    greeting: str = ""
    sample: str = ""


def parse_endpoint(value: str) -> Addr:
    """Return (host, port) for host:port (or [v6]:port)."""
    text = str(value).strip()
    if not text:
        raise ValueError("endpoint is empty")
    if text.startswith("["):
        end = text.find("]")
        if end <= 1 or text[end + 1:end + 2] != ":":
            raise ValueError("endpoint must look like 1.2.3.4:5438, got " + repr(value))
        host, port_text = text[1:end], text[end + 2:]
    else:
        host, _, port_text = text.rpartition(":")
        if not host:
            raise ValueError("endpoint must look like 1.2.3.4:5438, got " + repr(value))
    if not port_text.isdigit():
        raise ValueError("endpoint must look like 1.2.3.4:5438, got " + repr(value))
    port = int(port_text)
    if port < 1 or port > 65535:
        raise ValueError("endpoint port must be 1-65535, got " + repr(value))
    return host, port


def parse_relay(value: str) -> Addr:
    """argparse type for --relay; kept for the historical callers."""
    try:
        return parse_endpoint(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(str(exc)) from exc


def parse_advertised(value: str) -> list:
    """argparse type for --advertised: a comma-separated list of relay IPs."""
    text = str(value).strip()
    if not text:
        return []
    return [item.strip() for item in text.split(",") if item.strip()]


def valid_ip(value: str) -> bool:
    """Mirror the panel's FILTER_VALIDATE_IP acceptance for a bare address."""
    for family in (socket.AF_INET, socket.AF_INET6):
        try:
            socket.inet_pton(family, str(value).strip())
            return True
        except (OSError, ValueError):
            continue
    return False


def question_bytes(name: Sequence[str] = DNS_NAME) -> bytes:
    out = bytearray()
    for label in name:
        out.append(len(label))
        out.extend(label.encode("ascii"))
    out.append(0)
    out.extend(struct.pack("!HH", DNS_TYPE_A, DNS_CLASS_IN))
    return bytes(out)


def build_dns_query(identifier: int) -> bytes:
    """The A query the research selftest proved end to end."""
    header = struct.pack("!6H", identifier & 0xFFFF, 0x0100, 1, 0, 0, 0)
    return header + question_bytes()


def validate_dns_answer(payload: bytes, identifier: int) -> bool:
    """True only for a real answer to the query we asked.

    The old check accepted 81 80 anywhere in any datagram, which a relay can
    satisfy without ever reaching a resolver. This one requires the response bit,
    a matching transaction id, opcode 0, RCODE 0, the same question and at least
    one answer record - none of which our own query bytes contain, so an echo of
    the query is not an answer.
    """
    if not isinstance(payload, (bytes, bytearray)):
        return False
    question = question_bytes()
    if len(payload) < 12 + len(question):
        return False
    body = bytes(payload)
    if struct.unpack("!H", body[0:2])[0] != (identifier & 0xFFFF):
        return False
    flags = struct.unpack("!H", body[2:4])[0]
    if flags & 0x8000 == 0:  # QR: this must be a response
        return False
    if flags & 0x7800 != 0:  # opcode
        return False
    if flags & 0x000F != 0:  # RCODE
        return False
    qdcount, ancount = struct.unpack("!HH", body[4:8])
    if qdcount != 1 or ancount < 1:
        return False
    if body[12:12 + len(question)] != question:
        return False
    return True


def strip_address_header(payload: bytes) -> Tuple[bytes, bool]:
    """Strip an optional Shadowsocks/SOCKS5 prefix, return (inner, was_framed)."""
    body = bytes(payload)
    for prefix, offset in ((b"\x00\x00\x00", 3), (b"", 0)):
        if prefix and not body.startswith(prefix):
            continue
        cursor = offset
        if cursor >= len(body):
            continue
        address_type = body[cursor]
        cursor += 1
        if address_type == 1:
            cursor += 4
        elif address_type == 4:
            cursor += 16
        elif address_type == 3:
            if cursor >= len(body):
                continue
            length = body[cursor]
            cursor += 1 + length
        else:
            continue
        cursor += 2  # port
        if cursor <= len(body):
            return body[cursor:], True
    return body, False


def address_header(host: str, port: int) -> bytes:
    """A Shadowsocks none address header (ATYP/address/port)."""
    try:
        packed = socket.inet_pton(socket.AF_INET, host)
        return b"\x01" + packed + struct.pack("!H", port)
    except OSError:
        encoded = host.encode("idna")
        if not encoded or len(encoded) > 255:
            raise ValueError("target host cannot be encoded")
        return b"\x03" + bytes([len(encoded)]) + encoded + struct.pack("!H", port)


def xor_one(payload: bytes) -> bytes:
    """The client XORs every outbound stream byte with 1; downstream is raw."""
    return bytes(item ^ 1 for item in payload)


def credential_checksum(password: bytes) -> int:
    """Mirror of tools/ultra-client credentialChecksum (main.go:102-107)."""
    if len(password) != 16:
        return 0
    return (0x74 - (password[6] - 0x30) - (password[10] - 0x30)) & 0xFF


def build_stream_handshake(greeting: bytes, user: bytes, password: bytes, target: Addr) -> bytes:
    return (
        greeting
        + bytes([len(user)]) + user
        + bytes([len(password)]) + password
        + bytes([SOCKS_VERSION, STREAM_COMMAND_TARGET_CONNECT, credential_checksum(password)])
        + address_header(target[0], target[1])
    )


def recv_exact(conn: socket.socket, count: int, deadline: float) -> bytes:
    chunks = bytearray()
    while len(chunks) < count:
        remaining = deadline - time.time()
        if remaining <= 0:
            raise StreamHandshakeError("stream_ack_timeout")
        conn.settimeout(max(0.001, remaining))
        try:
            data = conn.recv(count - len(chunks))
        except socket.timeout as exc:
            raise StreamHandshakeError("stream_ack_timeout") from exc
        except OSError as exc:
            raise StreamHandshakeError("stream_ack_error", type(exc).__name__) from exc
        if not data:
            raise StreamHandshakeError("stream_ack_eof")
        chunks.extend(data)
    return bytes(chunks)


def read_stream_ack(conn: socket.socket, deadline: float) -> str:
    """Consume the relay's handshake reply; return legacy or modern."""
    first = recv_exact(conn, 2, deadline)
    if first == STREAM_AUTH_ACK[:2]:
        second = recv_exact(conn, 2, deadline)
        if second not in (STREAM_AUTH_ACK[2:], STREAM_AUTH_ACK[:2]):
            raise StreamHandshakeError("stream_authentication_rejected", second.hex())
        return "legacy"
    if first == b"\x00\x00":
        prelude = first + recv_exact(conn, STREAM_PRELUDE_SIZE - 2, deadline)
        if prelude[:8] != b"\x00" * 8 or prelude[8] == 0:
            raise StreamHandshakeError("stream_prelude_rejected", prelude.hex())
        return "modern"
    raise StreamHandshakeError("stream_ack_unrecognised", first.hex())


def valid_connect_frame(frame: bytes) -> bool:
    """The relay's target-connect result frame shape (04 01 <rep> 00 01 ...).

    A full frame carries the bound address; a reply header alone is accepted too,
    because a relay that cannot reach the target answers with just the header
    (measured on the live source host: 04 01 04 00, then close). Requiring the
    tenth byte made the probe discard the relay's refusal and then describe the
    round as if the relay had said nothing.
    """
    return (
        len(frame) >= CONNECT_REPLY_PREFIX_SIZE
        and frame[0] == 0x04
        and frame[1] == 0x01
        and frame[3] == 0x00
        and (len(frame) < CONNECT_FRAME_SIZE or frame[4] == 0x01)
    )


def connect_reply_code(frame: bytes) -> Optional[int]:
    """The reply code byte of a target-connect frame, or None if not a frame."""
    if not valid_connect_frame(frame):
        return None
    return int(frame[2])


def reply_meaning(code: Optional[int]) -> str:
    if code is None:
        return ""
    return SOCKS_REPLY_MEANINGS.get(int(code), "unknown_reply_" + hex(int(code)))


def classify_stream_downstream(data: bytes, identifier: int, sent: bytes) -> StreamRound:
    """Turn the bytes a stream returned into evidence about the target.

    A round counts only when the relay reported a successful target connect or
    the target itself answered with the DNS response we asked for, and when the
    bytes are not simply our own payload coming back.
    """
    outcome = StreamRound()
    outcome.downstream_bytes = len(data)
    if not data:
        return outcome
    outcome.sample = bytes(data[:DOWNSTREAM_SAMPLE_BYTES]).hex()
    body = bytes(data)
    if valid_connect_frame(body[:CONNECT_FRAME_SIZE]):
        full = len(body) >= CONNECT_FRAME_SIZE
        frame = body[:CONNECT_FRAME_SIZE] if full else body
        outcome.connect_frame_rep = connect_reply_code(frame)
        outcome.target_refused_code = outcome.connect_frame_rep
        outcome.target_refused_meaning = reply_meaning(outcome.connect_frame_rep)
        outcome.connect_frame = outcome.connect_frame_rep == 0
        body = body[CONNECT_FRAME_SIZE:] if full else b""
    # The relay's reply CODE is not a verdict. Measured on the live source host
    # 2026-09-14: a stream that delivered 316 real bytes and a stream that delivered
    # nothing produced byte-identical ten-byte frames (04 01 01 00 01 <addr> <port>),
    # so the code is 0x01 on a SUCCESSFUL carry. Treating a non-zero code as failure
    # threw away delivered bytes; treating it as success would invent a transfer.
    # The only reliable evidence is whether downstream payload actually arrived.
    #
    # Payload accounting must happen BEFORE any DNS probing, because
    # strip_address_header() happily consumes three bytes of an HTTP body as if they
    # were a Shadowsocks prefix, which would make real payload look like a 3-byte
    # fragment (and therefore "unparsed").
    payload = bytes(body)
    echo = bool(payload) and payload in (bytes(sent), xor_one(bytes(sent)))
    outcome.echo = echo
    # The target's own answer is checked first: it is the strongest evidence and it
    # does not depend on the relay's framing at all.
    inner, framed = strip_address_header(payload)
    if framed:
        outcome.dns_answer = validate_dns_answer(inner, identifier)
    if not outcome.dns_answer:
        outcome.dns_answer = validate_dns_answer(payload, identifier)
    # ARRIVED PAYLOAD IS THE EVIDENCE. If bytes came back that are not our own echo,
    # the relay carried something for this stream - that is the transfer, whatever the
    # relay's reply code says. Everything the code claims is a diagnostic.
    delivered = len(payload) >= MIN_DELIVERED_PAYLOAD_BYTES and not echo
    # Unparsed applies only when NOTHING usable arrived: a short non-empty reply that
    # is not a frame we recognise, not an answer and not our own bytes. We cannot say
    # what it means, so we say exactly that and keep the bytes. It must never be called
    # a silence.
    outcome.reply_unparsed = bool(
        payload
        and not delivered
        and not outcome.connect_frame
        and not outcome.dns_answer
        and len(payload) >= CONNECT_REPLY_PREFIX_SIZE
    )
    outcome.connect_no_payload = bool(outcome.connect_frame_rep is not None) and not delivered
    # A refusal requires BOTH a non-zero code AND no delivered payload AND no answer.
    # Never the code alone: the code is a named diagnostic, not a decision.
    outcome.target_refused = bool(
        outcome.target_refused_code not in (None, 0) and not delivered and not outcome.dns_answer
    )
    # Evidence = payload the relay actually delivered for our stream, or the target's
    # own answer. A bare "target connected" frame is NOT evidence: it is the relay's
    # claim, and we have seen that claim made on a stream that delivered 316 bytes and
    # on one that delivered nothing, with identical bytes. A claim cannot decide.
    outcome.ok = bool(delivered or outcome.dns_answer)
    return outcome


def read_stream_downstream(
    conn: socket.socket,
    window: float,
    *,
    complete: Optional[Callable[[bytes], bool]] = None,
    limit: int = MAX_DOWNSTREAM_BYTES,
) -> Tuple[bytes, bool]:
    """Read until the evidence is in, the relay closes, or the window ends."""
    deadline = time.time() + max(0.0, window)
    chunks = bytearray()
    closed = False
    while len(chunks) < limit:
        remaining = deadline - time.time()
        if remaining <= 0:
            break
        conn.settimeout(max(0.001, remaining))
        try:
            data = conn.recv(min(2048, limit - len(chunks)))
        except socket.timeout:
            break
        except OSError:
            closed = True
            break
        if not data:
            closed = True
            break
        chunks.extend(data)
        if complete is not None and complete(bytes(chunks)):
            break
    return bytes(chunks), closed


def probe_target_stream(
    relay: Addr,
    user: str,
    password: str,
    target: Addr,
    *,
    timeout: float,
    dns_target: Addr = DEFAULT_DNS_TARGET,
    greetings: Sequence[bytes] = (LEGACY_STREAM_GREETING, MODERN_STREAM_GREETING),
) -> StreamRound:
    """Open the client's stream path to the target through the relay, read back.

    This is the probe that can actually discriminate: the greeting is the real
    handshake a client sends, the payload is a real request the target may answer,
    and a round only counts when real downstream bytes come back for it.
    """
    last: Optional[StreamRound] = None
    for greeting in greetings:
        outcome = _probe_target_stream_once(
            relay, user, password, target, timeout=timeout, dns_target=dns_target, greeting=greeting
        )
        if outcome.ok or not outcome.retryable:
            return outcome
        last = outcome
    return last if last is not None else StreamRound(error="stream_not_attempted")


def _probe_target_stream_once(
    relay: Addr,
    user: str,
    password: str,
    target: Addr,
    *,
    timeout: float,
    dns_target: Addr,
    greeting: bytes,
) -> StreamRound:
    outcome = StreamRound()
    user_bytes = user.encode()
    password_bytes = password.encode()
    if len(user_bytes) == 0 or len(user_bytes) > 255 or len(password_bytes) == 0 or len(password_bytes) > 255:
        outcome.error = "credential_length_invalid"
        return outcome
    identifier = int.from_bytes(bytes([int(time.time() * 1000) & 0xFF, 0x5A]), "big")
    # The request is both a valid Shadowsocks none request for a DNS answer -
    # which makes an address-header forward listener answer - and a byte string a
    # plain application target will react to.
    payload = address_header(dns_target[0], dns_target[1]) + build_dns_query(identifier)
    started = time.time()
    deadline = started + max(0.5, timeout)
    try:
        conn = socket.create_connection(relay, timeout=max(0.5, timeout))
    except OSError as exc:
        outcome.error = "relay_connect_failed:" + type(exc).__name__
        outcome.retryable = True
        return outcome
    try:
        try:
            conn.sendall(build_stream_handshake(greeting, user_bytes, password_bytes, target))
            outcome.greeting = read_stream_ack(conn, min(deadline, time.time() + 3.0))
        except StreamHandshakeError as exc:
            outcome.error = str(exc)
            outcome.retryable = True
            outcome.close_ms = int((time.time() - started) * 1000)
            return outcome
        outcome.handshake_ms = int((time.time() - started) * 1000)
        try:
            conn.sendall(xor_one(payload))
        except OSError as exc:
            outcome.error = "stream_write_failed:" + type(exc).__name__
            return outcome

        def complete(data: bytes) -> bool:
            return classify_stream_downstream(data, identifier, payload).ok

        data, closed = read_stream_downstream(conn, max(0.2, deadline - time.time()), complete=complete)
        classified = classify_stream_downstream(data, identifier, payload)
        classified.handshake_ms = outcome.handshake_ms
        classified.close_ms = int((time.time() - started) * 1000)
        classified.greeting = outcome.greeting
        if not classified.ok:
            if classified.target_refused:
                # The relay gave a verdict about the target. Say so, with the code,
                # instead of reporting the refusal as if nothing had come back.
                classified.error = (
                    "target_refused: rep=0x%02x %s" % (classified.connect_frame_rep, classified.target_refused_meaning)
                )
            elif classified.reply_unparsed:
                # Never call an unparsed reply a silence. The raw bytes travel with it.
                classified.error = "unparsed_reply: bytes=" + str(classified.sample or "")
            else:
                classified.error = outcome.error or (
                    "no_downstream_bytes" if closed or not data else "undiscriminating_downstream"
                )
        return classified
    finally:
        try:
            conn.close()
        except OSError:
            pass


def unwrap_down(packet: bytes) -> Optional[Tuple[Addr, bytes]]:
    """Parse a relay DOWN datagram -> (source, payload), or None.

    native_udp.unwrap_down does this, and the probe prefers it. The local copy
    exists because the source host's native_udp.py is the one part of the runtime
    we cannot inspect from here, and a probe that dies with AttributeError on an
    older helper would report nothing at all - the review would come back
    rebuttal=blocked with no explanation.
    """
    if not isinstance(packet, (bytes, bytearray)) or len(packet) < 10:
        return None
    if bytes(packet[:4]) != b"\x00\x00\x00\x01":
        return None
    return (
        (socket.inet_ntoa(bytes(packet[4:8])), struct.unpack("!H", bytes(packet[8:10]))[0]),
        bytes(packet[10:]),
    )


def parse_down(packet: bytes) -> Optional[Tuple[Addr, bytes]]:
    """Prefer the dependency's parser; fall back to the local one."""
    parser = getattr(N, "unwrap_down", None)
    if callable(parser):
        return parser(bytes(packet))
    return unwrap_down(packet)


def recv_payload(session: N.NativeSession, window: float) -> Optional[bytes]:
    """Return the next datagram the relay sends back, or None."""
    if session.udp is None:
        return None
    deadline = time.time() + window
    while time.time() < deadline:
        session.udp.settimeout(max(0.001, deadline - time.time()))
        try:
            data, _ = session.udp.recvfrom(8192)
            return data
        except socket.timeout:
            return None
        except OSError:
            return None
    return None


def recv_down(session: N.NativeSession, window: float) -> Optional[Tuple[Addr, bytes]]:
    """Return (source, payload) for the next DOWN datagram, or None.

    The source address matters: a relay-local echo comes back as 2.0.0.0:0, and a
    reply that claims to come from a destination the relay never reached is not
    evidence about that destination.
    """
    deadline = time.time() + window
    while time.time() < deadline:
        raw = recv_payload(session, max(0.001, deadline - time.time()))
        if raw is None:
            return None
        parsed = parse_down(raw)
        if parsed is not None:
            return parsed
    return None


def probe_udp_big(session: N.NativeSession, size: int, window: float) -> Tuple[bool, int]:
    """Send size bytes to the relay-local echo target; report echo + size.

    Relay-local: this measures the association, not the client's data path.
    """
    payload = bytes((index * 7 + 11) & 0xFF for index in range(size))
    try:
        session.send(ECHO_TARGET, payload)
    except (RuntimeError, OSError):
        return False, 0
    echo = recv_payload(session, window)
    if echo is None:
        return False, 0
    return True, len(echo)


def probe_dns(session: N.NativeSession, window: float, dns_target: Addr = DEFAULT_DNS_TARGET) -> bool:
    """A strict DNS round trip through the relay to a real resolver.

    Real downstream evidence from a real destination, but not the destination the
    failing clients were reaching, so it never decides a pass.
    """
    identifier = int.from_bytes(bytes([int(time.time() * 1000) & 0xFF, 0x3C]), "big")
    try:
        session.send(dns_target, build_dns_query(identifier))
    except (RuntimeError, OSError):
        return False
    deadline = time.time() + window
    seen = 0
    while time.time() < deadline:
        parsed = recv_down(session, max(0.05, deadline - time.time()))
        if parsed is None:
            break
        source, payload = parsed
        if tuple(source) == tuple(dns_target) and validate_dns_answer(payload, identifier):
            return True
        seen += 1
        if seen > 32:
            break
    return False


def probe_target_udp(
    session: N.NativeSession,
    target: Addr,
    window: float,
    dns_target: Addr = DEFAULT_DNS_TARGET,
) -> bool:
    """Try to move real bytes to the CLIENT'S target over the relay.

    Two request shapes are sent, because the panel knows the target address but
    not its application: the address-header-framed DNS query (a forwarding
    listener answers it) and the bare query. Only a strictly validated answer
    that arrives from the target's own address counts. A silent target is not
    counted against the line - a target application is not obliged to answer an
    arbitrary datagram - so this can only ever add evidence.
    """
    identifier = int.from_bytes(bytes([int(time.time() * 1000) & 0xFF, 0x2D]), "big")
    query = build_dns_query(identifier)
    try:
        session.send(target, address_header(dns_target[0], dns_target[1]) + query)
        session.send(target, query)
    except (RuntimeError, OSError):
        return False
    deadline = time.time() + window
    seen = 0
    while time.time() < deadline:
        parsed = recv_down(session, max(0.05, deadline - time.time()))
        if parsed is None:
            break
        source, payload = parsed
        if tuple(source) == tuple(target):
            inner, framed = strip_address_header(payload)
            if validate_dns_answer(inner if framed else payload, identifier):
                return True
        seen += 1
        if seen > 32:
            break
    return False


def review(
    relay: Addr,
    user: str,
    password: str,
    seconds: int,
    *,
    interval: float,
    big_bytes: int,
    require_big_packet: bool,
    auth_timeout: float,
    target: str = "",
    advertised: Optional[Sequence[str]] = None,
    dns_target: Addr = DEFAULT_DNS_TARGET,
    stream_timeout: float = 3.0,
    session_factory: Optional[Callable[..., object]] = None,
    stream_factory: Optional[Callable[..., StreamRound]] = None,
    clock: Callable[[], float] = time.time,
    sleep: Callable[[float], None] = time.sleep,
) -> dict:
    """Run one review window and return the result the panel stores."""
    result: dict = {
        "passed": False,
        "relay": str(relay[0]) + ":" + str(relay[1]),
        "rounds": 0,
        "udp_ok": 0,
        "udp_failed": 0,
        "big_ok": 0,
        "big_failed": 0,
        "dns_ok": 0,
        "dns_failed": 0,
        "max_echo_bytes": 0,
        "min_rtt_ms": None,
        "last_error": "",
        # New in this revision: the client's target, the advertised mandate and the
        # end-to-end counters the verdict is built from.
        "target": str(target or "").strip(),
        "advertised_ok": True,
        "end_to_end_ok": 0,
        "local_path_only": True,
        "target_tcp_ok": 0,
        "target_tcp_failed": 0,
        "target_tcp_zero_downstream": 0,
        "target_tcp_frame_ok": 0,
        "target_tcp_dns_ok": 0,
        "target_tcp_echo_rounds": 0,
        # A refusal and a silence are different faults and must not share a counter:
        # the operator note reads target_tcp_zero_downstream as the fault signature,
        # so a refusal must never be counted there.
        "target_tcp_refused": 0,
        "target_tcp_refused_code": None,
        "target_tcp_refused_meaning": "",
        "target_tcp_reply_unparsed": 0,
        "target_tcp_unparsed_sample": "",
        "target_tcp_min_close_ms": None,
        "target_tcp_max_close_ms": None,
        "target_tcp_sample": "",
        "target_udp_ok": 0,
        "target_udp_failed": 0,
        "target_stream_timeout_seconds": round(float(stream_timeout), 3),
    }
    target_addr: Optional[Addr] = None
    if result["target"]:
        try:
            target_addr = parse_endpoint(result["target"])
        except ValueError as exc:
            result["last_error"] = "target_invalid: " + str(exc)
    advertised_ips = [str(item).strip().lower() for item in (advertised or []) if str(item).strip()]
    result["advertised_ok"] = not advertised_ips or str(relay[0]).strip().lower() in advertised_ips
    if not result["advertised_ok"]:
        result["last_error"] = result["last_error"] or "relay_not_advertised"

    if session_factory is None:
        session_factory = N.NativeSession
    if stream_factory is None:
        stream_factory = probe_target_stream

    try:
        with session_factory(relay, user.encode(), password.encode()) as session:
            session.arm(auth_timeout=auth_timeout)
            result["armed_ms"] = round(float(session.arm_ms or 0.0), 1)
            deadline = clock() + max(5, seconds)
            while clock() < deadline:
                result["rounds"] += 1
                keep_rtt = session.latency_ms(1)
                if keep_rtt == keep_rtt:  # not NaN
                    result["udp_ok"] += 1
                    result["min_rtt_ms"] = (
                        keep_rtt if result["min_rtt_ms"] is None else min(result["min_rtt_ms"], keep_rtt)
                    )
                else:
                    result["udp_failed"] += 1
                    result["last_error"] = result["last_error"] or "keep echo timed out"

                # Relay-local diagnostics. Reported, never sufficient.
                confirmed, echo_bytes = probe_udp_big(session, big_bytes, max(1.0, interval))
                if confirmed:
                    result["big_ok"] += 1
                    result["max_echo_bytes"] = max(result["max_echo_bytes"], echo_bytes)
                else:
                    result["big_failed"] += 1
                    result["last_error"] = result["last_error"] or "no echo for " + str(big_bytes) + "B payload"

                if probe_dns(session, max(1.0, interval), dns_target):
                    result["dns_ok"] += 1
                else:
                    result["dns_failed"] += 1
                    result["last_error"] = result["last_error"] or "no DNS answer through the relay"

                if target_addr is None:
                    result["last_error"] = result["last_error"] or (
                        "no client target in the review payload; a relay-local echo cannot prove a client data path"
                    )
                else:
                    if probe_target_udp(session, target_addr, max(1.0, interval), dns_target):
                        result["target_udp_ok"] += 1
                    else:
                        result["target_udp_failed"] += 1
                    stream = stream_factory(
                        relay, user, password, target_addr, timeout=stream_timeout, dns_target=dns_target
                    )
                    if stream.ok:
                        result["target_tcp_ok"] += 1
                        if stream.connect_frame:
                            result["target_tcp_frame_ok"] += 1
                        if stream.dns_answer:
                            result["target_tcp_dns_ok"] += 1
                    else:
                        result["target_tcp_failed"] += 1
                        result["last_error"] = result["last_error"] or str(stream.error or "no target transfer")
                    # A refusal and a silence are counted apart on purpose. The note
                    # tells a human to read target_tcp_zero_downstream as the fault
                    # signature, and a relay that answered "host unreachable" has not
                    # been silent - counting it there would make a refusal read clean.
                    if stream.target_refused:
                        result["target_tcp_refused"] += 1
                        result["target_tcp_refused_code"] = stream.target_refused_code
                        result["target_tcp_refused_meaning"] = stream.target_refused_meaning
                    elif stream.reply_unparsed:
                        result["target_tcp_reply_unparsed"] += 1
                        if stream.sample and not result["target_tcp_unparsed_sample"]:
                            result["target_tcp_unparsed_sample"] = stream.sample
                    elif stream.downstream_bytes == 0:
                        result["target_tcp_zero_downstream"] += 1
                    if stream.echo:
                        result["target_tcp_echo_rounds"] += 1
                    if stream.close_ms is not None:
                        low = result["target_tcp_min_close_ms"]
                        high = result["target_tcp_max_close_ms"]
                        result["target_tcp_min_close_ms"] = stream.close_ms if low is None else min(low, stream.close_ms)
                        result["target_tcp_max_close_ms"] = stream.close_ms if high is None else max(high, stream.close_ms)
                    if stream.sample and not result["target_tcp_sample"]:
                        result["target_tcp_sample"] = stream.sample

                remaining = deadline - clock()
                if remaining > 0:
                    sleep(min(interval, remaining))
    except N.LineNotLive as exc:
        result["last_error"] = "line_not_live: " + str(exc)
    except N.AuthError as exc:
        result["last_error"] = "auth_failed: " + str(exc)
    except OSError as exc:
        result["last_error"] = "net_error: " + str(exc)

    return finish(result, target_addr is not None, require_big_packet)


def finish(result: dict, target_configured: bool, require_big_packet: bool) -> dict:
    """Apply the evidence ladder to the counters and publish the proof block."""
    advertised_ok = bool(result.get("advertised_ok", True))
    end_to_end_ok = int(result.get("target_tcp_ok", 0)) + int(result.get("target_udp_ok", 0))
    result["end_to_end_ok"] = end_to_end_ok
    # Proof the panel may act on: the relay it advertised carried real downstream
    # bytes for the client's target. Everything else is a diagnostic.
    proof_e2e = end_to_end_ok if advertised_ok else 0
    result["local_path_only"] = proof_e2e < 1
    result["proof"] = {
        "endpoint": str(result.get("relay", "")),
        "local_path_only": bool(result["local_path_only"]),
        "end_to_end_ok": int(proof_e2e),
        "target": str(result.get("target", "")),
        "method": "relay_stream_to_client_target",
        "advertised": advertised_ok,
        "tcp_downstream_ok": int(result.get("target_tcp_ok", 0)),
        "tcp_connect_frame_ok": int(result.get("target_tcp_frame_ok", 0)),
        "tcp_dns_answer_ok": int(result.get("target_tcp_dns_ok", 0)),
        "udp_target_ok": int(result.get("target_udp_ok", 0)),
        "dns_e2e_ok": int(result.get("dns_ok", 0)),
        "relay_local_echo_ok": int(result.get("udp_ok", 0)) + int(result.get("big_ok", 0)),
        # The relay's own verdict about the target, kept separate from silence so a
        # reader can tell "refused with a code" from "went quiet". Confirmed on the
        # live source host: the refusal is what actually happens; a true silence has
        # no confirmed instance yet.
        "tcp_refused": int(result.get("target_tcp_refused", 0)),
        "tcp_refused_code": result.get("target_tcp_refused_code"),
        "tcp_refused_reason": str(result.get("target_tcp_refused_meaning", "") or ""),
        "tcp_zero_downstream_rounds": int(result.get("target_tcp_zero_downstream", 0)),
        # A reply whose shape we do not know, with its bytes. Distinct from both a
        # decoded refusal and a genuine silence.
        "tcp_reply_unparsed": int(result.get("target_tcp_reply_unparsed", 0)),
        "tcp_unparsed_sample": str(result.get("target_tcp_unparsed_sample", "") or ""),
    }
    if not advertised_ok:
        result["proof"]["reason"] = "relay_not_advertised"
    elif not target_configured:
        result["proof"]["reason"] = "no_client_target"
    elif int(result.get("target_tcp_refused", 0)) > 0 and proof_e2e < 1:
        # The relay answered and named a reason. That is a real, named verdict about
        # the target and must not be flattened into the generic no-transfer reason.
        result["proof"]["reason"] = "target_refused"
        result["proof"]["refusal_detail"] = "rep=0x%02x %s" % (
            int(result.get("target_tcp_refused_code") or 0),
            str(result.get("target_tcp_refused_meaning", "") or ""),
        )
    elif int(result.get("target_tcp_reply_unparsed", 0)) > 0 and proof_e2e < 1:
        result["proof"]["reason"] = "target_reply_unparsed"
        result["proof"]["unparsed_sample"] = str(result.get("target_tcp_unparsed_sample", "") or "")
    elif proof_e2e < 1:
        result["proof"]["reason"] = "no_end_to_end_transfer"

    # A short UDP loss burst is a quality signal, not an immediate line death.
    # Keep the same 25% ceiling used by the client selector: a review window
    # with a few late/lost datagrams must not make an otherwise reachable line
    # look like `no_healthy_candidate`.
    udp_ok = int(result.get("udp_ok", 0))
    udp_failed = int(result.get("udp_failed", 0))
    udp_total = udp_ok + udp_failed
    udp_clean = udp_ok > 0 and udp_total > 0 and udp_failed * 100 <= udp_total * 25
    big_clean = not require_big_packet or (result.get("big_failed", 0) == 0 and result.get("big_ok", 0) > 0)
    result["passed"] = bool(
        target_configured
        and advertised_ok
        and result.get("rounds", 0) > 0
        and udp_clean
        and result.get("target_tcp_failed", 0) == 0
        and result.get("target_tcp_ok", 0) > 0
        and big_clean
    )
    if not result["passed"]:
        result["last_error"] = result["last_error"] or "review did not complete a target transfer"
    return result


def main(argv: Optional[list] = None) -> int:
    parser = argparse.ArgumentParser(description="Review a relay line for the panel.")
    parser.add_argument("--relay", required=True, type=parse_relay)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--seconds", type=int, default=600)
    parser.add_argument("--interval", type=float, default=20.0)
    parser.add_argument("--big-bytes", type=int, default=1400)
    parser.add_argument("--auth-timeout", type=float, default=5.0)
    parser.add_argument("--require-big-packet", action="store_true")
    parser.add_argument(
        "--target",
        default="",
        help="the client's destination for this candidate, host:port; empty means the panel sent none",
    )
    parser.add_argument(
        "--advertised",
        default="",
        help="comma-separated relay IPs the panel advertises for this line",
    )
    parser.add_argument("--dns-target", type=parse_relay, default=DEFAULT_DNS_TARGET)
    parser.add_argument("--stream-timeout", type=float, default=3.0)
    parser.add_argument("--json", action="store_true", help="print one JSON object on stdout")
    args = parser.parse_args(argv)

    advertised = parse_advertised(args.advertised)
    result = review(
        args.relay,
        args.user,
        args.password,
        args.seconds,
        interval=max(1.0, args.interval),
        big_bytes=max(64, min(65000, args.big_bytes)),
        require_big_packet=bool(args.require_big_packet),
        auth_timeout=max(1.0, args.auth_timeout),
        target=args.target,
        advertised=advertised,
        dns_target=args.dns_target,
        stream_timeout=max(0.5, min(30.0, args.stream_timeout)),
    )
    result["advertised_ips"] = advertised
    if isinstance(result.get("proof"), dict):
        result["proof"]["advertised_ips"] = advertised
    if args.json:
        print(json.dumps(result, separators=(",", ":"), sort_keys=True))
    else:
        for key in sorted(result):
            print(str(key) + ": " + str(result[key]))
    return 0 if result["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
