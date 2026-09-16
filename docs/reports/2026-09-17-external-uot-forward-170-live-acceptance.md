# External UoT forward 170 live acceptance

## Scope

First production-shaped external UoT deployment, accepted live on 2026-09-17:

```text
UDP client -> Japan runtime :10001 (ultra-client-extuot)
           -> Ultra TCP carrier -> Tokyo sidecar :7443
           -> fixed UDP target 185.220.238.143:10001 (sing-box entrance)
```

Forward 170 is the only forward with external UoT enabled. Every other
forward and all TCP traffic keep the native paths.

Deployment facts:

- Japan `ultra-forward-170.service`: active + enabled, config
  `/etc/ultra/forward-170.json` with `external_uot.enabled` true,
  `external_uot.sidecar_target` `185.220.238.143:7443`, `target`
  `185.220.238.143:10001`, `target_digest`
  `0f877086c7cff08cd00f6778f0f18654464fc41cb5a0b45a4d787f4e1244170b`.
- Tokyo `leikwan-external-uot.service`: active + enabled, entry point
  `/usr/local/bin/leikwan_external_uot.py`, md5
  `124d78a948bddd3fb8788e2c0eed8ab4` - byte-identical to
  `sidecar-agent/leikwan_external_uot.py` in this tree.
- Sidecar config `/etc/leikwan-external-uot.json` carries forward 170 with
  `"targets": []`. The HMAC token already binds `forward_id` + `target`, so
  the sidecar runs allow-any instead of a hand-maintained allow-list; target
  strings are still normalized and malformed targets are still rejected.

## Acceptance evidence

Test rig: `iperf3 -s -p 5201` on Japan behind `/tmp/iperf3_bridge.py` (UDP
`127.0.0.1:15201`), the sidecar target forwards to it. Every run below is a
real UDP stream through the full carrier, not a loopback or lab hop.

| Direction | Rate cap | Result |
| --- | --- | --- |
| client -> server | 20 Mbit/s | 19.9 Mbit/s, 0/17856 (0%) lost |
| client -> server | 30 Mbit/s | 29.8 Mbit/s, 0/21423 (0%) lost |
| client -> server | 50 Mbit/s | 49.6 Mbit/s, 0/35627 (0%) lost |
| server -> client (`-R`) | 20 Mbit/s | 20.0 Mbit/s, 0/14292 (0%) lost |
| server -> client (`-R`) | 50 Mbit/s | 50.0 Mbit/s, 0/26794 (0%) lost |

Attribution (A/B): stopping `leikwan-external-uot.service` on Tokyo kills the
UDP path immediately - no listener, `iperf3` receives nothing. Starting it
again restores the path, and every run in the table above is a post-restart
run. The carrier, not the Leigod line, moves this forward's UDP.

Carrier byte proof: `ss -tinp` on Tokyo for the carrier socket owned by the
sidecar pid showed `bytes_received` 126,606,665 -> 157,611,585 (+29.6 MB)
across a single 30 Mbit/s burst, matching the burst volume. `bytes_sent` moved
by the keepalive frames only (+51 bytes) because the upstream direction does
not return per-datagram replies.

Runtime journal: `reconnects=0`, `drops=0` in every steady-state window
checked after 01:28 +08:00.

## Failure and restart behaviour

- A sidecar restart produces a short burst of `external UoT carrier reconnect
  ... reason=external UoT frame length invalid` lines clustered inside one
  minute (01:27 +08:00 on this run), then zero. `drops=0` throughout and the
  path self-heals; all acceptance runs above happen after that window.
- That burst is characterized but not root-caused. The logged `elapsed` values
  are 150-300 us, i.e. the failing read returns without waiting for a carrier
  round trip, so the working hypothesis is stale or non-UoT bytes on a carrier
  during re-negotiation rather than a provider RTT failure. Next diagnostic:
  `tcpdump` on the carrier across a forced sidecar restart.

## Verification traps

- `/proc/<pid>/io` is NOT usable on the Tokyo host: a control python that moved
  4 MiB through a `socketpair` reported `rchar` +98 / `wchar` +0, and the
  sidecar's counters stayed frozen at startup values through ~170 MB of real
  relay. Use `ss -tinp` socket byte counters or the runtime counters instead.
- `config.target` must stay byte-identical to the target the panel grant was
  issued for. The runtime rejects any lease whose `target_digest` does not
  match `sha256(config.target)`, which surfaces as `awaiting_policy` /
  `lease_invalid` with a dead carrier. Repointing the target is a re-grant
  operation, not a config edit; a temporary repoint to a test port was rolled
  back during this acceptance.

## Coverage added

`tests/test_external_uot_runtime.py` now pins the allow-any contract: empty and
wildcard (`"*"`) target sets accept any normalizable target, malformed targets
stay rejected, a populated list still filters, `_parse_targets(["*"])` no
longer raises during config load, and the relay path delivers a datagram for a
target that is not listed in the binding. `python -m pytest
tests/test_external_uot_runtime.py -q` = 7 passed.

## Residual / not covered

1. Session-reattach framing (stable target-visible source port across a forced
   carrier reconnect) is implemented as grace-period socket reuse but is not
   yet exercised with an explicit relay-side disconnect.
2. Multi-region sidecars: single sidecar host only.
3. The restart-window `frame length invalid` burst: bounded and self-healing,
   not root-caused (see above).
4. Windows/macOS runtime packaging for the external UoT path: untouched.

History: `docs/reports/2026-09-16-ultra-japan-external-uot-pilot.md` (lab
pilot) and `docs/reports/2026-09-16-external-uot-sidecar-runtime.md` (wire
contract and runtime wiring).


## Update — 2026-09-17 (store publish + install path)

* External UoT now ships inside the standard sidecar install: `external_uot_installer`
  (sha256 `0506d838…c6a4d665`) and `uot_sidecar_linux_{amd64,arm64}`
  (`18bd060a…21ac60ef` / `9799779f…38ea6320`) are published on the artifact store and
  `install/install-bond-sidecar.sh` installs them (`--external-uot-config` carries the
  per-host config). A clean host installed straight from https://v3.leikwanhost.com
  passed `LIVE-ACCEPT-ALL-PASS`, and the real runtime -> relay -> freshly installed
  sidecar -> real UDP path returned `VERDICT udp_roundtrip=PASS sustained=20/20
  burst=50/50`. See `docs/reports/2026-09-17-external-uot-live-store-publish.md`
  (commit df6f43c4).
* Residual 2 (multi-region): every region installs from the same store, so a second
  sidecar host is now an install plus its own config away; the real-host canary is
  tracked separately from this report.
* Residual 4 (Windows/macOS runtime packaging): the desktop external-UoT grant
  bootstrap is verified end-to-end — Windows writes `runtime/ultra-forward-{id}.json`,
  macOS injects the bootstrap payload over stdin, and the contract signature folds the
  token fingerprint only (`LeikwanBridge.Core.Tests` 570 passed, `LeikwanBridge.Mac.Tests`
  227 passed, Go sidecar + ultra-client suites ok).
* Residual 1 (session re-attach across a forced carrier reconnect) and residual 3
  (restart-window `frame length invalid` burst) remain open.

