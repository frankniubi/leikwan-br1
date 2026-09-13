# Ultra 快速故障切换 + 吞吐验收（2026-09-13 第二轮）

目标（用户原话）：**稳定无丢包，或者非常快的切换；最好速度也能跑。**
上一轮报告里的每条建议本轮都落地了，并在 jstc（172.21.255.189）与用户本机（Windows）两端做了实测。

## 0. 结论

- **故障切换从「130.9 秒且根本没切走」变成「6.8 秒切到健康线路」**；同一注入条件下 TCP 重连 **301 → 4**，注入期间 UDP 丢包 **13.4% → 0.0%**。
- **稳态零丢包**：jstc 侧 30 分钟压测里 21 分钟 0.00% 丢包、p50 50 ms、抖动 0.7 ms；用户本机侧三个 10 分钟窗口各 18,570 个包 **全部 0.000% 丢包**、p50 35.5 ms、p99 ≤38 ms。
- **速度够用**：隧道 TCP 实测 157–189 Mbit/s；UDP 到 50 Mbit/s 丢包 <0.75%，100 Mbit/s 时 4.6%（上限）。游戏量级（<1 Mbit/s、20–60 pps）远在安全区。
- 唯一已知毛刺：**新建 UDP 关联的首包有 ~2.0–2.6 s 一次性延迟**（约 1/3 的新 socket；已建立的关联永不发生）——冷启动，不是持续不稳定。

## 1. 故障切换：根因、修复、A/B 实测

### 1.1 根因

中继（线路）有两种死法，运行时代码只认其中一种：

1. **dial 失败**（连不上）：早就处理 —— dial 报错 → MarkCandidateFailure → 隔离 → 换下一个候选。
2. **dial 成功但之后一个字节都不回**（半死）：**没有处理**。relayTCP 只看到 eof，而 ha_status.go 明确把 eof/closed/canceled 排除在失败计数之外，于是同一条候选被反复选中 —— 2026-09-13 的事故里持续了 6 分钟、73 次重连。

### 1.2 修复（tools/ultra-client，commit 81f66ff9）

- copyInbound/copyOutbound 现在返回字节数；relayTCP 新增 noteRelayOutcome：**连接建立后 3 秒内、客户端发过数据、而下游一个字节都没回来** 记一次静默失败，**连续两次**即隔离该路径（FailSilentPath）。
- 任何**收到数据**的流、或 UDP keepalive ACK，都会 ClearPathFailure 清空计数与隔离。
- 隔离窗口**递进**：15 s → 45 s → 2 min（旧的固定 15 s 让死线路每 15 秒被重试一次，白费 arm 预算）。
- **全局断网保护**：某协议下所有授权路径同时被隔离时，说明是本地上游故障而不是某条线路的错 —— 立即清空递进计数，把恢复时间限制在首个窗口（≤15 s），而不是被推到 5 分钟。
- 测试：新增 ha_dead_stream_test.go（静默隔离、健康清除、递进与重置、越权路径拒绝、全局中断不递进）+ ha_test.go 集成用例 TestHATCPRelayQuarantinesSilentRelay（真起 socket，provider 接受后立刻关闭）。go test ./... 全绿。

### 1.3 A/B 实测（同一注入、同一客户端、只换运行时）

注入手法 switch-driver2.py：按 **forward-170 的 MainPID** 精确锁定它自己的 socket，取 6 秒内 bytes_acked 增长最多的对端（= 数据面线路），然后对该 IP 的**已建立连接** DROP（静默切断）、对**新连接** REDIRECT 到本地假中继 blackhole.py —— 假中继**完整应答 ultra 握手（发送 05 02 01 00，dial 因此成功）之后不承载任何字节**，精确复刻事故形态。

| 指标 | 修复前 2026.09.13.2 | 修复后 2026.09.13.3 |
| --- | --- | --- |
| 线路变哑 → TCP 恢复 | **130.9 s**（恢复发生在解除注入后 ≈50 s，期间从未切走） | **6.8 s**（注入仍生效时就切走） |
| 注入窗口内 TCP 重连 | **301** | **4** |
| 注入窗口内 TCP 完全不可用 | 80.0 s（整个窗口） | 9.0 s |
| 注入窗口内 UDP 丢包 | 13.39% | **0.00%** |
| 注入解除前是否恢复 | 否 | **是** |

