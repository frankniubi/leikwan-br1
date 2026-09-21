# 三平台验收结果与 Windows 监督级联缺陷

日期：2026-09-21。判定依据：用户给出的验收指标 **"TCP+UDP 测试 20Mbps 10min，无中断无断流算成功"**。

## 1. 结论

| 平台 | TCP 20 Mbps × 600 s | UDP 20 Mbps | 判定 |
|---|---|---|---|
| Linux | **19.999 Mbps，600/600 窗口，idle 0，CV 0.21%** | 单平台 **0.0% 丢包**（369,560/369,560） | **PASS** |
| macOS | **19.999 Mbps，600/600 窗口，idle 0，CV 0.37%** | 单平台 0.0%（`tcp+udp` 复测后） | **PASS** |
| Windows | **19.589 Mbps，600/600 窗口，idle 0，interruptions 0，delivered 1.0，error_classes {}，CV 6.54%** | 单平台 6.24% 丢包；与 TCP 并发时 13.31% | **TCP PASS / UDP FAIL** |

Windows 是**新达标**的一台：在本轮之前它连一轮 600 秒都跑不完。达标的前提是先修掉下面第 2 节的缺陷。

## 2. Windows 600 秒曾经跑不完 —— 监督级联，不是数据面塌陷

第一轮 Windows 600 秒（16:00 前后）结果：

```
TCP aggregate=17.13 Mbps median=17.473 min=3.583 CV=20.94%
TCP windows=600 idle=35 interruptions=35 ended_early=True delivered=0.9417
error_classes = { 4 个 "wsasend: An existing connection was forcibly closed" }
```

四条 TCP 流**在同一秒一起被杀**，之后 35 秒全空。日志给出的机制是：

```
16:00:02 [WRN] forward 159: processes not running, restarting
16:00:02 [INF] health_issue:159:process_dead
16:00:02 [WRN] Ultra recovery queued for fresh policy and lease (forward=159, reason=process_dead)
16:00:02 [WRN] forward 170: processes not running, restarting
16:00:05 [INF] preparing_routes:0/7  ... 4/7
16:00:05 [INF] heartbeat_failed:HttpRequestException
16:00:06 [INF] starting process for forward 159/171/170 ...
```

更早（15:57 起）日志已经在每 ~10 秒重复一整轮 `preparing_routes:0/7 ... 7/7`，中间夹着：

```
15:57:29 [INF] heartbeat_failed:HttpRequestException          <- 面板不可达
15:57:30 [WRN] forward 159: local port 32469 not listening
15:57:34 [WRN] Ultra recovery queued ... (forward=159, reason=port_not_listening)
15:57:39 [WRN] forward 159: Ultra runtime role exited [ultra-runtime]
15:57:39 [INF] stopped all processes for forward 159
```

**因果链**：控制面（面板心跳）不可达 → agent 反复整轮重准备路由 → 健康检查把某个 runtime 的本地端口
判成 `not listening` → `RequestRestart(force: true)` 直接**停掉一个健康的 runtime** → 该 runtime
的端口真的没了 → 更多 health issue → 更多恢复 → 雪崩。**控制面抖动把数据面掐断了**，这是企业级高可用
里最不该发生的一类耦合。

而这个 `not listening` 判定，正是旧探测器的固有缺陷：它靠**真的去 TCP connect** 一个端口来判断
"是否在监听"。负载高时监听队列被占满，connect 失败，于是"探测失败 → 杀掉被探测对象"。

## 3. 修复与验证

`NetworkProbe.IsPortListening` 改为**读内核监听表**（`GetActiveTcpListeners()` / `GetActiveUdpListeners()`），
不再主动连接。它既不会扰动数据面（Ultra runtime 会把任何被接受的连接当成真实客户，拨号上游、失败后
记一条 `io_error` 并累加 `ha_failures`），也不会阻塞（旧的 `TcpClient.Connect` 没有超时）。

部署与验证：

| 项 | 修复前 | 修复后 |
|---|---|---|
| Windows 201 `ha_failures`（目标只开 UDP） | 每 **20.02 s** 稳定 +1，累计 166+ | 状态文件里 **`ha_failures` 键根本不存在**（客户端只在有失败时才写），持续 140 s+ 全零 |
| macOS 六个 forward | 201=28、202=15，且 20 s 周期 | **六个 forward 全部 `ha_failures` 缺失 / `ha_events` 缺失**，零失败 |
| Windows 600 s | 35 idle 窗口，4 条流被杀 | **idle 0，interruptions 0，600/600 窗口，delivered 1.0，error_classes {}** |

