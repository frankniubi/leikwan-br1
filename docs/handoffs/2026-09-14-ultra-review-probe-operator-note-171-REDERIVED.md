# Operator note (re-derived) — Ultra review probe, source host 10.10.10.171

Audience: whoever holds the source host. Every command below is meant to be pasted
verbatim. Nothing here touches the panel.

**This supersedes `2026-09-14-ultra-review-probe-operator-note.md`.** It was
re-derived against the live host on 2026-09-14 by reading the installed bytes, not
by trusting the earlier memo. Section 3 lists what the earlier memo got wrong.

**中文摘要**：源主机 10.10.10.171 上的复审探针与代理**必须同时**替换。探针新版本
要真正把数据送到客户端目标并能收回下行字节才判通过；代理新版本才会把面板下发的
advertised 中转端点与客户端目标传给探针。**先备份**，再校验旧文件指纹，再粘贴新
文件，再 `systemctl restart ultra-source-agent`，最后用第 9 步一条命令确认新代码
真的在跑。回滚见第 11 步。

---

## 1. What is deployed, and why the two files must go together

| # | File | What it is | new md5 | new sha256 | bytes |
| - | ---- | ---------- | ------- | ---------- | ----- |
| 1 | /opt/ultra-source/runtime/ultra_line_review.py | the review probe the agent runs for each review | `24cd4309810a07bb5de8ad61d9593253` | `2eb012e3345a4fc5ab84cb6e71c7c623b541b66642095689a2ad6c3028ebd789` | 41557 |
| 2 | /usr/local/bin/ultra-source-agent.py | the agent itself (points the probe at an advertised endpoint + the client target) | `264990c14399877fa0300ba65527736a` | `1d11023ffd5afb61f5dbf722f6dbe94fca1b6148394a271ffa7161c1d231e57a` | 57632 |

The two files are a matched pair. The new agent passes `--target` and `--advertised`
to the probe; the old probe does not accept those flags, and the old agent does not
send them. **A half upgrade silently degrades to `rebuttal=blocked` on every review**
(old probe + new agent -> argparse exit 2; new probe + old agent -> always
`no_client_target`). Install both, in one sitting.

Before this upgrade a pass could ride on a datagram the relay echoed back to *itself*
(the keep echo and the large-payload echo to `2.0.0.0:0`), with `min_rtt_ms` at
loopback level (0.06-0.08 ms), and it probed whichever relay the agent acquired
first rather than the relay the panel advertises — 8 of the first 9 reviews probed an
endpoint the panel never advertised. After this upgrade a pass requires **all** of:

1. the relay still answers for the whole window;
2. a real TCP stream was opened to the relay, the relay ACKed the greeting, and the
   stream names the CLIENT'S TARGET as its destination;
3. real downstream bytes arrived for that stream in **every** round, and those bytes
   are not simply the probe's own payload echoed back.

Relay-local echoes and the DNS round trip are still recorded as diagnostics, but can
no longer make a review pass. An empty target (unknown zone, or a zone with no live
forward) can never pass.

Two honest notes:

* The probe now opens ~1 TCP connection per round (20 s interval, 600 s window ->
  about 30 connections) to the relay and sends 34 bytes to the client's target
  through it. That is the same path real clients use; it is not a scan, but it is new
  traffic the old probe did not generate.
* Nothing in this upgrade prints, logs or transmits credentials. The probe never logs
  the password; the agent's new log line contains only counts.

---

## 2. Verified host facts (2026-09-14, read from the host, not assumed)

```text
hostname                 full-9929-vm      (root)
python3                  Python 3.11.2  -> /usr/bin/python3.11  (/usr/bin/python3 symlink)
unit                     ultra-source-agent.service
ExecStart                /usr/bin/python3 /usr/local/bin/ultra-source-agent.py --config /etc/ultra-source-agent.json
hardening                ProtectSystem=strict  ReadWritePaths=/var/lib/ultra-source-agent /opt/leigod
                         NoNewPrivileges=true  PrivateTmp=true  ProtectHome=true
                         User=(unset -> runs as root)
config                   /etc/ultra-source-agent.json
  review_script            /opt/ultra-source/runtime/ultra_line_review.py
  review_interval_seconds  20
  review_big_bytes         1400
  review_require_big_packet true
  max_reviews_per_cycle    1
  state_dir                /var/lib/ultra-source-agent
probe dependency         /opt/ultra-source/runtime/native_udp.py (NativeSession, LineNotLive, AuthError, unwrap_down)
panel                    https://v3.leikwanhost.com , source_id=source-home-171
```

Installed baseline before the upgrade (the drift-gate baseline; both were
byte-identical to the repo's `1893e05b` blobs):

```text
a9cc8b87b1bebf8a370bda023c9981ec  /opt/ultra-source/runtime/ultra_line_review.py   (7876 bytes)
db911ae5522e8e5986c6b8855a588dd3  /usr/local/bin/ultra-source-agent.py            (50670 bytes)
```

---

## 3. What the earlier memo (`SOURCE-HOST-171-ACCESS.md`) got wrong

Recorded because more than one report repeated these:

1. **"no live ultra_line_review.py anywhere" is FALSE.** It is installed at
   `/opt/ultra-source/runtime/ultra_line_review.py`, md5 `a9cc8b87...`, 7876 bytes,
   mode 755. The memo's `find` was malformed (a stray `-o -xdev` changes the `find`
   expression), which is why it "returned NOTHING".
2. **"the drift gate WILL refuse" is FALSE.** The gate passes: the installed probe md5
   IS `a9cc8b87b1bebf8a370bda023c9981ec` and the installed agent md5 IS
   `db911ae5522e8e5986c6b8855a588dd3`. The note's baseline was correct all along; the
   revision mismatch was only ever *old-installed vs new-in-repo*.
3. **"the deployed revision is a different layout" is FALSE for the review path.** The
   split layout is live: the monolith does NOT contain the review logic inline — it
   spawns the probe as a subprocess. `grep -c 'NativeSession|ECHO_TARGET|def probe_'`
   on the monolith returns **0**.
4. **The shipped revisions do match the repo baseline.** `git cat-file blob
   1893e05b:source-agent/...` md5s equal the installed md5s exactly. (Hashing
   `git show ... > file` in PowerShell gives a DIFFERENT hash, because the redirection
   re-encodes the text — that trap produced a false "the baseline is not in the repo"
   reading once during this work.)
5. **A stale probe bytecode cache exists** that the earlier note never mentioned:
   `/opt/ultra-source/runtime/__pycache__/ultra_line_review.cpython-311.pyc`. See 7b.
6. **The unit cannot write to the standard paths.** `ProtectSystem=strict` with
   `ReadWritePaths=/var/lib/ultra-source-agent /opt/leigod` covers everything else,
   including `/usr/local/bin` and `/opt/ultra-source`. That is also why the agent has
   no self-update path. The upgrade must be performed out-of-band as real root; do not
   weaken the unit to make a write succeed.

---

## 4. STEP 0 — preflight (read-only; confirm before writing)

Never `cat` `/etc/ultra-source-agent.json` — it contains `source_secret`. This prints
the safe keys only.

```bash
systemctl list-units --type=service --all | grep -i ultra-source
systemctl cat ultra-source-agent | sed -n '1,25p'
python3 -VV
python3 - <<'PY'
import json
cfg = json.load(open('/etc/ultra-source-agent.json'))
for key in ('review_script', 'review_interval_seconds', 'review_big_bytes',
            'review_require_big_packet', 'max_reviews_per_cycle', 'state_dir'):
    print(key, '=', cfg.get(key))
PY
python3 -c "import sys; sys.path.insert(0, '/opt/ultra-source/runtime'); import native_udp as n; print('native_udp:', n.__file__); print('usable:', all(hasattr(n, k) for k in ('NativeSession', 'LineNotLive', 'AuthError')))"
```

---

## 5. STEP 1 — backup FIRST (timestamped)

```bash
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/root/leikwan-source-agent-backups/$STAMP
sudo mkdir -p "$BACKUP"
sudo cp -a /opt/ultra-source/runtime/ultra_line_review.py "$BACKUP/ultra_line_review.py.orig"
sudo cp -a /usr/local/bin/ultra-source-agent.py       "$BACKUP/ultra-source-agent.py.orig"
sudo cp -a /opt/ultra-source/runtime/__pycache__/ultra_line_review.cpython-311.pyc "$BACKUP/probe.cpython-311.pyc.orig" 2>/dev/null || true
sudo cp -a /usr/local/bin/__pycache__/ultra-source-agent.cpython-311.pyc            "$BACKUP/agent.cpython-311.pyc.orig" 2>/dev/null || true
sudo bash -c "cd '$BACKUP' && sha256sum ultra-source-agent.py.orig ultra_line_review.py.orig | tee SHA256SUMS"
sudo ls -l "$BACKUP"
echo "BACKUP_DIR=$BACKUP"
```

Before continuing, prove the backup restores the prior bytes:

```bash
sudo cmp "$BACKUP/ultra-source-agent.py.orig" /usr/local/bin/ultra-source-agent.py && echo "agent backup OK"
sudo cmp "$BACKUP/ultra_line_review.py.orig"  /opt/ultra-source/runtime/ultra_line_review.py && echo "probe backup OK"
```

---

## 6. STEP 2 — drift gate: only install over the known baseline

The gate REFUSES on anything it does not recognise. Do not force it past a refusal:
back the file up, record its digest, and report it — it may carry local fixes nobody
recorded.

```bash
sudo bash -c 'BASE=a9cc8b87b1bebf8a370bda023c9981ec; BASEA=db911ae5522e8e5986c6b8855a588dd3; NEW=24cd4309810a07bb5de8ad61d9593253; NEWA=264990c14399877fa0300ba65527736a;
P=/opt/ultra-source/runtime/ultra_line_review.py; A=/usr/local/bin/ultra-source-agent.py;
GOTP=$(md5sum "$P" | cut -d" " -f1); GOTA=$(md5sum "$A" | cut -d" " -f1);
echo "installed probe md5: $GOTP"; echo "installed agent md5: $GOTA";
if [ "$GOTP" = "$NEW" ] && [ "$GOTA" = "$NEWA" ]; then echo "already on the new revision - nothing to install"; exit 2; fi;
if [ "$GOTP" != "$BASE" ]; then echo "REFUSING: unknown probe revision - back it up and report it"; exit 1; fi;
if [ "$GOTA" != "$BASEA" ]; then echo "REFUSING: unknown agent revision - back it up and report it"; exit 1; fi;
echo "OK: documented baseline detected, safe to install"'
```

