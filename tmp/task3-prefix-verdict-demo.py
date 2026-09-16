
import sys
sys.path.insert(0, r"C:\Users\frankniubi\Downloads\task3-prefix\source-agent")
import ultra_line_review as old

class FakeSession:
    def __init__(self, *a, **k): self.arm_ms = 1.0
    def __enter__(self): return self
    def __exit__(self, *e): return False
    def arm(self, auth_timeout=5.0): return None
    def latency_ms(self, count=1): return 0.0608   # relay-local loopback keep echo

old.N.NativeSession = FakeSession
old.probe_udp_big = lambda session, size, window: (True, size)
old.probe_dns = lambda session, window: True

result = old.review(("1.117.238.119", 5438), "USER1", "1234567890123456", 3,
                    interval=1.0, big_bytes=1400, require_big_packet=False, auth_timeout=1.0)
print("PRE-FIX VERDICT passed=%r min_rtt_ms=%r" % (result["passed"], result["min_rtt_ms"]))
print("PRE-FIX VERDICT udp_ok=%d udp_failed=%d big_ok=%d dns_ok=%d rounds=%d"
      % (result["udp_ok"], result["udp_failed"], result["big_ok"], result["dns_ok"], result["rounds"]))
print("PRE-FIX VERDICT has proof block:", "proof" in result)
