# 会话入口�?026-09-21 第二轮）

上一轮入口是 `2026-09-21-SESSION-FINAL-HANDOFF.md`（内容仍有效，第 6 �?STILL OPEN"以本文为准更新）�?本文是当前入口。已推送：`integration/ultra-enterprise-ha` @ `1760d9f8`�?
## 验收状�?
用户指标�?*"TCP+UDP 测试 20Mbps 10min，无中断无断流算成功"**�?
| 平台 | TCP 20 Mbps × 600 s | UDP 20 Mbps |
|---|---|---|
| Linux | 19.999 Mbps�?00/600 窗口�?*idle 0**，CV 0.21% | **0.0% 丢包** |
| macOS | 19.999 Mbps�?00/600 窗口�?*idle 0**，CV 0.37% | **0.0% 丢包**（`tcp+udp` 复测�?|
| Windows | 19.589 Mbps�?00/600 窗口�?*idle 0，interruptions 0**，delivered 1.0，`error_classes {}`，CV 6.54% | 单跑 6.24%、与 TCP 并发 13.31% 前向丢包 |

**TCP 三平台全部达标。UDP �?Linux/macOS 达标，Windows 未达�?*，归因见下�?
## 本轮修掉的三个缺陷（都有可证伪的验证�?
### 1. agent 的监听探测会扰动数据面（已部�?Windows + macOS�?
`NetworkProbe.IsPortListening` �?*真的 TCP connect** 判断端口是否在监听。Ultra runtime 会把任何被接�?的连接当成真实客户：拨号上游、失败后�?`io_error` 并累�?`ha_failures`。于是健康检查在测量自己�?Windows 测试�?forward 201（目标只开 UDP）因此每 **20.02 s** 稳定 +1 条假故障，累�?166+�?
改成读内核监听表。验证：Windows 201 �?macOS 六个 forward �?`ha_failures` / `ha_events` �?**直接消失**（客户端只在有失败时才写这两个键）。周期性归因用单变量实验证明：interval=20 时间隔恰�?20.02 s，改�?47 后周期性消失�?
**这条同时解开�?Windows 600 秒跑不完的死�?*（见�?3 点）�?
### 2. panel �?target �?forward 静默失去 lease（未部署�?
Ultra �?lease 平面，且 lease scope digest �?target 折进去（`LeigodTokenBroker.php:492-512`�?`UltraLeaseService.php:1066`），�?target 后所有旧 lease �?`:146-147/:189-190/:220-224` 拒绝�?`ClientAgentController.php:601-606` 直接�?409 且不带任�?lease。而写路径
`ProvisioningService.php:5163-5226` 只调用了�?Ultra �?no-op �?reconciler（`:8740`）�?
修：enabled �?Ultra forward �?target_host/target_port/transport_mode 时在同一个事务里重新签发，或
�?`ultra_reprovision_unavailable` 回滚保留�?target；并�?`awaiting_policy`、任�?runtime 错误�?"enabled 但没有有�?lease" 都算成健康失败，在租户列表和后台新增�?Health 列渲染出来�?单测�?`master/tests/ultra_target_change_reprovision_test.php` �?`ultra_enabled_without_lease_health_test.php`�?
### 3. 控制面抖动会把数据面掐断（探测器的放大效应已修，机制本身仍在�?
Windows 首轮 600 秒：4 �?TCP 流在同一秒被一起杀掉，之后 35 秒全空。日志显�?`heartbeat_failed:HttpRequestException`（面板不可达）→ �?~10 秒一整轮 `preparing_routes:0/7�?/7`
�?`forward 159: local port 32469 not listening` �?`stopped all processes for forward 159`�?
因果链是"健康检查判定失�?�?杀掉被检查对�?。负载高时旧�?connect 探测会因监听队列占满而失败，
正是这条链的引信。探测器改掉之后，同一台机器同一条件跑出 **600/600 窗口、idle 0�? 错误**�?
**仍未�?*：面板不可达时整轮重准备路由这件事本身还在，�?`ConfigPollingService.RequestUltraRecovery`
没有�?`RecoveryTriggerThrottle` 那条节流。建议下一轮单独处理�?
## 本轮新增的能力（代码在仓库，未部署）

