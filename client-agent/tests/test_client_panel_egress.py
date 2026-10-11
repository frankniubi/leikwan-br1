import errno
import http.server
import importlib.util
import json
import logging
import socket
import ssl
import tempfile
import threading
import unittest
import urllib.request
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_panel_egress", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)

SO_BINDTODEVICE = getattr(socket, "SO_BINDTODEVICE", 25)
PROXY_ENV = {
    "HTTP_PROXY": "http://127.0.0.1:9",
    "HTTPS_PROXY": "http://127.0.0.1:9",
    "ALL_PROXY": "socks5://127.0.0.1:9",
    "http_proxy": "http://127.0.0.1:9",
    "https_proxy": "http://127.0.0.1:9",
    "all_proxy": "socks5://127.0.0.1:9",
}


def iface(operstate="up", tun_flags=False, type_=1):
    return {"operstate": operstate, "tun_flags": tun_flags, "type": type_}


def route(name, metric, gateway="192.168.1.1", table="main"):
    return {"interface": name, "metric": metric, "gateway": gateway, "table": table}


def snapshot(interfaces, main=(), other=()):
    return {"interfaces": interfaces, "main_default_routes": list(main), "other_default_routes": list(other)}


def fresh_egress(snap=None, **kwargs):
    kwargs.setdefault("platform_supported", True)
    return MODULE.PanelEgress(snapshot_reader=lambda: snap, **kwargs)


class FakeSocket:
    instances: list = []

    def __init__(self, family=None, socktype=None, proto=None, setsockopt_error=None):
        self.calls: list = []
        self.setsockopt_error = setsockopt_error
        FakeSocket.instances.append(self)

    def setsockopt(self, level, option, value):
        self.calls.append(("setsockopt", level, option, value))
        if self.setsockopt_error is not None and option == SO_BINDTODEVICE:
            raise self.setsockopt_error

    def settimeout(self, timeout):
        self.calls.append(("settimeout", timeout))

    def bind(self, address):
        self.calls.append(("bind", address))

    def connect(self, address):
        self.calls.append(("connect", address))

    def close(self):
        self.calls.append(("close",))