---

## 7. STEP 3 — install the two files

Paste each block as one piece. The heredoc is quoted (`'ULTRA_EOF'`), so nothing is
expanded while pasting. Bytes are verified against the digests above before anything
is trusted.

### 7a. the probe

```bash
sudo cp -a /opt/ultra-source/runtime/ultra_line_review.py /tmp/ultra_line_review.py.before-upgrade
sudo tee /opt/ultra-source/runtime/ultra_line_review.py >/dev/null <<'ULTRA_EOF'
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
        outcome.target_refused = outcome.connect_frame_rep != 0
        body = body[CONNECT_FRAME_SIZE:] if full else b""
    inner, framed = strip_address_header(body)
    if framed:
        outcome.dns_answer = validate_dns_answer(inner, identifier)
    if not outcome.dns_answer:
        outcome.dns_answer = validate_dns_answer(body, identifier)
    if body and body in (bytes(sent), xor_one(bytes(sent))):
        outcome.echo = True
    if not outcome.connect_frame and not outcome.dns_answer and not outcome.echo and len(body) >= CONNECT_REPLY_PREFIX_SIZE:
        # A reply that is not a frame we recognise, not an answer and not our own
        # bytes, but is long enough to be a protocol reply we simply do not know.
        # We cannot say what it means, so we say exactly that and keep the bytes.
        # A shorter payload stays classified by the downstream rules, so a one-byte
        # fragment is not newly reported as a protocol verdict.
        outcome.reply_unparsed = True
    outcome.ok = bool((outcome.connect_frame or outcome.dns_answer) and not outcome.echo)
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

    big_clean = not require_big_packet or (result.get("big_failed", 0) == 0 and result.get("big_ok", 0) > 0)
    result["passed"] = bool(
        target_configured
        and advertised_ok
        and result.get("rounds", 0) > 0
        and result.get("udp_failed", 0) == 0
        and result.get("udp_ok", 0) > 0
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

ULTRA_EOF
sudo sed -i 's/\r$//' /opt/ultra-source/runtime/ultra_line_review.py
sudo chmod 755 /opt/ultra-source/runtime/ultra_line_review.py
sudo chown root:root /opt/ultra-source/runtime/ultra_line_review.py
```

### 7b. the agent

