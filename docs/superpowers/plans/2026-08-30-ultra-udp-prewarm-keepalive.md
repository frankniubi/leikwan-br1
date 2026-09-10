# Ultra UDP Prewarm And Keepalive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在 forward 启动时异步预热一个 UDP association，并在关联存活期间每 5 秒发送协议级保活，以减少游戏首包丢失、NAT/relay 超时和抖动，同时让 Windows、Linux、macOS 客户端通过一致配置获得该行为。

**Architecture:** Go Ultra runtime 在 UDP listener 建立后启动非阻塞的单槽 warm-session；首个本地客户端 association 优先消费该 session，其他客户端仍走原有按需 setup。已建立 session 使用固定的 reserved envelope（目标 `2.0.0.0:0`、现有 `keepPayloadHex`）每 5 秒发送协议保活，绝不注入用户目标数据。Linux/Python、Windows/C#、macOS/C# 统一写入 `udp_prewarm=true` 和 `udp_keepalive_interval_seconds=5`；Panel 把 Linux Ultra runtime hash 纳入强制更新收敛证据，Android 明确延期。

**Tech Stack:** Go 1.x (`tools/ultra-client`), Python 3 (`client-agent`), PHP (`master`), .NET 8/C# (`LeikwanBridge.Desktop`), PowerShell/bash packaging, systemd/Windows process supervisors.

**Spec:** `docs/superpowers/specs/2026-08-25-ultra-client-lifecycle-upgrade-and-fast-forward-design.md` plus the user-approved requirements in this plan: protocol keepalive exactly 5 seconds, no arbitrary user payload, Android deferred, Windows/Linux/macOS sample validation, artifact publication only after hash and convergence checks.

## Global Constraints

- Keepalive interval is exactly 5 seconds; configuration is normalized/rejected unless it resolves to 5 seconds.
- Keepalive uses only the existing protocol-reserved envelope and `keepPayloadHex`; no arbitrary application/user payload is generated or forwarded.
- Prewarm is best-effort and asynchronous: listener readiness and lease delivery cannot wait for the warm handshake; a failed warm attempt must leave lazy setup available.
- A warm session is consumed by the first local UDP association only; per-client associations and retries retain bounded queues and setup-worker limits.
- Preserve `leikwan-ultra-policy-v2` generation/nonce/target-digest validation, ACL/rate limits, memory-only credentials, and the existing single-line default; do not alter auto-selection policy.
- Android is out of scope for code, binaries, tests, and release publication in this change.
- Do not use `iperf3 -R` as the sole relay acceptance signal because provider UDP source-port NAT can make its TCP-bound UDP listener report `0/0`; use sequence-numbered echo/target-side packet counts and document this limitation.
- Do not copy credentials from `leikwan-v3.txt`, `cantons.txt`, or `shanghais.txt` into source, artifacts, logs, commits, or final reports; preserve the client -> slave -> sidecar topology.
- Preserve unrelated dirty worktree changes. No production publish or client mutation occurs until staged artifacts, backups, hashes, and smoke tests are verified.

---

### Task 1: Go UDP Prewarm And Protocol Keepalive

**Files:**
- Modify: `tools/ultra-client/main.go` (`Config`, `udpAssociation`, `serveUDPOnConn`, setup helpers)
- Test: `tools/ultra-client/main_test.go`

**Interfaces:**
- Add `Config.UDPPrewarm bool` (`json:"udp_prewarm"`) and `Config.UDPKeepaliveIntervalSecs int` (`json:"udp_keepalive_interval_seconds"`).
- Add `resolveUDPKeepaliveInterval(Config) time.Duration`, returning exactly `5*time.Second` for enabled UDP transports and normalizing invalid values to the fixed safe interval.
- Add a single-session warm channel owned by `serveUDPOnConn`; setup code must expose a common attach path so both prewarm and lazy associations install `DatagramSession` without duplicating queue flush/read-loop logic.

- [ ] Write failing tests before implementation:
  - `TestServeUDPPrewarmsAssociationBeforeFirstClientPacket`: fake provider records the first control TCP accept before any local datagram, then the first client payload receives an echo without queue-drop increments.
  - `TestServeUDPProtocolKeepaliveUsesReservedEnvelope`: after setup, wait beyond one 5-second interval and assert a datagram with destination `2.0.0.0:0` and bytes equal to `keepPayloadHex`; assert no local client payload is emitted for the keepalive.
  - Extend the existing queue/parallel-association test with `UDPPrewarm:false` to retain lazy setup semantics.