20 s 周期与控制面无关这一点，是用单变量实验证明的：`health_check_interval_seconds` 保持默认 20 时
`io_error` 间隔恰好 20.02 s；改成 47 之后周期性消失。客户端与 agent 里没有第二个 20 秒定时器。

已部署：Windows headless agent（`LeikwanBridge.Core.dll` = `3e355e3ae366…`）、macOS .NET agent
（同 hash；apphost 未换，只换 `Resources/dotnet` 下两个 DLL，并 `codesign --force --deep -s -` 重签）。
macOS 原文件备份在 `~/lk-backup/mac-agent-20260921T155355Z/`，Windows 原文件备份在
`C:\Users\lwtest\lk-backup\*.pre-probefix`。两台都设了 `disable_auto_updates=true`，避免修复被
自动更新静默回退。

## 4. Windows UDP 仍未达标 —— 归因

| 场景 | 客户端观察丢包 | **前向路径**丢包（客户端→目标） | 返回路径丢包 |
|---|---|---|---|
| UDP 20 Mbps 单跑，30 s | 6.24% | 6.23% | 0.01% |
| TCP 20 + UDP 20 并发，600 s | 13.31% | 13.24% | 0.07% |

"前向/返回"的拆分靠给目标端加了一个**可查询计数器**（向 UDP 62101 发 `LKSTAT` 得到 JSON 快照）。
丢包发生在去程，不是回程，也不是客户端自己的接收缓冲。

同一目标、同一 sidecar、同一 relay 池，Linux 主机在同一速率下是 **0.0%**。Windows 主机本身的外网
干净：到 1.1.1.1 RTT 80 ms、丢包 0%（与 Linux 主机的 80.7 ms 一致）。差异只出现在 Windows 客户端到
它所租用 relay 的那一段。

不可忽视的旁证：Windows 该 forward 的 UDP arm 曾出现 `rtt_ms=1605`、`2621` 的极端读数，并且
`candidate_feedback` 里连续出现 `outcome=failure, reason=udp_arm_failed`（07:41:29、07:42:48、
07:43:38、07:43:57），而客户端仍然继续使用同一个候选。**候选质量门限/轮换**目前不生效。

这里没有把原因归给具体某一段：relay 内部无法插桩，所以"客户端→relay"与"relay→sidecar"未能再拆分。
可以确定的是：**不在 Linux 的同一段路径上，也不在共享的 sidecar/target 上。**

## 5. 本轮未能关闭的项（明确列出，不掩盖）

1. **Windows UDP 前向丢包 6.2%（单跑）/ 13.2%（并发）** —— 已归因到 Windows→relay 那一段，未修复。
2. **候选质量门限缺失** —— 2.6 s RTT + 连续 `udp_arm_failed` 的候选不会被淘汰或轮换。
3. **控制面不可达时的整轮路由重准备** —— 这次是旧探测器把它放大成雪崩；探测器修好后 600 秒跑通，
   但"面板不可达就整轮重准备"本身仍在，且 `RequestUltraRecovery` 没有走
   `RecoveryTriggerThrottle` 那条节流。建议单独处理。
4. **全方向 E2E 矩阵** —— 11 个方向只覆盖 1 个，逐方向阻塞原因见
   `2026-09-21-all-directions-coverage-and-blocked.md`。
5. **本地仓库仍有 13.6 GB 历史**（master 的 350 次提交里有大二进制），已推送的
   `lk-clean/repo.git` 镜像只有 53.69 MiB。
6. `400 次 headers_eof` 已归因（leased relay 自身约 1000 ms 后 RST 自己的流，非产品缺陷），见
   `2026-09-21-headers-eof-attribution.md`。

## 6. 复现命令

三平台使用同一个验收 harness（Windows 上没有 python 也没有 go，所以用 Go 交叉编译成单个 exe 发过去）：

```powershell
# 交叉编译（在操作机上）
cd tmp/lkwintest
$env:CGO_ENABLED='0'; $env:GOOS='windows'; $env:GOARCH='amd64'; go build -o lkwintest.exe .
```

```cmd
:: Windows 测试机上（先确认 201/202 都是 ready、udp_rtt 稳定在两位数）
lkwintest.exe -seconds 600 -tcp-mbps 20 -udp-mbps 20 -write-timeout 15 -label windows-600s
```

TCP 口径：1 秒窗口累计字节，**零字节窗口 = 一次中断**，窗口序列补齐到请求时长（否则提前死掉的流会伪装成
`idle=0`）。写超时是必需的：转发停住时 socket 缓冲写满，`Write` 会永久阻塞，整轮证据就丢了。
UDP 口径：1200 字节原始载荷（`LKT1` + 序号），目标端逐包回 `LKT1ACK`；并发时用目标端计数器拆分
前向/返回丢包。
