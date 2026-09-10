# Ultra Probe And Client Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在 Windows、macOS、Linux、Android 上统一以固定 `1.1.1.1:53` 做协议感知的 Ultra 候选竞速，并让控制面短断线不再立即使有效 lease、listener 或已建立 TCP 失效。

**Architecture:** 保持现有 Ultra wire/API 不变，在 `tools/ultra-client` 增加共享的 UDP DNS/TCP greeting 双协议探测与候选生命周期；桌面 Core、Linux `client-agent`、Android session/control loop 各自接入同一套状态和错误语义。控制面使用现有 endpoint supervisor/selector 做 primary/mirror failover，数据面仅在权威 lease 状态失效或有界 drain 到期时关闭。

**Tech Stack:** Go, C#/.NET 8, Python 3, Kotlin/coroutines, systemd, xUnit, pytest, Gradle/JUnit, shell installer tests.

**Spec:** [docs/superpowers/specs/2026-09-10-ultra-probe-and-client-resilience-design.md](../specs/2026-09-10-ultra-probe-and-client-resilience-design.md)

## Global Constraints

- 探测目标固定为 `1.1.1.1:53`；不得加入 `8.8.8.8` 或静默目标 fallback。
- 四个平台都必须覆盖；`tcp+udp` 在当前单 lease 兼容模型下必须同时通过 UDP DNS 和 TCP greeting。
- 不修改 `/api/client/configs/pull`、`/api/client/ultra/lease`、`/api/client/heartbeat` 的路径、字段、响应结构或协议版本。
- 网络 timeout、无响应连接错误、502/503/504/530 才能 failover；收到 401/403/409 或其他业务响应后不得重复 POST 或换 endpoint。
- 控制面短断线不能调用 Stop All；有效 lease 到期后才停止新连接并进行最多 30 秒 drain，不得延长服务端授权。
- 不记录 session ticket、nonce、用户名、密码、Authorization header；保留现有 dirty worktree 文件，不回滚无关改动。
- 每个任务先写失败测试，再实现，再运行针对性验证；每个任务完成后只提交该任务相关文件，提交信息使用本计划中的建议文本。

---

## 执行状态（2026-09-11）

本计划的原始范围包含协议探测竞速、控制面宽限、四端运行时生命周期和上线故障注入。本轮已落地 candidate reputation/isolation 核心、Go 协议感知探测与连接级候选重试、桌面端 native outbound 生命周期隔离，以及 Android 普通 Ultra 候选批量注入和控制面状态；仍未把真实 `OpenGFW`、完整四端 drain 故障注入或生产 canary 标记为完成。

### 本轮已落地

- 服务端新增 scoped candidate reputation current/events 表、稳定 hash、probation/quarantine TTL/backoff、独立 observer 计数、恢复计数和 availability floor。
- `UltraMaterialService` 在候选生成时登记 identity；`ULTRA_CANDIDATE_QUARANTINE_ENABLED` 默认关闭，开启后在 `max_candidates`/offset 前过滤动态 quarantine，并将 reputation fingerprint 纳入 policy generation。
- lease audit、Go runtime、Linux `client-agent`、Windows/macOS heartbeat 均携带 opaque `candidate_key_hash` 与 `lease_hash`；服务端只接受已消费且未撤销 lease 绑定的反馈。
- Go `PolicyStore` 支持最多 4 个候选、两次连续分类数据面失败后的本地 isolation、健康 sibling 保留和成功恢复；`process_stderr` 不计入失败证据。
- 管理面新增只读 Reputation 视图；未实现手工 restore/force quarantine，也未实现自动购买或自动挂载新线。
- Go `udp_probe.go` 已加入固定 `1.1.1.1:53` 校验、DNS 响应完整性校验、TCP/UDP 独立结果、`tcp+udp` 双门槛、并发 worker、协议错误码和候选连接重试；已建立的 stream 不会因为一次候选失败而重放应用 payload。
- Desktop Core 已用共享 `UltraProbeContract` 锁定普通 auto-select 参数；native outbound forward 在 polling/runtime manager 中跳过普通 Ultra lease、listener preflight 和 refresh，并清理旧 ordinary runtime 状态。
- Android 已支持普通 Ultra 最多四个候选的批量解析/注入，保留 `ProtectedConnectivitySet`，并为 `ClientControlLoop` 暴露 `READY/RECONNECTING/STOPPED` 状态。