```bash
sudo cp -a /usr/local/bin/ultra-source-agent.py /tmp/ultra_source_agent.py.before-upgrade
sudo tee /usr/local/bin/ultra-source-agent.py >/dev/null <<'ULTRA_EOF'
#!/usr/bin/env python3
"""Bounded outbound-only Ultra material collector."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import random
import re
import secrets
import shutil
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

try:
    import tomllib
except ImportError:  # pragma: no cover - Python 3.10 compatibility on source hosts
    tomllib = None


AGENT_VERSION = "2026.09.13.1"
MAX_INVENTORY_BYTES = 256 * 1024
INVENTORY_SCHEMA_VERSION = 3
MAX_INVENTORY_RECORDS = 1024
CATALOG_SCHEMA_VERSION = 1

LINELIST_SUMMARY_RE = re.compile(
    r"^os_type=\d+\s+game_id=\d+\s+area_id=\d+\s+lines=(\d+)\s*$",
    re.MULTILINE,
)
LINELIST_ROW_RE = re.compile(
    r"^\s*line_id=(\d+)\s+area_id=(\d+)\s+line_type=(\d+)\s+"
    r"title=(.*?)\s+speed_ip=(\S+)\s+pc_port=(\d+)\s*$",
    re.MULTILINE,
)


def rfc3339(timestamp: int | float) -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(timestamp))


def signed_headers(
    source_id: str,
    source_secret: str,
    method: str,
    path: str,
    body: bytes,
    *,
    timestamp: int | None = None,
    nonce: str | None = None,
) -> dict[str, str]:
    issued = int(time.time()) if timestamp is None else int(timestamp)
    request_nonce = nonce or secrets.token_hex(16)
    # The panel signs the request path without its query string, so a parameter
    # added to the URL must not leak into the canonical request.
    signed_path = path.split("?", 1)[0]
    canonical = "\n".join(
        [
            method.upper(),
            signed_path,
            source_id,
            str(issued),
            request_nonce,
            hashlib.sha256(body).hexdigest(),
        ]
    ).encode("utf-8")
    signature = hmac.new(source_secret.encode("utf-8"), canonical, hashlib.sha256).hexdigest()
    return {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": f"Ultra-Source-Agent/{AGENT_VERSION}",
        "X-Ultra-Source-Id": source_id,
        "X-Ultra-Timestamp": str(issued),
        "X-Ultra-Nonce": request_nonce,
        "X-Ultra-Signature": signature,
    }


def request_json(
    base_url: str,
    source_id: str,
    source_secret: str,
    method: str,
    path: str,
    payload: dict | None,
    timeout: float,
) -> dict:
    body = b"" if payload is None else json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    headers = signed_headers(source_id, source_secret, method, path, body)
    request = urllib.request.Request(
        urllib.parse.urljoin(base_url.rstrip("/") + "/", path.lstrip("/")),
        data=body if method.upper() != "GET" else None,
        headers=headers,
        method=method.upper(),
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            decoded = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        message = exc.read().decode("utf-8", errors="ignore")[:512]
        raise RuntimeError(f"control request failed with HTTP {exc.code}: {message}") from exc
    if not isinstance(decoded, dict) or decoded.get("ok") is not True:
        raise RuntimeError("control response was rejected")
    return decoded


def request_json_with_retries(
    base_url: str,
    source_id: str,
    source_secret: str,
    method: str,
    path: str,
    payload: dict | None,
    timeout: float,
    *,
    attempts: int = 3,
) -> dict:
    last_error: Exception | None = None
    count = max(1, min(3, int(attempts)))
    for attempt in range(count):
        try:
            return request_json(base_url, source_id, source_secret, method, path, payload, timeout)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            if attempt + 1 < count:
                time.sleep(0.5 if attempt == 0 else 1.5)
    raise RuntimeError(f"control request retries exhausted: {last_error}") from last_error


def _flatten_mapping(value: object) -> dict[str, object]:
    flattened: dict[str, object] = {}
    if not isinstance(value, dict):
        return flattened
    for raw_key, item in value.items():
        key = str(raw_key).strip().lower()
        if isinstance(item, dict):
            flattened.update(_flatten_mapping(item))
        elif key:
            flattened[key] = item
    return flattened


def _parse_line_config(raw: bytes) -> dict[str, object]:
    if tomllib is not None:
        try:
            return _flatten_mapping(tomllib.loads(raw.decode("utf-8-sig")))
        except (UnicodeDecodeError, ValueError):
            return {}
    parsed: dict[str, object] = {}
    for raw_line in raw.decode("utf-8-sig", errors="ignore").splitlines():
        line = raw_line.split("#", 1)[0].strip()
        if not line or line.startswith("[") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip().lower()
        value = value.strip().strip('"').strip("'")
        if key in {"line_id", "game_id", "area_id", "line_type"}:
            try:
                parsed[key] = int(value)
            except ValueError:
                continue
        elif key in {
            "speed_ip",
            "title",
            "family_key",
            "family",
            "access_class",
            "native_udp_state",
            "native_socks5_state",
            "route_class",
            "pricing_class",
            "pricing_evidence",
            "audit_at",
            "audited_at",
            "audit_timestamp",
            "checked_at",
            "audit_hash",
            "manifest_hash",
            "audit_sha256",
            "manifest_sha256",
            "sha256",
        }:
            parsed[key] = value
    return parsed


def _inventory_index(index_path: Path) -> dict[int, dict[str, object]]:
    try:
        if index_path.stat().st_size > MAX_INVENTORY_BYTES:
            raise RuntimeError("inventory index exceeds size limit")
        decoded = json.loads(index_path.read_text(encoding="utf-8-sig"))
    except FileNotFoundError:
        return {}
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("inventory index is invalid") from exc
    raw_lines = decoded.get("lines", []) if isinstance(decoded, dict) else decoded
    if not isinstance(raw_lines, list):
        return {}
    records: dict[int, dict[str, object]] = {}
    for raw in raw_lines[:MAX_INVENTORY_RECORDS]:
        if not isinstance(raw, dict):
            continue
        try:
            line_id = int(raw.get("line_id", 0) or 0)
        except (TypeError, ValueError):
            continue
        if 0 < line_id <= 45535:
            records[line_id] = raw
    return records


def _line_audit_manifest(manifest_path: Path | None) -> dict[int, dict[str, object]]:
    if manifest_path is None:
        return {}
    try:
        if manifest_path.stat().st_size > MAX_INVENTORY_BYTES:
            raise RuntimeError("line audit manifest exceeds size limit")
        decoded = json.loads(manifest_path.read_text(encoding="utf-8-sig"))
    except FileNotFoundError:
        return {}
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("line audit manifest is invalid") from exc

    known_fields = {
        "access_class",
        "native_udp_state",
        "native_socks5_state",
        "route_class",
        "pricing_class",
        "pricing_evidence",
        "audit_at",
        "audited_at",
        "audit_timestamp",
        "audit_time",
        "checked_at",
        "timestamp",
        "at",
        "audit_hash",
        "manifest_hash",
        "audit_sha256",
        "manifest_sha256",
        "sha256",
        "hash",
    }

    def known_values(value: object) -> dict[str, object]:
        if not isinstance(value, dict):
            return {}
        return {str(key).strip().lower(): item for key, item in value.items()
                if str(key).strip().lower() in known_fields}

    def default_values(value: object) -> dict[str, object]:
        defaults = known_values(value)
        if isinstance(value, dict):
            for key in ("audit", "metadata"):
                defaults.update(known_values(value.get(key)))
        return defaults

    def has_line_id(value: object, depth: int = 0) -> bool:
        if not isinstance(value, dict):
            return False
        if value.get("line_id") not in (None, ""):
            return True
        if depth >= 3:
            return False
        return any(has_line_id(value.get(key), depth + 1) for key in ("line", "audit", "record", "data"))

    def line_id_from(*values: object, _depth: int = 0) -> int:
        for value in values:
            if isinstance(value, dict):
                try:
                    candidate = int(value.get("line_id", 0) or 0)
                except (TypeError, ValueError):
                    candidate = 0
                if 0 < candidate <= 45535:
                    return candidate
                if _depth < 3:
                    candidate = line_id_from(
                        *(value.get(key) for key in ("line", "audit", "record", "data")),
                        _depth=_depth + 1,
                    )
                    if candidate:
                        return candidate
        return 0

    manifest_defaults: dict[str, object] = {}
    if isinstance(decoded, dict):
        # Defaults may be flat or grouped under an audit/defaults object. Keep
        # this allowlist-only so credentials and opaque provider fields never
        # enter the inventory record.
        manifest_defaults.update(default_values(decoded))
        for key in ("defaults", "default", "audit_defaults", "audit_default"):
            manifest_defaults.update(default_values(decoded.get(key)))
        top_audit = decoded.get("audit")
        if isinstance(top_audit, dict) and not has_line_id(top_audit):
            manifest_defaults.update(default_values(top_audit))

    if isinstance(decoded, list):
        raw_lines: object = decoded
    elif isinstance(decoded, dict):
        if has_line_id(decoded):
            raw_lines = [decoded]
        else:
            raw_lines = decoded
            for key in ("lines", "records", "audits", "audit"):
                if key in decoded:
                    raw_lines = decoded[key]
                    break
    else:
        return {}

    if isinstance(raw_lines, list):
        iterable = ((None, raw) for raw in raw_lines[:MAX_INVENTORY_RECORDS])
    elif isinstance(raw_lines, dict):
        iterable = list(raw_lines.items())[:MAX_INVENTORY_RECORDS]
    else:
        return {}

    records: dict[int, dict[str, object]] = {}
    for raw_key, raw in iterable:
        if not isinstance(raw, dict):
            continue
        line_id = line_id_from(raw, raw.get("line"), raw.get("audit"), {"line_id": raw_key})
        if 0 < line_id <= 45535:
            record = dict(manifest_defaults)
            nested_line = raw.get("line")
            nested_audit = raw.get("audit")
            record.update(default_values(nested_line))
            if isinstance(nested_audit, dict):
                record.update(default_values(nested_audit))
            record.update(known_values(raw))
            record["line_id"] = line_id
            records[line_id] = record
    return records


def _bounded_text(value: object, limit: int = 255) -> str:
    text = str(value or "").strip()
    if any(ord(character) < 32 and character not in "\t\n\r" for character in text):
        return ""
    return text[:limit]


def _unsigned_int(*values: object) -> int | None:
    for value in values:
        if value is None or value == "":
            continue
        try:
            number = int(value)
        except (TypeError, ValueError):
            continue
        if 0 <= number <= 4294967295:
            return number
    return None


def _literal_ip(*values: object) -> str:
    for value in values:
        text = str(value or "").strip()
        try:
            return str(ipaddress.ip_address(text))
        except ValueError:
            continue
    return ""


def _single_line_text(value: object, limit: int = 255) -> str:
    return _bounded_text(" ".join(str(value or "").split()), limit)


def _safe_inventory_text(value: object, limit: int = 255) -> str:
    if not isinstance(value, (bool, int, float, str)):
        return ""
    return _single_line_text(value, limit)


def _first_value(*records: dict[str, object], keys: tuple[str, ...]) -> object:
    for record in records:
        for key in keys:
            value = record.get(key)
            if value is not None and value != "":
                return value
    return None


def _inventory_classification(value: object) -> str:
    text = _safe_inventory_text(value, 64).lower()
    return text or "unknown"


def _inventory_evidence(value: object) -> str:
    return _safe_inventory_text(value, 255) or "unknown"


def _inventory_audit_at(value: object) -> str:
    return _safe_inventory_text(value, 64) or "unknown"


def _inventory_audit_hash(*records: dict[str, object]) -> str:
    for record in records:
        for key in ("audit_hash", "manifest_hash", "audit_sha256", "manifest_sha256", "sha256", "hash"):
            text = _safe_inventory_text(record.get(key), 64).lower()
            if re.fullmatch(r"[0-9a-f]{64}", text):
                return text
    return "unknown"


def _inventory_config_hash(
    line_id: int,
    raw: bytes | None,
    line: dict[str, object],
    *records: dict[str, object],
) -> str:
    if raw is not None:
        return hashlib.sha256(raw).hexdigest()
    for record in records:
        text = _safe_inventory_text(record.get("config_sha256"), 64).lower()
        if re.fullmatch(r"[0-9a-f]{64}", text):
            return text
    identity = dict(line)
    identity.pop("config_sha256", None)
    identity["line_id"] = line_id
    encoded = json.dumps(identity, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def parse_linelist_output(output: str) -> tuple[bool, dict[int, dict[str, object]]]:
    summary = LINELIST_SUMMARY_RE.search(str(output))
    if summary is None:
        return False, {}
    declared_count = int(summary.group(1))
    matched_count = 0
    records: dict[int, dict[str, object]] = {}
    for match in LINELIST_ROW_RE.finditer(str(output)):
        matched_count += 1
        line_id = int(match.group(1))
        if line_id < 1 or line_id > 45535:
            continue
        title = _single_line_text(match.group(4), 255)
        segments = [segment.strip() for segment in title.split("-") if segment.strip()]
        records[line_id] = {
            "line_id": line_id,
            "title": title,
            "catalog_ingress": _single_line_text(segments[0] if len(segments) >= 2 else "", 191),
            "catalog_destination": _single_line_text(segments[1] if len(segments) >= 2 else "", 191),
            "discovery_ip": _literal_ip(match.group(5)),
        }
    if matched_count != declared_count:
        return False, {}
    return True, records


def _empty_catalog_cache() -> dict[str, object]:
    return {"schema_version": CATALOG_SCHEMA_VERSION, "records": {}, "pairs": {}}


def _read_catalog_cache(path: Path) -> dict[str, object]:
    try:
        if path.stat().st_size > MAX_INVENTORY_BYTES:
            return _empty_catalog_cache()
        decoded = json.loads(path.read_text(encoding="utf-8-sig"))
    except (FileNotFoundError, OSError, UnicodeDecodeError, json.JSONDecodeError):
        return _empty_catalog_cache()
    if not isinstance(decoded, dict):
        return _empty_catalog_cache()
    records = decoded.get("records", {})
    pairs = decoded.get("pairs", {})
    return {
        "schema_version": CATALOG_SCHEMA_VERSION,
        "records": records if isinstance(records, dict) else {},
        "pairs": pairs if isinstance(pairs, dict) else {},
    }


def _write_catalog_cache(path: Path, cache: dict[str, object]) -> None:
    encoded = json.dumps(cache, ensure_ascii=False, separators=(",", ":"), sort_keys=True) + "\n"
    if len(encoded.encode("utf-8")) > MAX_INVENTORY_BYTES:
        raise RuntimeError("catalog cache exceeds size limit")
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    try:
        temporary.write_text(encoded, encoding="utf-8")
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)
        os.chmod(path, 0o600)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def _line_config_groups(lines_dir: Path) -> dict[tuple[int, int], list[tuple[int, Path]]]:
    groups: dict[tuple[int, int], list[tuple[int, Path]]] = {}
    for path in lines_dir.glob("*.toml"):
        try:
            line_id = int(path.stem)
            metadata = _parse_line_config(path.read_bytes())
            game_id = int(metadata.get("game_id", 0) or 0)
            area_id = int(metadata.get("area_id", 0) or 0)
        except (OSError, TypeError, ValueError):
            continue
        if not (0 < line_id <= 45535 and game_id > 0 and area_id > 0):
            continue
        groups.setdefault((game_id, area_id), []).append((line_id, path))
    for members in groups.values():
        members.sort(key=lambda item: item[0])
    return dict(sorted(groups.items()))


def refresh_catalog_cache(
    lines_dir: Path,
    binary_path: Path,
    session_path: Path | None,
    cache_path: Path,
    *,
    now: int,
    refresh_seconds: int,
    retry_seconds: int,
    max_queries: int,
    timeout: float,
) -> dict[str, int]:
    cache = _read_catalog_cache(cache_path)
    records = dict(cache.get("records", {}))
    pair_states = dict(cache.get("pairs", {}))
    groups = _line_config_groups(lines_dir)
    due: list[tuple[tuple[int, int], list[tuple[int, Path]]]] = []
    refresh_after = max(300, int(refresh_seconds))
    retry_after = max(60, int(retry_seconds))
    for pair, members in groups.items():
        key = f"{pair[0]}:{pair[1]}"
        state = pair_states.get(key, {})
        state = state if isinstance(state, dict) else {}
        last_attempt = int(state.get("last_attempt_at", 0) or 0)
        last_success = int(state.get("last_success_at", 0) or 0)
        if last_success > 0 and now - last_success < refresh_after:
            continue
        if last_success <= 0 and last_attempt > 0 and now - last_attempt < retry_after:
            continue
        due.append((pair, members))
    attempted = 0
    succeeded = 0
    changed = 0
    for pair, members in due[: max(1, min(64, int(max_queries)))]:
        attempted += 1
        key = f"{pair[0]}:{pair[1]}"
        previous_state = pair_states.get(key, {})
        previous_state = previous_state if isinstance(previous_state, dict) else {}
        pair_states[key] = {
            "last_attempt_at": now,
            "last_success_at": int(previous_state.get("last_success_at", 0) or 0),
        }
        command = [str(binary_path), "linelist", "--config", str(members[0][1])]
        if session_path is not None:
            command.extend(["--session", str(session_path)])
        try:
            completed = subprocess.run(
                command,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=max(1.0, min(30.0, float(timeout))),
                check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            continue
        if completed.returncode != 0:
            continue
        valid, current = parse_linelist_output(completed.stdout)
        if not valid:
            continue
        local_ids = {line_id for line_id, _path in members}
        before = {str(line_id): records.get(str(line_id)) for line_id in local_ids if str(line_id) in records}
        for line_id in local_ids:
            records.pop(str(line_id), None)
        for line_id, record in current.items():
            if line_id in local_ids:
                records[str(line_id)] = record
        after = {str(line_id): records.get(str(line_id)) for line_id in local_ids if str(line_id) in records}
        if before != after:
            changed += 1
        pair_states[key] = {"last_attempt_at": now, "last_success_at": now}
        succeeded += 1
    cache = {
        "schema_version": CATALOG_SCHEMA_VERSION,
        "updated_at": now,
        "records": dict(sorted(records.items(), key=lambda item: int(item[0]))),
        "pairs": dict(sorted(pair_states.items())),
    }
    if attempted > 0 or not cache_path.exists():
        _write_catalog_cache(cache_path, cache)
    return {"attempted": attempted, "succeeded": succeeded, "changed": changed}


def _catalog_snapshot(
    path: Path | None,
    required_pairs: set[tuple[int, int]],
) -> tuple[dict[int, dict[str, object]], bool]:
    if path is None:
        return {}, False
    cache = _read_catalog_cache(path)
    raw_records = cache.get("records", {})
    records: dict[int, dict[str, object]] = {}
    for raw_line_id, raw in list(raw_records.items())[:MAX_INVENTORY_RECORDS] if isinstance(raw_records, dict) else []:
        if not isinstance(raw, dict):
            continue
        try:
            line_id = int(raw_line_id)
        except (TypeError, ValueError):
            continue
        if 0 < line_id <= 45535:
            records[line_id] = raw
    pair_states = cache.get("pairs", {})
    authoritative = bool(required_pairs)
    for game_id, area_id in required_pairs:
        state = pair_states.get(f"{game_id}:{area_id}", {}) if isinstance(pair_states, dict) else {}
        if not isinstance(state, dict) or int(state.get("last_success_at", 0) or 0) <= 0:
            authoritative = False
            break
    return records, authoritative


def build_inventory(
    lines_dir: Path,
    index_path: Path,
    *,
    catalog_path: Path | None = None,
    audit_manifest_path: Path | None = None,
    max_lines: int = 256,
) -> dict[str, object]:
    limit = max(1, min(256, int(max_lines)))
    index = _inventory_index(index_path)
    audit = _line_audit_manifest(audit_manifest_path)
    catalog, _catalog_authoritative = _catalog_snapshot(catalog_path, set())
    local_paths: dict[int, Path] = {}
    for path in lines_dir.glob("*.toml"):
        try:
            line_id = int(path.stem)
        except ValueError:
            continue
        if 0 < line_id <= 45535 and path.is_file():
            local_paths[line_id] = path

    candidate_ids = sorted(set(local_paths) | set(index) | set(audit))
    all_lines: list[dict[str, object]] = []
    for line_id in candidate_ids:
        path = local_paths.get(line_id)
        raw = path.read_bytes() if path is not None else None
        metadata = _parse_line_config(raw) if raw is not None else {}
        indexed = index.get(line_id, {})
        audited = audit.get(line_id, {})
        cataloged = catalog.get(line_id, {})
        line: dict[str, object] = {
            "line_id": line_id,
            "title": _safe_inventory_text(
                _first_value(cataloged, audited, indexed, metadata, keys=("title", "name", "label"))
            ),
            "family_key": _safe_inventory_text(
                _first_value(audited, indexed, metadata, keys=("family_key", "family"))
            ),
            "game_id": _unsigned_int(
                metadata.get("game_id"), audited.get("game_id"), indexed.get("game_id")
            ),
            "area_id": _unsigned_int(
                metadata.get("area_id"), audited.get("area_id"), indexed.get("area_id")
            ),
            "line_type": _unsigned_int(
                metadata.get("line_type"), audited.get("line_type"), indexed.get("line_type")
            ),
            # Carrier metadata (2026-09-11): the sweep index is the freshest source,
            # the audit manifest may add it later. The panel normalizes 电信/联通/移动/通用
            # into telecom/unicom/mobile/generic.
            "in_line_type": _unsigned_int(
                metadata.get("in_line_type"), audited.get("in_line_type"), indexed.get("in_line_type")
            ),
            "isp": _safe_inventory_text(
                _first_value(indexed, audited, metadata, keys=("isp",)), 32
            ),
            "provider": _safe_inventory_text(
                _first_value(indexed, audited, metadata, keys=("provider",)), 64
            ),
            # The audit manifest may declare the dispatch priority: a line whose relay
            # association drops big datagrams is dispatched last, like the Tencent ones.
            "dispatch_class": _inventory_classification(
                _first_value(audited, indexed, keys=("dispatch_class",))
            ),
            "catalog_ingress": _safe_inventory_text(
                _first_value(cataloged, audited, indexed, keys=("catalog_ingress",)), 191
            ),
            "catalog_destination": _safe_inventory_text(
                _first_value(cataloged, audited, indexed, keys=("catalog_destination",)), 191
            ),
            "discovery_ip": _literal_ip(
                cataloged.get("discovery_ip"),
                audited.get("discovery_ip"),
                indexed.get("speed_ip"),
                indexed.get("discovery_ip"),
                metadata.get("speed_ip"),
            ),
            "access_class": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("access_class",))
            ),
            "native_udp_state": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("native_udp_state",))
            ),
            "native_socks5_state": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("native_socks5_state",))
            ),
            "route_class": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("route_class",))
            ),
            "pricing_class": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("pricing_class",))
            ),
            "pricing_evidence": _inventory_evidence(
                _first_value(audited, indexed, metadata, keys=("pricing_evidence",))
            ),
            "audit_at": _inventory_audit_at(
                _first_value(
                    audited,
                    indexed,
                    metadata,
                    keys=("audit_at", "audited_at", "audit_timestamp", "checked_at", "timestamp"),
                )
            ),
            "audit_hash": _inventory_audit_hash(audited, indexed, metadata),
        }
        line["config_sha256"] = _inventory_config_hash(line_id, raw, line, audited, indexed)
        all_lines.append(line)

    complete = len(all_lines) <= limit
    normalized = all_lines[:limit]
    fingerprint_input = json.dumps(
        {
            "schema_version": INVENTORY_SCHEMA_VERSION,
            "complete": complete,
            "line_count": len(all_lines),
            "lines": all_lines,
        },
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    payload: dict[str, object] = {
        "schema_version": INVENTORY_SCHEMA_VERSION,
        "agent_version": AGENT_VERSION,
        "inventory_fingerprint": hashlib.sha256(fingerprint_input).hexdigest(),
        "complete": complete,
        "lines": normalized,
    }
    if len(json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")) > MAX_INVENTORY_BYTES:
        raise RuntimeError("inventory payload exceeds size limit")
    return payload


def inventory_due(
    state_path: Path,
    fingerprint: str,
    *,
    now: int | float,
    refresh_seconds: int | float,
) -> bool:
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
    except (FileNotFoundError, OSError, UnicodeDecodeError, json.JSONDecodeError):
        return True
    if not isinstance(state, dict) or str(state.get("inventory_fingerprint", "")) != fingerprint:
        return True
    try:
        uploaded_at = float(state.get("uploaded_at", 0))
    except (TypeError, ValueError):
        return True
    return float(now) - uploaded_at >= max(60.0, float(refresh_seconds))


def write_inventory_state(state_path: Path, fingerprint: str, *, uploaded_at: int | float) -> None:
    state_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = state_path.with_name(f".{state_path.name}.{os.getpid()}.tmp")
    encoded = json.dumps(
        {"inventory_fingerprint": fingerprint, "uploaded_at": int(uploaded_at)},
        separators=(",", ":"),
    ) + "\n"
    try:
        temporary.write_text(encoded, encoding="utf-8")
        os.chmod(temporary, 0o600)
        os.replace(temporary, state_path)
        os.chmod(state_path, 0o600)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def normalize_acquisition(line_id: int, response: dict, *, now: int, lifetime_seconds: int) -> dict:
    data = response.get("data") if isinstance(response, dict) else None
    if not isinstance(data, dict):
        raise RuntimeError("acquisition response has no data")
    user = str(data.get("line_user", "") or "").strip()
    credential = str(data.get("line_token", "") or "").strip()
    if not user or len(user) > 255 or not credential or len(credential) > 255:
        raise RuntimeError("acquisition response has invalid material")
    default_port = int(data.get("pc_port", 0) or 0)
    relays: list[dict[str, object]] = []
    seen: set[tuple[str, int]] = set()

    def add(ip_value: object, port_value: object) -> None:
        ip_text = str(ip_value or "").strip()
        try:
            ip_text = str(ipaddress.ip_address(ip_text))
        except ValueError:
            return
        try:
            port = int(port_value or default_port)
        except (TypeError, ValueError):
            return
        if not 1 <= port <= 65535 or (ip_text, port) in seen:
            return
        seen.add((ip_text, port))
        relays.append({"ip": ip_text, "port": port})

    add(data.get("speed_ip"), default_port)
    add(data.get("test_speed_ip"), default_port)
    for item in data.get("fail_over_list", []) or []:
        if isinstance(item, dict):
            add(item.get("speed_ip") or item.get("server_ip"), item.get("pc_port") or item.get("server_port"))
    spare_ports = data.get("spare_port_list", []) or []
    for index, item in enumerate(data.get("spare_list", []) or []):
        if isinstance(item, dict):
            add(item.get("speed_ip") or item.get("server_ip") or item.get("ip"), item.get("pc_port") or item.get("server_port") or item.get("port"))
        else:
            port = spare_ports[index] if index < len(spare_ports) else default_port
            add(item, port)
    if not relays:
        raise RuntimeError("acquisition response has no literal relay entries")
    relays = relays[:8]
    generation_seed = json.dumps([line_id, user, credential, relays], separators=(",", ":"), sort_keys=True).encode("utf-8")
    return {
        "line_id": int(line_id),
        "generation": hashlib.sha256(generation_seed).hexdigest(),
        "acquired_at": rfc3339(now),
        "expires_at": rfc3339(now + max(60, int(lifetime_seconds))),
        "user": user,
        "credential": credential,
        "relays": relays,
        "probe": {"ok": True},
    }


def acquire_line(
    line_id: int,
    binary_path: Path,
    lines_dir: Path,
    output_path: Path,
    *,
    session_path: Path | None = None,
    timeout: float,
    lifetime_seconds: int,
    now: int | None = None,
) -> dict:
    line_path = lines_dir / f"{line_id}.toml"
    if not line_path.is_file():
        raise RuntimeError(f"line configuration {line_id} is missing")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        output_path.unlink()
    except FileNotFoundError:
        pass
    command = [str(binary_path), "startacc", "--config", str(line_path)]
    if session_path is not None:
        command.extend(["--session", str(session_path)])
    command.extend(["--save", str(output_path)])
    completed = subprocess.run(
        command,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=max(1.0, float(timeout)),
        check=False,
    )
    if completed.returncode != 0 or not output_path.is_file():
        raise RuntimeError(f"line acquisition {line_id} failed")
    try:
        response = json.loads(output_path.read_text(encoding="utf-8"))
        return normalize_acquisition(
            line_id,
            response,
            now=int(time.time()) if now is None else int(now),
            lifetime_seconds=lifetime_seconds,
        )
    finally:
        try:
            output_path.unlink()
        except FileNotFoundError:
            pass


def advertised_relay_ips(advertised: object) -> list:
    """The relay IPs the panel advertised for a review, validated and deduplicated.

    The panel sends the set it re-derives from ultra_lines.relay_ips_json, which
    is also the set reviewProof() checks a probed endpoint against, so an agent
    cannot widen its own mandate by claiming an endpoint.
    """
    if isinstance(advertised, str):
        advertised = [item for item in advertised.split(",")]
    if not isinstance(advertised, (list, tuple)):
        return []
    seen = {}
    for item in advertised:
        ip = str(item or "").strip()
        if not ip:
            continue
        seen.setdefault(ip.lower(), ip)
    return list(seen.values())


def select_review_relay(relays: object, advertised: object) -> tuple:
    """Pick the relay a review may probe.

    The reviewed endpoint must be one the panel advertises for the line, and the
    panel stores relay IPs only - it cannot express ip:port for a review - so the
    advertised IP is paired with the port the line material carried for it. An
    endpoint the source host picked for itself is not evidence about the
    candidate, which is how 8 of the first 9 reviews came to test the wrong
    endpoint.

    Returns (relay, reason); reason is "" when a relay was chosen.
    """
    usable = []
    for raw in relays if isinstance(relays, list) else []:
        if not isinstance(raw, dict):
            continue
        ip = str(raw.get("ip", "") or "").strip()
        try:
            port = int(raw.get("port", 0) or 0)
        except (TypeError, ValueError):
            continue
        if not ip or port <= 0 or port > 65535:
            continue
        usable.append({"ip": ip, "port": port})
    if not usable:
        return None, "line material has no relay"
    mandate = advertised_relay_ips(advertised)
    if not mandate:
        # An older panel payload carries no advertised set at all. Run the review
        # anyway so the agent keeps working, but its proof names an endpoint the
        # panel never advertised, and the panel's own proof gate refuses it - the
        # intended outcome for evidence about an endpoint nobody advertised.
        return usable[0], ""
    by_ip = {}
    for relay in usable:
        by_ip.setdefault(relay["ip"].lower(), relay)
    for ip in mandate:
        chosen = by_ip.get(ip.lower())
        if chosen is not None:
            return chosen, ""
    return None, "no advertised relay in the line material"


def non_discriminating_review_detail(reason: str, target: str = "", relay: str = "") -> dict:
    """A probe-shaped result that can never be mistaken for evidence.

    The panel stores this verbatim and its proof gate records the review as
    rebuttal=blocked, so nothing is struck, demoted or lifted on a review that
    could not test an advertised endpoint.
    """
    return {
        "passed": False,
        "relay": relay,
        "target": target,
        "rounds": 0,
        "last_error": reason,
        "end_to_end_ok": 0,
        "local_path_only": True,
        "proof": {
            "endpoint": relay,
            "local_path_only": True,
            "end_to_end_ok": 0,
            "target": target,
            "method": "relay_stream_to_client_target",
            "reason": reason,
        },
    }


class SourceAgent:
    def __init__(self, config_path: Path):
        self.config = json.loads(config_path.read_text(encoding="utf-8-sig"))
        self.base_url = str(self.config["panel_url"]).rstrip("/")
        if urllib.parse.urlparse(self.base_url).scheme != "https":
            raise RuntimeError("panel_url must use HTTPS")
        self.source_id = str(self.config["source_id"])
        self.source_secret = str(self.config["source_secret"])
        self.binary_path = Path(self.config.get("binary_path", "/opt/ultra-source/runtime/line-client"))
        # Panel-requested reviews of quarantined lines. The probe keeps a large
        # UDP payload and a real DNS query alive for the whole window; a line the
        # provider side can still carry is cleared on the panel.
        self.review_script = Path(self.config.get("review_script", "/opt/ultra-source/runtime/ultra_line_review.py"))
        self.review_interval = float(self.config.get("review_interval_seconds", 20))
        self.review_big_bytes = int(self.config.get("review_big_bytes", 1400))
        self.review_require_big_packet = bool(self.config.get("review_require_big_packet", False))
        self.max_reviews_per_cycle = int(self.config.get("max_reviews_per_cycle", 1))
        self.lines_dir = Path(self.config.get("lines_dir", "/opt/ultra-source/lines"))
        self.state_dir = Path(self.config.get("state_dir", "/var/lib/ultra-source-agent"))
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.inventory_path = Path(self.config.get("inventory_path", str(self.lines_dir / "index.json")))
        self.inventory_refresh = max(300, min(86400, int(self.config.get("inventory_refresh_seconds", 1800))))
        self.max_inventory_lines = max(1, min(256, int(self.config.get("max_inventory_lines", 256))))
        self.inventory_state_path = self.state_dir / "inventory-state.json"
        configured_audit = ""
        for key in ("audit_manifest_path", "line_audit_manifest_path"):
            configured_audit = str(self.config.get(key, "") or "").strip()
            if configured_audit:
                break
        self.audit_manifest_path = Path(configured_audit) if configured_audit else None
        self.catalog_path = Path(self.config.get("catalog_path", str(self.state_dir / "catalog-cache.json")))
        self.catalog_refresh = max(1800, min(604800, int(self.config.get("catalog_refresh_seconds", 21600))))
        self.catalog_retry = max(60, min(3600, int(self.config.get("catalog_retry_seconds", 300))))
        self.catalog_max_queries = max(1, min(8, int(self.config.get("catalog_max_queries_per_cycle", 4))))
        self.catalog_timeout = max(2.0, min(30.0, float(self.config.get("catalog_query_timeout_seconds", 15))))
        configured_session = str(self.config.get("session_path", "") or "").strip()
        self.session_path = Path(configured_session) if configured_session else None
        self._harden_session_permissions()
        self.login_binary_path = Path(self.config.get("login_binary_path", str(self.binary_path)))
        self.login_mobile = str(self.config.get("login_mobile", "") or "").strip()
        self.login_country = max(1, min(999, int(self.config.get("login_country", 86))))
        self.login_region = max(1, min(999, int(self.config.get("login_region", 1))))
        self.login_config_path = str(self.config.get("login_config_path", "") or "").strip()
        self.login_password_env = str(self.config.get("login_password_env", "LEIGOD_PASSWORD") or "").strip()
        self.login_home = Path(str(self.config.get("login_home", "") or "").strip()) if str(self.config.get("login_home", "") or "").strip() else None
        self.login_timeout = max(15.0, min(180.0, float(self.config.get("login_timeout_seconds", 60))))
        self.login_retry = max(60.0, min(3600.0, float(self.config.get("login_retry_seconds", 300))))
        self._last_login_at = 0.0
        self.timeout = max(2.0, min(30.0, float(self.config.get("request_timeout_seconds", 10))))
        self.acquire_timeout = max(5.0, min(60.0, float(self.config.get("acquire_timeout_seconds", 30))))
        self.material_lifetime = max(300, min(3600, int(self.config.get("material_lifetime_seconds", 1800))))
        self.max_lines = max(1, min(8, int(self.config.get("max_lines_per_cycle", 8))))
        self.poll_interval = max(30.0, min(900.0, float(self.config.get("poll_interval_seconds", 60))))

    def run_cycle(self) -> int:
        try:
            inventory = build_inventory(
                self.lines_dir,
                self.inventory_path,
                catalog_path=self.catalog_path,
                audit_manifest_path=self.audit_manifest_path,
                max_lines=self.max_inventory_lines,
            )
            now = int(time.time())
            fingerprint = str(inventory["inventory_fingerprint"])
            if inventory_due(
                self.inventory_state_path,
                fingerprint,
                now=now,
                refresh_seconds=self.inventory_refresh,
            ):
                request_json(
                    self.base_url,
                    self.source_id,
                    self.source_secret,
                    "POST",
                    "/api/ultra/source/inventory",
                    inventory,
                    self.timeout,
                )
                write_inventory_state(self.inventory_state_path, fingerprint, uploaded_at=now)
        except Exception as exc:  # noqa: BLE001
            logging.warning("inventory upload failed: %s", exc)
        jobs = request_json_with_retries(
            self.base_url,
            self.source_id,
            self.source_secret,
            "GET",
            "/api/ultra/source/jobs?reviews_capacity=" + str(max(0, self.max_reviews_per_cycle)),
            None,
            self.timeout,
            attempts=3,
        )
        ticket = str(jobs.get("upload_ticket", "") or "")
        reviews = []
        for raw_review in jobs.get("reviews", []) if isinstance(jobs.get("reviews"), list) else []:
            if isinstance(raw_review, dict):
                reviews.append(raw_review)
        line_ids = []
        for raw_line_id in jobs.get("line_ids", []) if isinstance(jobs.get("line_ids"), list) else []:
            line_id = int(raw_line_id or 0)
            if 0 < line_id <= 45535 and line_id not in line_ids:
                line_ids.append(line_id)
        line_ids = line_ids[: self.max_lines]
        if not line_ids:
            self.process_reviews(reviews)
            self.refresh_catalog()
            return 0
        def acquire_requested(requested_ids: list[int] | None = None) -> tuple[list[dict], list[int]]:
            pending_ids = line_ids if requested_ids is None else requested_ids
            acquired: list[dict] = []
            failed: list[int] = []
            for line_id in pending_ids:
                try:
                    acquired.append(
                        acquire_line(
                            line_id,
                            self.binary_path,
                            self.lines_dir,
                            self.state_dir / f"line-{line_id}.json.tmp",
                            session_path=self.session_path,
                            timeout=self.acquire_timeout,
                            lifetime_seconds=self.material_lifetime,
                        )
                    )
                except Exception as exc:  # noqa: BLE001
                    logging.warning("line %s acquisition failed: %s", line_id, exc)
                    failed.append(line_id)
            return acquired, failed

        materials, failed_line_ids = acquire_requested()
        if failed_line_ids and self.reauthenticate():
            logging.info(
                "source session refreshed; retrying %s failed line materials",
                len(failed_line_ids),
            )
            retried, _still_failed = acquire_requested(failed_line_ids)
            materials.extend(retried)
        if not materials:
            raise RuntimeError("no requested material could be acquired")
        result = request_json(
            self.base_url,
            self.source_id,
            self.source_secret,
            "POST",
            "/api/ultra/source/materials",
            {"upload_ticket": ticket, "materials": materials},
            self.timeout,
        )
        accepted = len(result.get("accepted_line_ids", [])) if isinstance(result.get("accepted_line_ids"), list) else 0
        self.process_reviews(reviews)
        self.refresh_catalog()
        return accepted

    def process_reviews(self, reviews: list[dict]) -> int:
        """Run the reviews the panel asked for and report each outcome back.

        Reviews are serial and happen after the material work, so a long review
        window cannot delay the line acquisitions that keep forwards alive.
        """
        if not reviews:
            return 0
        completed = 0
        for review in reviews[: max(0, self.max_reviews_per_cycle)]:
            review_id = int(review.get("review_id", 0) or 0)
            line_id = int(review.get("line_id", 0) or 0)
            seconds = int(review.get("seconds", 600) or 600)
            if review_id <= 0 or line_id <= 0:
                continue
            # The panel hands down the advertised relay set and the client's
            # target; without them the probe can only test an endpoint it picked
            # for itself, which is not evidence about this candidate.
            payload = self.run_review(
                review_id,
                line_id,
                seconds,
                advertised=review.get("relays"),
                target=str(review.get("target", "") or ""),
            )
            try:
                request_json(
                    self.base_url,
                    self.source_id,
                    self.source_secret,
                    "POST",
                    "/api/ultra/source/review",
                    payload,
                    self.timeout,
                )
                completed += 1
                detail = payload.get("detail") if isinstance(payload.get("detail"), dict) else {}
                proof = detail.get("proof") if isinstance(detail.get("proof"), dict) else {}
                # Local, secret-free evidence that the review was discriminating:
                # the panel records rebuttal=blocked unless the proof block names
                # an advertised endpoint and carried a real transfer, so this line
                # is how an operator tells a working deploy from a silent one.
                logging.info(
                    "review %s line %s passed=%s proof_reason=%s end_to_end_ok=%s zero_downstream=%s note=%s",
                    review_id,
                    line_id,
                    bool(payload.get("passed")),
                    str(proof.get("reason", "") or "none"),
                    proof.get("end_to_end_ok", 0),
                    detail.get("target_tcp_zero_downstream", 0),
                    str(payload.get("note", ""))[:160],
                )
            except Exception as exc:  # noqa: BLE001
                logging.warning("review %s could not be reported: %s", review_id, exc)
        return completed

    def run_review(
        self,
        review_id: int,
        line_id: int,
        seconds: int,
        advertised: object = None,
        target: str = "",
    ) -> dict:
        """Acquire the line and probe the client's target through an advertised relay.

        The probe's verdict requires a real transfer to the client's target over
        the relay, so a review that cannot be pointed at an advertised endpoint -
        or that has no client target - is reported as a non-discriminating result
        rather than run against an endpoint of the agent's own choosing.
        """
        target_text = str(target or "").strip()
        if not self.review_script.is_file():
            return {
                "review_id": review_id,
                "passed": False,
                "note": "review probe is not installed",
                "detail": non_discriminating_review_detail("review probe is not installed", target_text),
            }
        try:
            material = acquire_line(
                line_id,
                self.binary_path,
                self.lines_dir,
                self.state_dir / f"review-{line_id}.json.tmp",
                session_path=self.session_path,
                timeout=self.acquire_timeout,
                lifetime_seconds=self.material_lifetime,
            )
        except Exception as exc:  # noqa: BLE001
            note = f"line acquisition failed: {exc}"
            return {
                "review_id": review_id,
                "passed": False,
                "note": note,
                "detail": non_discriminating_review_detail(note, target_text),
            }
        relays = material.get("relays") if isinstance(material.get("relays"), list) else []
        mandate = advertised_relay_ips(advertised)
        relay, reason = select_review_relay(relays, mandate)
        if relay is None:
            return {
                "review_id": review_id,
                "passed": False,
                "note": reason,
                "detail": non_discriminating_review_detail(reason, target_text),
            }
        relay_text = f"{relay['ip']}:{relay['port']}"
        window = max(30, min(1800, seconds))
        command = [
            "python3",
            str(self.review_script),
            "--relay", relay_text,
            "--user", str(material.get("user", "")),
            "--password", str(material.get("credential", "")),
            "--seconds", str(window),
            "--interval", str(max(1.0, self.review_interval)),
            "--big-bytes", str(max(64, min(65000, self.review_big_bytes))),
            "--target", target_text,
            "--json",
        ]
        if mandate:
            command.extend(["--advertised", ",".join(mandate)])
        if self.review_require_big_packet:
            command.append("--require-big-packet")
        try:
            completed = subprocess.run(
                command,
                capture_output=True,
                text=True,
                timeout=window + 120,
                check=False,
            )
        except subprocess.TimeoutExpired:
            return {
                "review_id": review_id,
                "passed": False,
                "note": "review timed out",
                "detail": non_discriminating_review_detail("review timed out", target_text, relay_text),
            }
        except OSError as exc:
            note = f"review could not start: {exc}"
            return {
                "review_id": review_id,
                "passed": False,
                "note": note,
                "detail": non_discriminating_review_detail(note, target_text, relay_text),
            }
        detail: dict = {}
        for line in reversed((completed.stdout or "").strip().splitlines()):
            try:
                parsed = json.loads(line)
            except ValueError:
                continue
            if isinstance(parsed, dict):
                detail = parsed
                break
        if not detail:
            # An agent whose probe predates --target/--advertised exits on an
            # argparse error with no JSON at all; say so instead of reporting an
            # empty review the operator cannot explain.
            stderr_lines = [item for item in (completed.stderr or "").strip().splitlines() if item.strip()]
            note = f"review probe produced no result (exit {completed.returncode})"
            if stderr_lines:
                note = note + ": " + stderr_lines[-1].strip()
            note = note[:240]
            return {
                "review_id": review_id,
                "passed": False,
                "note": note,
                "detail": non_discriminating_review_detail(note, target_text, relay_text),
            }
        passed = bool(detail.get("passed")) and completed.returncode == 0
        note = str(detail.get("last_error", "") or "")[:240]
        return {"review_id": review_id, "passed": passed, "note": note, "detail": detail}
    def reauthenticate(self) -> bool:
        """Refresh the LeiGod session without destroying a working session."""
        if not self.login_mobile or not self.login_password_env or not self.login_binary_path.is_file():
            return False
        now = time.time()
        if now - self._last_login_at < self.login_retry:
            return False
        self._last_login_at = now
        environment = os.environ.copy()
        if self.login_home is not None:
            environment["HOME"] = str(self.login_home)
        command = [
            str(self.login_binary_path),
            "login",
            "--mobile",
            self.login_mobile,
            "--password-env",
            self.login_password_env,
            "--country",
            str(self.login_country),
            "--region",
            str(self.login_region),
        ]
        if self.login_config_path:
            command.extend(["--config", self.login_config_path])
        try:
            completed = subprocess.run(
                command,
                env=environment,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=self.login_timeout,
                check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            return False
        if completed.returncode != 0:
            return False

        candidates: list[Path] = []
        if self.login_home is not None:
            candidates.append(self.login_home / ".leigod" / "session.json")
        if self.session_path is not None:
            candidates.append(self.session_path)
        source = next((path for path in candidates if path.is_file()), None)
        if source is None or self.session_path is None:
            return False
        if source.resolve() == self.session_path.resolve():
            return self._harden_session_permissions()
        try:
            if source.stat().st_size <= 64 * 1024:
                self.session_path.parent.mkdir(parents=True, exist_ok=True)
                temporary = self.session_path.with_name(f".{self.session_path.name}.{os.getpid()}.tmp")
                shutil.copyfile(source, temporary)
                os.chmod(temporary, 0o600)
                os.replace(temporary, self.session_path)
                os.chmod(self.session_path, 0o600)
                return True
        except OSError:
            try:
                temporary.unlink()
            except (UnboundLocalError, FileNotFoundError):
                pass
        return False

    def _harden_session_permissions(self) -> bool:
        if self.session_path is None:
            return False
        try:
            if not self.session_path.is_file():
                return False
            os.chmod(self.session_path, 0o600)
            return True
        except OSError:
            return False

    def refresh_catalog(self) -> None:
        try:
            result = refresh_catalog_cache(
                self.lines_dir,
                self.binary_path,
                self.session_path,
                self.catalog_path,
                now=int(time.time()),
                refresh_seconds=self.catalog_refresh,
                retry_seconds=self.catalog_retry,
                max_queries=self.catalog_max_queries,
                timeout=self.catalog_timeout,
            )
            if result["attempted"] > 0:
                logging.info(
                    "catalog refresh completed; attempted=%s succeeded=%s changed=%s",
                    result["attempted"],
                    result["succeeded"],
                    result["changed"],
                )
        except Exception as exc:  # noqa: BLE001
            logging.warning("catalog refresh failed: %s", exc)

    def run(self, once: bool = False) -> None:
        failures = 0
        while True:
            try:
                accepted = self.run_cycle()
                failures = 0
                logging.info("source cycle completed; accepted=%s", accepted)
            except Exception as exc:  # noqa: BLE001
                failures += 1
                logging.warning("source cycle failed: %s", exc)
            if once:
                return
            backoff = min(300.0, self.poll_interval * (2 ** min(failures, 3)))
            time.sleep(max(5.0, backoff * random.uniform(0.75, 1.25)))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="/etc/ultra-source-agent.json")
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    SourceAgent(Path(args.config)).run(once=args.once)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

ULTRA_EOF
sudo sed -i 's/\r$//' /usr/local/bin/ultra-source-agent.py
sudo chmod 755 /usr/local/bin/ultra-source-agent.py
sudo chown root:root /usr/local/bin/ultra-source-agent.py
```

