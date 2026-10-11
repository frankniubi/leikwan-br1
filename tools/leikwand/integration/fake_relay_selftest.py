#!/usr/bin/env python3
"""Self-test for fake_relay.py: performs the real Ultra provider handshake
against the relay and checks that bytes reach the echo target and come back.

Usage: fake_relay_selftest.py <relay-port>
"""
import socket
import sys

PORT = int(sys.argv[1])
LEGACY_GREETING = bytes([5, 3, 2, 0x80, 0x83, 1])


def build_handshake(user, password, host, port):
    frame = bytearray(LEGACY_GREETING)
    frame.append(len(user))
    frame += user.encode()
    frame.append(len(password))
    frame += password.encode()
    frame += bytes([5, 1, 0])          # 05 <command=1> <checksum>
    ip = bytes(int(part) for part in host.split("."))
    frame.append(1)
    frame += ip
    frame += port.to_bytes(2, "big")
    return bytes(frame)


def main():
    conn = socket.create_connection(("127.0.0.1", PORT), timeout=5)
    conn.sendall(build_handshake("user-0", "pass-0", "127.0.0.1", 9))
    ack = conn.recv(4)
    if ack != b"\x05\x02\x01\x00":
        print("FAIL ack=%r" % (ack,))
        return 1
    conn.sendall(b"ping")
    data = conn.recv(64)
    if data != b"echo:ping":
        print("FAIL echo=%r" % (data,))
        return 1
    conn.close()
    print("OK relay handshake and echo verified")
    return 0


if __name__ == "__main__":
    sys.exit(main())
