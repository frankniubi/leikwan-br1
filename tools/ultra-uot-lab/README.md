# Japan external UoT experiment

This directory is an opt-in laboratory, not part of the released runtime or
installer. It uses only Python's standard library.

Data path tested:

```text
Linux UDP application -> local UDP companion -> existing Ultra TCP forward 170
-> LeiGod relay command 1 -> Japan sidecar SS-none test listener
-> loopback UoT server -> loopback UDP echo
```

The SS-none listener is an existing test transport adapter on the same sidecar,
not another network hop. No slave carries experimental data. Administration
uses the existing SSH slave hop. No provider command 4 is used.

Components:

- `japan_canary.py server`: token-authenticated, loopback-only framed transport;
  one fixed UDP destination and one socket per session ID. Reader and writer
  are independent; target replies do not require another request. Reconnecting
  with the same 16-byte session ID returns `OKR` and reuses the target-visible
  UDP source port during the grace period; a new ID returns `OKN`.
- `udp_companion.py`: loopback UDP ingress, separate carrier per local UDP peer,
  max 16 peers, queues bounded to 256 datagrams per peer, packets older than one
  second dropped before sending. Provider credentials remain inside Ultra.
- `pipeline_canary.py`: four independent send/receive flows; 256/1400/4096/16000
  byte payloads; 60pps per flow with one-second 200pps bursts every 30 seconds.
  Checks full bytes, session identity, duplicates, loss and >500ms latency.
- `test_japan_canary.py`: frame split/coalescing, truncation/size bounds,
  authentication and unsolicited/delayed multi-packet UDP return behavior.

Run local checks:

```powershell
python -m unittest discover -s tools/ultra-uot-lab -p 'test_*.py' -v
```

For a lab run, place a randomly generated token (at least 32 bytes) in a private
file on each endpoint. Keep the server loopback-only. Start the server on Japan
with `--echo-port` pointing at an existing operator-owned echo service. The
client's existing Ultra TCP target must be the Japan SS-none test listener.

```text
python3 japan_canary.py server --token-file PRIVATE_FILE
python3 udp_companion.py --token-file PRIVATE_FILE --stats-file STATS_FILE
python3 pipeline_canary.py --token-file PRIVATE_FILE --raw-udp-port 30170 --duration 180 --out RESULT_FILE
```

Stop the experimental processes and remove private token files after the test.
Do not expose this lab server as a production authenticated Internet service:
the token has no additional TLS protection on the test stream.

Production gaps: direct carrier-to-UoT endpoint integration (remove the test SS
adapter), managed authentication/encryption and target-bound capabilities,
independent reverse load/remote target testing, Windows/macOS packaging and
control-plane integration. The lab now verifies stable sidecar UDP socket
resumption after a forced carrier failure; real relay failover and production
session authentication remain untested. Lease refresh continuity is separate
from carrier-failure resumption.
