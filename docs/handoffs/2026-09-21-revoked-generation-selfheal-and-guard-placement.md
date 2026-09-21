# 2026-09-21 — Ultra 拒绝代际的自愈闭环（以及我上一轮修复放错了路径）

本轮目标：验证"策略代际被拒后 agent 能自愈"。验证过程推翻了我自己上一轮的两个结论，
并找到了把生产整个打下去的那条真实因果链。

## 1. 真实因果链：面板会永远重新签发已被吊销的代际

**这是 `2026-09-21` 那次全量故障的根因，不是计划（plan）变更。**

两条独立代码路径对同一个 `policy_generation` 的判断不一致：

| 路径 | 位置 | 是否看吊销 |
|---|---|---|
| 配置下发（agent 取 policy） | `LeigodTokenBroker::grantsForClientForwards()` | **不看** `isGenerationRevoked` |
| 租约签发（agent 换 lease） | `UltraLeaseService::issueLease():152` | **看**，命中即 `policy_generation_invalid` |

而 `policy_generation` 是**请求输入的纯函数**（`LeigodTokenBroker.php:492-512`：forward/state/target/transport/rate/quota/expiry/materialFingerprint/reputationFingerprint）。
所以：某代际一旦被吊销，面板下一次下发**仍会算出同一个值并交给客户端**，
客户端拿它换 lease 必然 409，再拉一次配置又拿到同一个死值——**闭环，永不恢复**。

生产上唯一的解开方式是我手工 `revoked_at = NULL`。

触发它的现成操作路径是 `ProvisioningService::reprovisionUltraLeasePlane()`（`:5329`）：
改 target 时调 `revokeForwardLeases($forwardId)`，它按 `forward_id` 吊销**所有**代际行。
把 target 改回原值，算出的代际就等于被吊销的那个 → 该 forward **永久死亡**，
面板还一直显示 enabled。**这是两步骤就能触发的生产陷阱。**

### 修法：把吊销纪元折进代际（零半径）

`UltraMaterialService::revocationIndex()` 每次下发批量查一次
（`epochs` = 该 forward 已吊销行的 `MAX(id)`；`revoked` = 已被吊销的 `forward:generation` 集合），
`LeigodTokenBroker::policyGeneration()` 只在**算出来的值确实已被吊销时**才改用
`hash(原输入 + revocation-epoch:<epoch>)`：

- 健康 forward（当前代际未被吊销）→ 哈希**逐字节不变**，部署不会让任何在线客户端换代际；
- 已被吊销 → 立刻换成租约平面会接受的代际，客户端下一次下发即自愈；
- 纪元单调递增（吊销行的 `MAX(id)`），再次吊销必然得到新值，**不可能回到旧值**。

**部署安全性的实测依据**（不是推断）：131 个在线 (forward, device) 组合中，
当前代际被吊销的有 50 个，但**这 50 个的租约全部已过期**（`STALE_UNEXPIRED = 0`），
所以部署时这条分支对任何在serving的客户端都不会触发。
部署后 Linux 侧渲染的 7 个 `policy_generation` 与部署前**逐字节相同**。

## 2. 我上一轮那个 guard 放错了路径（`17c42b23` 没能修好它想修的东西）

`ConfigPollingService` 有**两条**换租约的路径，各自有自己的 `catch`：

| 方法 | 行 | 何时走 |
|---|---|---|
| `ReconcileForwardAsync` | 348–483 | 配置拉取（全量 pull）——**`RequestControlPlaneRecovery`/`TriggerImmediateSync` 走这条** |
| `RefreshUltraForwardAsync` | 535–658 | 长轮询返回 304 时的刷新 |

上一轮我把"记住被拒代际"只写进了 `RefreshUltraForwardAsync`。
而故障回路是：409 → `RequestControlPlaneRecovery` → 立即全量 pull → `RestoreLastKnownUltraPolicies()`
把死代际塞回来 → `ReconcileForwardAsync` 里的 409 **从不记录** → 永远重试。
**所以那个提交没有打断它声称要打断的回路。**

