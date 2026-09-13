# 三端控制面高可用与自愈 Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

Goal: 为 Windows、macOS、Linux 增加 Cloudflare 主站 / Aliyun 备用站的本地故障切换和连接自愈，同时保持现有 API 下发协议不变。

Architecture: Desktop 在 Core 层通过 endpoint supervisor 统一包裹 MasterApiClient 的请求，保存当前 endpoint 并区分可重试的传输失败与不可切换的业务响应；Linux 在 client-agent.py 中扩展 endpoint 状态和统一 HTTP 调用路径，安装器预置镜像并通过 systemd watchdog 处理卡死。已有 forward runtime 在控制面短暂故障时保持运行。

Tech Stack: C#/.NET 8, Avalonia/WPF desktop core, Python 3, systemd, xUnit, pytest, shell installer tests.

Spec: docs/superpowers/specs/2026-09-09-control-plane-ha-design.md

## Global Constraints

- Primary endpoint is https://v3.leikwanhost.com; secondary endpoint is https://api-leikwanbridge.nbwly.com.
- Do not modify existing panel API paths, request JSON fields, forward schema, lease schema, or response schema.
- Preserve existing dirty worktree files; only stage files belonging to this feature.
- Never retry a POST after an HTTP response has been received.
- Existing forward/runtime must not be stopped solely because both control-plane endpoints are temporarily unavailable.
- All implementation changes require failing tests first, then green verification.

## Task 1: Desktop endpoint supervisor and API transport

Files:
- Create: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ControlPlaneEndpointSupervisor.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs only if backward-compatible local fields are required
- Test: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/MasterApiClientDesktopLoginTests.cs
- Test: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ControlPlaneEndpointSupervisorTests.cs

Interfaces:
- ControlPlaneEndpointSupervisor(string primary, IReadOnlyList<string> fallback)
- Uri CurrentEndpoint { get; }
- Task<T> ExecuteAsync<T>(HttpMethod method, string relativePath, Func<Uri, HttpRequestMessage> requestFactory, Func<HttpResponseMessage, CancellationToken, Task<T>> responseReader, CancellationToken ct)
- void RecordSuccess(Uri endpoint)
- void RecordTransportFailure(Uri endpoint)
- bool IsBusinessResponse(HttpStatusCode statusCode)

Steps:
- [ ] Write tests proving timeout/502/503/504/530 switches endpoint, 401/403/409 does not, and successful primary probes allow recovery.
- [ ] Run focused xUnit tests and verify they fail because supervisor behavior is absent.
- [ ] Implement the supervisor with bounded exponential backoff, cooldown, and primary recovery probe.
- [ ] Refactor MasterApiClient request construction so paths and JSON payloads are unchanged while transport chooses the endpoint.
- [ ] Add request tests proving lease/heartbeat body and path remain byte-for-byte compatible.
- [ ] Run focused desktop tests and then Core test project.

## Task 2: Desktop lifecycle integration for Windows and macOS

Files:
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/DesktopClientController.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Mac/App.axaml.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Mac/ViewModels/MainWindowViewModel.cs
- Test: existing App/Core/Mac test suites

Steps:
- [ ] Add failing tests for startup failure retry, transient pull failure preserving runtime, and reconnect triggering a full pull.
- [ ] Run the focused tests and verify the expected failures.
- [ ] Implement one lifecycle reconnect loop shared by Windows/macOS, with cancellation on sign-out/disconnect.
- [ ] Keep UI state non-blocking and prevent duplicate reconnect loops.
- [ ] Run Windows/App/Core/Mac focused suites and desktop solution build.

## Task 3: Linux endpoint failover and installer defaults

Files:
- Modify: client-agent/client_agent.py
- Modify: artifacts/client-agent.py
- Modify: install/install-client-agent.sh
- Modify: client-agent/tests/test_ultra_renewal_schedule.py
- Create/modify: client-agent/tests/test_control_plane_failover.py
- Modify: installer shell tests covering generated config

Interfaces:
- Preserve control_plane_endpoints(config) and pull_client_configs(config, token) call compatibility.
- Add internal endpoint state persisted under existing state directory.
- All control-plane requests use the selected endpoint without changing payloads.

Steps:
- [ ] Write failing pytest tests for default generated mirror, endpoint switching, business-status handling, current endpoint reuse, and dual outage runtime preservation.
- [ ] Run the focused pytest file and verify failures.
- [ ] Implement endpoint state/backoff and route pull, lease, heartbeat, diagnostics, update and live-check calls through it.
- [ ] Add atomic watchdog heartbeat update in the agent loop and systemd WatchdogSec/notify integration without changing service name or topology.
- [ ] Mirror identical logic from client-agent/client_agent.py into artifacts/client-agent.py.
- [ ] Run all client-agent tests and installer tests.

## Task 4: Cross-platform regression and release verification

Files:
- Modify only tests/manifests if required by actual implementation.
- No API server source changes unless a test demonstrates an unavoidable compatibility defect.

Steps:
- [ ] Run desktop Core/App/Mac tests.
- [ ] Run Linux pytest suites and installer tests.
- [ ] Run desktop solution build and Python syntax compilation.
- [ ] Verify git diff contains no master API/controller/schema changes.
- [ ] Verify request paths and JSON payload snapshots remain unchanged.
- [ ] Review dirty worktree and stage only feature files.