## 2. 吞吐与限速曲线（jstc）

方法：sspipe.py 在本地开端口，把每条连接按 SS-none 帧送进隧道，指向对端 127.0.0.1:5201 的 iperf3；UDP 用 udprate2.py（收发分线程，1200 B 载荷）。

| 测试 | 结果 |
| --- | --- |
| 隧道 TCP（iperf3） | **157–189 Mbit/s**（随后 iperf3 控制连接被对端关闭而中止） |
| 隧道 UDP 5 Mbit/s | 达成 4.99，丢包 **0.56%**，p50 46 ms |
| 隧道 UDP 20 Mbit/s | 达成 20.0，丢包 **0.64%**，p50 47 ms |
| 隧道 UDP 50 Mbit/s | 达成 50.0，丢包 **0.72%**，p50 56 ms，p95 107 ms |
| 隧道 UDP 100 Mbit/s | 达成 100.0，丢包 **4.59%**，p50 64 ms |

结论：**50 Mbit/s 以内 UDP 基本无感，100 Mbit/s 触及中继/队列上限**。游戏流量完全在安全区。
附带发现：iperf3 的**控制连接（长时间零字节）被对端关闭** → 长期空闲、无 keepalive 的 TCP 通道会被线路回收；有 1/s 心跳的流不受影响（用户本机 600 s × 3 窗口 0 断线）。

## 3. 用户本机（Windows，forward-170）实测

由 subagent 独立完成，只读探测、未改任何网络/代理配置（原始数据：tmp/soak-local-20260913T{1429,1440,1451}Z/、tmp/dns-probe-20260913T1451Z.json）。

| 腿 | 丢包 | p50 | p95 | p99 | 抖动 |
| --- | --- | --- | --- | --- | --- |
| 隧道 UDP ×3 窗口 | **0.000% / 0.000% / 0.000%** | 35.3 / 35.5 / 35.7 ms | 36.2 / 36.3 / 36.5 | 37.2 / 38.0 / 37.2 | 0.61 / – / 0.45 ms |
| 直连 UDP ×3 窗口 | 3.14% / 2.97% / 3.32% | 93.6 / 92.9 / 94.2 ms | 97.0 / 95.9 / 95.8 | 100 / 96 / 96 | 1.87 / – / 1.45 ms |
| 隧道 TCP（600 s 单连接） | 0/600 心跳丢失 | 36.2 ms | 37.2 ms | 37.9 ms | 0.61 ms |
| 直连 TCP | 0/600 | 94.2 ms | 98.9 ms | **443.5 ms** | 31.3 ms |
| DNS over tunnel（1/s 常驻 socket） | **600/600，100.000% 可用** | 37.4 ms | 39.7 ms | 41.2 ms | 0.89 ms |

逐分钟：隧道每条腿只有第 0 分钟丢 1 个包（预热），其余分钟 0.00%；直连 10/11 分钟都有丢包。
全程**无重启、无断线、无 HA 事件**（PID 11896 不变，363 次采样 state 均为 ready；出现的 ha_event 全是探针自己关闭 TCP 造成）。

## 4. Attestation（防拆包指纹）链闭环

之前指纹链在三个地方同时断掉，闸门「开着 enforce 但永远匹配不到东西」。

