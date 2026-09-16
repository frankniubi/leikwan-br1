#!/usr/bin/env python3
"""A relay that completes the handshake and then carries nothing.

Reads the client's greeting, answers the SOCKS5 stream ack the ultra runtime
expects (05 02 01 00) so the dial succeeds, then closes without sending a byte
of payload. That is the exact shape that kept a dead candidate selected on
2026-09-13 (dial ok, stream EOF, zero downstream bytes).
"""
import socket
import sys
import threading

port = int(sys.argv[1]) if len(sys.argv) > 1 else 19999
server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind(("127.0.0.1", port))
server.listen(256)
print("fake relay on 127.0.0.1:%d: handshake accepted, payload never carried" % port, flush=True)

accepted = 0
lock = threading.Lock()


def handle(conn):
    global accepted
    try:
        conn.settimeout(3.0)
        try:
            conn.recv(4096)
        except OSError:
            pass
        conn.sendall(b"\x05\x02\x01\x00")
        with lock:
            accepted += 1
            count = accepted
        if count % 10 == 0:
            print("fake relay streams=%d" % count, flush=True)
        try:
            conn.recv(4096)
        except OSError:
            pass
    except OSError:
        pass
    finally:
        try:
            conn.close()
        except OSError:
            pass


while True:
    client, _ = server.accept()
    threading.Thread(target=handle, args=(client,), daemon=True).start()
