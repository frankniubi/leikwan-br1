import socket
import struct
import threading
import unittest

from japan_canary import (MAX_DATAGRAM, SESSION_ID_BYTES, SessionRegistry,
                          read_frame, server_connection, write_frame)


class FramingTests(unittest.TestCase):
    def test_udp_reply_is_independent_of_next_request(self):
        # The target remains silent until it has TWO requests, then returns
        # TWO datagrams. A request/response-only bridge deadlocks here.
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as echo, socket.socket() as listener:
            echo.bind(('127.0.0.1', 0))
            echo.settimeout(3)
            listener.bind(('127.0.0.1', 0))
            listener.listen(1)
            target = echo.getsockname()
            registry = SessionRegistry(target)
            errors = []
            def target_worker():
                try:
                    first, a = echo.recvfrom(65535)
                    second, b = echo.recvfrom(65535)
                    self.assertEqual(a, b)
                    echo.sendto(second, b)
                    echo.sendto(first, a)
                except Exception as exc:
                    errors.append(exc)
            target_thread = threading.Thread(target=target_worker)
            target_thread.start()
            with socket.create_connection(listener.getsockname()) as client:
                client.settimeout(3)
                accepted, _ = listener.accept()
                def serve():
                    try:
                        server_connection(accepted, b'token'*8, registry)
                    except EOFError:
                        pass
                    except Exception as exc:
                        errors.append(exc)
                thread = threading.Thread(target=serve)
                thread.start()
                write_frame(client, b'token'*8)
                session_id = b'session-id-00001'
                self.assertEqual(len(session_id), SESSION_ID_BYTES)
                write_frame(client, session_id)
                self.assertEqual(read_frame(client), b'OKN')
                write_frame(client, b'first')
                write_frame(client, b'second'*2700)
                self.assertEqual(read_frame(client), b'second'*2700)
                self.assertEqual(read_frame(client), b'first')
            target_thread.join(3)
            thread.join(3)
            self.assertFalse(thread.is_alive())
            self.assertFalse(target_thread.is_alive())
            self.assertEqual(errors, [])
            registry.close_all()

    def test_reattach_reuses_udp_source_port(self):
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as echo, socket.socket() as listener:
            echo.bind(('127.0.0.1', 0))
            echo.settimeout(3)
            listener.bind(('127.0.0.1', 0))
            listener.listen(2)
            registry = SessionRegistry(echo.getsockname())
            session_id = b'reattach-sess!!!'
            ports = []
            def accept_once(expected):
                client = socket.create_connection(listener.getsockname())
                accepted, _ = listener.accept()
                def serve():
                    try:
                        server_connection(accepted, b'token'*8, registry)
                    except EOFError:
                        pass
                thread = threading.Thread(target=serve)
                thread.start()
                with client:
                    write_frame(client, b'token'*8)
                    write_frame(client, session_id)
                    self.assertEqual(read_frame(client), expected)
                    write_frame(client, b'probe')
                    data, address = echo.recvfrom(65535)
                    ports.append(address[1])
                    thread.join(2)
            accept_once(b'OKN')
            accept_once(b'OKR')
            self.assertEqual(ports[0], ports[1])
            registry.close_all()

    def test_fragmented_tcp_and_coalesced_datagrams_keep_boundaries(self):
        left, right = socket.socketpair()
        data = [b'', b'\x00\x00\x00\x01hello', b'x' * 16000]
        wire = b''.join(struct.pack('!H', len(p)) + p for p in data)
        def send():
            with left:
                for start in range(0, len(wire), 7):
                    left.sendall(wire[start:start+7])
        thread = threading.Thread(target=send)
        thread.start()
        with right:
            right.settimeout(3)
            self.assertEqual([read_frame(right) for _ in data], data)
            with self.assertRaises(EOFError):
                read_frame(right)
        thread.join()

    def test_truncation_and_oversized_frames_are_rejected(self):
        for wire, error in [(b'\x00', EOFError), (b'\x00\x04xx', EOFError), (b'\xff\xff', ValueError)]:
            with self.subTest(wire=wire):
                left, right = socket.socketpair()
                left.sendall(wire)
                left.close()
                with right, self.assertRaises(error):
                    read_frame(right)
        with self.assertRaises(ValueError):
            write_frame(None, b'x'*(MAX_DATAGRAM+1))

    def test_bad_token_cannot_send_udp(self):
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as echo, socket.socket() as listener:
            echo.bind(('127.0.0.1', 0))
            echo.settimeout(.1)
            listener.bind(('127.0.0.1', 0))
            listener.listen(1)
            with socket.create_connection(listener.getsockname()) as client:
                accepted, _ = listener.accept()
                errors = []
                def serve():
                    try:
                        server_connection(accepted, b'private-test-token'*2, echo.getsockname())
                    except Exception as exc:
                        errors.append(exc)
                thread = threading.Thread(target=serve)
                thread.start()
                write_frame(client, b'wrong-token')
                client.settimeout(2)
                self.assertEqual(client.recv(1), b'')
                with self.assertRaises(socket.timeout):
                    echo.recvfrom(4096)
                thread.join(2)
                self.assertFalse(thread.is_alive())
                self.assertEqual(errors, [])


if __name__ == '__main__':
    unittest.main()
