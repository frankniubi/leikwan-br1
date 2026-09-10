import io
import pathlib
import struct
import unittest
from unittest import mock

try:
    from test_mptcp_bond_regressions import load_module
except ModuleNotFoundError:  # dotted unittest invocation from repo root
    import importlib
    load_module = importlib.import_module("client-agent.tests.test_mptcp_bond_regressions").load_module


client_agent_module = load_module(
    "client_agent_strict_doh_under_test",
    "client-agent/client_agent.py",
)


class FakeSocket:
    def __init__(self, response: bytes = b"") -> None:
        self.response = response
        self.sent = bytearray()
        self.timeout = None
        self.closed = False

    def sendall(self, data: bytes) -> None:
        self.sent.extend(data)

    def makefile(self, mode: str, *args, **kwargs):
        return io.BytesIO(self.response)

    def settimeout(self, timeout) -> None:
        self.timeout = timeout

    def close(self) -> None:
        self.closed = True


class FakeTLSContext:
    def __init__(self) -> None:
        self.server_hostname = None

    def wrap_socket(self, sock, server_hostname=None):
        self.server_hostname = server_hostname
        return sock


class StrictDoHTests(unittest.TestCase):
    def test_doh_providers_match_android_china_first_order(self) -> None:
        self.assertEqual(
            [
                ("AliDNS", "https://dns.alidns.com/dns-query", ("223.5.5.5", "223.6.6.6")),
                ("DNSPod", "https://doh.pub/dns-query", ("1.12.12.12", "120.53.53.53")),
                ("360", "https://doh.360.cn/dns-query", ("101.226.4.6", "218.30.118.6")),
            ],
            client_agent_module.STRICT_DOH_PROVIDERS,
        )

    def test_only_public_leikwan_hosts_are_protected(self) -> None:
        protected = client_agent_module.is_protected_leikwan_url

        self.assertTrue(protected("https://panel.leikwanhost.com/api/client/configs/pull"))
        self.assertTrue(protected("http://artifacts.leikwanhost.com:8080/artifacts/client-agent.py"))
        self.assertTrue(protected("https://leikwanhost.com/artifacts/install-client-agent.sh"))
        self.assertFalse(protected("https://example.com/api/client/configs/pull"))
        self.assertFalse(protected("http://localhost:8080/artifacts/client-agent.py"))
        self.assertFalse(protected("http://127.0.0.1:8080/artifacts/client-agent.py"))
        self.assertFalse(protected("http://10.0.0.5/artifacts/client-agent.py"))
        self.assertFalse(protected("http://169.254.1.9/artifacts/client-agent.py"))
        self.assertFalse(protected("http://203.0.113.7/artifacts/client-agent.py"))

    def test_dns_a_query_encoder_uses_single_a_question(self) -> None:
        message, query_id = client_agent_module.build_dns_a_query("Panel.LeikwanHost.com")

        self.assertEqual(query_id, struct.unpack("!H", message[:2])[0])
        self.assertEqual(b"\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00", message[2:12])
        self.assertIn(b"\x05panel\x0bleikwanhost\x03com\x00", message.lower())
        self.assertTrue(message.endswith(b"\x00\x01\x00\x01"))

    def test_dns_response_parser_extracts_a_records_and_ignores_other_answers(self) -> None:
        query, query_id = client_agent_module.build_dns_a_query("panel.leikwanhost.com")
        question = query[12:]
        response = bytearray()
        response.extend(struct.pack("!HHHHHH", query_id, 0x8180, 1, 2, 0, 0))
        response.extend(question)
        response.extend(b"\xc0\x0c")
        response.extend(struct.pack("!HHIH", 1, 1, 45, 4))
        response.extend(b"\xcb\x00\x71\x07")
        response.extend(b"\xc0\x0c")
        response.extend(struct.pack("!HHIH", 28, 1, 45, 16))
        response.extend(bytes.fromhex("20010db8000000000000000000000001"))

        self.assertEqual(["203.0.113.7"], client_agent_module.parse_dns_a_response(bytes(response), query_id))

    def test_protected_http_urlopen_resolves_target_with_doh_and_preserves_host_header(self) -> None:
        resolver = mock.Mock()
        resolver.resolve_a.return_value = ["203.0.113.7"]
        fake_socket = FakeSocket(
            b"HTTP/1.1 200 OK\r\n"
            b"Content-Type: application/json\r\n"
            b"Content-Length: 11\r\n"
            b"\r\n"
            b'{"ok":true}'
        )
        request = client_agent_module.urllib.request.Request(
            "http://panel.leikwanhost.com:8080/api/client/configs/pull",
            headers={"Accept": "application/json"},
            method="GET",
        )

        with mock.patch.object(client_agent_module.socket, "create_connection", return_value=fake_socket) as create_connection:
            with client_agent_module.urlopen_with_leikwan_doh(request, timeout=7, resolver=resolver) as response:
                self.assertEqual(b'{"ok":true}', response.read())

        resolver.resolve_a.assert_called_once_with("panel.leikwanhost.com")
        create_connection.assert_called_once_with(("203.0.113.7", 8080), 7, None)
        sent = bytes(fake_socket.sent).decode("iso-8859-1")
        self.assertIn("GET /api/client/configs/pull HTTP/1.1\r\n", sent)
        self.assertIn("Host: panel.leikwanhost.com:8080\r\n", sent)
        self.assertIn("Accept: application/json\r\n", sent)

    def test_protected_http_urlopen_tries_next_doh_address_after_connection_failure(self) -> None:
        resolver = mock.Mock()
        resolver.resolve_a.return_value = ["203.0.113.7", "203.0.113.8"]
        fake_socket = FakeSocket(
            b"HTTP/1.1 200 OK\r\n"
            b"Content-Type: application/json\r\n"
            b"Content-Length: 11\r\n"
            b"\r\n"
            b'{"ok":true}'
        )
        request = client_agent_module.urllib.request.Request(
            "http://panel.leikwanhost.com/api/client/configs/pull",
            method="GET",
        )

        with mock.patch.object(
            client_agent_module.socket,
            "create_connection",
            side_effect=[OSError("first address blocked"), fake_socket],
        ) as create_connection:
            with client_agent_module.urlopen_with_leikwan_doh(request, timeout=7, resolver=resolver) as response:
                self.assertEqual(b'{"ok":true}', response.read())

        self.assertEqual(
            [mock.call(("203.0.113.7", 80), 7, None), mock.call(("203.0.113.8", 80), 7, None)],
            create_connection.call_args_list,
        )

    def test_protected_https_connection_dials_resolved_ip_but_keeps_tls_hostname(self) -> None:
        fake_socket = FakeSocket()
        fake_context = FakeTLSContext()
        connection = client_agent_module.ResolvedHTTPSConnection(
            "panel.leikwanhost.com",
            port=443,
            timeout=5,
            resolved_ip="203.0.113.7",
            context=fake_context,
        )

        with mock.patch.object(client_agent_module.socket, "create_connection", return_value=fake_socket) as create_connection:
            connection.connect()

        create_connection.assert_called_once_with(("203.0.113.7", 443), 5, None)
        self.assertIs(connection.sock, fake_socket)
        self.assertEqual("panel.leikwanhost.com", fake_context.server_hostname)

    def test_http_json_uses_protected_opener_for_leikwan_control_plane_urls(self) -> None:
        class FakeResponse:
            headers = {}

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def read(self) -> bytes:
                return b'{"ok":true}'

        with mock.patch.dict(client_agent_module.os.environ, {}, clear=True), \
             mock.patch.object(client_agent_module, "urlopen_with_leikwan_doh", return_value=FakeResponse()) as open_mock:
            self.assertEqual(
                {"ok": True},
                client_agent_module.http_json("GET", "https://panel.leikwanhost.com/api/health", "token"),
            )

        request = open_mock.call_args.args[0]
        self.assertEqual("https://panel.leikwanhost.com/api/health", request.full_url)
        self.assertEqual("Bearer token", request.headers["Authorization"])

    def test_explicit_proxy_takes_precedence_over_strict_doh(self) -> None:
        class FakeResponse:
            pass

        request = client_agent_module.urllib.request.Request("https://panel.leikwanhost.com/api/health")
        with mock.patch.dict(client_agent_module.os.environ, {"HTTPS_PROXY": "http://127.0.0.1:2080"}, clear=True), \
             mock.patch.object(client_agent_module.urllib.request, "urlopen", return_value=FakeResponse()) as urlopen_mock, \
             mock.patch.object(client_agent_module, "urlopen_with_leikwan_doh") as doh_mock:
            response = client_agent_module._urlopen_for_request(request, timeout=7)

        self.assertIsInstance(response, FakeResponse)
        urlopen_mock.assert_called_once_with(request, timeout=7)
        doh_mock.assert_not_called()


if __name__ == "__main__":
    unittest.main()
