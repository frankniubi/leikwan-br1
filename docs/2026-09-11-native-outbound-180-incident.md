# Native Outbound Forward 180 断线问题与解决方案

> **状态（2026-09-11）**：根因已确认，native lifecycle 防线已在工作区实现并通过本地回归；尚未部署、重启或执行真实 native relay 流量 canary。Ultra candidate quarantine/isolation 不计为 forward 180 的修复，生产配置和服务未因本文改变。

## 1. 事件范围

- **时间**：2026-09-10 23:47–23:48（本机日志时间）
- **对象**：forward `180`
- **类型**：`native outbound`
- **现象**：客户端反复报告运行时启动失败，看起来像断线；普通 forward `170` 同时出现一次上游 provider 连接超时。

## 2. 结论摘要

这次不是本机网卡、Wi-Fi、DHCP 或系统网络链路断开。核心问题是：

> 代码没有在自动 forward reconciliation/runtime 生命周期中对 `native outbound` 做特殊处理，把 forward 180 错误地当成普通 Ultra direct runtime 启动了。

因此，panel 下发的 `listen_port` 被错误地当成本地监听端口使用。180 的 `54544` 属于 panel/native 配置值，不应由普通 Ultra runtime 监听，也不应要求客户端开放该端口。

## 3. 已确认的问题

### P1. 自动 reconciliation 忽略 native outbound 标记（根因）

`ConfigPollingService.ReconcileForwardAsync()` 和相关刷新逻辑只按：

```csharp
effectiveForward.BackendMode == "ultra"
```

判断是否进入普通 Ultra 生命周期，没有先判断 `NativeOutboundEnabled`。

结果是 native forward 180 仍然执行：

1. 普通 Ultra lease resolution；
2. `ForwardRuntimeManager.ApplyForward()`；
3. `WindowsUltraRuntimeHost.Start()`；
4. 本地监听端口预检和监听探测。

### P2. 普通 Ultra runtime 错误使用 panel 的 `listen_port` 和 `target`

`WindowsUltraRuntimeHost.Start()` 直接将：

```text
forward.ListenPort -> listen_port
forward.Target     -> target
```

本次实际生成了类似：

```text
listen 127.0.0.1:54544
target 127.0.0.1:54544
```

这等价于让 native carrier 被当成本地 direct forward，既错误又会形成自指向配置。

### P3. native 字段在 effective config clone 时不完整传播

`EffectiveForwardConfigBuilder` 当前复制了大部分 forward 字段，但遗漏：

```text
NativeOutboundEnabled
NativeDirection
```

这会导致后续即使在 runtime 层补充判断，也可能拿不到可靠的 native 类型信息。

### P4. 两套生命周期没有清晰隔离

正确的 native 路径是：

```text
WindowsProxyConnectionService.StartNativeAsync()
  -> NativeOutboundRelayHost.StartCandidatesAsync()
  -> private loopback relay
  -> sing-box proxy/TUN
```

但自动配置同步仍可能同时启动：

```text
ConfigPollingService
  -> ForwardRuntimeManager
  -> WindowsUltraRuntimeHost
  -> ordinary Ultra direct runtime
```

同一 forward 被两套生命周期处理，导致 native relay 与普通 runtime 互相干扰。

### P5. 变更前缺少 native/ordinary Ultra 的回归测试和防御性拒绝

变更前没有测试明确保证：

- native forward 不调用 `IUltraRuntimeHost.Start()`；
- native forward 不执行 panel `listen_port` 的端口预检；
- native forward 不进入普通 Ultra lease refresh；
- ordinary Ultra forward 的现有行为不受影响。

### P6. 本轮修复结果

以下修复已落地在工作区：

- `ConfigPollingService` 识别 `NativeOutboundEnabled` 后跳过 ordinary Ultra lease resolution、apply 和 refresh；若历史 ordinary 状态残留，会先清理该状态。
- `ForwardRuntimeManager` 对 native outbound 增加第二道防线，拒绝 ordinary Ultra start、refresh、restart、listener readiness 和 port ownership 判断。
- `EffectiveForwardConfigBuilder`、contract signature 和 bond-runtime endpoint clone 保留 `ForwardCombinationDirection`、`NativeOutboundEnabled`、`NativeDirection`，避免同步/切换时丢失 native 身份。
- `UltraProbeContract` 统一普通 auto-select 的探测目标和窗口；Windows/macOS host 与 Android ordinary runner 使用同一组参数。
- native relay 仍由专用 `NativeOutboundRelayHost`/`WindowsProxyConnectionService` 管理，不受上述 ordinary guard 影响。

## 4. 现场证据

| 时间 | 证据 | 判断 |
|---|---|---|
| 23:46:14 | forward 180 lease refresh 返回 `409 lease_scope_invalid` | 控制面/lease 问题，触发后续 full pull |
| 23:46:22 | 日志写入 `forward 180 [Ultra direct runtime]` | 已确认走错 runtime 分类 |
| 23:46:55 | `listen tcp 127.0.0.1:54544: ... forbidden by its access permissions`，exit code 1 | 错误监听路径失败 |
| 23:47:48–23:48:43 | 180 重试并重复相同 bind 失败 | 客户端 forward runtime 持续失败 |
| 23:47:51、23:48:44 | heartbeat 成功 | 本机到控制面的网络仍然可用 |
| 同一窗口 | 未发现 NetworkProfile/WLAN/DHCP 链路事件 | 没有本机物理/系统网络断线证据 |
| 23:47:33 | forward 170 provider `1.117.179.215:5438` connect timeout | 独立的上游候选超时，不是 180 bind 根因 |