class SelectorTests(unittest.TestCase):
    def test_eth0_wins_over_tun0_and_docker0(self):
        snap = snapshot(
            {"lo": iface(type_=772), "eth0": iface(), "tun0": iface(tun_flags=True, type_=65534), "docker0": iface()},
            main=[route("tun0", 0, "10.8.0.1"), route("eth0", 100)],
        )
        name, detail = MODULE.select_panel_egress_interface(snap)
        self.assertEqual("eth0", name)
        self.assertIn("default route via 192.168.1.1 metric 100", detail)

    def test_ens3_wins_over_wireguard_and_tailscale_policy_tables(self):
        snap = snapshot(
            {"ens3": iface(), "wg0": iface(type_=65534), "tailscale0": iface(type_=65534)},
            main=[route("ens3", 0, "10.0.0.1")],
            other=[route("wg0", 0, "", "51820"), route("tailscale0", 0, "", "52")],
        )
        self.assertEqual("ens3", MODULE.select_panel_egress_interface(snap)[0])

    def test_wlan0_wins_when_mihomo_meta_holds_the_default_route(self):
        snap = snapshot(
            {"wlan0": iface(), "Meta": iface(tun_flags=True, type_=65534)},
            main=[route("Meta", 0, "198.18.0.1"), route("wlan0", 600)],
        )
        self.assertEqual("wlan0", MODULE.select_panel_egress_interface(snap)[0])

    def test_tun_flags_or_point_to_point_type_excludes_arbitrarily_named_devices(self):
        snap = snapshot(
            {"eth0": iface(), "office": iface(tun_flags=True), "corp": iface(type_=65534)},
            main=[route("office", 0), route("corp", 5), route("eth0", 100)],
        )
        self.assertEqual("eth0", MODULE.select_panel_egress_interface(snap)[0])

    def test_name_filter_list(self):
        for name in (
            "tun0", "tap1", "utun3", "wg0", "Meta", "mihomo", "clash0", "sing-box", "singbox0", "xray0",
            "v2ray", "tailscale0", "zt5u4abc", "ztmosaic", "docker0", "br-1a2b", "veth12", "virbr0",
            "vmnet8", "vboxnet0", "lxcbr0", "cni0", "flannel.1", "cali123", "kube-ipvs0", "lo",
        ):
            self.assertTrue(MODULE.panel_egress_interface_is_tunnel_like(name, iface()), name)
        for name in ("eth0", "ens3", "enp0s31f6", "wlan0", "wlp2s0", "bond0", "br0", "vmbr0", "eth0.100", "ppp0"):
            self.assertFalse(MODULE.panel_egress_interface_is_tunnel_like(name, iface()), name)

    def test_down_interface_is_skipped(self):
        snap = snapshot({"eth0": iface(operstate="down"), "eth1": iface()}, main=[route("eth0", 0), route("eth1", 10)])
        self.assertEqual("eth1", MODULE.select_panel_egress_interface(snap)[0])

    def test_ppp_is_the_pppoe_uplink_but_ranks_after_lan_defaults(self):
        pppoe = snapshot({"eth0": iface(), "ppp0": iface(type_=512)}, main=[route("ppp0", 0, "100.64.0.1")])
        self.assertEqual("ppp0", MODULE.select_panel_egress_interface(pppoe)[0])
        pptp = snapshot({"eth0": iface(), "ppp0": iface(type_=512)}, main=[route("ppp0", 0), route("eth0", 100)])
        self.assertEqual("eth0", MODULE.select_panel_egress_interface(pptp)[0])

    def test_policy_table_default_is_used_when_main_default_is_a_tunnel(self):
        snap = snapshot({"eth0": iface(), "tun0": iface(tun_flags=True)}, main=[route("tun0", 0)], other=[route("eth0", 0, "192.168.1.1", "100")])
        name, detail = MODULE.select_panel_egress_interface(snap)
        self.assertEqual("eth0", name)
        self.assertIn("table 100", detail)

    def test_no_eligible_interface_leaves_sockets_unbound(self):
        snap = snapshot({"lo": iface(), "tun0": iface(tun_flags=True), "eth0": iface()}, main=[route("tun0", 0)])
        name, detail = MODULE.select_panel_egress_interface(snap)
        self.assertEqual("", name)
        self.assertIn("no physical interface holds a default route", detail)

    def test_override_is_honored(self):
        snap = snapshot({"eth0": iface(), "eth1": iface()}, main=[route("eth0", 0)])
        self.assertEqual(("eth1", "panel_egress_interface override"), MODULE.select_panel_egress_interface(snap, "eth1"))

    def test_missing_or_invalid_override_falls_back_to_auto(self):
        snap = snapshot({"eth0": iface()}, main=[route("eth0", 0)])
        name, detail = MODULE.select_panel_egress_interface(snap, "eth9")
        self.assertEqual("eth0", name)
        self.assertIn("'eth9' not found", detail)
        name, detail = MODULE.select_panel_egress_interface(snap, "eth0; reboot")
        self.assertEqual("eth0", name)
        self.assertIn("invalid panel_egress_interface", detail)
        self.assertEqual("eth0", MODULE.select_panel_egress_interface(snap, "AUTO")[0])