### 已验证与阻塞

- PHP Ultra 相关契约测试：24/24 通过；相关 PHP lint、`git diff --check` 通过（PHP CLI 仅有现有 `UTC+8` 时区警告）。
- Python Ultra status/artifact 定向测试：12/12 通过；本轮控制面相关 pytest 定向集：29 passed、2 个参数化子用例通过；Android artifact manifest：通过；桌面 Core 定向测试：70/70 通过，macOS host：3/3 通过，Desktop solution build：0 warning/0 error。Mac 全量测试在既有 `StopDuringForwardPreparationCancelsWithoutLaunchingProxy` 用例挂起，单测单独重跑同样复现。
- Android `:app:testDebugUnitTest`：BUILD SUCCESSFUL；包含普通 Ultra bootstrap、批量 lease 注入和 control-loop 状态回归。
- Python client-agent 全量：432 个测试项中 431 个通过、1 个失败（另有 19 个参数化子用例通过）；唯一失败是现有 `test_client_installer_downloads_both_helpers_atomically` 仍期待 `$ARTIFACT_BASE`，而当前 dirty installer 使用 `$DOWNLOAD_BASE`，未在本轮改动。
- Go 协议探测/候选重试定向测试曾在临时最小 `opengfw` stub 下通过；该未跟踪 stub 已删除。当前真实 `go test` 被 `tools/ultra-client/go.mod` 的 `../../opengfw` 缺少 `go.mod` 阻塞，且不能据此宣称 OpenGFW 分析器验收。
- 原始 Task 1-9 中的 60 秒探测缓存、完整 `control_reconnecting`/lease drain 状态、Linux watchdog 对等接入、四端故障注入和 live canary 仍保持未完成，需真实依赖和环境证据。

### 本轮增量对应项

- [x] 固定探测目标和普通 Desktop/Android auto-select bootstrap 已统一为 `1.1.1.1:53`、30 秒、800 ms、30 samples、1 秒间隔、4 workers。
- [x] Go DNS transaction/QR/opcode/TC/RCODE/结构校验、`tcp+udp` 双协议硬门槛、候选排序和 bounded cancellation 已实现并有定向测试。
- [x] Go provider TCP 建连在读取应用 payload 前按最多四个已签发候选重试，并返回 `no_healthy_candidate`。
- [x] Desktop native outbound forward 180 的普通 Ultra reconciliation/start/refresh/preflight 防线已实现并有 Core 回归测试。
- [x] Android 普通 Ultra 候选批量下发、credential wipe 和控制面状态回归已实现并通过 unit tests。
- [ ] 60 秒 per-relay 健康缓存、完整 lease-at-risk/30 秒 drain 和跨平台状态 schema 尚未完成。
- [ ] 真实 `OpenGFW` module、四端 failure-injection、部署和 live canary 尚未完成。

---

## Task 1: Lock Shared Contract And Fixtures（部分完成）

**Files:**
- Modify: `tools/ultra-client/main.go` (config defaults/constants only)
- Modify: `tools/ultra-client/udp_probe.go` (result/error contract)
- Modify: `tools/ultra-client/status.go` (status fields/error names)
- Test: `tools/ultra-client/udp_probe_test.go`, `tools/ultra-client/selector_test.go`, `tools/ultra-client/main_test.go`
- Test: `client-agent/tests/test_ultra_runtime.py`, `client-agent/tests/test_ultra_client.py`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsUltraRuntimeHostTests.cs`, `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/Runtime/MacUltraRuntimeHostTests.cs`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/UltraModelsTest.kt`, `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/UltraForwardRunnerTest.kt`

**Steps:**

- [x] Add contract tests/assertions for generated auto-selection configs using `probe_target=1.1.1.1:53`; no new fixture uses `8.8.8.8`.
- [ ] Add the full cross-platform status-value matrix for `probe_udp_failed`, `probe_tcp_failed`, `no_healthy_candidate`, `control_reconnecting`, `lease_at_risk`, and `lease_expired` (only the Go probe error subset is covered this round).
- [ ] Add a complete cross-platform fixture with UDP-only, TCP-only, dual-success, and timeout candidates (Go synthetic protocol/ranking cases are covered; live transport fixture remains open).
- [ ] Run `go test ./tools/ultra-client/...`, focused `dotnet test` projects, `python -m pytest client-agent/tests/test_ultra_runtime.py client-agent/tests/test_ultra_client.py`, and `./gradlew :app:test` from `leikwan-android`; confirm the new assertions fail before implementation.
- [x] Implement shared constants, typed result/error names, and backward-compatible single-lease parsing.
- [ ] Re-run the focused suites and commit as `test: lock ultra probe and lease resilience contract`.

