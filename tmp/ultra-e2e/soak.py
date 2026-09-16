#!/usr/bin/env python3
"""30-minute Ultra tunnel soak.

Legs (all against the same echo pair on the peer):
  tunnel-udp : SS-none UDP  via 127.0.0.1:10001  -> peer:60000
  tunnel-tcp : SS-none TCP  via 127.0.0.1:10001  -> peer:60001
  direct-udp : plain UDP    -> peer:60000          (path baseline)
  direct-tcp : plain TCP    -> peer:60001          (path baseline)

Answer sheet: packet loss, connection interruptions, and whether the UDP path
stays fit for gaming for the whole window (per-minute breakdown + tail RTT).
"""
from __future__ import annotations

import argparse
import collections
import datetime
import json
import os
import socket
import struct
import subprocess
import sys
import threading
import time

MAGIC = b"SOAK"
HDR = struct.Struct("!QdHB")   # seq, monotonic send time, size, burst flag
HDR_LEN = len(MAGIC) + HDR.size
TCP_HB = HDR_LEN                # tcp heartbeats use the bare payload


def utcnow_iso() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def log(msg: str) -> None:
    print("%s %s" % (datetime.datetime.now(datetime.timezone.utc).strftime("%H:%M:%S"), msg), flush=True)


def encode_address(host: str, port: int) -> bytes:
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def envelope(target, payload: bytes) -> bytes:
    """SOCKS5 UDP RSV/FRAG prefix + the SS-none address-first header.

    The local Ultra listener accepts exactly this shape and forwards the inner
    address-first payload; without it every datagram is an unparseable frame and
    is dropped silently.
    """
    return b"\x00\x00\x00" + encode_address(*target) + payload


def payload_for(seq: int, size: int, burst: int) -> bytes:
    head = MAGIC + HDR.pack(seq, time.monotonic(), size, burst)
    return head + b"\x00" * max(0, size - len(head))


def parse_payload(packet: bytes):
    idx = packet.find(MAGIC)
    if idx < 0 or len(packet) - idx < HDR_LEN:
        return None
    seq, mono, size, burst = HDR.unpack(packet[idx + len(MAGIC):idx + HDR_LEN])
    return seq, mono, size, burst


def percentile(values, q: float):
    if not values:
        return None
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    pos = (len(ordered) - 1) * q
    low = int(pos)
    high = min(low + 1, len(ordered) - 1)
    return ordered[low] + (ordered[high] - ordered[low]) * (pos - low)


def rtt_block(pairs):
    """pairs: list of (rtt_ms, size)."""
    if not pairs:
        return {}
    rtts = [p[0] for p in pairs]
    jitter = [abs(rtts[i] - rtts[i - 1]) for i in range(1, len(rtts))]
    return {
        "samples": len(rtts),
        "min": round(min(rtts), 2),
        "p50": round(percentile(rtts, 0.50), 2),
        "p90": round(percentile(rtts, 0.90), 2),
        "p95": round(percentile(rtts, 0.95), 2),
        "p99": round(percentile(rtts, 0.99), 2),
        "max": round(max(rtts), 2),
        "mean": round(sum(rtts) / len(rtts), 2),
        "jitter_mean": round(sum(jitter) / len(jitter), 2) if jitter else 0.0,
        "jitter_p95": round(percentile(jitter, 0.95), 2) if jitter else 0.0,
    }


