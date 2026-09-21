# 会话入口（2026-09-21 第二轮）

上一轮入口是 `2026-09-21-SESSION-FINAL-HANDOFF.md`（内容仍有效，第 6 节 "STILL OPEN" 以本文为准更新）。
本文是当前入口。已推送：`integration/ultra-enterprise-ha`。

## 验收状态

用户指标：**"TCP+UDP 测试 20Mbps 10min，无中断无断流算成功"**。

| 平台 | TCP 20 Mbps × 600 s | UDP 20 Mbps |
|---|---|---|
| Linux | 19.999 Mbps，600/600 窗口，**idle 0**，CV 0.21% | **0.0% 丢包** |
| macOS | 19.999 Mbps，600/600 窗口，**idle 0**，CV 0.37% | **0.0% 丢包** |
| Windows | 19.589 Mbps，600/600 窗口，**idle 0，interruptions 0**，delivered 1.0，`error_classes {}` | 单跑 **0.0000%**；与 TCP 并发 40 Mbps 时 0.0008%–13.5% 抖动 |

**TCP 三平台全部达标。** UDP 在 Linux/macOS 达标。Windows 的并发丢包已归因到**测试机线路容量**
（该机裸下载 15.25 Mbps、隧道内 TCP 上限 31.4 Mbps；合计负载 ≤30 Mbps 时 UDP 丢包 0.0000%–0.0032%）。
详见 `2026-09-21-three-platform-acceptance-and-supervision-cascade.md` 第 4 节。

## 本轮修掉的缺陷（都有可证伪的验证）

### 1. agent 的监听探测会扰动数据面（已部署 Windows + macOS）

`NetworkProbe.IsPortListening` 用**真的 TCP connect** 判断端口是否在监听。Ultra runtime 会把任何被接受
的连接当成真实客户：拨号上游、失败后记 `io_error` 并累加 `ha_failures`。于是健康检查在测量自己。
Windows 测试机 forward 201（目标只开 UDP）因此每 **20.02 s** 稳定 +1 条假故障，累计 166+。

改成读内核监听表。验证：Windows 201 与 macOS 六个 forward 的 `ha_failures` / `ha_events` 键
**直接消失**（客户端只在有失败时才写这两个键）。周期性归因用单变量实验证明：interval=20 时间隔恰好
20.02 s，改成 47 后周期性消失。

**这条同时解开了 Windows 600 秒跑不完的死结。**

### 2. panel 改 target 后 forward 静默失去 lease（未部署）

Ultra 是 lease 平面，且 lease scope digest 把 target 折进去（`LeigodTokenBroker.php:492-512`、
`UltraLeaseService.php:1066`），改 target 后所有旧 lease 被 `:146-147/:189-190/:220-224` 拒绝，
`ClientAgentController.php:601-606` 直接回 409 且不带任何 lease。而写路径
`ProvisioningService.php:5163-5226` 只调用了对 Ultra 是 no-op 的 reconciler（`:8740`）。

修：enabled 的 Ultra forward 改 target_host/target_port/transport_mode 时在同一个事务里重新签发，或
抛 `ultra_reprovision_unavailable` 回滚保留原 target；并把 `awaiting_policy`、任何 runtime 错误、
"enabled 但没有有效 lease" 都算成健康失败。单测在 `master/tests/ultra_target_change_reprovision_test.php`
与 `ultra_enabled_without_lease_health_test.php`。

### 3. 控制面抖动会把数据面掐断（引信已拆，机制仍在）

Windows 首轮 600 秒：4 条 TCP 流在同一秒被一起杀掉，之后 35 秒全空。日志显示
`heartbeat_failed:HttpRequestException`（面板不可达）→ 每 ~10 秒一整轮 `preparing_routes:0/7…7/7`
→ `forward 159: local port 32469 not listening` → `stopped all processes for forward 159`。

因果链是"健康检查判定失败 → 杀掉被检查对象"。负载高时旧的 connect 探测会因监听队列占满而失败，
正是这条链的引信。探测器改掉之后，同一台机器同一条件跑出 **600/600 窗口、idle 0、0 错误**。

