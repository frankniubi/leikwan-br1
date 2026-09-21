"""Regression: an Ultra forward whose runtime is not serving must not be stranded.

A forward wedged in awaiting_policy keeps its enabled status in the panel but has no
bound listener. The agent used to defer a static runtime change whenever no previous
lease snapshot was cached, regardless of whether the runtime was actually alive, so
such a forward never recovered. The deferral is now conditional on the runtime really
serving, which ultra_runtime_is_serving decides.
"""
import pathlib
import shutil
import socket
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

import client_agent  # noqa: E402


class UltraRuntimeIsServingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Only the listener probe is exercised; no agent state is needed.
        cls.agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)

    def _free_udp_port(self):
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.bind(("127.0.0.1", 0))
        return s, s.getsockname()[1]

    def test_false_when_nothing_is_bound(self):
        sock, port = self._free_udp_port()
        sock.close()
        self.assertFalse(self.agent.ultra_runtime_is_serving(port, "tcp+udp"))
        self.assertFalse(self.agent.ultra_runtime_is_serving(port, "udp"))
        self.assertFalse(self.agent.ultra_runtime_is_serving(port, "tcp"))

    @unittest.skipUnless(
        shutil.which("ss"),
        "UDP listener detection needs 'ss'; without it local_listener_present reports "
        "'udp listener check requires ss', and the gate then treats the runtime as not "
        "serving -- which errs toward applying the fresh lease, i.e. recovery.",
    )
    def test_true_for_a_udp_only_listener(self):
        # transport=udp binds UDP only, which is exactly the shape a TCP-only
        # probe reports as dead.
        sock, port = self._free_udp_port()
        try:
            self.assertTrue(self.agent.ultra_runtime_is_serving(port, "udp"))
            self.assertTrue(self.agent.ultra_runtime_is_serving(port, "tcp+udp"))
        finally:
            sock.close()

    def test_udp_without_ss_reports_not_serving(self):
        # Documents the platform limit: no ss means no UDP detection, and the
        # gate must then prefer applying the fresh lease over deferring.
        if shutil.which("ss"):
            self.skipTest("ss is available; the limited path cannot be exercised")
        sock, port = self._free_udp_port()
        try:
            self.assertFalse(self.agent.ultra_runtime_is_serving(port, "udp"))
        finally:
            sock.close()

    def test_true_for_a_tcp_listener(self):
        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv.bind(("127.0.0.1", 0))
        srv.listen(8)
        port = srv.getsockname()[1]
        try:
            self.assertTrue(self.agent.ultra_runtime_is_serving(port, "tcp"))
            self.assertTrue(self.agent.ultra_runtime_is_serving(port, "tcp+udp"))
        finally:
            srv.close()

    def test_invalid_port_is_not_serving(self):
        self.assertFalse(self.agent.ultra_runtime_is_serving(0, "tcp+udp"))
        self.assertFalse(self.agent.ultra_runtime_is_serving(-1, "tcp+udp"))


if __name__ == "__main__":
    unittest.main()
