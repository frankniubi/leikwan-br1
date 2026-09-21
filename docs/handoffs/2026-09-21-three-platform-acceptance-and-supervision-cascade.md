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

## 4. Windows UDP —— 归因到宿主线路容量，不是产品缺陷

先用目标端的**可查询计数器**（向 UDP 62101 发 `LKSTAT` 拿 JSON 快照）把丢包按路径段拆开。
丢包全在**去程**（客户端→目标），不是回程，也不是客户端自己的接收缓冲：

| 场景 | 客户端观察丢包 | **前向路径**丢包 | 返回路径丢包 |
|---|---|---|---|
| UDP 20 Mbps 单跑（探测器修好后复测） | **0.0000%** | 0.0000% | 0.01% |
| TCP 20 + UDP 20 并发，600 s | 13.31% | 13.24% | 0.07% |

然后测**容量边界**：同一台 Windows 测试机、同一个 harness，只改负载。

| 提供的负载 | TCP 实测 / CV / 空闲窗口 | **UDP 丢包** |
|---|---|---|
| TCP 20 单独 | 20.002 Mbps / 0.63% / 0-60 | — |
| UDP 20 单独 | — | **0.0000%**（目标端收到 124,612 / 发出 124,612） |
| TCP 10 + UDP 10 = 20 Mbps | 9.994 / 0.62% / 0-120 | **0.0000%** |
| TCP 20 + UDP 5 = 25 Mbps | 19.999 / 0.45% / 0-120 | **0.0000%** |
| TCP 15 + UDP 15 = 30 Mbps | 15.000 / 0.45% / 0-120 | **0.0032%** |
| TCP 20 + UDP 20 = 40 Mbps | 19.9-20.7 / 3.6-4.1% / 0-120 | **0.0008% ~ 13.50%**（多次运行散布极大） |

两端宿主的**裸互联网**（同一个 Cloudflare 下载，同时段）和**隧道内 TCP 上限**（同 harness 不限速）：

| 宿主 | 原始下载 | 隧道内 TCP 上限 |
|---|---|---|
| Windows `172.21.255.4` | 50 MB / 26.23 s = **15.25 Mbps** | **31.4 Mbps** |
| Linux `172.21.255.189` | 50 MB / 4.98 s = **80.4 Mbps** | **151.9 Mbps** |

**判定：这台 Windows 测试机的线路大约只能承载 30 Mbps 量级，验收要求的 20+20=40 Mbps 超出了它。**
UDP 没有拥塞控制，超出的部分由 UDP 承担；TCP 有拥塞控制，所以 TCP 始终满 20 Mbps、零中断。
这一条解释了全部观测：单独跑任何一个都干净；合计 ≤30 Mbps 时 UDP 丢包 0.0000%-0.0032%、TCP 零中断；
合计 40 Mbps 时 UDP 丢包在不同运行间从 0.0008% 跳到 13.50%——这是"贴着容量上限"的特征，不是一个
固定比例的路径缺陷。

**在这台宿主上，产品与线路容量一致：凡是线路扛得住的速率，UDP 零丢包、TCP 零中断。**
要把 40 Mbps 跑成零丢包需要一台有余量的测试机——Linux 和 macOS 两台就是，它们在同一测试下 UDP 都是 0.0%。

仍然记录两条未修的观察：

1. Windows 该 forward 的 UDP arm 出现过 `rtt_ms=1605`、`2621` 的极端读数，`candidate_feedback`
   里连续出现 `outcome=failure, reason=udp_arm_failed`（07:41:29、07:42:48、07:43:38、07:43:57），
   客户端仍继续使用同一候选。**候选质量门限/轮换**目前不生效。
2. relay 内部无法插桩，"客户端→relay"与"relay→sidecar"没有再拆开。可以确定的是丢失不发生在 Linux
   使用的同一段路径上，也不在共享的 sidecar/target 上：sidecar 宿主 `/proc/net/snmp` 的
   `SndbufErrors`、`RcvbufErrors` 全程为零，它发出的包与目标收到的包一致。

## 5. 本轮未能关闭的项（明确列出，不掩盖）

1. **Windows 测试机线路承载不了 40 Mbps** —— 验收要求的 20+20 超出该宿主上限（见第 4 节）。
   产品在 ≤30 Mbps 合计负载下 UDP 零丢包、TCP 零中断；40 Mbps 下的 UDP 丢包随线路余量在 0.0008%-13.5% 之间跳。
   **要拿到 40 Mbps 的零丢包结论，需要换一台有余量的 Windows 测试机。**
2. **候选质量门限缺失** —— 2.6 s RTT + 连续 `udp_arm_failed` 的候选不会被淘汰或轮换。
3. **控制面不可达时的整轮路由重准备** —— 这次是旧探测器把它放大成雪崩；探测器修好后 600 秒跑通，
   但"面板不可达就整轮重准备"本身仍在，且 `RequestUltraRecovery` 没有走
   `RecoveryTriggerThrottle` 那条节流。建议单独处理。
4. **全方向 E2E 矩阵** —— 11 个方向只覆盖 1 个，逐方向阻塞原因见
   `2026-09-21-all-directions-coverage-and-blocked.md`。
5. **本地仓库 12.59 GB**（master 的 350 次提交里有大二进制，都是可达历史，不重写历史就删不掉）。
   本轮做的是安全压缩：`git prune --expire=now` + 一次 `git gc`，把 11.61 GiB 松散对象收进单个 12.57 GiB
   的 pack，`.git` 13.68 → 12.59 GB，HEAD 与工作树未变。已推送的 `lk-clean/repo.git` 镜像只有 53.69 MiB。
   另外：注册的 worktree 有 51 个、合计 173.51 GB（`.worktrees` 35.6 GB、`.tmp` 32.3 GB、
   `.build` 14.7 GB、`artifacts` 9.4 GB 等），其中约 39 GB 是 `dirty=0` 的陈旧会话 worktree。
   **我没有删**——它们是可重建的，但里面有被 gitignore 的文件，删除与否由你决定。
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