class SnapshotReaderTests(unittest.TestCase):
    def test_reads_sysfs_proc_route_and_policy_tables(self):
        with tempfile.TemporaryDirectory() as td:
            sys_net = Path(td) / "net"
            for name, type_, tun in (("lo", "772", False), ("eth0", "1", False), ("tun0", "65534", True)):
                (sys_net / name).mkdir(parents=True)
                (sys_net / name / "operstate").write_text("up\n", encoding="utf-8")
                (sys_net / name / "type").write_text(type_ + "\n", encoding="utf-8")
                if tun:
                    (sys_net / name / "tun_flags").write_text("0x1002\n", encoding="utf-8")
            proc_route = Path(td) / "route"
            proc_route.write_text(
                "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
                "tun0\t00000000\t0100080A\t0003\t0\t0\t0\t00000000\t0\t0\t0\n"
                "eth0\t00000000\t0101A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n"
                "eth0\t0001A8C0\t00000000\t0001\t0\t0\t100\t00FFFFFF\t0\t0\t0\n",
                encoding="utf-8",
            )
            ip_output = (
                "default via 192.168.1.1 dev eth0 proto dhcp metric 100 \n"
                "default dev wg0 table 51820 scope link \n"
                "default via 10.9.0.1 dev eth0 table 100 metric 5 \n"
            )
            run = mock.Mock(return_value=mock.Mock(returncode=0, stdout=ip_output))
            with mock.patch.object(MODULE.shutil, "which", return_value="/usr/sbin/ip"):
                snap = MODULE.read_linux_egress_snapshot(str(sys_net), str(proc_route), run=run)
        self.assertEqual({"lo", "eth0", "tun0"}, set(snap["interfaces"]))
        self.assertTrue(snap["interfaces"]["tun0"]["tun_flags"])
        self.assertEqual(65534, snap["interfaces"]["tun0"]["type"])
        self.assertEqual(
            [route("tun0", 0, "10.8.0.1"), route("eth0", 100, "192.168.1.1")],
            snap["main_default_routes"],
        )
        self.assertEqual(
            [route("wg0", 0, "", "51820"), route("eth0", 5, "10.9.0.1", "100")],
            snap["other_default_routes"],
        )
        self.assertEqual("eth0", MODULE.select_panel_egress_interface(snap)[0])