- [ ] Run `go test ./...` in `tools/ultra-client`; record the expected RED failure for the new tests.
- [ ] Implement the minimal runtime change: start one asynchronous prewarm worker after the UDP listener is bound; do not block `ReadFromUDP`, publish counts while the worker is pending, hand the warm session to the first association, and close an unused/stale warm session on context cancellation or bounded expiry. Keep lazy setup for all other clients.
- [ ] Add a per-session keepalive ticker at 5 seconds guarded by `writeMu`; send only `makeDatagramEnvelope(session.mode, session.sid, "2.0.0.0", 0, []byte(keepPayloadHex))`. A write failure closes the association. Keepalive responses are consumed as protocol frames and never counted as user bytes; retain separate user-activity timestamps for the existing two-minute idle cleanup.
- [ ] Run `gofmt -w tools/ultra-client/main.go tools/ultra-client/main_test.go`, then `go test ./...` and `go test -race ./...`.
- [ ] Commit only this task with `git add tools/ultra-client/main.go tools/ultra-client/main_test.go && git commit -m "feat: prewarm ultra udp associations and keep relay alive"`.

### Task 2: Linux Runtime Config And Panel-Forced Ultra Convergence

**Files:**
- Modify: `client-agent/install_forward_runtime.py` and byte-identical `artifacts/install-forward-runtime.py`
- Modify: `client-agent/client_agent.py`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Test: `client-agent/tests/test_ultra_runtime.py`, `client-agent/tests/test_client_agent_mptcp_health_grace.py`, and a focused `master/tests/client_agent_linux_ultra_runtime_contract_test.php`

**Interfaces:**
- `render_ultra_runtime()` emits `udp_prewarm` and `udp_keepalive_interval_seconds` for `udp`/`tcp+udp`, with `udp_prewarm=false` and interval `0` for TCP-only forwards.
- `ClientAgentController::stableConfigSnapshot()` includes `ultra_client_runtime` metadata in `config_revision` and returns `desired_ultra_runtime_version`/`desired_ultra_runtime_sha256`.
- `ClientAgent.auto_update_request_token()` compares the desired Ultra hash with the installed `/usr/local/bin/ultra-client` (or platform-resolved helper path) and includes `ultra_runtime=<sha>` in the token on mismatch.

- [ ] Add Python/PHP failing assertions for the two config keys, TCP-only disabling, manifest metadata in the snapshot/revision, and an Ultra-only hash mismatch requesting update.
- [ ] Run focused Python/PHP tests to capture RED.
- [ ] Implement both Python source/artifact renderers identically, add the Linux desired-hash fields to the authenticated snapshot and update-token path, and ensure the existing installer downloads/verifies the Ultra artifact before restart. Do not add Android fields.
- [ ] Run `python -m unittest client-agent/tests/test_ultra_runtime.py client-agent/tests/test_client_agent_mptcp_health_grace.py`, the new PHP contract test, and the full `python -m unittest discover -s client-agent/tests -p 'test_*.py'` suite.
- [ ] Commit with `git add client-agent artifacts/install-forward-runtime.py master/src/Controllers/ClientAgentController.php client-agent/tests master/tests/client_agent_linux_ultra_runtime_contract_test.php && git commit -m "feat: roll out ultra udp warmup settings to linux clients"`.

### Task 3: Windows/macOS Runtime Bootstrap Contract

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/Runtime/MacUltraRuntimeHostTests.cs` and a new Windows host test under `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/`

**Interfaces:**
- Both hosts add `udp_prewarm` and `udp_keepalive_interval_seconds` to the JSON/STDIN bootstrap for UDP-capable transports only; values are `true` and `5`.
- Startup readiness remains listener/process based and must not wait for the asynchronous prewarm handshake; lease material remains memory-only and is wiped as today.

- [ ] Add failing assertions for single-lease TCP+UDP and TCP-only bootstrap payloads, and ensure multi-candidate auto-select retains probe fields plus the new UDP keys.
- [ ] Run focused Mac/Core tests to capture RED.
- [ ] Implement the conditional dictionary entries in both hosts without changing auto-select or provider redaction behavior.
- [ ] Run `dotnet test LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln --no-restore` and verify all existing Core/Mac/App tests pass.
- [ ] Commit with `git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs LeikwanBridge.Desktop/tests && git commit -m "feat: enable udp prewarm in desktop ultra bootstrap"`.

### Task 4: Build, Version, Mirror, And Validate Artifacts

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj` and `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj` only when the next release version is selected from the current manifest.
- Create/replace staged outputs under `artifacts/ultra/`, `artifacts/windows/`, and `artifacts/macos/`; update `artifacts/versions.json` through the existing packaging scripts.