## Task 2: Implement Go Protocol-Aware Probe Race（核心已实现；缓存/真实依赖待补）

**Files:**
- Modify: `tools/ultra-client/udp_probe.go`
- Modify: `tools/ultra-client/selector.go` if ranking needs shared helpers
- Modify: `tools/ultra-client/main.go` for config validation/defaults
- Test: `tools/ultra-client/udp_probe_test.go`, `selector_test.go`, `selection_tier_test.go`

**Steps:**

- [x] Add tests for DNS transaction ID/QR validation, malformed response rejection, cancellation classification, and strict `1.1.1.1:53` target validation.
- [x] Implement transport-specific TCP greeting/target-connect, UDP DNS association/query, and `tcp+udp` dual hard gate.
- [ ] Add a complete fake dial/association harness proving quality-window cancellation and no worker leak (bounded dispatch cancellation is covered; early quality-window cancellation remains open).
- [x] Add ranking tests for hard-failure filtering, loss/jitter/p95/p50 ordering, selection tier preservation, and deterministic original-index tie-breaks.
- [x] Implement the probe target parser and DNS response validator; keep association setup timeout separate from per-packet timeout.
- [x] Implement independent UDP/TCP metrics and protocol error categories; `selectBestLeaseWithProbe` requires both paths for `tcp+udp`.
- [ ] Add 60-second in-memory per-relay health TTL and 30-second quarantine after two consecutive hard failures, keyed by forward/policy generation/selection ref; do not persist credentials.
- [ ] Run the full Go suite and `go vet` against the real `../../opengfw` module; currently blocked because that module is absent. Targeted probe tests passed only with a temporary stub that has been removed.

## Task 3: Make Go Runtime Preserve Streams And Retry Candidates（连接级重试已实现）

**Files:**
- Modify: `tools/ultra-client/main.go`
- Modify: `tools/ultra-client/control.go`, `tools/ultra-client/policy.go` for lease state transitions
- Modify: `tools/ultra-client/status.go`
- Test: `tools/ultra-client/main_test.go`, `regression_test.go`, control/policy tests

**Steps:**

- [x] Add a regression test where the first provider TCP dial fails and a second already-delivered candidate succeeds before application payload read.
- [ ] Add failing tests where runtime policy refresh/control socket errors occur while a TCP stream is active; assert listener and stream remain until lease expiry/revoke.
- [ ] Add failing tests for `lease_at_risk` (no new associations), 30-second drain deadline, `lease_expired`, explicit revoke, policy generation rotation, and local listener-not-ready states.
- [x] Refactor candidate dial setup so failed candidates are marked unhealthy and the next candidate is attempted before lease expiry; exhaustion returns `no_healthy_candidate`.
- [ ] Separate runtime context cancellation caused by control-plane transport loss from authoritative policy invalidation; only the latter closes listeners immediately.
- [ ] Add stream/association counters and close reasons to `RuntimeStatus`; ensure status writer cannot remain `ready` when listener is absent or lease is expired.
- [ ] Run `go test ./tools/ultra-client/... -race` and the focused stream tests; commit as `feat: preserve ultra runtime across transient control loss`.