**仍未修**：面板不可达时整轮重准备路由这件事本身还在。另外 `ConfigPollingService.RequestUltraRecovery`
没有走 `RecoveryTriggerThrottle` 那条节流（`RequestControlPlaneRecovery` 走了），而该节流本身只有
10 秒——日志里 `preparing_routes` 的 10 秒周期与它一致。建议下一轮单独处理。

## 本轮新增的能力（代码在仓库，未部署）

- **sidecar binding 热加载**：`tools/uot-sidecar/reload.go` 等。SIGHUP（unix）+ 本地 marker 文件。
  校验通过才发布，失败保留旧快照；每个 session 捕获自己的准入快照，删除/轮换不影响存量会话。
  7 个新测试；在 70.36.96.183 上用 scratch 进程做过完整真机验证（存量会话跨三次 reload 26/26 ping
  无间隙；生产 unit 的 MainPID/NRestarts/配置 md5 全程未变）。**五台 sidecar 都还没部署。**
- **验收 harness**：`tools/acceptance-harness/`（Go，Windows 测试机上没有 python 也没有 go）。
  含两条让测量可信的关键约定：零字节 1 秒窗口 = 一次中断，窗口序列补齐到请求时长；socket 写带超时。

## 仍然未关闭（不掩盖）

1. **Windows 测试机线路承载不了 40 Mbps** —— 见上。要拿到 40 Mbps 零丢包的结论需要换一台有余量的
   Windows 测试机；产品在 ≤30 Mbps 合计负载下 UDP 零丢包、TCP 零中断。
2. **候选质量门限缺失** —— 2.6 s RTT + 连续 `udp_arm_failed` 的候选不会被淘汰或轮换。
3. **控制面不可达时的整轮路由重准备**（见上）。
4. **全方向 E2E 矩阵** —— 11 个方向只覆盖 1 个；逐方向阻塞原因见
   `2026-09-21-all-directions-coverage-and-blockers.md`。
5. **本地仓库 12.59 GB**（master 的 350 次提交里有大二进制，都是可达历史）。
   本轮做了安全压缩：`git prune --expire=now` + 一次 `git gc`，`.git` 13.68 → 12.59 GB，
   HEAD 与工作树未变。另有 51 个注册 worktree、合计 173.51 GB，约 39 GB 是 `dirty=0` 的陈旧会话
   worktree——**没有删**，由你决定。
6. `400 次 headers_eof` 已归因：leased relay 自身约 1000 ms 后 RST 自己的流，**不是产品缺陷**；
   见 `2026-09-21-headers-eof-attribution.md`。

## 机器状态（离开时的样子）

| 主机 | 状态 |
|---|---|
| Linux `172.21.255.189` | ultra-client `fbf6d977…`，forward 201/202 ready，agent NRestarts=0 |
| macOS `100.103.253.21` | .NET agent 已换成本轮构建（`LeikwanBridge.Core.dll` = `3e355e3ae366…`），六个 forward ready 且零 ha 失败；备份 `~/lk-backup/mac-agent-20260921T155355Z/` |
| Windows `172.21.255.4` | headless agent 已换成本轮构建（同 hash），服务 RUNNING，201/202 ready 且零 ha 失败；备份 `C:\Users\lwtest\lk-backup\*.pre-probefix`；两台都设了 `disable_auto_updates=true` |
| 五台 sidecar | 全部未部署新构建；`70.36.96.183` 的验收 pump PID 1427263，端口 62101(UDP)/62102(TCP)，生产 unit PID 1075611、NRestarts=0 |

**必须保留的纪律**：把 `Copy-Item` 从备份还原源码时会保留源文件的 LastWriteTime，NTFS 上会让
MSBuild 认为输出比输入新而**静默跳过重新编译**——本轮因此对着一个已被证伪的二进制测了好几轮。
改完源码后用 `dotnet build --no-incremental` 或 `dotnet test`（不带 `--no-build`）确认。
