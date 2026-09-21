# 2026-09-21 — 全方向 × 全平台 E2E 验收矩阵（11 方向，600 秒，TCP+UDP 各 20 Mbps）

口径：每个方向在其专属 sidecar 上，Linux/macOS/Windows **顺序**各跑 600 秒（并发会把 3 倍负载压在
同一台 sidecar 上，那测的不是验收口径）。判定 = 20 Mbps 达成 + 窗口 idle 0（无中断）+ UDP 丢包 <0.1%。

| 方向 | combo | Linux | macOS | Windows |
|---|---|---|---|---|
| SHA→HKG | 46 | **PASS** 20.000 / idle 0 / UDP 0% | **PASS** 19.905 / idle 0 / UDP 0.018% | **PASS** 20.001 / idle 0 / UDP 0% |
| SHA→SIN | 47 | FAIL idle 598/600, cfwg 3 + self-update 2 | PASS 20.000 / idle 0 / UDP 0% | FAIL idle 598/600（首次跑 PASS，间歇） |
| SHA→ICN | 48 | FAIL 14.48 Mbps, UDP 19.5% | PASS 19.996 / idle 0 / UDP 0% | FAIL idle 599/600, start_failed 28, delivery_false 14 |
| PEK→FRA | 49 | FAIL idle 570/600, no_healthy 64, apply_failed 34 | FAIL 15.02 Mbps（无中断但达不到 20） | FAIL 9.71 Mbps, UDP 16.0% |
| PEK→MOS | 50 | FAIL idle 571/600, cfwg 3（Ultra 侧零错误） | FAIL 2.85 Mbps, idle 596/600 | FAIL idle 598/600, UDP 6.07% |
| CAN→HKG | 51 | **PASS** 20.001 / idle 0 / UDP 0% | **PASS** 20.001 / idle 0 / UDP 0% | 18.829 Mbps, idle 0, UDP 0.36%（无中断，速率略欠） |
| CAN→SIN | 52 | FAIL idle 598/600, no_healthy 90, apply_failed 62 | FAIL idle 507/600, UDP 84.1% | FAIL idle 567/600, UDP 64.5% |
| FOC→TPE | 53 | 阻塞（三次尝试，三平台均无监听） | 阻塞 | 阻塞 |
| SHA→TYO | 54 | **PASS**（第二次；首次 idle 185） | FAIL idle 599/600, UDP 100% | 无监听 |
| SHA→LAX | 55 | **PASS** 20.000 / idle 0 / UDP 0% | FAIL UDP 67.3% | 15.444 Mbps / idle 0 / UDP 0%（无中断，速率欠） |
| HKG→内地 | 56 | FAIL 6.26 Mbps, UDP 98.0% | 无监听 | 无监听 |

**11 个方向里只有 46 一个在三个平台全部达标。** 51 三平台无中断但 Windows 速率 18.83 Mbps。

## 失败归因（自动采集自各平台 agent 日志）

1. **Linux 客户端自我重启**（方向 47/50/52）：`cfwg latency watchdog restarted leikwan-client-47.service`
   每 10 分钟最多 3 次（电路断路器上限）+ `requested client self-update` 2–3 次，
   而这三个窗口里 **Ultra 侧零错误**（`no_healthy=0, apply_failed=0`）。
   即：**与 Ultra 无关的 CFWG 隧道不健康 → 客户端整体重启 → Ultra 数据面被打断整整 10 分钟。**
2. **TCP forward 没有健康候选**（方向 49/52/54）：`no_healthy_candidate` 64–90 次 / 窗口，
   `failed to apply forward` 34–62 次。UDP（走 sidecar）同期零丢包。
3. **每个方向的 TCP 实际吞吐差异巨大**：46/47/48/51/54/55 能到 20 Mbps，
   49 只有 9.7–15、50 只有 2.85–20.8、56 只有 6.26。
4. **macOS/Windows 的 .NET agent 根本起不来某些 forward**（53 三平台、54 与 56 的 macOS/Windows）：
   面板侧 5 台设备都持有这些 forward 的有效租约，但 6 分钟内监听始终不出现。
   Windows agent 进程数 12、最近 900 行 0 次启动失败——不是崩溃，是这些 forward 没被推到可服务状态。
5. **方向 56（HKG→内地）UDP 丢包 98%**，TCP 也只有 6.26 Mbps。

## 本轮已修的 4 个缺陷（对比上面未修的 5 类）

1. 面板把已吊销的代际永久重新签发 → 折入吊销纪元（live 验证，三平台自愈）。
2. `17c42b23` 的 guard 写在了 304 刷新路径，而 wedge 走全量 pull 路径 → 两条路径都记录。
3. Windows 把"租约投递瞬时失败"当"启动失败" → 有界重试（macOS 从 598/600 空窗口变 0）。
4. 素材刷新由 enabled forward 驱动（90 秒），不是源端故障。