### 7c. the stale bytecode cache

A `.pyc` for the probe sits in `__pycache__` next to it. The agent runs the probe as
`python3 <probe> --json`, i.e. as a script, which does **not** load that `.pyc` — but
any import-based caller would, and a stale cache next to a changed file is a trap.
Delete it (7b above / step 8). It regenerates on the next run.

---

## 8. STEP 4 — verify the bytes and the syntax, then restart

```bash
sha256sum /opt/ultra-source/runtime/ultra_line_review.py /usr/local/bin/ultra-source-agent.py
sudo python3 -m py_compile /opt/ultra-source/runtime/ultra_line_review.py /usr/local/bin/ultra-source-agent.py && echo "py_compile OK"
sudo rm -f /opt/ultra-source/runtime/__pycache__/ultra_line_review.cpython-311.pyc
cd /opt/ultra-source/runtime && python3 /opt/ultra-source/runtime/ultra_line_review.py --help | head -20
```

The two sha256 values must equal the table in section 1. `python3 -m py_compile`
prints nothing and exits 0 when both files are valid for the host's Python 3.11.2.

```bash
sudo systemctl restart ultra-source-agent
sudo systemctl is-active ultra-source-agent
sudo systemctl status ultra-source-agent --no-pager -n 5
```

Expect `active`. The agent polls the panel about every 60 s and picks reviews up by
itself. Reviews take 600 s, so allow up to ~12 minutes for a completed one.

