# External UoT sidecar - Go implementation parity report

Date: 2026-09-17
Tree: `leikwan-br1` (Windows workstation), baseline `master` @ `33d5d5a3`
Status: **parity verified locally (Go unit tests + Python parity harness); on-host canary NOT yet run**

## 1. What shipped

The operator-owned external UoT sidecar now has a Go implementation that is
wire-compatible with `sidecar-agent/leikwan_external_uot.py`. It speaks the
identical UOT2 framing, HELLO/ACK semantics, HMAC token derivation, keepalive,
idle-close, reattach/grace and queue-drop behaviour, and it accepts the exact
same JSON configuration file, so it can replace the Python entry point on
hosts without a Python runtime.

| Path | Role |
| --- | --- |
| `tools/uot-sidecar/protocol.go` | frame codec, HELLO/ACK payloads, HMAC token |
| `tools/uot-sidecar/config.go` | config loader/validator + `summarizeConfig` (secret-free dump) |
| `tools/uot-sidecar/server.go` | listener, sessions, carrier I/O, UDP bridge, keepalive/idle |
| `tools/uot-sidecar/main.go` | CLI: `--config`, `--log-level`, `--version`, `--print-token`, `--secret`, `--secret-file`, `--check-config` |
| `tools/uot-sidecar/*_test.go` | Go unit tests (frame codec, config, server behaviour) |
| `tests/test_external_uot_sidecar_go.py` | differential harness: Go binary vs. Python reference, same fixture |
| `scripts/build-uot-sidecar.ps1` | cross-build + reproducibility check + `manifest.json` |
| `artifacts/uot-sidecar/` | built binaries for linux/amd64, linux/arm64, windows/amd64, darwin/arm64 |

## 2. Build

```powershell
# all fleet targets (+ reproducibility re-build and manifest)
./scripts/build-uot-sidecar.ps1 -All

# single platform, explicit identity
./scripts/build-uot-sidecar.ps1 -OS linux -Arch amd64 -Version 2026.09.17.1 -ShortSha 33d5d5a3
```

CGO is disabled, so every artifact is a static binary. `sidecarBuildVersion` is
read back from `--version` after each build and recorded next to the SHA-256 in
`artifacts/uot-sidecar/manifest.json`.

Built artifacts (this run):

```text
linux_arm64    2026.09.17.1.33d5d5a3  9799779f...a6320  2.95 MB
linux_amd64    2026.09.17.1.33d5d5a3  18bd060a...c60ef  3.01 MB
windows_amd64  2026.09.17.1.33d5d5a3  f47e26a1...3e9b0e 3.17 MB
darwin_arm64   2026.09.17.1.33d5d5a3  a90a3a68...4e1a680 2.96 MB
```

## 3. Test evidence

```text
cd tools/uot-sidecar; gofmt -l . ; go vet ./... ; go test -count=1 ./...
  -> vet=0, ok leikwan.local/uot-sidecar 1.345s
python -m pytest tests/test_external_uot_sidecar_go.py -q   # fixture: HMAC forward 170, wildcard forward 181, static-token forward 171
  -> 10 passed in 4.61s
python -m pytest tests/test_external_uot_sidecar_go.py tests/test_external_uot_runtime.py -q
  -> 17 passed in 4.80s
```

### Differential harness cases (Go binary vs. Python reference, byte-level)

| Case | What is asserted |
| --- | --- |
| `test_ack_bytes_match_reference` | 13 HELLO variants (plain, negotiated 300, wildcard, unknown forward, static token, static-token mismatch, bad token, foreign target, malformed target, missing token, tiny max, non-object JSON, broken JSON): the ACK bytes (or the exact log line) are identical to the reference |
| `test_echo_and_replay_suppression_match_reference` | DATA echo, duplicate sequence handling, replayed payload suppression |
| `test_reattach_marker_matches_reference` | reattach ACK fields (`reattached=true`) identical |
| `test_udp_source_port_survives_reattach` | the UDP socket is reused across a carrier reattach |
| `test_bulk_datagrams_are_delivered_in_order` | 200 datagrams (1..1200 B, sliding window) delivered in order through the carrier |
| `test_oversize_payloads_are_dropped_by_both` | a payload of exactly `frame_payload_max` (1200) is forwarded; one byte above it is dropped by both implementations, in the carrier->UDP direction |
| `test_rejection_log_line_is_unchanged` | rejection log text is byte-identical |
| `test_idle_keepalive_and_close_match_reference` | keepalive cadence and idle close are matched on a fast fixture (idle timeout 2 s, keepalive 0.2 s); the fleet fixture keeps the legacy 90 s / 10 s defaults |
| `test_print_token_matches_reference` | `--print-token` output equals `make_hmac_token()` for several targets/forwards |
| `test_check_config_matches_reference_loader` | `--check-config` JSON matches the reference loader field-by-field and never prints a secret |

## 4. Behaviour notes / deviations

1. **Carrier write deadline.** Go sets a write deadline of `idle_timeout` on
   each carrier write, so a client that stops reading is disconnected after the
   same horizon at which the reference closes on the read-side idle timeout. The
   reference blocks indefinitely on `drain()`; the observable session lifetime is
   the same, but the Go side cannot stall a writer goroutine forever. This is an
   intentional hardening, not a protocol change.
2. **Oversize datagrams.** Both implementations drop payloads larger than the
   session's negotiated `frame_payload_max` (default 1200) and count them in
   `oversize_drops`. Verified in the carrier->UDP direction.
3. **Empty datagrams.** Both ignore zero-length UDP datagrams arriving from the
   target (the reference's `if payload:` guard, mirrored in the Go reader).
4. **Adaptive tuner.** As in the reference, the tuner is advisory: it never
   raises a live session above its negotiated maximum.
5. **Unverified.** No live sidecar host has run this binary yet: the fleet
   canary (slave -> sidecar hop, real forward, panel traffic) is still pending.

## 5. Deployment sketch (not yet executed)

```bash
install -m 0755 uot-sidecar_linux_amd64 /usr/local/bin/leikwan-external-uot-go   # arm64: uot-sidecar_linux_arm64
/usr/local/bin/leikwan-external-uot-go --check-config --config /etc/leikwan-external-uot.json
/usr/local/bin/leikwan-external-uot-go --version
```

Keep the existing systemd unit name and config path; swap `ExecStart` to the Go
binary and keep the Python file in place for rollback (`ExecStart` revert +
`systemctl daemon-reload` + `restart`).

## 6. 中文摘要

- Go 版 sidecar 已实现 UOT2 线协议全兼容（帧格式、HMAC 令牌、HELLO/ACK、
  keepalive、idle 关闭、重连/宽限、队列丢弃），并复用同一份 JSON 配置。
- 差分校验：11 组 HELLO 变体的 ACK 字节与 Python 参考实现逐字节一致；
  200 条数据报按序透传；超限（>1200B）与空数据报两边都被丢弃。
- CLI 为超集：`--version`、`--print-token --secret/--secret-file`、
  `--check-config`（不打印密钥）可用于无 Python 的运维主机。
- 产物：4 个平台静态二进制 + `manifest.json`（sha256/md5/version）。
- 未验证：真机（slave -> sidecar）灰度与面板实走流量；写入侧写超时是本实现
  相对参考实现的唯一有意差异（同样以 idle_timeout 为界）。