发现方式：补上上一轮欠的回归测试。测试在旧构建上**失败**（`Assert.Null` 失败，
说明死代际被复用），修复后通过。定位过程用了 3 个探针点（SYNC/PULL/ACCEPT/DISPATCH/RECON），
证明全量 pull 确实派发到 `ReconcileForwardAsync` 而该处没有记录。

修法：抽出 `RecordRefusedPolicyGeneration(forward, exception)`，**两条路径都调用**。

## 3. 计划（plan）变更从来不是触发条件——已实测否定

`service_plan_forward_combinations` 在整个 `master/src` 里**只有 `ProvisioningService` 引用**
（8 处，都是"允许创建哪些 forward"），租约/下发路径完全不读它。
唯一进入代际计算的 plan 字段是 `feature_flags_json`（只影响 native outbound 的 `native_unrestricted_allowed`，且不进哈希）。

实测：新建 plan 11 = plan 9 **去掉 combo 51**（180/201/202 用的就是 51），把 service30 移过去，
**3 分钟内三平台 180/201/202 全部照常监听、照常 serving、零拒绝**，随后移回 plan 9、停用 plan 11。
结论：plan 变更对 Ultra 数据面没有控制点。之前的"plan 变更导致 wedge"叙述是错的，已作废。

## 4. 四方向 `no_healthy_candidate` 的真因：forward 被停用后素材不再采集

`UltraMaterialService::requiredLineIds()` 只列出**当前有 enabled forward 需要**的线路。
素材过期后，源端不会再去采。相关性 11/11：

| combo | enabled forward 数 | 素材 |
|---|---|---|
| 46,47,48,49,50,53,56 | 0 | **EXPIRED** |
| 51,52,54,55,57 | >0 | **FRESH** |

实测时延：启用 forward 211/212 后 `requiredLineIds` 立刻包含线路 818，
素材在 **90 秒**内变为有效。**不需要动源端主机**——所以之前"要驱动 ultra source 重新采集"的判断也是错的，
缺的只是把 forward 启用起来。

## 5. 交付与部署教训

- 只替换 `LeikwanBridge.Core.dll` 时必须**保证程序集版本与被替换的一致**：
  上一轮部署的 Core 是 `1.0.45.0`，而 `dotnet build -c Release` 默认产出 `1.0.0.0`，
  宿主按 `deps.json` 找 `1.0.45.0` → `FileNotFoundException` → Windows 服务 1053 + APPCRASH、macOS 进程起不来。
  正确做法：`-p:AssemblyVersion=1.0.45.0 -p:FileVersion=1.0.45.0`。
- 本轮部署：面板 `LeigodTokenBroker.php`+`UltraMaterialService.php`
  （备份 `/root/lk-panel-backup-20260921-121749/`），四 pool reload；
  Windows/macOS agent 换 `LeikwanBridge.Core.dll` = `33ed2911cb79`（asmver 1.0.45.0）。

## 6. 逐关证据

**关 1：吊销必须 fail-closed，且必须能自愈（forward 201，三平台同时）**

```
LIVE_GENS_BEFORE ["9520cd4a2e56"]
REVOKE 9520cd4a2e56 {"ok":true,"revoked":104}
FAIL_CLOSED 9520cd4a2e56 revoked=yes
T+10s..T+30s  live=[]                                        <- 全部被拒，符合 fail-closed
T+40s  live=["03476c:6864fb777dcf"]                          <- 自愈开始，无需重启
T+50s  live=["03476c:6864fb777dcf","6a0cff:6864fb777dcf"]
T+70s  live=[... "bbda7965fe2e" ...]
T+90s..T+270s  5 台设备全部 = bbda7965fe2e，稳定 180 秒
```

同一时段三平台**零中断**：Linux `60501=1 202=1 procs=7`、macOS `60501=1 procs=6`、
Windows `60501=1 procs=6` 全程不变；
Linux agent 只报**一次**拒绝即 `preserving last known-good runtime`，runtime `state=ready`，
随后 `forward 201 independent Ultra lease renewal delivered`。**没有 409 风暴。**

**关 2：部署不扰动在线代际** — `STALE_UNEXPIRED=0`（131 组合中 50 个当前代际被吊销但全部已过期）；
部署前后 Linux 渲染代际逐字节相同。

