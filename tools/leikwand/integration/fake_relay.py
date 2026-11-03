#!/usr/bin/env python3
"""Minimal Ultra relay that completes the real provider handshake.

It exists so the integration test can prove the whole data path — listener,
runtime, control socket, candidate probe and forwarding — instead of only the
control plane. The handshake is parsed from the client bytes exactly as
ultra-client emits it (see buildHandshakeWithGreeting in
tools/ultra-client/main.go); the requested destination is deliberately ignored
and every stream is spliced to a local echo server, which makes the test
deterministic and offline.

Usage: fake_relay.py <listen-port> <echo-port> [--reject-auth]
"""

import os
import socket
import sys
import threading

LISTEN = int(sys.argv[1])
ECHO = int(sys.argv[2])
REJECT_AUTH = "--reject-auth" in sys.argv
# Loopback is the right default for the host-only integration suite. The
# router VM test sets FAKE_RELAY_HOST=0.0.0.0: the router is a separate
# machine (QEMU guest) and reaches the relay over the user network, so a
# loopback-only listener would make every candidate probe fail.
HOST = os.environ.get("FAKE_RELAY_HOST", "127.0.0.1")
RELAY_LOG = os.environ.get("FAKE_RELAY_LOG", "")


def read_exactly(conn, count):
    buffer = b""
    while len(buffer) < count:
        chunk = conn.recv(count - len(buffer))
        if not chunk:
            raise EOFError("short read")
        buffer += chunk
    return buffer


def read_handshake(conn):
    """Consume one complete provider handshake and return the auth identity."""
    version = read_exactly(conn, 1)
    if version != b"\x05":
        raise ValueError("unexpected greeting version")
    method_count = read_exactly(conn, 1)[0]
    read_exactly(conn, method_count)          # method list
    # Both greetings the runtime emits end with a fixed trailing byte after the
    # method list (legacyHandshakeGreeting / currentHandshakeGreeting in
    # tools/ultra-client/main.go). Skipping it is what keeps the rest of the
    # handshake byte-aligned.
    read_exactly(conn, 1)
    user_length = read_exactly(conn, 1)[0]
    user = read_exactly(conn, user_length)
    password_length = read_exactly(conn, 1)[0]
    password = read_exactly(conn, password_length)
    read_exactly(conn, 3)                     # 05 <command> <checksum>
    address_type = read_exactly(conn, 1)[0]
    if address_type == 1:
        read_exactly(conn, 4)
    elif address_type == 4:
        read_exactly(conn, 16)
    elif address_type == 3:
        read_exactly(conn, read_exactly(conn, 1)[0])
    else:
        raise ValueError("unexpected address type")
    read_exactly(conn, 2)                     # port
    return user, password


def splice(left, right):
    def pump(source, sink):
        try:
            while True:
                data = source.recv(65536)
                if not data:
                    break
                sink.sendall(data)
        except OSError:
            pass
        finally:
            try:
                sink.shutdown(socket.SHUT_WR)
            except OSError:
                pass

    first = threading.Thread(target=pump, args=(left, right), daemon=True)
    second = threading.Thread(target=pump, args=(right, left), daemon=True)
    first.start()
    second.start()
    first.join()
    second.join()


def note_connection(user):
    """Record the authenticated identity, when the harness asked for it.

    Each candidate lease carries its own credential, so the user name is how a
    test tells which relay actually carried a stream — the only honest way to
    prove a failover happened (a counter can lag the event by a poll cycle).
    """
    if not RELAY_LOG:
        return
    try:
        with open(RELAY_LOG, "a") as handle:
            handle.write(user.decode(errors="replace") + "\n")
    except OSError:
        pass


def handle(conn):
    try:
        user, _password = read_handshake(conn)
        note_connection(user)
        if REJECT_AUTH:
            conn.sendall(b"\x05\x02\x01\x01")   # authentication rejected
            return
        # The authentication acknowledgement comes from the relay itself and
        # does not depend on the target being reachable, so it is emitted
        # before dialling upstream. Sending it afterwards would make a dead
        # target look like a dead relay.
        conn.sendall(b"\x05\x02\x01\x00")
        _ = user
        upstream = socket.create_connection(("127.0.0.1", ECHO), timeout=5)
        splice(conn, upstream)
    except EOFError:
        # A probe socket closed by the runtime before it finished its handshake
        # is normal, not a relay fault.
        pass
    except Exception as exc:
        sys.stderr.write("relay stream failed: %r\n" % (exc,))
        sys.stderr.flush()
    finally:
        try:
            conn.close()
        except OSError:
            pass


def main():
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((HOST, LISTEN))
    server.listen(64)
    while True:
        try:
            conn, _ = server.accept()
        except OSError:
            return
        threading.Thread(target=handle, args=(conn,), daemon=True).start()


if __name__ == "__main__":
    main()