class UdpLeg(threading.Thread):
    def __init__(self, name, sock, target, pps, size, size_big, big_every,
                 burst_every, burst_len, duration, tunnel, stop, inner=None):
        super().__init__(daemon=True)
        self.name = name
        self.sock = sock
        # target = where this leg sends; inner = the SS-none destination the
        # tunnel leg asks the far-end Shadowsocks server to reach.
        self.target = target
        self.inner = inner if inner is not None else target
        self.pps = pps
        self.size = size
        self.size_big = size_big
        self.big_every = big_every
        self.burst_every = burst_every
        self.burst_len = burst_len
        self.duration = duration
        self.tunnel = tunnel
        self.stop = stop
        self.lock = threading.Lock()
        self.pending = {}
        self.pairs = []
        self.recv_count = 0
        self.dup = 0
        self.unknown = 0
        self.send_errors = collections.Counter()
        self.recv_errors = collections.Counter()
        self.minutes = collections.defaultdict(lambda: [0, 0])
        self.burst_sent = 0
        self.burst_recv = 0
        self.big_sent = 0
        self.big_recv = 0

    def _minute_index(self, since):
        return int(since // 60)

    def send_loop(self):
        interval = 1.0 / max(1, self.pps)
        start = time.monotonic()
        next_at = start
        seq = 0
        n = 0
        next_burst = start + self.burst_every if self.burst_every else None
        while not self.stop.is_set():
            now = time.monotonic()
            if now - start > self.duration:
                break
            if next_burst is not None and now >= next_burst:
                next_burst += self.burst_every
                for _ in range(self.burst_len):
                    seq += 1
                    pkt = payload_for(seq, self.size, 1)
                    with self.lock:
                        self.pending[seq] = (time.monotonic(), self.size, 1)
                        self.minutes[self._minute_index(now - start)][0] += 1
                        self.burst_sent += 1
                    try:
                        self.sock.sendto(envelope(self.inner, pkt) if self.tunnel else pkt, self.target)
                    except OSError as exc:
                        self.send_errors[type(exc).__name__] += 1
                    time.sleep(0.002)
                continue
            if now < next_at:
                time.sleep(min(next_at - now, 0.05))
                continue
            next_at += interval
            seq += 1
            n += 1
            big = self.big_every and n % self.big_every == 0
            size = self.size_big if big else self.size
            pkt = payload_for(seq, size, 0)
            with self.lock:
                self.pending[seq] = (time.monotonic(), size, 0)
                self.minutes[self._minute_index(now - start)][0] += 1
                if big:
                    self.big_sent += 1
            try:
                self.sock.sendto(envelope(self.inner, pkt) if self.tunnel else pkt, self.target)
            except OSError as exc:
                self.send_errors[type(exc).__name__] += 1

    def recv_loop(self):
        start = time.monotonic()
        while not (self.stop.is_set() and time.monotonic() - start > self.duration + 3.0):
            if time.monotonic() - start > self.duration + 3.0:
                break
            try:
                packet, addr = self.sock.recvfrom(4096)
            except socket.timeout:
                continue
            except OSError as exc:
                self.recv_errors[type(exc).__name__] += 1
                continue
            parsed = parse_payload(packet)
            if parsed is None:
                self.unknown += 1
                continue
            seq, mono, size, burst = parsed
            rtt = (time.monotonic() - mono) * 1000.0
            with self.lock:
                entry = self.pending.pop(seq, None)
                self.recv_count += 1
                if entry is None:
                    self.dup += 1
                else:
                    self.pairs.append((rtt, size))
                    self.minutes[self._minute_index(time.monotonic() - start)][1] += 1
                    if burst:
                        self.burst_recv += 1
                    if size > self.size:
                        self.big_recv += 1

    def run(self):
        t = threading.Thread(target=self.send_loop, daemon=True)
        t.start()
        self.recv_loop()
        t.join(timeout=2.0)

    def report(self):
        with self.lock:
            lost = list(self.pending.items())
            sent = sum(v[0] for v in self.minutes.values())
            recv = sum(v[1] for v in self.minutes.values())
            pairs = list(self.pairs)
        lost.sort()
        runs = []
        run_start = None
        run_count = 0
        for seq, _ in lost:
            if run_start is None:
                run_start = seq
                run_count = 1
            elif seq == run_start + run_count:
                run_count += 1
            else:
                runs.append([run_start, run_count])
                run_start, run_count = seq, 1
        if run_start is not None:
            runs.append([run_start, run_count])
        runs = [r for r in runs if r[1] >= 3]
        by_minute = []
        for minute in sorted(self.minutes):
            ms, mr = self.minutes[minute]
            by_minute.append({
                "minute": minute,
                "sent": ms,
                "recv": mr,
                "loss_pct": round(100.0 * (ms - mr) / ms, 2) if ms else 0.0,
            })
        worst = sorted(by_minute, key=lambda m: m["loss_pct"], reverse=True)[:5]
        return {
            "name": self.name,
            "sent": sent,
            "recv": recv,
            "unknown_replies": self.unknown,
            "duplicates": self.dup,
            "loss_pct": round(100.0 * (sent - recv) / sent, 3) if sent else 0.0,
            "permanent_loss": len(lost),
            "burst": {"sent": self.burst_sent, "recv": self.burst_recv,
                      "loss_pct": round(100.0 * (self.burst_sent - self.burst_recv) / self.burst_sent, 2) if self.burst_sent else None},
            "big_packet": {"sent": self.big_sent, "recv": self.big_recv,
                           "loss_pct": round(100.0 * (self.big_sent - self.big_recv) / self.big_sent, 2) if self.big_sent else None},
            "rtt_ms": rtt_block(pairs),
            "send_errors": dict(self.send_errors),
            "recv_errors": dict(self.recv_errors),
            "loss_runs_ge3": runs[:40],
            "worst_minutes": worst,
            "by_minute": by_minute,
        }


class TcpLeg(threading.Thread):
    def __init__(self, name, local, target, duration, tunnel, stop, heartbeat=1.0, timeout=3.0):
        super().__init__(daemon=True)
        self.name = name
        self.local = local
        self.target = target
        self.duration = duration
        self.tunnel = tunnel
        self.stop = stop
        self.heartbeat = heartbeat
        self.timeout = timeout
        self.events = []
        self.pairs = []
        self.connects = 0
        self.disconnects = 0
        self.hb_sent = 0
        self.hb_ok = 0
        self.outages = []
        self.seq = 0

    def _event(self, kind, detail=""):
        self.events.append({"at": utcnow_iso(), "kind": kind, "detail": detail})

    def run(self):
        start = time.monotonic()
        while not self.stop.is_set() and time.monotonic() - start < self.duration:
            outage_start = time.monotonic()
            sock = None
            try:
                t0 = time.monotonic()
                sock = socket.create_connection(self.local, timeout=5.0)
                sock.settimeout(self.timeout)
                connect_ms = (time.monotonic() - t0) * 1000.0
                self.connects += 1
                self._event("connect", "%.1fms" % connect_ms)
                if self.tunnel:
                    sock.sendall(encode_address(*self.target))
                next_hb = time.monotonic()
                while not self.stop.is_set() and time.monotonic() - start < self.duration:
                    now = time.monotonic()
                    if now < next_hb:
                        time.sleep(min(next_hb - now, 0.2))
                        continue
                    next_hb += self.heartbeat
                    self.seq += 1
                    pkt = payload_for(self.seq, TCP_HB, 0)
                    self.hb_sent += 1
                    sock.sendall(pkt)
                    data = b""
                    while len(data) < TCP_HB:
                        chunk = sock.recv(TCP_HB - len(data))
                        if not chunk:
                            raise ConnectionResetError("eof")
                        data += chunk
                    parsed = parse_payload(data)
                    if parsed:
                        self.hb_ok += 1
                        self.pairs.append(((time.monotonic() - parsed[1]) * 1000.0, TCP_HB))
            except Exception as exc:
                self.disconnects += 1
                self._event("break", type(exc).__name__ + ": " + str(exc))
                self.outages.append({"at": utcnow_iso(),
                                     "down_s": round(time.monotonic() - outage_start, 2)})
                if self.stop.wait(1.0):
                    break
            finally:
                if sock is not None:
                    try:
                        sock.close()
                    except OSError:
                        pass

    def report(self):
        return {
            "name": self.name,
            "connects": self.connects,
            "breaks": self.disconnects,
            "heartbeats_sent": self.hb_sent,
            "heartbeats_ok": self.hb_ok,
            "heartbeat_loss_pct": round(100.0 * (self.hb_sent - self.hb_ok) / self.hb_sent, 3) if self.hb_sent else 0.0,
            "rtt_ms": rtt_block(self.pairs),
            "events": self.events[:120],
            "outages": self.outages[:60],
        }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--duration", type=int, default=1800)
    ap.add_argument("--out-dir", default="/root/soak")
    ap.add_argument("--local-host", default="127.0.0.1")
    ap.add_argument("--local-port", type=int, default=10001)
    ap.add_argument("--peer-host", default="185.220.238.143")
    ap.add_argument("--udp-port", type=int, default=60000)
    ap.add_argument("--tcp-port", type=int, default=60001)
    ap.add_argument("--pps", type=int, default=30)
    ap.add_argument("--size", type=int, default=64)
    ap.add_argument("--size-big", type=int, default=512)
    ap.add_argument("--big-every", type=int, default=50)
    ap.add_argument("--burst-every", type=int, default=30)
    ap.add_argument("--burst-len", type=int, default=30)
    ap.add_argument("--status-path", default="/run/ultra/forward-170.status.json")
    ap.add_argument("--probe-status", default="/var/lib/leikwan-ss-none-probe/status.json")
    ap.add_argument("--selftest", action="store_true")
    args = ap.parse_args()

    os.makedirs(args.out_dir, exist_ok=True)
    peer = (args.peer_host, args.udp_port)
    peer_tcp = (args.peer_host, args.tcp_port)
    local = (args.local_host, args.local_port)

    if args.selftest:
        ok = True
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.settimeout(6.0)
        for i in range(1, 6):
            s.sendto(envelope(peer, payload_for(i, args.size, 0)), local)
            try:
                pkt, _ = s.recvfrom(4096)
                parsed = parse_payload(pkt)
                print("udp selftest %d: %s" % (i, "ok rtt=%.1fms" % ((time.monotonic() - parsed[1]) * 1000) if parsed else "unparsed"))
            except socket.timeout:
                print("udp selftest %d: timeout" % i)
                ok = False
        s.close()
        try:
            c = socket.create_connection(local, timeout=6.0)
            c.settimeout(6.0)
            c.sendall(encode_address(*peer_tcp))
            c.sendall(payload_for(1, TCP_HB, 0))
            data = c.recv(TCP_HB)
            parsed = parse_payload(data)
            print("tcp selftest: %s" % ("ok rtt=%.1fms" % ((time.monotonic() - parsed[1]) * 1000) if parsed else "bad response %r" % data[:40]))
            c.close()
        except Exception as exc:
            print("tcp selftest: %s: %s" % (type(exc).__name__, exc))
            ok = False
        try:
            d = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            d.settimeout(4.0)
            d.sendto(payload_for(1, args.size, 0), peer)
            d.recvfrom(4096)
            print("direct udp selftest: ok")
        except Exception as exc:
            print("direct udp selftest: %s: %s" % (type(exc).__name__, exc))
        print("selftest result:", "PASS" if ok else "FAIL")
        return 0 if ok else 1

    # A fresh destination pays the runtime's datagram-association setup (measured
    # at 1.0-1.7 s). Do that once before the measured window, exactly like a game
    # client opening its first socket, so the loss numbers describe the session
    # rather than the cold start.
    def prewarm():
        probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        probe.settimeout(3.0)
        deadline = time.monotonic() + 15.0
        got = 0
        while time.monotonic() < deadline and got < 1:
            probe.sendto(envelope(peer, payload_for(900000 + got, args.size, 0)), local)
            try:
                probe.recvfrom(4096)
                got += 1
            except socket.timeout:
                pass
        probe.close()
        try:
            connection = socket.create_connection(local, timeout=5.0)
            connection.settimeout(5.0)
            connection.sendall(encode_address(*peer_tcp))
            connection.sendall(payload_for(1, TCP_HB, 0))
            connection.recv(TCP_HB)
            connection.close()
        except OSError:
            pass
        log("prewarm udp_replies=%d" % got)

    prewarm()

    stop = threading.Event()
    udp_tunnel = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    udp_tunnel.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
    udp_tunnel.settimeout(0.5)
    udp_direct = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    udp_direct.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
    udp_direct.settimeout(0.5)

    legs = [
        UdpLeg("tunnel-udp", udp_tunnel, local, args.pps, args.size, args.size_big, args.big_every,
               args.burst_every, args.burst_len, args.duration, True, stop, inner=peer),
        UdpLeg("direct-udp", udp_direct, peer, args.pps, args.size, args.size_big, args.big_every,
               args.burst_every, args.burst_len, args.duration, False, stop),
    ]
    tcp_legs = [
        TcpLeg("tunnel-tcp", local, peer_tcp, args.duration, True, stop),
        TcpLeg("direct-tcp", peer_tcp, peer_tcp, args.duration, False, stop),
    ]

    samples = []
    sampler_stop = threading.Event()

    def unit_state() -> dict:
        try:
            out = subprocess.run(
                ["systemctl", "show", "ultra-forward-170", "-p", "MainPID", "-p", "NRestarts",
                 "-p", "ActiveEnterTimestamp", "-p", "SubState", "-p", "ExecMainStartTimestamp"],
                capture_output=True, text=True, timeout=5).stdout
            return {line.split("=", 1)[0]: line.split("=", 1)[1] for line in out.strip().splitlines() if "=" in line}
        except Exception as exc:
            return {"error": type(exc).__name__}

    def sampler():
        while not sampler_stop.is_set():
            rec = {"at": utcnow_iso()}
            rec["unit"] = unit_state()
            for key, path in (("ultra", args.status_path), ("probe", args.probe_status)):
                try:
                    with open(path) as handle:
                        rec[key] = json.load(handle)
                except Exception as exc:
                    rec[key] = {"error": type(exc).__name__}
            samples.append(rec)
            progress = {
                "at": utcnow_iso(),
                "elapsed_s": round(time.time() - started_wall, 1),
                "legs": {leg.name: {"sent": sum(v[0] for v in leg.minutes.values()),
                                    "recv": sum(v[1] for v in leg.minutes.values())} for leg in legs},
                "tcp": {leg.name: {"connects": leg.connects, "breaks": leg.disconnects,
                                   "hb_sent": leg.hb_sent, "hb_ok": leg.hb_ok} for leg in tcp_legs},
            }
            try:
                with open(os.path.join(args.out_dir, "progress.json"), "w") as handle:
                    json.dump(progress, handle)
            except OSError:
                pass
            sampler_stop.wait(5.0)

    started_wall = time.time()
    log("soak start duration=%ss pps=%d tunnel=%s direct=%s" % (args.duration, args.pps, local, peer))
    sampler_thread = threading.Thread(target=sampler, daemon=True)
    sampler_thread.start()
    for leg in legs:
        leg.start()
    for leg in tcp_legs:
        leg.start()
    for leg in legs:
        leg.join(timeout=args.duration + 30)
    stop.set()
    for leg in tcp_legs:
        leg.join(timeout=15)
    sampler_stop.set()
    sampler_thread.join(timeout=10)

    summary = {
        "started": utcnow_iso(),
        "elapsed_s": round(time.time() - started_wall, 1),
        "target": {"local": "%s:%d" % local, "peer_udp": "%s:%d" % peer, "peer_tcp": "%s:%d" % peer_tcp,
                   "pps": args.pps, "size": args.size, "size_big": args.size_big,
                   "big_every": args.big_every, "burst_every": args.burst_every, "burst_len": args.burst_len},
        "udp": [leg.report() for leg in legs],
        "tcp": [leg.report() for leg in tcp_legs],
        "samples": samples,
    }
    path = os.path.join(args.out_dir, "summary.json")
    tmp = path + ".new"
    with open(tmp, "w") as handle:
        json.dump(summary, handle, indent=1)
    os.replace(tmp, path)
    for block in summary["udp"]:
        log("%-11s sent=%d recv=%d loss=%.2f%% rtt p50=%s p95=%s p99=%s max=%s" % (
            block["name"], block["sent"], block["recv"], block["loss_pct"],
            block["rtt_ms"].get("p50"), block["rtt_ms"].get("p95"), block["rtt_ms"].get("p99"), block["rtt_ms"].get("max")))
    for block in summary["tcp"]:
        log("%-11s connects=%d breaks=%d hb_ok=%d/%d rtt p50=%s p95=%s" % (
            block["name"], block["connects"], block["breaks"], block["heartbeats_ok"], block["heartbeats_sent"],
            block["rtt_ms"].get("p50"), block["rtt_ms"].get("p95")))
    log("summary -> " + path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
