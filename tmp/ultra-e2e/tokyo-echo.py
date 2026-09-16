#!/usr/bin/env python3
"""UDP/TCP echo pair for the Tokyo game-sim experiment.

UDP 60000: echo payload unchanged; log per-second receive rate (pps).
TCP 60001: echo stream; log connect/close events.

Log: /root/tokyo-echo.log   Stdout: /root/tokyo-echo.out
Only touches these new ports; no existing service is modified.
"""
import datetime
import os
import socket
import threading
import time

UDP_PORT = 60000
TCP_PORT = 60001
LOG_PATH = "/root/tokyo-echo.log"

_lock = threading.Lock()
_udp_packets = 0
_tcp_connects = 0


def log(msg):
    line = "%s %s" % (datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), msg)
    try:
        with open(LOG_PATH, "a") as f:
            f.write(line + "\n")
    except OSError:
        pass
    print(line, flush=True)


def udp_worker():
    global _udp_packets
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
    sock.bind(("0.0.0.0", UDP_PORT))
    sock.settimeout(0.5)
    rcvbuf = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
    log("UDP echo listening 0.0.0.0:%d rcvbuf=%d" % (UDP_PORT, rcvbuf))
    while True:
        try:
            data, addr = sock.recvfrom(65535)
        except socket.timeout:
            continue
        except OSError:
            return
        with _lock:
            _udp_packets += 1
        try:
            sock.sendto(data, addr)
        except OSError:
            pass


def tcp_handle(conn, addr):
    global _tcp_connects
    with _lock:
        _tcp_connects += 1
    log("TCP connect %s:%d" % (addr[0], addr[1]))
    try:
        conn.settimeout(60)
        while True:
            data = conn.recv(65536)
            if not data:
                break
            conn.sendall(data)
    except Exception:
        pass
    finally:
        log("TCP close %s:%d" % (addr[0], addr[1]))
        try:
            conn.close()
        except Exception:
            pass


def tcp_worker():
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind(("0.0.0.0", TCP_PORT))
    sock.listen(128)
    sock.settimeout(0.5)
    log("TCP echo listening 0.0.0.0:%d" % TCP_PORT)
    while True:
        try:
            conn, addr = sock.accept()
        except socket.timeout:
            continue
        except OSError:
            return
        threading.Thread(target=tcp_handle, args=(conn, addr), daemon=True).start()


def stats_worker():
    global _udp_packets
    while True:
        time.sleep(1)
        with _lock:
            n = _udp_packets
            _udp_packets = 0
        log("UDP pps=%d" % n)


def main():
    log("echo server starting pid=%d python=%s" % (os.getpid(), ".".join(str(x) for x in __import__("sys").version_info[:3])))
    threading.Thread(target=stats_worker, daemon=True).start()
    threading.Thread(target=tcp_worker, daemon=True).start()
    udp_worker()


if __name__ == "__main__":
    main()
