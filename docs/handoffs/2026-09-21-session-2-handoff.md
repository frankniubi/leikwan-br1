# 会话入口（2026-09-21 第二轮）

上一轮入口是 `2026-09-21-SESSION-FINAL-HANDOFF.md`（第 6 节 "STILL OPEN" 以本文为准更新）。
本文是当前入口。

## 目标

把 LeiKwan Ultra TCP/UoT 做到**企业级高可用**，闭环 2026-09-20 交接文档里的全部未完成项，
验收口径是用户给的 **"TCP+UDP 测试 20Mbps 10min，无中断无断流算成功"**。

## 验收状态

| 平台 | TCP 20 Mbps × 600 s | UDP 20 Mbps |
|---|---|---|
| Linux | 19.999 Mbps，600/600 窗口，**idle 0**，CV 0.21% | **0.0% 丢包** |
| macOS | 19.999 Mbps，600/600 窗口，**idle 0**，CV 0.37% | **0.0% 丢包** |
| Windows | 19.589 Mbps，600/600 窗口，**idle 0，interruptions 0**，delivered 1.0，`error_classes {}` | 单跑 **0.0000%**；与 TCP 合计 40 Mbps 时 0.0008%–13.5% 抖动 |

**TCP 三平台全部达标。** UDP 在 Linux/macOS 达标。Windows 的并发丢包已归因到**测试机线路容量**：
该机裸下载 15.25 Mbps、隧道内 TCP 上限 31.4 Mbps；合计负载 ≤30 Mbps 时 UDP 丢包 0.0000%–0.0032%、
TCP 零中断。详见 `2026-09-21-three-platform-acceptance-and-supervision-cascade.md` 第 4 节。

## 本轮修掉的缺陷（都已部署到生产）

### 1. agent 的监听探测会扰动数据面

`NetworkProbe.IsPortListening` 用**真的 TCP connect** 判断端口是否在监听。Ultra runtime 会把任何被接受
的连接当成真实客户：拨号上游、失败后记 `io_error` 并累加 `ha_failures`。于是健康检查在测量自己：
Windows forward 201（目标只开 UDP）每 **20.02 s** 稳定 +1 条假故障，累计 166+。

改成读内核监听表。验证：Windows 201 与 macOS 六个 forward 的 `ha_failures` / `ha_events` 键
**直接消失**（客户端只在有失败时才写这两个键）。周期性用单变量实验证明：interval=20 时间隔恰好
20.02 s，改成 47 后周期性消失。**这条同时解开了 Windows 600 秒跑不完的死结。**

已部署：Windows headless agent 与 macOS .NET agent（`LeikwanBridge.Core.dll` = `3e355e3ae366…`）。
备份：`C:\Users\lwtest\lk-backup\*.pre-probefix`、`~/lk-backup/mac-agent-20260921T155355Z/`。

### 2. 控制面抖动把数据面掐断（引信已拆）

首轮 Windows 600 秒：4 条 TCP 流在同一秒被杀，之后 35 秒全空。日志：
`heartbeat_failed:HttpRequestException`（面板不可达）→ 每 ~10 秒一整轮 `preparing_routes:0/7…7/7`
→ `forward 159: local port 32469 not listening` → `stopped all processes for forward 159`。
因果链是"健康检查判定失败 → 杀掉被检查对象"，而引信就是旧的 connect 探测在监听队列占满时误判。
探测器修好后，同机同条件跑出 **600/600 窗口、idle 0、0 错误**。

### 3. panel 改 target 后 forward 静默失去 lease —— **已部署到生产面板**

Ultra 是 lease 平面，lease scope digest 把 target 折进去（`LeigodTokenBroker.php:492-512`、
`UltraLeaseService.php:1066`），改 target 后旧 lease 全被 `:146-147/:189-190/:220-224` 拒绝，
`ClientAgentController.php:601-606` 回 409 且不带任何 lease；写路径
`ProvisioningService.php:5163-5226` 只调用了对 Ultra 是 no-op 的 reconciler（`:8740`）。

修：改 target_host/target_port/transport_mode 时在同一事务里重新签发，或抛
`ultra_reprovision_unavailable` 回滚保留原 target；`runtimeHealthVerdict()` 把 `awaiting_policy`、
任何 runtime 错误、"enabled 但没有有效 lease" 都算健康失败，在租户列表与新增的后台 Health 列渲染。