## Task 4: Windows And macOS Bootstrap/Control-Plane Integration（bootstrap/native guard 已实现）

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ControlPlaneEndpointSupervisor.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/LeikwanHttpClientFactory.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ControlPlaneEndpointSupervisorTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsUltraRuntimeHostTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/Runtime/MacUltraRuntimeHostTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs`, `MasterApiClientDesktopLoginTests.cs`

**Steps:**

- [x] Add xUnit coverage for Windows/macOS auto-select bootstrap using `1.1.1.1:53` and shared duration/timeout/sample/worker values.
- [ ] Add failing tests proving system proxy/PAC changes rebuild the control-plane transport without stopping an active runtime; direct/no-proxy is used by default.
- [ ] Add failing tests for timeout/502/503/504/530 failover, 401/403/409 no-failover, no-response-only POST retry, and full pull after recovery.
- [ ] Add failing lifecycle tests proving transient `MasterApiClient`/polling errors preserve runtime and UI reports `control_reconnecting`; explicit revoke/policy change still stops the affected forward.
- [x] Implement shared bootstrap constants/config validation in Core; provider credentials remain out of static bootstrap files/logs.
- [ ] Route all desktop pull, heartbeat, Ultra lease, diagnostics, and update control calls through the existing supervisor/handler; reset pooled connections when proxy settings change.
- [ ] Add lease remaining-time and drain state to the host/runtime status path without changing panel JSON.
- [x] Run focused Core/macOS tests and `dotnet build LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln` (70/70, 3/3, 0 warning/0 error); full control-plane failover/drain matrix remains open.

## Task 5: Linux Agent Parity And Watchdog Behavior

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `artifacts/client-agent.py` to mirror source behavior
- Modify: installer/service templates that render Ultra runtime config and watchdog settings
- Test: `client-agent/tests/test_ultra_runtime.py`
- Test: `client-agent/tests/test_ultra_renewal_schedule.py`, `test_control_plane_failover.py`, `test_ultra_status.py`

**Steps:**

- [ ] Add failing pytest tests asserting generated Linux Ultra config uses `1.1.1.1:53`, protocol-aware values, and no 8.8.8.8 string.
- [ ] Add failing tests for endpoint transport error retention, mirror failover, primary recovery, business response no-failover, and no-response-only POST retry.
- [ ] Add failing tests where lease resolution fails while an Ultra service is active; assert the old runtime and cached lease remain until the expiry/drain boundary.
- [ ] Add failing tests for status collection of listener readiness, control-plane state, lease expiry/renew-after, candidate errors, active streams, and explicit close reason.
- [ ] Implement Linux config rendering and `ControlPlaneEndpointState` integration using the existing state file; preserve current endpoint across process restart and atomically write state.
- [ ] Make renewal/reconcile classify transport loss separately from policy/revoke errors; send systemd watchdog notifications even while in bounded reconnect backoff.
- [ ] Mirror the source into `artifacts/client-agent.py`, run `python -m compileall client-agent artifacts`, all client-agent pytest suites, and installer shell tests; commit as `feat: align linux ultra probe and lease grace`.

## Task 6: Android Control Loop And Runtime Parity（批量注入/状态已实现）

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/UltraForwardRunner.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/UltraLeaseClient.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/UltraModels.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/session/ClientControlLoop.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/UltraForwardRunnerTest.kt`, `UltraLeaseClientTest.kt`, `UltraModelsTest.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/session/ClientControlLoopTest.kt`, `OrdinaryUltraRenewalTest.kt`

**Steps:**

- [x] Add Android coverage for shared bootstrap default, bounded candidate batch delivery, and sensitive payload wiping; dual-protocol selection is enforced by the Go runtime contract.
- [ ] Add failing coroutine tests where heartbeat/lease refresh throws socket/TLS timeout; assert the VPN/runtime remains active and the loop schedules retry without duplicate jobs.
- [ ] Add failing tests for terminal 401/403/409/revoke/policy change, lease-at-risk new-connection blocking, and expiry drain signaling to `TunnelOrchestrator`.
- [x] Implement typed `ClientControlLoop` control-plane state and bounded ordinary Ultra batch resolution/injection in `PanelRepository`; full Android endpoint failover remains open.
- [ ] Pass lease expiry/renew-after and protected connectivity metadata to the runtime lifecycle; do not close the VPN on transient panel errors.
- [x] Keep Android local socket retries bounded and wipe all candidate credential byte arrays on success, rejection, cancellation, and exception.
- [x] Run `./gradlew :app:testDebugUnitTest` successfully; Robolectric/instrumentation smoke tests and full expiry-drain behavior remain open.

## Task 7: Panel/Material Health Projection Compatibility

**Files:**
- Modify only when the contract test demonstrates a server-side filtering gap: `master/src/Support/UltraCandidateReputationService.php`, `master/src/Support/UltraMaterialService.php`, `master/src/Support/UltraLeaseService.php`, `master/src/Controllers/UltraSourceController.php`, and a new migration under `master/migrations/`.
- Test: `master/tests/ultra_candidate_reputation_contract_test.php`, `master/tests/ultra_candidate_resolution_quarantine_test.php`, `master/tests/ultra_lease_endpoint_contract_test.php`, `master/tests/ultra_policy_lease_contract_test.php`.

