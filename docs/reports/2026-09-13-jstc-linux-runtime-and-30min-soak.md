# 172.21.255.189 最新 runtime 安装 + 10001 SS-none 30 分钟压测

日期：2026-09-13。主机 \`root@172.21.255.189\`（jstc，client 3 的 Linux canary，Debian 12，ultra forward 158/159/170/171/180），
对端 \`root@185.220.238.143\`（东京 sidecar：sing-box shadowsocks \`method=none\` / 密码 \`laufrank\`，TCP+UDP 10001），
测试目标 \`185.220.238.143:60000\`(UDP echo) / \`:60001\`(TCP echo)。

## 0. 结论

- **已安装最新 runtime**：linux amd64 \`2026.09.13.2\`，sha256 \`76055872721d9a1a…\`，自报指纹 \`2026.09.13.2.452c8fe8\`；
  五个 forward 的运行进程 \`/proc/<pid>/exe\` sha 全部等于该值。
- **30 分钟压测：经隧道 UDP 几乎零丢包、TCP 零中断**；而同一条路径的直连对照丢 ~21%。
  也就是说这条"墙内→东京"的裸路径本身很差，走转发之后才可用。
- **UDP 全程稳定可用**（p50 ≈ 50 ms，抖动小，逐分钟无异常分钟），但**新目的地/新关联的首次建链要 1.0–1.7 s**，
  之后才是 50 ms 级。TCP 新连接首字节 ~120 ms。
- 顺带发现面板侧 **attestation（防拆包指纹）一直是空转的**：195 个 session 行全部 \`ultra_runtime_fingerprint=NULL\`，
  即使 \`ULTRA_ATTESTATION_ENFORCEMENT=enforce\` 也永远匹配不到东西。本轮修好了数据面并暂时切回 \`report\`。

## 1. 安装

此前只发布过 Windows runtime，Linux 仍是 \`2026.09.13.1\`（sha \`33ac5e4c\`），而且**指纹是 \`<version>.unknown\`**（见 §3.1）。

| 步骤 | 结果 |
| --- | --- |
| 构建 | \`scripts/build-ultra-linux.ps1 -Version 2026.09.13.2 -ShortSha 452c8fe8\`，garble v0.17.0、PIE、stripped、两次构建哈希一致 |
| 产物 | 16,535,818 B，sha256 \`76055872721d9a1a12a1a9ec432423a2b38e4a7cd28d6e855e19821fc92b6c9b\` |
| 面板发布 | \`artifacts/ultra/ultra-client_linux_amd64\` + \`artifacts/versions.json\`（\`version/sha256/size_bytes/attestation\`），备份在 \`/root/leikwan-br1-backups/ultra-linux-*\` |
| 服务面板实际字节 | 与发布值一致（下载后 sha256 比对） |
| jstc 自更新 | agent 自己生成 \`auto-release:ultra_runtime=8251965d…\` 并跑 installer 换二进制——链路本身是通的，但 **installer 只换文件不重启服务**，所以运行中的进程还是旧的 |
| 生效 | 手动重启 5 个 \`ultra-forward-*\`（170 先，其余间隔 2s） |

安装后 \`/run/ultra/forward-170.status.json\` 自报：

\`\`\`json
"runtime_attestation": {
  "fingerprint": "2026.09.13.2.452c8fe8",
  "self_sha256": "76055872721d9a1a12a1a9ec432423a2b38e4a7cd28d6e855e19821fc92b6c9b"
}
\`\`\`

## 2. 30 分钟压测

### 2.1 方法

\`/root/soak.py\`（自写、无依赖），四条腿同时跑 1800 s：

| 腿 | 路径 | 说明 |
| --- | --- | --- |
| tunnel-udp | SS-none UDP → \`127.0.0.1:10001\` → 隧道 → 东京 → \`:60000\` | 30 pps，64 B；每 50 包一个 512 B；每 30 s 一次 30 包突发 |
| tunnel-tcp | SS-none TCP → \`127.0.0.1:10001\` → 隧道 → 东京 → \`:60001\` | 长连接，1 心跳/s，断线立即记录并重连 |
| direct-udp | 直连 \`185.220.238.143:60000\` | 同参数对照（裸路径基线） |
| direct-tcp | 直连 \`185.220.238.143:60001\` | 长连接对照 |

每条腿记录：逐包 RTT、丢失/重复、逐分钟丢包、连续丢包段、突发/大包分组统计；
每 5 s 采样 \`/run/ultra/forward-170.status.json\` 与 \`systemctl show ultra-forward-170\`（PID/NRestarts），
用于把任何异常对齐到运行时重启。

### 2.2 结果

### 2.2 结果（1803 s，四条腿同时）

| 指标 | tunnel-udp（经转发） | direct-udp（裸路径） | tunnel-tcp | direct-tcp |
| --- | --- | --- | --- | --- |
| 发包 / 收包 | 55,770 / 53,511 | 55,770 / 43,369 | 1728 心跳 | 1800 心跳 |
| **丢包** | **4.05%**（全部集中在一段 70 s 事故；其余 29 分钟 0.00%） | **22.24%**（30/31 分钟都在丢） | 0.058% | 0.000% |
| RTT p50 / p95 / p99 / max | 50.1 / 51.9 / 53.4 / 3360 ms | 95.3 / 121.2 / 129.8 / 136 ms | 48.6 / 50.0 / 51.5 / 121 ms | 98.7 / 435.4 / 752.8 / 1631 ms |
| 抖动（平均 / p95） | **0.7 / 1.8 ms** | 1.8 / 5.1 ms | 0.81 ms | **125.3 ms** |
| 突发 30 包 | 3.39% 丢 | 21.47% 丢 | — | — |
| 512 B 大包 | 4.07% 丢 | 22.96% 丢 | — | — |
| 连接中断 | — | — | 2 次建连 / **73 次断开**（每次 <1 s 自动重连，仅丢 1 个心跳） | 0 |

逐分钟（tunnel-udp）：`min 0 = 0.05%`、`min 1–17 = 0.00%`、**`min 18 = 93.12%`**、**`min 19 = 28.33%`**、`min 20–30 = 0.00%`。
永久丢失只有一段连续序列（`seq 33578 + 2259` 包，≈70 s）。

### 2.3 那次 70 s 事故的归因（有日志）

- `ultra-forward-170` 进程 **全程没有重启**（`NRestarts=0`，PID 2937867 从 21:33:44 一直活到压测结束）。
- 运行时候选线路在 **13:58:49Z** 同时坏掉：

      ultra UDP candidate probe failed endpoint=113.31.107.11:5438 error=probe_udp_failed:
          datagram handshake failed after greeting fallback: initial=datagram handshake: EOF; legacy=datagram handshake: EOF
      tcp stream ended forward_id=170 direction=upstream reason=eof generation=fc314928... duration_ms=52
      tcp stream ended forward_id=170 direction=downstream reason=eof generation=fc314928... duration_ms=1052

  之后每 ~30 s 重复一次；同时 `ultra lease selection started forward_id=170 candidates=4` 反复重选，
  **但选中的仍是同一个候选**（`candidate_key_hash=212e6ca3...`），直到 14:04 还是它。
  即：该候选的 UDP 握手与 TCP 流都已死亡，运行时**没有迁移到健康候选**——这正是"故障自己迁移"该覆盖的场景。
- 同一时间窗（14:01Z）agent 还报了 5 条 `Ultra lease delivery failed ... timed out` 与
  `forward N Ultra refresh failed; preserving last-known-good runtime`，systemd 记录到 1 次 agent watchdog 杀进程。
- 结论：**数据面在这 21 分钟里是 0.00% 丢包 / 0 中断**；4.05% 全部来自一次"候选线路死亡且未迁移"的事件。

## 3. 本轮修掉的缺陷

### 3.1 build 脚本把显式 \`-ShortSha\` 覆盖成 \`unknown\`（指纹链断裂）

\`scripts/build-ultra-{linux,windows}.ps1\`：

\`\`\`powershell
$shortSha = $ShortSha
if ([string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = git ... }
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = "unknown" }
\`\`\`

新开的 PowerShell 会话里 \`$LASTEXITCODE\` 是 \`$null\`，而 \`$null -ne 0\` 为真 —— 于是**显式传入的 sha 被丢掉**，
所有 Linux/macOS runtime 都自报 \`<version>.unknown\`。面板的 attestation 只认"已发布的指纹"，
\`<version>.unknown\` 不在其中，于是这道闸门对 Linux 完全空转（enforce 开着也等于没开）。
修复：只在 git 真的跑过时看 \`$LASTEXITCODE\`；回归断言写进 \`client-agent/tests/test_ultra_artifact_hardening.py\`。

### 3.2 自更新重试在 systemd 里堆了 440 个失败单元

\`client_agent.py\` 用 \`systemd-run\` 起瞬时单元却**没有 \`--collect\`**；自更新失败会每 \`UPDATE_RETRY_SECONDS\`(=300 s) 重试一次，
每次留下一个永久 failed 单元。jstc 上累积到 **440 个**（\`systemctl --failed\` 已不可用）。
修复：\`systemd-run --collect\`，新增 \`client-agent/tests/test_client_agent_update_unit_collection.py\`；
现场 \`systemctl reset-failed\` 清空（442 → 0）。AGENT_VERSION 提到 \`2026.09.13.1\`（仓库已就绪，未推面板）。

### 3.3 面板 attestation 数据面根本没接线（防拆包闸门空转）

- 面板 \`ClientAgentController::heartbeat\` 调用 \`UltraClientStatusService::ingest()\` 时**只传了 3 个参数**，
  而 attestation 有两条上报形状：桌面客户端放在 \`ultra_usage[].runtime_attestation\`，agent 放在 \`ultra_status[].runtime_attestation\`，
  两者都没被读；
- 结果 \`client_device_sessions\` 195 行**全部 fingerprint=NULL**、\`ultra_lease_refusals\` 0 行 —— enforce 永远匹配不到。
- 修复：heartbeat 现在从顶层 / \`ultra_usage\` 收集 attestation 并显式传给 ingest；契约测试钉住这个透传。
- 同时把 \`ULTRA_ATTESTATION_ENFORCEMENT\` 暂切回 \`report\`（观察窗口），因为：
  - 客户端侧仍有缺口（见 §5.1）：**空闲 forward 不会上报 attestation**，先 enforce 等于拿没数据的状态去拒人；
  - 面板里 mac/Android/arm64 条目的 \`attestation.self_sha256\` 是旧的，与它们实际服务的字节不一致，
    一旦这些平台开始上报且被匹配上，会**误拒付费客户**。
  新增契约断言：当前版本 runtime 条目必须 \`attestation.self_sha256 == sha256\`。

### 3.4 测试与实现漂移

- \`ultra_policy_lease_contract_test.php\` 仍按"nonce 立即重放必须拒绝"断言，而实现**有意**容忍
  \`REPLAY_GRACE_SECONDS=300\` / \`MAX_REPLAY_ATTEMPTS=5\` 内有限重试（配置轮询与定向刷新会撞车）。改成断言该预算。
- \`ultra_client_artifact_distribution_test.php\` 用的是仓库里的 manifest，而我只在面板侧打了补丁 —— 已同步仓库 manifest。

## 4. 现场观察（值得记住的行为）

- **UDP 新目的地首包 1.0–1.7 s**：SS-none→ultra 数据报关联是懒建立的。游戏首次进服会有一次 1 秒级抖动，
  之后 50 ms；但如果关联被拆掉（线路切换/空闲超时），下一次又是 1 秒级。
- **TCP 新连接首字节 ~120–130 ms**，connect 本身 0–5 ms。
- probe 面板上显示的 TCP 1.37 s **不是隧道问题**：\`1.1.1.1:80\` 从东京出口的响应本身要 1.1 s 才收完
  （同一时刻经隧道的东京 echo 只要 120 ms）。
- 直连丢包 ~21%，而且 24 h 维度的 probe 可用率只有 **87%**（6269 样本 / 821 失败），最坏一段**连续 82 分钟不可用**；
  把失败时间与 ultra-forward 重启（24h 内 42 次）、agent watchdog 杀进程（48h 内 158 次）对齐，只能解释约 17%，
  其余是线路/候选质量本身（\`tcp_http_response_invalid\`、\`tcp_connection_refused\`）。
- runtime 自报的候选体检与实测数据面**互相矛盾**：压测期间 UDP 99.99%、TCP 100% 的同时，
  它每 30 s 报一次 \`udp_endpoint_unreachable\`，并周期性产生 \`tcp closed/reset\` 的 HA 事件。

## 5. 未闭环（按优先级）

1. **空闲客户端不上报 attestation**（客户端设计缺口）：
   \`HeartbeatService.CollectUltraUsage\` 只把 \`DeltaIn>0 || DeltaOut>0\` 的 forward 放进 \`ultra_usage\`（HeartbeatService.cs:274），
   attestation 搭在这些行上 —— 空闲设备永远不上报。建议：只要 runtime 快照带 attestation 就发一行（字节数 0），
   或按固定低频（如每 10 个心跳）单独上报。需要随下一个桌面客户端版本发布。
2. **agent-only 主机没有 session 行**：\`ingest()\` 只做 \`UPDATE ... WHERE client_id AND device_id\`，
   jstc 这类纯 agent 主机在 \`client_device_sessions\` 里没有行，attestation 无处落地；需要 upsert。
3. **agent 被 systemd watchdog 杀**：48 h 内 158 次（≈18 分钟一次，\`WatchdogSec=90\` 超时），
   每次重启打断租约续期；面板 lease 接口曾出现 33 s 慢请求。
4. **ultra-forward 重启**：170 号 24 h 内 42 次，其中 21 次来自 agent 的
   "Ultra listener absent on 3 spaced checks"。
5. **跨境下载 ~30 KB/s**：16.5 MB runtime 从镜像到 jstc 要 ~10 分钟（面板本机 138 MB/s、我本机→jstc 4.3 MB/s），
   瓶颈在墙内→美国这一段；Windows 客户端 69.5 MB 安装包同理，这是 30 s 超时事故的根源。
6. 面板部署副本缺 \`master/migrations/20260912_add_ultra_runtime_attestation_and_abuse_limits.sql\`（测试因此无法在面板上跑）。
7. 4 个 client-agent 安装器测试在 HEAD 上就是红的：断言"installer 不应再安装 tcp-accel/mptcp 载荷"，
   而 \`install/install-client-agent.sh\` 仍在 \`download_manifest_artifact tcp_accel_installer\` 并执行它 —— 需要决定哪边是对的。
8. macOS/Android/linux-arm64 runtime 仍停在 \`2026.09.13.1\`，且它们的 attestation 块是旧值（见 §3.3）。
9. 面板 master 测试套件 388 个里 20 个红（本轮修掉 2 个 + 1 个由我引入的 manifest 漂移），其余属于既有漂移。

## 6. 复现命令

\`\`\`bash
# 构建 + 发布（本机）
./scripts/build-ultra-linux.ps1 -Version 2026.09.13.2 -ShortSha 452c8fe8 -Arch amd64
# 现场压测（jstc）
python3 /root/soak.py --selftest
systemd-run --unit=ultra-soak --collect /usr/bin/python3 /root/soak.py --duration 1800 --out-dir /root/soak-<ts>
python3 /root/soak-digest.py /root/soak-<ts>/summary.json
# 对端 echo
systemctl status tokyo-echo.service      # UDP 60000 / TCP 60001 echo
\`\`\`