**部署时发现生产面板一直是落后的**：`ProvisioningService.php` 缺 2026-09-21 那个 forward 暂停
(`forward_not_found` 回滚) 修复，两个 i18n 文件缺约 72 个词条。这次一并带上去了。
部署方式：8 个文件 SFTP 上传 + sha256 逐文件核对（全部 match）+ `php -l` 全过 +
`php-fpm-8{1,2,3}-source196` 与 `php8.4-fpm` reload + HTTP 200。
备份：`173.208.183.93:/root/lk-panel-backup-20260921-094833`（8 个 `.before`）。

### 4. sidecar binding 热加载 —— **已部署到五台生产 sidecar**

`tools/uot-sidecar/reload.go`：SIGHUP（unix）+ 本地 marker 文件触发；校验通过才用一次
`atomic.Pointer` 发布，失败保留旧快照；每个 session 捕获自己的准入快照，删除/轮换不影响存量会话。

部署：五台 `/usr/local/bin/leikwan-external-uot-go` 从 `c6d11563b8c1b8a2` 换到
`54130c42358a2baf`，配置文件 md5 全部未变，重启后全部 active、7443 在听。
随后对**五台各发一次真实 SIGHUP**：**PID 全部不变**、服务 active、监听正常，日志
`external UoT configuration reloaded path=/etc/leikwan-external-uot.json`。
备份：各主机 `<exe>.bak-<stamp>`。

## 仓库与磁盘

- **worktree 从 49 个清到 12 个**：删掉 36 个「tracked 改动为 0」的陈旧会话 worktree，
  释放 **47.1 GB**（C 盘可用 161.1 → 209.7 GB）。**分支和提交一个都没动**，只是不再有工作目录。
  保留的 12 个都有未提交改动（或两份未跟踪的测试夹具）。
  删除前把两个"只存在于 worktree 里"的文件救回仓库并提交：
  `tools/uot-acceptance/patches/desktop-installed-1.0.44-compat.patch` 与
  `docs/superpowers/handoffs/2026-08-07-single-leigod-line-local-bond-requirement-handoff.md`。
- `.git` 安全压缩：`git prune --expire=now` + 一次 `git gc`，13.68 → **12.59 GB**，HEAD 与工作树未变。
- `uot-sidecars.txt` / `test-env.txt` 之前躺在仓库根目录**且从未被 gitignore**——一次 `git add -A`
  就会提交 5 台 sidecar 的密码。已加规则。

## 仍然未关闭（不掩盖）

1. **Windows 测试机线路承载不了 40 Mbps** —— 要拿到 40 Mbps 零丢包的结论需要换一台有余量的测试机；
   产品在 ≤30 Mbps 合计负载下 UDP 零丢包、TCP 零中断。
2. **候选质量门限缺失** —— `rtt_ms` 到 2.6 s、连续 `udp_arm_failed` 的候选仍不会被淘汰或轮换。
3. **控制面不可达时的整轮路由重准备** —— 引信拆了，机制还在；且
   `ConfigPollingService.RequestUltraRecovery` 没走 `RecoveryTriggerThrottle`（该节流只有 10 秒，
   日志里 `preparing_routes` 的周期与它一致）。
4. **全方向 E2E 矩阵** —— 11 个方向只覆盖 1 个；逐方向阻塞原因见
   `2026-09-21-all-directions-coverage-and-blockers.md`。
5. `400 次 headers_eof` 已归因：leased relay 自身约 1000 ms 后 RST 自己的流，**不是产品缺陷**。

## 机器状态

| 主机 | 状态 |
|---|---|
| Linux `172.21.255.189` | ultra-client `fbf6d977…`，forward 201/202 ready |
| macOS `100.103.253.21` | .NET agent 跑着本轮构建，forward 全部 ready、零 ha 失败 |
| Windows `172.21.255.4` | headless agent 跑着本轮构建，服务 RUNNING，201/202 ready、零探测假故障 |
| 五台 sidecar | 全部跑 `54130c42358a2baf`，配置未变，SIGHUP 热加载已验证 |
| 面板 `173.208.183.93` | 本轮 8 个文件已上线，备份在 `/root/lk-panel-backup-20260921-094833` |

**必须保留的纪律**：把 `Copy-Item` 从备份还原源码时会保留源文件的 LastWriteTime，NTFS 上会让
MSBuild 认为输出比输入新而**静默跳过重新编译**——本轮因此对着一个已被证伪的二进制测了好几轮。
改完源码后用 `dotnet build --no-incremental` 或 `dotnet test`（不带 `--no-build`）确认。