---

## 9. STEP 5 — ONE COMMAND: prove the new code is actually running

```bash
sudo bash -c 'set -u
UNIT=ultra-source-agent
AGENT=/usr/local/bin/ultra-source-agent.py
PROBE=/opt/ultra-source/runtime/ultra_line_review.py
want_agent=1d11023ffd5afb61f5dbf722f6dbe94fca1b6148394a271ffa7161c1d231e57a
want_probe=2eb012e3345a4fc5ab84cb6e71c7c623b541b66642095689a2ad6c3028ebd789
fail=0
got_agent=$(sha256sum "$AGENT" | cut -d" " -f1)
got_probe=$(sha256sum "$PROBE" | cut -d" " -f1)
if [ "$got_agent" = "$want_agent" ]; then echo "OK   agent file is the new revision"; else echo "FAIL agent file digest: $got_agent"; fail=1; fi
if [ "$got_probe" = "$want_probe" ]; then echo "OK   probe file is the new revision"; else echo "FAIL probe file digest: $got_probe"; fail=1; fi
if grep -q -- "--advertised" "$PROBE"; then echo "OK   probe source carries --advertised"; else echo "FAIL probe source lacks --advertised"; fail=1; fi
if grep -q "select_review_relay" "$AGENT"; then echo "OK   agent carries select_review_relay"; else echo "FAIL agent lacks select_review_relay"; fail=1; fi
if (cd /opt/ultra-source/runtime && python3 "$PROBE" --help 2>/dev/null | grep -q -- "--target"); then echo "OK   probe CLI exposes --target/--advertised"; else echo "FAIL probe CLI is still the old one"; fail=1; fi
if python3 -c "import sys; sys.path.insert(0, \"/opt/ultra-source/runtime\"); import native_udp as n; [getattr(n, k) for k in (\"NativeSession\", \"LineNotLive\", \"AuthError\")]" 2>/dev/null; then echo "OK   probe dependency native_udp is usable"; else echo "FAIL probe dependency native_udp"; fail=1; fi
if systemctl is-active --quiet "$UNIT"; then echo "OK   $UNIT is active"; else echo "FAIL $UNIT is not active"; fail=1; fi
started=$(date -d "$(systemctl show -p ExecMainStartTimestamp --value "$UNIT")" +%s 2>/dev/null || echo 0)
newest=$(stat -c %Y "$AGENT" "$PROBE" | sort -n | tail -1)
if [ "$started" -ge "$newest" ]; then echo "OK   service started after the new files were written"; else echo "FAIL service start predates the new files - restart it"; fail=1; fi
if [ "$fail" -eq 0 ]; then echo "RESULT: the new review probe is installed and running"; else echo "RESULT: NOT verified"; fi
exit $fail'
```

