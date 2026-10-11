#!/usr/bin/env python3
"""native_udp — LeiGod native-UDP envelope protocol client (research-derived).

Speaks the relay envelope protocol directly (no local SOCKS dependency), per
SDK_RE_PROGRESS.md §0d (round-3 breakthrough, verified 17/17 + 12/12 + live):

    auth relay:5438  -> 04 01 01 00 01 <3B r0 r1 r2> <2B port>
    M      = r0 ^ 0x01
    sid[0] = r1 ^ 0x01
    sid[1] = r2 ^ 0x01
    udp_port = port ^ 0x0101
    3rd sid byte: scan 0x20..0x3f via keep-echo to 2.0.0.0:0 (relay echoes the
                  accepted value in <100ms)
    UP   = 01 00 <M> <sid3> 00 00 00 01 <dst4> <dst2> <payload>
    DOWN = 00 00 00 01 <dst4> <dst2> <payload>
    keep payload (idle keepalive / arming probe): 0000a46434c9b62b82eed2a87faa6a5f45ac

One armed session serves ANY number of targets and clients (the real client sends
8.8.8.8:53, 2.0.0.0:0 etc. on a single session). The relay forwards arbitrary
destinations — there is no destination whitelist on the relay side.
"""
from __future__ import annotations

import socket
import struct
import time
from typing import Optional, Tuple

Addr = Tuple[str, int]

# Idle keepalive payload: the same bytes probe_arm8/9 used to arm + keep the session.
KEEP = bytes.fromhex("0000a46434c9b62b82eed2a87faa6a5f45ac")

# Envelope header cost (bytes) subtracted from a TUN/payload budget.
ENV_HEADER = 16  # 01 00 M sid3 0000 0001 dst4 dst2

# Default scan range for the 3rd sid byte (all observed accepted values are even).
SCAN_RANGE = range(0x20, 0x40)

# Where the auth port lives (probes used 5438; app_pool `pc_port` may differ).
AUTH_PORT = 5438


class AuthError(Exception):
    """Raised when the relay rejects the auth handshake."""


class LineNotLive(AuthError):
    """Reply was `05 02 ...` (engine/line not active) — no session port granted."""


def cksum(pw: bytes) -> int:
    """PASS checksum byte used in the auth packet (empirically verified)."""
    return (0x74 - (pw[6] - ord("0")) - (pw[10] - ord("0"))) & 0xFF


def build_auth(user: bytes, pw: bytes, relay_ip: str, port: int = AUTH_PORT) -> bytes:
    """Build the relay:5438 auth packet (probe_arm8 layout)."""
    return (
        b"\x05\x05\x00\x01\x02\x80\x83\x01"
        + bytes([len(user)]) + user
        + bytes([len(pw)]) + pw
        + b"\x05\x03" + bytes([cksum(pw)]) + b"\x01"
        + socket.inet_aton(relay_ip) + struct.pack("!H", port)
    )


def parse_reply(reply: bytes) -> Tuple[bytes, int]:
    """Return (reply3B, relay_port) from the relay reply stream.

    Raises LineNotLive if the stream only carries `05 02` (no session granted).
    """
    idx = reply.rfind(b"\x04\x01\x01\x00\x01")
    if idx < 0 or idx + 10 > len(reply):
        if b"\x05\x02" in reply:
            raise LineNotLive("relay answered 05 02 (line not live / bad creds)")
        raise AuthError(f"no session reply in stream ({len(reply)}B): {reply[:32].hex()}")
    return reply[idx + 5:idx + 8], struct.unpack("!H", reply[idx + 8:idx + 10])[0]


def derive(reply3b: bytes, rport: int) -> Tuple[int, bytes, int]:
    """(M, sid12, udp_port) from the reply — the XOR-1 / ^0x0101 rules."""
    return reply3b[0] ^ 0x01, bytes([reply3b[1] ^ 0x01, reply3b[2] ^ 0x01]), rport ^ 0x0101


def make_envelope(M: int, sid3: bytes, dst: Addr, payload: bytes) -> bytes:
    return (
        b"\x01\x00" + bytes([M]) + bytes(sid3)
        + b"\x00\x00" + b"\x00\x01"
        + socket.inet_aton(dst[0]) + struct.pack("!H", dst[1])
        + payload
    )