- [ ] Use the selected next versions: Ultra runtime `2026.08.30.4` on Linux/Windows, Windows app `0.1.19.9`, and macOS app/update `1.0.4`. Do not write any version into the manifest without a matching verified binary.
- [ ] Build Linux Ultra with `pwsh -File scripts/build-ultra-linux.ps1 -Version 2026.08.30.4` and verify reproducible output hashes; use the same runtime version for Windows unless the build script requires a platform-specific suffix.
- [ ] Build the Windows app/setup with `scripts/build-windows-client-setup.ps1`, verify the bundled Windows Ultra hash against `versions.json`, and validate installer metadata/version tests.
- [ ] On macOS build both architectures via `scripts/build-macos-client.sh --rid osx-arm64 --version 1.0.4` and `scripts/build-macos-client.sh --rid osx-x64 --version 1.0.4`, then package with `scripts/package-macos-client.sh`; verify helper-manifest architecture and Ultra hash. Bump the Windows app from `0.1.19.8` to `0.1.19.9`.
- [ ] Mirror the Python installer source byte-for-byte, regenerate manifest size/SHA-256 fields, run `git diff --check`, artifact hardening/distribution tests, and reject any artifact containing credentials or provider branding.
- [ ] Commit generated artifacts and version metadata separately from source changes with `git add artifacts LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj && git commit -m "release: publish ultra udp prewarm artifacts"`.

### Task 5: Windows/Linux/macOS Sample Smoke Matrix And Publication Gate

**Files:**
- Create: `docs/reports/2026-08-30-ultra-udp-prewarm-smoke.md`
- Test/operate: existing sample hosts, installers, runtime status files, and artifact manifest; no credential-bearing files are committed.

- [ ] Before mutation, back up Windows Program Files/ProgramData runtime state, Linux Ultra binary/config/systemd state, and macOS app bundle/update state using the existing ownership-aware procedures.
- [ ] Windows sample: install the staged setup, verify one GUI/agent ownership chain, a ready listener, `udp_prewarm:true`, interval `5`, and no provider/credential fields in config/logs.
- [ ] Linux sample: apply the authenticated update, verify installed Ultra SHA-256 equals the published manifest, systemd unit/listener readiness, and the agent reports the desired hash as converged.
- [ ] macOS sample: install DMG or update ZIP for both architectures where hardware permits, verify bundle helper-manifest Ultra hash and runtime bootstrap keys.
- [ ] For each platform run a sequence-numbered UDP echo test: start forward, confirm provider control accept before first user datagram, send the first sequence immediately, pause user traffic for at least 15 seconds, confirm reserved keepalive frames keep the association alive, then resume and verify echo order/loss and `udp_queue_drops`.
- [ ] Record p95 startup-to-echo, loss percentage (target `<=0.1%` for the sustained low-rate acceptance), jitter/loaded RTT observations, and the `iperf3 -R` source-port-NAT limitation. Do not claim a platform converged until binary hash, process/listener, and health evidence are present.
- [ ] Run the full focused release gates: Go, Python, PHP artifact/controller tests, and .NET solution tests; only then publish manifest pointers/update channels. Android remains unchanged.
- [ ] Write the credential-free smoke report with rollback commands (restore backups/old manifest pointers, stop new runtime, restart old owned release) and commit the report separately.

### Task 6: Final Review And Client Update Guidance

**Files:**
- Modify: `docs/` release/update guidance only if the shipped artifact contract requires a user-facing procedure.
- Review: complete diff, artifact manifest, generated configs, test output, and smoke report.

- [ ] Run `rg -n "udp_prewarm|udp_keepalive_interval_seconds|keepPayloadHex|desired_ultra_runtime_sha256"` across source and artifacts and verify every producer/consumer uses the same names and values.
- [ ] Perform a security review for credential leakage, arbitrary keepalive payloads, stale-session reuse, lock/write races, and accidental Android changes; a finding is blocking if it could send user data or claim a false update.
- [ ] Verify clean artifact/source mirrors, manifest hashes/sizes, no unrelated files staged, and no uncommitted release process left running.
- [ ] Publish only after all checks pass, then provide clients the existing authenticated update path: Linux agent pull/forced token, Windows app updater, and macOS app updater. Report installed-versus-desired evidence per platform and explicitly list offline clients as pending.