---

## 10. STEP 6 — confirm the first review carries a proof block

> ### READ THIS FIRST: the field this note used to point at READS CLEAN ON THE FAULT
>
> An earlier revision of this note told the reader to treat
> `target_tcp_zero_downstream` as "the fault signature". **That was wrong, and it was
> wrong in the worst way: the counter reads 0 on the exact fault it was written for.**
>
> Measured on 2026-09-14 on this host: an advertised relay refused the client's target
> in 4 of 4 rounds with `REP=0x01 general_failure`, and `target_tcp_zero_downstream`
> stayed at **0**. A probe that refused the target was recorded as a probe that had
> nothing to report, so the fault looked like health.
>
> `target_tcp_zero_downstream = 0` **must never be read as "healthy" on its own.** It
> counts one specific thing only: rounds where the relay closed without sending any
> reply at all. Read the refusal fields instead:
>
> | field | meaning |
> | ----- | ------- |
> | `$.detail.proof.reason` | `target_refused` = the relay named a reason for failing to reach the target |
> | `$.detail.target_tcp_refused` | how many rounds the relay answered with a refusal code |
> | `$.detail.target_tcp_refused_code` | the code (SOCKS5 numbering) |
> | `$.detail.target_tcp_refused_meaning` | `host_unreachable`, `general_failure`, ... |
> | `$.detail.proof.tcp_refused` / `tcp_refused_reason` | the same two values inside the proof block |
> | `$.detail.target_tcp_reply_unparsed` | a reply we could not parse, with raw bytes in `target_tcp_unparsed_sample` |
> | `$.detail.target_tcp_zero_downstream` | genuine silence ONLY - as of this date there is **no confirmed instance** of it |
>
> Three different facts, three different fields: a DECODED REFUSAL, an UNPARSED REPLY,
> and a GENUINE SILENCE. Never collapse them into one number.
>
> Also: the ~1 s stream close is **the refusal arriving**, not an idle timeout. Do not
> read a ~1 s close as "the relay went quiet".