Windows 当前 TCP excluded range 包含 `54457–54556`，因此 `54544` 的 bind 失败是可复现的次级症状；不能通过开放该端口来修复 native outbound 架构问题。

## 5. 解决方案

### S1. 建立统一 native 判定

增加统一谓词，例如：

```csharp
bool IsNativeOutbound(ForwardConfig forward) =>
    forward.NativeOutboundEnabled &&
    string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase);
```

不要通过 `target`、`listen_port` 或端口范围推断 native 类型，必须使用 panel 的显式 native 标记。

### S2. 从普通 forward reconciliation 中排除 native forward

在 `ConfigPollingService`：

- native forward 仍保留在可选 carrier/forward 列表中；
- 但跳过普通 Ultra lease resolution、`ApplyForward` 和普通 lease refresh；
- native 状态切换时，先停止并清理已存在的 ordinary Ultra runtime/applied state；
- ordinary Ultra forward 保持原有流程不变。

### S3. 在 runtime manager 增加第二道防线

在 `ForwardRuntimeManager` 对以下入口拒绝 native forward：

- `ApplyUltraForward()`；
- `RefreshUltraLease()`；
- `RestartForward()`；
- `IsForwardReady()`；
- `HasOwnedProxyListener()`。

拒绝时应记录明确日志，例如：

```text
skip ordinary Ultra runtime for native outbound forward 180
```

不得执行 `listen_port` preflight 或 listener probe。

### S4. 完整传播 native 字段

在 `EffectiveForwardConfigBuilder` 补齐：

```csharp
NativeOutboundEnabled = source.NativeOutboundEnabled,
NativeDirection = source.NativeDirection,
```

并为 clone/contract signature 增加对应测试，避免 native 状态在同步过程中丢失。

### S5. native 只走专用 relay 路径

Windows native outbound 应保持以下行为：

1. 由 `StartNativeAsync()` 分配 app-private ephemeral loopback 端口；
2. 由 `NativeOutboundRelayHost` 启动 `native_socks5=true` relay；
3. sing-box/TUN/HTTP proxy 只连接该 relay；
4. 不使用 panel 的 `listen_port` 作为本地监听端口；
5. 不要求公网端口监听、开放或 NAT 映射。

### S6. 增加回归测试

至少覆盖：

```text
native forward -> no IUltraRuntimeHost.Start()
native forward -> no ordinary lease refresh
native forward -> no panel listen_port preflight
native forward -> native relay receives ephemeral loopback port
ordinary Ultra forward -> existing start/refresh behavior unchanged
```

另外增加 native -> ordinary、ordinary -> native 的状态切换清理测试，确认不会残留旧 `ultra-runtime` 进程。

## 6. 验收标准

- 配置同步后，日志中不再出现 `forward 180 [Ultra direct runtime]`；
- 不再尝试监听 `127.0.0.1:54544` 或任何 panel/native 配置端口；
- forward 180 的 runtime state 不出现 ordinary `ultra-runtime` 进程；
- native relay 使用随机 loopback 端口，并且 `native_socks5=true`；
- HTTP proxy/TUN 的流量可以通过 native relay；
- 普通 Ultra forward（例如 170/171）仍可正常启动和刷新；
- 不需要新增公网监听端口或修改 Windows excluded port range。

## 7. 当前状态

- 本文档已完成事件范围、证据、根因、修复边界和验收条件记录。
- 结论等级为“控制面/客户端 runtime 分类根因已确认；native 数据面恢复尚未验收”。`process_stderr`、heartbeat 成功和普通 Ultra provider timeout 只能作为旁证，不能替代 native relay 的真实流量验收。
- 当前工作区包含针对 forward 180 的 native lifecycle 修复，但尚未形成部署提交；没有重启服务、开放 `54544`、修改 Windows excluded range 或改变线上配置。
- 本地代码测试已覆盖 native apply/refresh/polling guard 和字段传播；仍需补充/执行 native -> ordinary、ordinary -> native 的完整切换 fixture，以及真实 relay 承载 HTTP proxy/TUN 的 canary。
- 后续必须按“代码测试 -> 本机 canary -> 生产灰度”顺序执行。任何仅通过端口 bind、`process_stderr` 或 `/health` 的结果都不算修复完成。

## 8. 修复与验收清单

- [x] `ConfigPollingService` 对 `native_outbound=true` 跳过普通 Ultra lease/runtime reconciliation。
- [x] `ForwardRuntimeManager` 对 native forward 拒绝 ordinary Ultra start/refresh/preflight，并保留 native relay 生命周期。
- [x] `EffectiveForwardConfigBuilder` 保留 `NativeOutboundEnabled` 与 `NativeDirection`；contract signature 同步纳入 native 状态。
- [x] forward 180 等价 fixture 已证明无 `IUltraRuntimeHost.Start()`、无 panel `listen_port` 探测；native relay 的 ephemeral loopback 由既有 `NativeOutboundRelayHost` contract tests 覆盖。
- [ ] 本机 canary 证明 native relay 实际承载 HTTP proxy/TUN 流量，普通 Ultra forward 仍能启动；再记录重启和回滚证据。

### 本地验证记录

- Desktop Core native/config/runtime 定向：70/70 通过；macOS Ultra host：3/3 通过；Desktop solution build：0 warning、0 error。
- Android ordinary Ultra bootstrap/batch/control-loop 定向与全量 unit test：`BUILD SUCCESSFUL`。
- 生产环境未执行任何变更；因此 native 数据面恢复仍未验收。
