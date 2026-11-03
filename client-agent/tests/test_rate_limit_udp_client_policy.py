import importlib.util
import pathlib
import socket
import sys
import threading
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


rate_limit = load_module("leikwan_rate_limit_under_test", "client-agent/leikwan_rate_limit.py")

MAIN = ("10.0.0.2", 50000)
PROBE = ("10.0.0.2", 51145)
OTHER = ("10.0.0.2", 52000)


class UdpClientPolicyTest(unittest.TestCase):
    def test_single_probe_does_not_steal_return_path(self) -> None:
        policy = rate_limit.UdpClientPolicy()
        policy.client_packet(MAIN, 0.0)
        policy.client_packet(PROBE, 0.1)

        self.assertEqual(policy.reply_targets(0.2), [MAIN, PROBE])
        self.assertEqual(policy.reply_targets(1.2), [MAIN])
        self.assertEqual(policy.primary, MAIN)

    def test_sustained_new_port_is_promoted(self) -> None:
        policy = rate_limit.UdpClientPolicy()
        policy.client_packet(MAIN, 0.0)
        for i in range(policy.PROMOTE_PACKETS):
            policy.client_packet(OTHER, 0.1 + i * 0.01)

        self.assertEqual(policy.primary, OTHER)
        self.assertEqual(policy.reply_targets(0.5), [OTHER])

    def test_roam_after_primary_silence_switches_immediately(self) -> None:
        policy = rate_limit.UdpClientPolicy()
        policy.client_packet(MAIN, 0.0)
        policy.client_packet(OTHER, policy.PRIMARY_SILENT_SECONDS + 0.5)

        self.assertEqual(policy.primary, OTHER)
        self.assertEqual(policy.reply_targets(policy.PRIMARY_SILENT_SECONDS + 0.6), [OTHER])

    def test_replies_stop_after_client_idle(self) -> None:
        policy = rate_limit.UdpClientPolicy()
        self.assertEqual(policy.reply_targets(0.0), [])
        policy.client_packet(MAIN, 0.0)

        self.assertEqual(policy.reply_targets(policy.CLIENT_IDLE_SECONDS - 1), [MAIN])
        self.assertEqual(policy.reply_targets(policy.CLIENT_IDLE_SECONDS + 1), [])

    def test_new_port_replaces_previous_secondary(self) -> None:
        policy = rate_limit.UdpClientPolicy()
        policy.client_packet(MAIN, 0.0)
        policy.client_packet(PROBE, 0.1)
        policy.client_packet(PROBE, 0.2)
        policy.client_packet(OTHER, 0.3)

        self.assertEqual(policy.primary, MAIN)
        self.assertEqual(policy.secondary, OTHER)
        self.assertEqual(policy.secondary_packets, 1)


def udp_socket(timeout: float = 2.0) -> socket.socket:
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind(("127.0.0.1", 0))
    sock.settimeout(timeout)
    return sock


class ServeUdpLoopbackTest(unittest.TestCase):
    def setUp(self) -> None:
        self.stop = threading.Event()
        self.target = udp_socket()
        self.incoming = udp_socket()
        self.incoming.setblocking(False)
        self.upstream = udp_socket()
        self.upstream.setblocking(False)
        self.relay = threading.Thread(
            target=rate_limit.serve_udp,
            args=(self.incoming, self.upstream, self.target.getsockname(), 1000.0, self.stop),
            daemon=True,
        )
        self.relay.start()
        self.listen = self.incoming.getsockname()

    def tearDown(self) -> None:
        self.stop.set()
        self.relay.join(timeout=3)
        for sock in (self.target, self.incoming, self.upstream):
            sock.close()

    def echo_once(self) -> None:
        data, addr = self.target.recvfrom(65535)
        self.target.sendto(b"reply:" + data, addr)

    def test_probe_from_new_port_keeps_main_replies(self) -> None:
        main = udp_socket()
        probe = udp_socket(timeout=0.5)
        self.addCleanup(main.close)
        self.addCleanup(probe.close)

        main.sendto(b"m1", self.listen)
        self.echo_once()
        self.assertEqual(main.recvfrom(65535)[0], b"reply:m1")

        probe.sendto(b"probe", self.listen)
        self.echo_once()
        self.assertEqual(probe.recvfrom(65535)[0], b"reply:probe")
        self.assertEqual(main.recvfrom(65535)[0], b"reply:probe")

        # Downstream-only replies right after the probe are what used to be
        # misdelivered to the probe port; the main socket must still get them.
        upstream_addr = self.upstream.getsockname()
        for i in range(5):
            self.target.sendto(b"push%d" % i, upstream_addr)
        for i in range(5):
            self.assertEqual(main.recvfrom(65535)[0], b"push%d" % i)


if __name__ == "__main__":
    unittest.main()