On the source host (the new agent logs one line per reported review):

```bash
sudo journalctl -u ultra-source-agent --since "-40 min" --no-pager | grep -E "review [0-9]+ line" | tail -5
```

A working deploy looks like this (numbers vary):

```text
review 11 line 453 passed=True  proof_reason=none            end_to_end_ok=30 zero_downstream=0  note=
review 12 line 581 passed=False proof_reason=target_refused  end_to_end_ok=0  zero_downstream=0  note=target_refused: rep=0x01 general_failure
```

* `passed=True proof_reason=none` — the probe proved the line end to end; the panel
  should record `rebuttal=applied`.
* `passed=False proof_reason=target_refused` — **the relay answered and named a
  reason.** Measured on this host: `rep=0x01 general_failure` for forward 184's target,
  `rep=0x04 host_unreachable` for another. This is a real, reproducible measurement of
  the target being refused, NOT a broken deploy — and note that
  `zero_downstream=0` in this row, which is exactly why that field must not be read as
  health.
* `passed=False proof_reason=target_reply_unparsed` — the relay answered with bytes
  whose shape we do not recognise; the raw bytes are in `unparsed_sample`. Unknown,
  and recorded as unknown rather than as a silence.
* `passed=False proof_reason=no_end_to_end_transfer` — the probe ran, held the relay
  for the window, and got **zero** downstream bytes for the target with no reply at
  all. A genuine silence. As of 2026-09-14 there is no confirmed instance of this.
* `proof_reason=no_client_target` — the panel sent an empty target. Expected for some
  candidates, and it can never pass.
* `proof_reason=relay_not_advertised` / `no advertised relay in the line material` —
  the endpoint was outside the advertised set; refused locally as well as by the panel.

On the panel, the stored proof distinguishes a real deploy from a silent one. Note
`$.detail.proof` (the probe's raw report) is separate from `$.proof` (the panel's
own verdict):

```sql
SELECT id, line_id, state, completed_at,
       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.rebuttal'))            AS rebuttal,
       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.proof.reason'))        AS panel_reason,
       JSON_EXTRACT(result_json, '$.detail.proof.end_to_end_ok')        AS probe_e2e_ok,
       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.detail.proof.target')) AS probe_target,
       JSON_EXTRACT(result_json, '$.detail.proof.local_path_only')      AS probe_local_only,
       JSON_EXTRACT(result_json, '$.detail.target_tcp_refused')         AS tcp_refused_rounds,
       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.detail.target_tcp_refused_meaning')) AS refused_meaning,
       JSON_EXTRACT(result_json, '$.detail.target_tcp_reply_unparsed')  AS unparsed_rounds,
       JSON_EXTRACT(result_json, '$.detail.target_tcp_zero_downstream') AS zero_downstream_rounds,
       JSON_EXTRACT(result_json, '$.detail.rounds')                     AS rounds,
       JSON_EXTRACT(result_json, '$.detail.target_tcp_min_close_ms')    AS min_close_ms,
       JSON_EXTRACT(result_json, '$.detail.target_tcp_max_close_ms')    AS max_close_ms
  FROM ultra_candidate_reviews
 ORDER BY id DESC LIMIT 5;
```

