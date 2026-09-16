# UoT Scope Determination & Probe/Reader Race Verification (2026-09-16)

## Scope

Follow-up to `2026-09-16-ultra-udp-cause-separation-and-canary.md` and `2026-09-16-leigod-client-udp-parity-audit.md`. Two open questions closed with local-only, read-only evidence plus one deterministic Go test:

1. Is official-client UoT (UDP-over-TCP) a per-line backend capability or an unconditional client feature?
2. Can the probe/reader deadline race be reproduced deterministically?

No production system was touched. All artifacts are local.

## Q1 Answer: UoT is per-line, backend-flagged

**Conclusion:** official LeiGod UoT is a **per-line backend flag**, not an all-lines client capability. The client enforces it per selected line; a separate local switch exists only as a force/test override (default off).

### Evidence (offsets verified 2026-09-16 against the local install)

| Artifact | Offset | Evidence | Meaning |
| --- | --- | --- | --- |
| `resources/leishenSdk/leishenSdk.exe` | 0x72b7c7 | Line-list API response field schema contains `is_udp_over_tcp` between `area_title` and `special_agreement_type`, surrounded by `server_id`, `node_id`, `regions_0`, `regions_1`, `multithreading_line_list` | backend marks UoT per line in the line-list payload |
| `resources/leishenSdk/leishenSdk.exe` | 0x728174 | Per-subline rule-string parser keys include `udpontcp=` next to `tun_mode=`, `udp_ka=`, `bandwidth=`, `banipv6=` | UoT is carried per subline in the rule string, parsed by `CEnginHelper::PraseGameRuleAndFill` |
| `resources/leishenSdk/leishenSdk.exe` | 0x727e9c | Route parser tokens `route:|proxyline:|over_tcp|kcp` | `over_tcp` is one of several route modes chosen when filling the line rule |
| `resources/leishenSdk/nne.dll` | 0xd7a04 | `over_tcp` inside `nn_server_manager::setup_servers` poll paths | engine applies `over_tcp` per server/line during polling |
| `resources/leishenSdk/nnlsp.ini` | 0x00 | `[local]` then `onlyuot=0` then `dns_cache_op=0` | local `onlyuot` is a force switch (force-only-UoT debug/test), default 0 |
| `resources/leishenSdk/leishenSdk.exe` | 0x72b1d5 | Test-flag cluster contains `test_onlyuot` among other backend-config `test_*` flags | confirms `onlyuot` is a test/force override, not the capability source |
| `resources/leishenSdk/log/nne_20260604.log` | line 44+ | Runtime rule JSON shows per-line options `options: {disconnect_cnt: "0", multi_udp_route: "true"}`, `udp_keep_alive: 0`, per-line entries `server_ip/server_port/tag: routine1` | options are delivered and applied per line at connect time |
| `tun2socks.dll` (string scan) | - | contains `onlyuot`, `over_tcp`, `multi_udp_route`, `enable_udp_limit`, `udp_keep_alive`, UDP-session `tcp_send/tcp_recv` | data plane implements the modes; it does not decide scope |

### Chain

1. Backend line-list API returns `is_udp_over_tcp` per line (0x72b7c7 schema).
2. The selected line's rule string carries `udpontcp=` per subline (0x728174 parser keys).
3. `CEnginHelper::PraseGameRuleAndFill` turns that into a route mode (`over_tcp`/`kcp`, 0x727e9c).
4. `nne.dll` server manager polls with the per-server `over_tcp` tag (0xd7a04).
5. `tun2socks.dll` executes UDP-over-TCP framing when told to (`write first uot pkt`, `udp_over_tcp.rs`, `uot handle sock5`).

Local `nnlsp.ini [local] onlyuot=0` and backend `test_onlyuot` are force overrides for QA (use UoT exclusively), default off.

### Implication for Ultra

- Model UoT as per-line capability metadata (panel/sidecar flag), not a global client mode; default off, enable per line during rollout.
- Our sidecar/leaf stack is one implementation across nodes, so capability gating belongs in line metadata + scheduler, mirroring the official model.
- Keep a local force switch for lab tests only (like `onlyuot`), never enabled in production.

## Q2 Answer: probe/reader race reproduced deterministically

**Conclusion:** confirmed. `probeEcho` and the runtime receive loop race on the same UDP socket; a 300ms probe deadline (or a probe receive stealing the runtime reader's datagram) collapses the session into `errUDPReceiveTimeout`, which `ha_tcp.go:20` labels `keepalive_timeout` ? the exact failure name in the canary report. "After a large packet, even small packets break" is self-inflicted before any relay/PMTU consideration.

### Reproduction

New deterministic test `tools/ultra-client/probe_reader_race_test.go`:

- `TestProbeDeadlineCollapsesRuntimeReader` ? fake-socket interleaving: business reply queued ahead of the probe echo; probe window (300ms) expires before the runtime reader's next read; asserts the session reports the keepalive-timeout failure path.

Results:

- Targeted run: PASS (0.30s).
- Full package: `go test . -count=1 -v` ? `ok leikwan.local/ultra-client 157.331s`, zero failures (log kept at `tmp/ultra-client-fulltest.log`).

### Code anchors (from earlier audit; unchanged this pass)

- `tools/ultra-client/main.go:816` ? probe window vs runtime receive loop.
- `tools/ultra-client/ha_udp.go:117` ? shared-socket receive path.
- `tools/ultra-client/ha_tcp.go:20` ? `errUDPReceiveTimeout` mapped to `keepalive_timeout` state label.

### Fix design (next step, not yet implemented)

1. Single reader: only the runtime receive loop calls `Read`/`SetReadDeadline`.
2. Probe as waiter: `probeEcho` registers a token + channel; the receive loop dispatches matching echo frames to waiters and never touches deadlines.
3. Error taxonomy: `net.ErrClosed` vs `i/o timeout` vs other ? never collapse timeouts into generic failure.
4. Regression: extend fake-socket tests with the interleavings above; run with `-race`.

## Verified vs unverified

Verified (local, reproducible):

- All string/offset evidence in the table above, read from the installed client today.
- `nnlsp.ini` default `onlyuot=0`.
- Deterministic race test PASS; full package PASS 157.331s.

Not verified:

- Whether any specific current Leigod backend line actually ships `is_udp_over_tcp=true` (requires an authenticated line-list capture).
- Native UoT data-plane throughput vs our Ultra (requires live A/B with relay-side capture).
- The exact wire format of official UoT framing (only Rust-side symbol names and log strings are known).

## Next actions

1. Land the single-reader refactor for `probeEcho` + receive loop.
2. Add an `is_udp_over_tcp`-style per-line capability to panel line metadata (default off) and plumb it to the Ultra client via the lease batch.
3. Capture one authenticated line-list payload to confirm live values.
4. Re-run the canary with the race fix; expect the `keepalive_timeout` count to collapse before any UoT rollout.