1. **构建脚本丢指纹**（已修 26758e14）：build-ultra-{linux,windows}.ps1 里 $LASTEXITCODE 在未跑过外部命令的新会话中是 $null，而 $null -ne 0 为真 → 显式传入的 -ShortSha 被覆盖成 unknown。现在只在 git 真的跑过时才看退出码，并把不变式写进 test_ultra_artifact_hardening.py。
2. **面板不读客户端上报**（已修 e3e7390d）：heartbeat 调用 ingest() 只传 3 个参数，而桌面客户端把指纹放在 ultra_usage[].runtime_attestation、agent 放在 ultra_status[].runtime_attestation，两边都没被读 → 库里 195 个 session 行全是 NULL。现在两种形状都读并显式传入。
3. **客户端空闲就不上报**（已修 fb0d46cc）：usage 行只在 DeltaIn>0||DeltaOut>0 时才发，而指纹搭在 usage 行上；空闲客户端永远不报。现在指纹出现即发一行（0 字节），此后每 5 分钟最多重发一次；新增单测（Core 22/22 通过）。
4. **发布清单里的旧 attestation 块**（已修 c115c35e + 面板同步清理）：linux-arm64 / macOS ×2 / Android ×2 五个条目挂着**上一版的指纹**和**与服务字节不符的 self_sha256**（例如 macOS x64 写 74c3f220…，实际文件是 0fba8582…）。在 enforce 下，一个跑着已发布二进制的 macOS 客户端会**被拒发租约**。现已删除这五个块，保留 retired_runtime 显式放行条目，并让契约测试**遇到陈旧块直接失败**（不再跳过）。

当前面板判定表（enforce 生效时用于比对）：

    2026.09.13.3.452c8fe8 -> [linux amd64 835ffd94…]   (本轮新 runtime)
    2026.09.13.2.452c8fe8 -> [windows 226cc3aa…]
    2026.09.12.1.4b106b02 -> [030b48158478…]           (retired 显式放行)

**enforcement 目前仍是 report（只观察不拒绝）**，这是刻意的：macOS/Android/arm64 的 runtime 还没用正确指纹重建，无法确认它们上报什么；在确认前打开 enforce 有误拒付费客户的风险。切换条件与命令：

    # 1) 观察一段时间，确认 client_device_sessions.ultra_runtime_fingerprint 出现预期值，
    #    且 php-fpm.log 中没有 ultra_attestation_mismatch
    # 2) 再切换：
    sed -i 's/^ULTRA_ATTESTATION_ENFORCEMENT=report/ULTRA_ATTESTATION_ENFORCEMENT=enforce/' \
      /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env

## 5. 本轮发布的制品

| 制品 | 版本 | sha256（前 16） | 说明 |
| --- | --- | --- | --- |
| ultra runtime linux amd64 | 2026.09.13.3 | 835ffd941596ff7f | 快速切换修复，已发布并在 jstc 验收 |
| client-agent | 2026.09.13.1 | 5a1f8ac28fd511a0 | systemd-run --collect（不再堆积 failed 单元）+ 空闲 attestation |
| windows runtime | 2026.09.13.2 | 226cc3aac2c786df | 上一轮，未含本轮 Go 修复（需随桌面客户端发版） |

面板侧均已验证：artifacts/... 实际服务字节 == 清单 sha；jstc 的 agent 会自行拉到新 runtime / 新 agent（自更新链本轮已验证可用）。

## 6. 未闭环

1. **Windows/macOS/Android runtime 未重建**：本轮 Go 修复只在 Linux 生效；Windows 要走桌面客户端发版（runtime 由 app 内嵌 + 面板 pin 校验，需按老流程同步重建，否则 HasRuntimeDrift 死循环）。
2. **enforce 开关**：见 `4，等 macOS/Android runtime 重建并确认上报后再打开。
3. **新建 UDP 关联首包 ~2 s**：冷启动代价，约 1/3 新 socket；若要消掉需要预建关联（常驻成本），建议保持现状、客户端避免每局新开 socket。
4. **中继会回收完全空闲的 TCP 流**（iperf3 控制连接 ~10 s 被杀）；有心跳的流不受影响。
5. **bytes_in/bytes_out 不能用于 UDP 计费**（用户本机 600 s：bytes_out +13.8 KB，实际发出 ~1.4 MB）。
6. agent watchdog 被杀（48h 内 158 次）本轮未动；--collect 只解决了失败单元堆积。
7. 4 个 client-agent 安装器测试在 HEAD 上依然红（断言「不应再装 tcp-accel」与 installer 实际行为矛盾），需要产品决策。