- **sidecar binding 热加�?*：`tools/uot-sidecar/reload.go` 等。SIGHUP（unix�? 本地 marker 文件
  （Windows/可测）。校验通过才发布，失败保留旧快照；每个 session 捕获自己的准入快照，删除/轮换不影�?  存量会话�? 个新测试；在 70.36.96.183 上用 scratch 进程做过完整真机验证（存量会话跨三次 reload
  26/26 ping 无间隙；生产 unit �?MainPID/NRestarts/配置 md5 全程未变）�?*五台 sidecar 都还没部署�?*
- **验收 harness**：`tools/acceptance-harness/`（Go，Windows 测试机上没有 python 也没�?go，所以交�?  编译成单 exe）。含两条让测量可信的关键约定：零字节 1 秒窗�?= 一次中断，窗口序列补齐到请求时长；
  socket 写带超时（转发停住时写会永久阻塞）�?
## 仍然未关闭（不掩盖）

1. **Windows UDP 前向丢包 6.24%（单跑）/ 13.24%（并发）** —�?用目标端可查询计数器拆出前向/返回�?   丢包在去程；同一目标同一 sidecar Linux �?0.0%；Windows 主机本身外网干净�?.1.1.1 RTT 80 ms�?   0% 丢包）。定位在"Windows 客户�?�?它租用的 relay"这一段，relay 内部无法插桩，未再细分�?2. **候选质量门限缺�?* —�?2.6 s RTT 且连�?`udp_arm_failed` 的候选不会被淘汰�?3. **控制面不可达时的整轮路由重准�?*（见上）�?4. **全方�?E2E 矩阵** —�?11 个方向只覆盖 1 个；逐方向阻塞原因见
   `2026-09-21-all-directions-coverage-and-blockers.md`�?5. **本地仓库 13.68 GB**（master �?350 次提交里有大二进制）。已推送的镜像
   `C:\Users\frankniubi\lk-clean\repo.git` 只有 53.69 MiB�?6. `400 �?headers_eof` 已归因：leased relay 自身�?1000 ms �?RST 自己的流�?*不是产品缺陷**�?   `headers_eof` 这个词只存在于当时那�?request-per-connection 的测试脚本里�?   �?`2026-09-21-headers-eof-attribution.md`�?
## 机器状态（离开时的样子�?
| 主机 | 状�?|
|---|---|
| Linux `172.21.255.189` | ultra-client `fbf6d977…`（`2026.09.21.1.localcandidate`），forward 201/202 ready |
| macOS `100.103.253.21` | .NET agent 已换成本轮构建（`LeikwanBridge.Core.dll` = `3e355e3ae366…`），六个 forward ready 且零 ha 失败；原文件备份 `~/lk-backup/mac-agent-20260921T155355Z/` |
| Windows `172.21.255.4` | headless agent 已换成本轮构建（�?hash），服务 RUNNING�?01/202 ready 且零 ha 失败；原文件备份 `C:\Users\lwtest\lk-backup\*.pre-probefix`；两台都设了 `disable_auto_updates=true` |
| 五台 sidecar | 全部未部署新构建；`70.36.96.183` 的验�?pump 两侧 `RuntimeMaxUSec=infinity`，PID 1427263，端�?62101(UDP)/62102(TCP) |

**必须保留的纪�?*：把 `Copy-Item` 从备份还原源码时会保留源文件�?LastWriteTime，NTFS 上会�?MSBuild 认为输出比输入新�?*静默跳过重新编译**——本轮因此对着一个已被证伪的二进制测了好几轮�?改完源码后用 `dotnet build --no-incremental` �?`dotnet test`（不�?`--no-build`）确认�?