**关 3：回归测试会失败于旧行为** — `ultra_revoked_generation_selfheal_test.php`
（把 bump 关掉 → `the revoked generation was handed out again`）；
`SyncOnceAsync_NeverReusesAPolicyGenerationThePanelRefused` 在 `17c42b23` 构建上失败、修复后通过。

**关 4：plan 变更无影响** — 见第 3 节。

**关 5：素材自愈** — 启用 forward 后 90 秒素材有效。见第 4 节。


## 7. 矩阵的真正阻塞点：Windows agent 把"租约投递瞬时失败"当成"启动失败"

启用 11 个方向的 22 个 forward 后，macOS/Windows 的 600 秒验收全面失败：

- Windows 日志 30 分钟内 **486 次整轮 `preparing_routes:0/N`**、**1196 次 `stopping forward`**、39 次 `heartbeat_failed`；
- macOS 那一轮 **598/600 个窗口是空的**，UDP 100% 丢包，TCP broken pipe；
- Linux 那一轮出现 `connection refused`（本地 60531 的 UDP socket 被关掉过）。

### 单一变量定位

```
21:25:09 [INF] ultra runtime process started for forward 221
21:25:09 [INF] ultra lease delivery begin for forward 221
21:25:10 [INF] ultra lease delivery result=false for forward 221     <- 1 秒后即判失败
21:25:32 [INF] ultra runtime process started for forward 221          <- 杀掉重来
21:25:58 ...                                                          <- 每 ~20 秒一轮
```

**Windows 的 `WindowsUltraRuntimeHost.Start` 在 spawn 运行时进程之后，立刻投递租约，没有等待运行时把控制端点建起来。**
且投递只尝试**一次**，返回 false 就 `Stop()` 抛 `ultra_runtime_start_failed`。
而 macOS 的 `MacUltraRuntimeHost` 在投递前有 `WaitForSocket(socketPath, 2s)`——**这是又一处平台分歧**。

于是在多 forward 同时准备（运行时启动变慢）时，瞬时"还没准备好"被当成永久失败，
变成"启动→投递失败→杀掉→下轮再启动"的风暴，并且**同一轮 sync 会把健康的 forward 一起停掉重来**，
所以连没问题的 Linux/macOS 通路也被拖下水。

### 修法

`WindowsUltraRuntimeHost.Start` 的投递改为**有界重试**（默认 20 秒窗口 / 250 ms 间隔，
两个参数可注入以便测试）；只有整个窗口都用完才判定启动失败。
`Refresh()`（运行时已在跑的热续期）保持单次尝试不变。

### 逐关证据

回归测试（`Start_RetriesLeaseDeliveryUntilTheRuntimeAcceptsIt`、
`Start_GivesUpAfterTheDeliveryWindowAndStopsTheRuntime`）在关掉重试后**两个都失败**，打开后 8/8 通过。

部署 `LeikwanBridge.Core.dll` = `2ec6adb07f6f`（asmver 1.0.45.0）到 Windows 与 macOS 后，
同样条件（方向 46，TCP+UDP 各 20 Mbps，120 秒）：

| 平台 | TCP | 窗口 | idle | interruptions | UDP 丢包 | error_classes |
|---|---|---|---|---|---|---|
| Windows | 20.003 Mbps | 120/120 | **0** | **0** | **0.0004%** | `{}` |
| macOS | 20.002 Mbps | 120/120 | **0** | **0** | **0%** | `{}` |

修前 macOS 是 598/600 空窗口，修后 0。**这是 ③ 从"完全跑不动"到"可跑"的转折点。**

### 方向 46 的 600 秒验收（20 Mbps TCP+UDP，顺序执行）

顺序执行（不并发）是必须的：三台机器同时打同一个方向时，
一台 sidecar 要同时吃 3×20 Mbps UDP + 3×20 Mbps TCP，会互相挤掉——
那测的是 3 倍负载，不是验收口径。

| 平台 | TCP | 窗口 | idle | interruptions | UDP 丢包 | error_classes |
|---|---|---|---|---|---|---|
| Linux | 20.0 Mbps | 600/600 | **0** | **0** | **0%** (1242870/1242870) | `{}` |
| macOS | 见下 | | | | | |
| Windows | 见下 | | | | | |