class ProxyIgnoredTests(unittest.TestCase):
    def test_panel_opener_has_no_proxies_even_with_proxy_env(self):
        with mock.patch.dict(MODULE.os.environ, PROXY_ENV):
            opener = MODULE.build_panel_opener()
            default_opener = urllib.request.build_opener()
        # Sanity: a stock opener would pick the env proxies up.
        self.assertTrue(any(isinstance(h, urllib.request.ProxyHandler) and h.proxies for h in default_opener.handlers))
        for current in (opener, MODULE.PANEL_OPENER):
            # The empty ProxyHandler({}) replaces the env one and, having no
            # *_open methods, is not registered at all: nothing proxies.
            self.assertFalse(any(isinstance(h, urllib.request.ProxyHandler) and h.proxies for h in current.handlers))
            self.assertFalse(any(hasattr(h, "proxy_open") for h in current.handlers))
            self.assertTrue(any(isinstance(h, MODULE.PanelHTTPSHandler) for h in current.handlers))
            self.assertTrue(any(isinstance(h, MODULE.PanelHTTPHandler) for h in current.handlers))

    def test_mirror_request_uses_panel_opener_not_env_proxied_urlopen(self):
        class FakeResponse:
            headers = {}

            def __enter__(self):
                return self

            def __exit__(self, *exc):
                return False

            def read(self):
                return b'{"ok": true}'

        opener = mock.Mock()
        opener.open.return_value = FakeResponse()
        with mock.patch.dict(MODULE.os.environ, PROXY_ENV), \
             mock.patch.object(MODULE, "PANEL_OPENER", opener), \
             mock.patch.object(MODULE.urllib.request, "urlopen") as urlopen_mock:
            payload = MODULE.http_json("GET", "https://api-leikwanbridge.nbwly.com/api/client/configs/pull", "token")
        self.assertEqual({"ok": True}, payload)
        opener.open.assert_called_once()
        self.assertEqual(30, opener.open.call_args.kwargs["timeout"])
        urlopen_mock.assert_not_called()

    def test_real_request_bypasses_a_dead_env_proxy(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                body = json.dumps({"path": self.path}).encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            url = f"http://127.0.0.1:{server.server_address[1]}/api/health"
            env = dict(PROXY_ENV, NO_PROXY="", no_proxy="")
            with mock.patch.dict(MODULE.os.environ, env), \
                 mock.patch.object(MODULE.PANEL_EGRESS, "interface_for_socket", return_value=""):
                self.assertEqual({"path": "/api/health"}, MODULE.http_json("GET", url, "token"))
        finally:
            server.shutdown()
            server.server_close()


class BindToDeviceTests(unittest.TestCase):
    def setUp(self):
        FakeSocket.instances = []
        self.addrinfo = [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("203.0.113.7", 443))]

    def connect_with(self, egress, socket_factory=FakeSocket):
        with mock.patch.object(MODULE, "PANEL_EGRESS", egress), \
             mock.patch.object(MODULE.socket, "getaddrinfo", return_value=self.addrinfo), \
             mock.patch.object(MODULE.socket, "socket", side_effect=socket_factory):
            return MODULE.panel_create_connection(("203.0.113.7", 443), 5, None)

    def test_socket_is_bound_to_the_selected_interface_before_connect(self):
        egress = fresh_egress(snapshot({"eth0": iface(), "tun0": iface(tun_flags=True)}, main=[route("tun0", 0), route("eth0", 100)]))
        sock = self.connect_with(egress)
        self.assertEqual(
            [
                ("setsockopt", socket.SOL_SOCKET, SO_BINDTODEVICE, b"eth0\0"),
                ("settimeout", 5),
                ("connect", ("203.0.113.7", 443)),
            ],
            sock.calls,
        )

    def test_loopback_panel_is_never_bound(self):
        self.addrinfo = [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("127.0.0.1", 8080))]
        egress = fresh_egress(snapshot({"eth0": iface()}, main=[route("eth0", 0)]))
        sock = self.connect_with(egress)
        self.assertFalse(any(call[0] == "setsockopt" for call in sock.calls))
        self.assertIn(("connect", ("127.0.0.1", 8080)), sock.calls)

    def test_eperm_falls_back_to_unbound_and_logs_once(self):
        egress = fresh_egress(snapshot({"eth0": iface()}, main=[route("eth0", 0)]))
        factory = lambda *a: FakeSocket(*a, setsockopt_error=PermissionError(errno.EPERM, "Operation not permitted"))
        with self.assertLogs(level=logging.WARNING) as logs:
            sock = self.connect_with(egress, factory)
            self.assertIn(("connect", ("203.0.113.7", 443)), sock.calls)
            fake_created = mock.Mock(name="unbound-socket")
            with mock.patch.object(MODULE.socket, "create_connection", return_value=fake_created) as create_connection:
                self.assertIs(fake_created, self.connect_with(egress, factory))
        create_connection.assert_called_once_with(("203.0.113.7", 443), 5, None)
        self.assertEqual(1, sum("SO_BINDTODEVICE" in line for line in logs.output))
        status = egress.status()
        self.assertFalse(status["bound"])
        self.assertTrue(status["summary"].startswith("unbound: SO_BINDTODEVICE not permitted"))

    def test_vanished_interface_connects_unbound_and_reselects(self):
        calls = []

        def reader():
            calls.append(1)
            return snapshot({"eth0": iface()}, main=[route("eth0", 0)])

        egress = MODULE.PanelEgress(snapshot_reader=reader, platform_supported=True)
        factory = lambda *a: FakeSocket(*a, setsockopt_error=OSError(errno.ENODEV, "No such device"))
        with self.assertLogs(level=logging.WARNING):
            sock = self.connect_with(egress, factory)
        self.assertIn(("connect", ("203.0.113.7", 443)), sock.calls)
        self.assertEqual("eth0", egress.resolve()[0])
        self.assertEqual(2, len(calls))

    def test_unsupported_platform_is_unbound(self):
        egress = fresh_egress(snapshot({"eth0": iface()}, main=[route("eth0", 0)]), platform_supported=False)
        self.assertEqual(("", "platform lacks SO_BINDTODEVICE"), egress.resolve())
        fake_created = mock.Mock()
        with mock.patch.object(MODULE, "PANEL_EGRESS", egress), \
             mock.patch.object(MODULE.socket, "create_connection", return_value=fake_created) as create_connection:
            self.assertIs(fake_created, MODULE.panel_create_connection(("panel.example", 443), 7, None))
        create_connection.assert_called_once_with(("panel.example", 443), 7, None)

    def test_discovery_errors_never_break_the_control_plane(self):
        egress = MODULE.PanelEgress(snapshot_reader=mock.Mock(side_effect=OSError("boom")), platform_supported=True)
        interface, detail = egress.resolve()
        self.assertEqual("", interface)
        self.assertIn("interface discovery failed", detail)

    def test_config_override_and_invalid_override(self):
        egress = fresh_egress(snapshot({"eth0": iface(), "eth1": iface()}, main=[route("eth0", 0)]))
        egress.configure({"panel_egress_interface": "eth1"})
        self.assertEqual("eth1", egress.resolve()[0])
        self.assertEqual("override", egress.status()["mode"])
        with self.assertLogs(level=logging.WARNING):
            egress.configure({"panel_egress_interface": "../etc"})
        interface, detail = egress.resolve()
        self.assertEqual("eth0", interface)
        self.assertIn("invalid panel_egress_interface", detail)
        egress.configure({})
        self.assertEqual("auto", egress.status()["mode"])

    def test_https_connection_keeps_sni_and_uses_bound_dial(self):
        class FakeTLSContext:
            server_hostname = None
            # Python <= 3.11 HTTPSConnection.__init__ reads these off the context.
            verify_mode = ssl.CERT_REQUIRED
            check_hostname = True

            def wrap_socket(self, sock, server_hostname=None):
                FakeTLSContext.server_hostname = server_hostname
                return sock

        dialed = FakeSocket()
        with mock.patch.object(MODULE, "panel_create_connection", return_value=dialed) as dial:
            connection = MODULE.PanelHTTPSConnection("panel.example.com", 443, timeout=5, context=FakeTLSContext())
            connection.connect()
        dial.assert_called_once_with(("panel.example.com", 443), 5, None)
        self.assertIs(dialed, connection.sock)
        self.assertEqual("panel.example.com", FakeTLSContext.server_hostname)

    def test_fake_ip_answer_is_replaced_by_strict_doh(self):
        fake_ip = [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("198.18.0.42", 443))]
        with mock.patch.object(MODULE.socket, "getaddrinfo", return_value=fake_ip), \
             mock.patch.object(MODULE._STRICT_DOH_RESOLVER, "resolve_a", return_value=["203.0.113.9"]) as resolve_a:
            infos = MODULE._panel_addresses_for_bound_socket("api-leikwanbridge.nbwly.com", 443)
        resolve_a.assert_called_once_with("api-leikwanbridge.nbwly.com")
        self.assertEqual([("203.0.113.9", 443)], [info[4] for info in infos])

    def test_real_answer_is_kept(self):
        with mock.patch.object(MODULE.socket, "getaddrinfo", return_value=self.addrinfo), \
             mock.patch.object(MODULE._STRICT_DOH_RESOLVER, "resolve_a") as resolve_a:
            self.assertEqual(self.addrinfo, MODULE._panel_addresses_for_bound_socket("panel.example", 443))
        resolve_a.assert_not_called()