| `rebuttal` | `panel_reason` | meaning |
| --- | --- | --- |
| applied | advertised_endpoint_end_to_end | the new probe worked and the line is proven end to end |
| blocked | target_refused | the relay answered and refused the target, with a code |
| blocked | target_reply_unparsed | the relay's reply shape is unknown; raw bytes recorded |
| blocked | no_end_to_end_transfer | the new probe ran and the relay sent nothing at all (no confirmed instance yet) |
| blocked | local_path_only | the report claimed only relay-local evidence |
| blocked | endpoint_not_advertised | the probed IP is not advertised for that line/source |
| blocked | proof_missing | **no proof block at all — the OLD agent/probe is still running** |

The deploy is confirmed by either `rebuttal=applied` with `probe_e2e_ok >= 1`, or
`panel_reason=no_end_to_end_transfer`/`no_client_target` **together with** a populated
`$.detail.proof` and non-zero `$.detail.rounds`.

### The measurement this upgrade exists for

`$.detail.rounds` is how many stream probes ran. Split those rounds three ways —
they are NOT interchangeable:

* `$.detail.target_tcp_refused` — the relay answered and refused the target. Read
  `target_tcp_refused_code`/`_meaning` for why. This is the fault measured on this host
  on 2026-09-14: 4 of 4 rounds, `REP=0x01 general_failure`, for forward 184's target
  45.143.235.221:10809, from three different advertised relays (71-73 ms closes).
* `$.detail.target_tcp_reply_unparsed` — the relay answered with a shape we do not
  know. Raw bytes in `target_tcp_unparsed_sample`.
* `$.detail.target_tcp_zero_downstream` — the relay sent nothing at all. Genuine
  silence, and `0` here does **not** mean healthy.

`target_tcp_min_close_ms`/`max_close_ms` is how long the stream lived. A ~1 s close is
the refusal arriving, not an idle timeout; a ~72 ms close is a policy-speed refusal.

Compare those across the advertised endpoints of the same line. Before this upgrade no
review could produce any of them.

### Known independent vantage, for triangulation

A refused target may be refused by the relay's egress while being perfectly reachable
from the open internet. Measured 2026-09-14 from the Mac test machine (plain TCP, no
relay): forward 184's target 45.143.235.221:10809 **CONNECTED in 95 ms**, while every
advertised relay of line 581 refused it in ~72 ms. A second target,
38.207.136.247:32469, was refused by the destination itself
(`ConnectionRefusedError`). So "the relay refuses it" and "it is down" are different
claims, and a single vantage cannot tell them apart.

---

## 11. STEP 7 — rollback (exact commands)

Roll back BOTH files together, from the same backup.

```bash
sudo systemctl stop ultra-source-agent
STAMP=$(sudo ls -1 /root/leikwan-source-agent-backups | sort | tail -1)   # or the stamp from step 1
echo "rolling back from /root/leikwan-source-agent-backups/$STAMP"
sudo cp -a "/root/leikwan-source-agent-backups/$STAMP/ultra-source-agent.py.orig" /usr/local/bin/ultra-source-agent.py
sudo cp -a "/root/leikwan-source-agent-backups/$STAMP/ultra_line_review.py.orig"  /opt/ultra-source/runtime/ultra_line_review.py
sudo chmod 755 /usr/local/bin/ultra-source-agent.py /opt/ultra-source/runtime/ultra_line_review.py
sudo chown root:root /usr/local/bin/ultra-source-agent.py /opt/ultra-source/runtime/ultra_line_review.py
sudo rm -f /opt/ultra-source/runtime/__pycache__/ultra_line_review.cpython-311.pyc
sudo systemctl start ultra-source-agent
sudo systemctl is-active ultra-source-agent
sudo md5sum /usr/local/bin/ultra-source-agent.py /opt/ultra-source/runtime/ultra_line_review.py
```

Expected after rollback:

```text
db911ae5522e8e5986c6b8855a588dd3  /usr/local/bin/ultra-source-agent.py
a9cc8b87b1bebf8a370bda023c9981ec  /opt/ultra-source/runtime/ultra_line_review.py
active
```

---

## 12. What to send back

1. The output of step 9 (the `OK`/`FAIL` list).
2. The output of step 10, and the row(s) from the panel query for the first completed
   review.
3. If something failed: the exact command, its output, and
   `sudo journalctl -u ultra-source-agent -n 100 --no-pager`.

Do **not** send `/etc/ultra-source-agent.json` (it contains `source_secret`). The
probe's report contains no credentials, but it does name relay IPs, which are
provider endpoints — redact them if that matters.

---

## 13. Limits, stated plainly

* **THE PROBE RUNS ON THE SOURCE HOST, SO ITS VERDICT IS ABOUT THE SOURCE HOST'S EGRESS,
  NOT A CLIENT'S.** It measures "from 10.10.10.171, via relay X, to target Y". It does
  NOT measure "from a client in Shanghai, via relay X, to target Y". A source host can
  sit behind a different egress, firewall or routing policy than a client, so a refusal
  code proves the SOURCE cannot reach the target through that relay - it does not by
  itself prove a user is affected. Measured 2026-09-14: forward 184's target
  45.143.235.221:10809 was refused (REP=0x01, ~72 ms) by every advertised relay of line
  581 from this host, while the SAME target accepted a plain TCP connection from an
  independent vantage in 95 ms. Before reading any refusal as a user-impacting fact,
  check it from a CLIENT vantage. Reading a source-host refusal as a field symptom
  without that check is the exact mistake this note was written to correct.

* The new probe **fails closed**. A target that never answers over TCP or UDP cannot
  produce a pass, so some reviews will now end in `rebuttal=blocked` where the old
  probe would have passed on a loopback echo. Nothing is struck, demoted or lifted in
  that case — it is the safe direction, and it is the point of the change.
* A line that is silent for only **some** rounds (the observed fault is ~6% of
  streams) fails the window, because every round must carry downstream bytes.
* A target that answers only after application-level framing the probe does not know
  cannot be proven. The probe sends an address-header-framed DNS query and a bare DNS
  query and counts a strictly validated answer from the target's own address, and the
  relay's own "target connected" frame also counts because that is real downstream
  evidence from the relay's outbound connect.
* If a whole candidate has no client target (empty `target`), the review can never
  pass. That is deliberate.

---

## 14. Operational finding: the advertised relay set is a poor sample of the live supply

Measured on the host after the upgrade, 2026-09-14 ~17:20-17:30 UTC. This is why a
review can now end `no advertised relay in the line material` even though the line is
fine and the deploy is correct - and it is the thing to fix next, on the PANEL side,
not on the source host.

The new agent picks the relay the panel advertises for the line (paired with the port
from the acquired material) and refuses to probe anything else. That requires the
advertised set to intersect the supplier's live pool. For this source it often does
not:

| line | panel advertises (`ultra_lines.relay_ips_json`) | live supply (acquired material) | overlap |
| ---- | ---- | ---- | ---- |
| 7 | 49.0.254.177, 190.92.232.41, 190.92.233.128 | 190.92.233.128, 190.92.237.124, 190.92.232.41 | 2 - selects a relay |
| 9 | 117.50.156.23, 106.75.79.92, 106.75.78.201 | 117.50.156.43, 106.75.20.96, 106.75.79.105 | 0 - refuses |
| 17 | 106.75.146.129, 106.75.165.188 | 106.75.142.217, 106.75.143.234, 106.75.162.111 | 0 - refuses |
| 581 | 113.31.106.203, 113.31.111.9, 113.31.108.63 | rotates on every acquisition | sometimes 0, sometimes 1 |

Line 581's supply rotates on every acquisition. Across 8 consecutive acquisitions the
advertised set matched on the 8th (`113.31.111.9`); across 4 earlier acquisitions it
matched once (`113.31.108.63`). An advertised relay IS reachable through this source -
it is simply not reliably present in any single acquisition.

Also worth recording: for source-home-171 most `ultra_lines` rows carry
`relay_ips_json = NULL`. A NULL/empty advertised set is handled as "no mandate" - the
agent falls back to the first supplied relay (so the agent keeps working) and the
panel's own proof gate is what refuses the result. A NON-empty set that does not
intersect is the stricter case: the agent refuses locally and reports
`no advertised relay in the line material` with `rounds=0`.

Consequence, stated plainly: while the advertised set and the live pool disagree, the
repaired probe reports `blocked` for those lines **even when the relay is healthy**,
because it is not allowed to test an endpoint nobody advertised. That is the intended
fail-safe direction, but it means `no advertised relay in the line material` is a
PANEL INVENTORY problem - refresh/re-derive `ultra_lines.relay_ips_json` for this
source - and not a probe defect and not a deployment failure.

To measure a specific relay regardless of that data problem:

```bash
cd /opt/ultra-source/runtime && python3 - <<'PY'
import importlib.util, sys, json
from pathlib import Path
spec = importlib.util.spec_from_file_location('a', '/usr/local/bin/ultra-source-agent.py')
m = importlib.util.module_from_spec(spec); sys.modules['a'] = m; spec.loader.exec_module(m)
agent = m.SourceAgent(Path('/etc/ultra-source-agent.json'))
adv = ['113.31.106.203', '113.31.111.9', '113.31.108.63']   # the panel's advertised IPs
target = '38.207.136.247:32469'                             # clientTarget()'s own lookup
for attempt in range(1, 21):
    mat = m.acquire_line(581, agent.binary_path, agent.lines_dir,
                         agent.state_dir / ('probe-581-%d.json.tmp' % attempt),
                         session_path=agent.session_path, timeout=agent.acquire_timeout,
                         lifetime_seconds=agent.material_lifetime)
    chosen, reason = m.select_review_relay(mat.get('relays'), adv)
    if chosen:
        print('endpoint', chosen, 'after', attempt, 'acquisitions'); break
else:
    raise SystemExit('no advertised relay in the live pool after 20 acquisitions')
print(json.dumps(agent.run_review(9001, 581, 600, advertised=adv, target=target), indent=2))
PY
```