**Steps:**

- [ ] Add a failing contract test showing a material with line-level `native_udp_state=verified` but one unhealthy relay is still accepted by old clients and can carry optional per-relay health for new clients.
- [ ] Define an optional per-relay health shape containing selection ref, protocol, last probe time, health state, and failure class; exclude credentials and raw probe payloads.
- [ ] Implement projection/filtering only when the failing contract test proves that client-side probes cannot prevent a known bad relay from being issued; otherwise record the test-only decision and leave server candidate order unchanged. Do not add a required field to `/api/client/ultra/lease`.
- [ ] Add migration/rollback coverage for any new table/index, and verify old desktop/Linux/Android parsers ignore the optional field.
- [ ] Run the listed panel tests and request/response snapshots; commit a server change as `feat: expose optional ultra relay health projection` only when the proof requires it, otherwise commit the contract evidence as `test: confirm client-side ultra relay health is sufficient`.

## Task 8: Unified Observability And Redaction

**Files:**
- Modify runtime status writers and platform status adapters touched by Tasks 3-6.
- Modify: `client-agent/client_agent.py` status normalization and desktop/Android diagnostic mappers.
- Test: Go status tests, `client-agent/tests/test_ultra_status.py`, desktop status tests, Android diagnostics/status tests.

**Steps:**

- [ ] Add failing redaction tests that reject session ticket, nonce, username, password, bearer token, and full request headers in logs/status.
- [ ] Add failing cross-platform schema tests for listener readiness, control-plane endpoint/state, lease timestamps, per-protocol candidate metrics, active stream counts, and close reasons.
- [ ] Implement bounded field lengths, atomic status writes, monotonic timestamps where available, and stable error-code normalization.
- [ ] Add counters for provider connect timeout, candidate fallback, all-candidate failure, control-plane reconnect, lease-at-risk, and drain completion.
- [ ] Run all observability-focused tests and static redaction scans; commit as `feat: standardize ultra resilience telemetry`.

## Task 9: Failure-Injection Acceptance And Rollout

**Files:**
- Add/modify integration fixtures under existing Go, Python, desktop, and Android test directories.
- Add rollout/config documentation under `docs/superpowers/` and the relevant client release notes only after code passes.

**Steps:**

- [ ] Build a matrix for four candidates: UDP timeout, TCP timeout, dual success, relay reset, target rejection, and all-fail; record selected ref and stream behavior.
- [ ] Inject Windows/macOS system proxy/PAC changes, panel primary timeout, mirror success, both endpoints down, recovery, and lease near-expiry; verify no premature TCP close.
- [ ] Run equivalent Linux and Android scenarios, including process restart with valid cached lease, watchdog heartbeat, VPN preservation, explicit revoke, and policy generation rotation.
- [ ] Run the full Go, .NET, Python, Kotlin/Gradle, installer, and artifact consistency suites; capture command output and test counts.
- [ ] Verify generated artifacts and source scans contain only `1.1.1.1:53`, no `8.8.8.8`, no secrets, and no unbounded retry/Stop All path on transport errors.
- [ ] Enable `ultra_probe_race_v2` then `ultra_control_plane_grace_v1` by client cohort, observe at least one complete lease period and primary/mirror recovery, and keep a one-step flag rollback.
- [ ] Commit release notes and rollout configuration as `chore: verify ultra probe and client resilience rollout` only after live canary evidence is recorded.

## Verification Checklist

- [ ] `rg -n "8\.8\.8\.8|probe_target" tools client-agent LeikwanBridge.Desktop leikwan-android` shows no new 8.8.8.8 usage and every auto-select path is pinned to `1.1.1.1:53`.
- [ ] API path/JSON snapshots for pull, lease, heartbeat, diagnostics, and update are unchanged.
- [ ] A transient control-plane error never calls `StopAll`, `stopForward`, or VPN teardown for an otherwise valid forward.
- [ ] Lease expiry, revoke, policy change, and local safety violations still fail closed per forward.
- [ ] All test suites pass, status artifacts are redacted, and the final diff contains only this feature’s files plus explicitly required generated artifacts.