class CheckOutputTests(unittest.TestCase):
    def test_check_reports_panel_egress(self):
        with tempfile.TemporaryDirectory() as td:
            config_path = Path(td) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://v3.leikwanhost.com",
                        "state_dir": str(Path(td) / "state"),
                        "agent_token": "token",
                        "client_id": 9,
                        "panel_egress_interface": "eth0",
                    }
                ),
                encoding="utf-8",
            )
            egress = fresh_egress(snapshot({"eth0": iface()}, main=[route("eth0", 0)]))
            with mock.patch.object(MODULE, "PANEL_EGRESS", egress):
                agent = MODULE.ClientAgent(config_path)
                with mock.patch.object(agent, "systemd_is_active", return_value=True), \
                     mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                    summary = agent.check()
        self.assertEqual(
            {
                "proxies_ignored": True,
                "mode": "override",
                "configured": "eth0",
                "interface": "eth0",
                "bound": True,
                "detail": "panel_egress_interface override",
                "summary": "eth0",
            },
            summary["panel_egress"],
        )

    def test_unbound_summary_carries_the_reason(self):
        status = fresh_egress(snapshot({"tun0": iface(tun_flags=True)}, main=[route("tun0", 0)])).status()
        self.assertEqual("unbound: no physical interface holds a default route", status["summary"])
        self.assertTrue(status["proxies_ignored"])


if __name__ == "__main__":
    unittest.main()
