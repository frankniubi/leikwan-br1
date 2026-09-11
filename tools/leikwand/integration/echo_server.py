#!/usr/bin/env python3
"""Echo server used as the forward target by the integration test."""
import socket
import sys
import threading

PORT = int(sys.argv[1])


def handle(conn):
    try:
        while True:
            data = conn.recv(65536)
            if not data:
                break
            conn.sendall(b"echo:" + data)
    except OSError:
        pass
    finally:
        try:
            conn.close()
        except OSError:
            pass


def main():
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("127.0.0.1", PORT))
    server.listen(64)
    while True:
        try:
            conn, _ = server.accept()
        except OSError:
            return
        threading.Thread(target=handle, args=(conn,), daemon=True).start()


if __name__ == "__main__":
    main()