def unwrap_down(pkt: bytes) -> Optional[Tuple[Addr, bytes]]:
    """Parse a relay DOWN datagram -> (target_addr, payload), or None if not ours."""
    if len(pkt) < 10 or pkt[:4] != b"\x00\x00\x00\x01":
        return None
    dst = socket.inet_ntoa(pkt[4:8])
    dport = struct.unpack("!H", pkt[8:10])[0]
    return (dst, dport), pkt[10:]


def recv_all(tcp: socket.socket, wait: float = 1.5) -> bytes:
    tcp.settimeout(0.4)
    chunks: list[bytes] = []
    end = time.time() + wait
    while time.time() < end:
        try:
            c = tcp.recv(4096)
            if not c:
                break
            chunks.append(c)
        except socket.timeout:
            if chunks:
                break
    return b"".join(chunks)


class NativeSession:
    """An armed native-UDP session on one relay.

    arm() does auth -> XOR-1 derivation -> 3rd-byte scan. Afterwards send() wraps
    any datagram to any destination and the relay forwards it; DOWN is returned by
    recv(). The TCP control socket is held open (the real client keeps it too).
    """

    def __init__(
        self,
        relay: Addr,
        user: bytes,
        pw: bytes,
        scan_range=SCAN_RANGE,
        scan_cand_wait: float = 0.25,
    ):
        self.relay = relay
        self.user = user
        self.pw = pw
        self.scan_range = scan_range
        self.scan_cand_wait = scan_cand_wait
        self.tcp: Optional[socket.socket] = None
        self.udp: Optional[socket.socket] = None
        self.M: Optional[int] = None
        self.sid3: Optional[bytes] = None
        self.up: Optional[int] = None
        self.armed = False
        self.arm_ms: Optional[float] = None

    @staticmethod
    def _wait_echo(sock: socket.socket, window: float) -> bool:
        """Wait up to `window` seconds (FRESH per candidate) for any echo."""
        end = time.time() + window
        while time.time() < end:
            sock.settimeout(max(0.001, end - time.time()))
            try:
                sock.recvfrom(4096)
                return True
            except socket.timeout:
                return False
            except OSError:
                return False
        return False

    def arm(self, auth_timeout: float = 5.0) -> "NativeSession":
        t0 = time.time()
        tcp = socket.create_connection(self.relay, timeout=auth_timeout)
        try:
            tcp.sendall(build_auth(self.user, self.pw, self.relay[0], self.relay[1]))
            reply = recv_all(tcp)
            reply3b, rport = parse_reply(reply)
            M, sid12, up = derive(reply3b, rport)
        except Exception:
            tcp.close()
            raise

        udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        udp.bind(("0.0.0.0", 0))
        udp.connect((self.relay[0], up))
        udp.setblocking(False)

        # 3rd-byte scan: each candidate gets a FRESH echo window (probe_arm8
        # semantics). The relay echoes exactly one accepted sid's keep in <100ms.
        accepted: Optional[int] = None
        for c in self.scan_range:
            cand = sid12 + bytes([c])
            udp.send(make_envelope(M, cand, ("2.0.0.0", 0), KEEP))
            if self._wait_echo(udp, self.scan_cand_wait):
                accepted = c
                break
        if accepted is None:
            udp.close()
            tcp.close()
            raise AuthError("no 3rd sid byte accepted in scan range")

        self.tcp = tcp
        self.udp = udp
        self.M = M
        self.sid3 = sid12 + bytes([accepted])
        self.up = up
        self.armed = True
        self.arm_ms = (time.time() - t0) * 1000.0
        return self

    def send(self, dst: Addr, payload: bytes) -> None:
        if not self.armed or self.udp is None or self.M is None or self.sid3 is None or self.up is None:
            raise RuntimeError("session not armed")
        self.udp.send(make_envelope(self.M, self.sid3, dst, payload))

    def keep(self) -> None:
        """Idle keepalive: echo to 2.0.0.0:0 (relay echoes it back)."""
        self.send(("2.0.0.0", 0), KEEP)

    def latency_ms(self, n: int = 3) -> float:
        """Min keep-echo RTT over n pings (for racing / health)."""
        if self.udp is None:
            raise RuntimeError("session not armed")
        best = None
        for _ in range(n):
            t0 = time.time()
            self.keep()
            if self._wait_echo(self.udp, 1.0):
                rtt = (time.time() - t0) * 1000.0
                best = rtt if best is None else min(best, rtt)
        return best if best is not None else float("nan")

    def close(self) -> None:
        for s in (self.udp, self.tcp):
            if s is not None:
                try:
                    s.close()
                except OSError:
                    pass
        self.udp = self.tcp = None
        self.armed = False

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()
