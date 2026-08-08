# HKG Bond 与 Single 同速问题评估 Handoff

- 日期：2026-08-08
- 当前仓库：`C:\Users\frankniubi\Downloads\leikwan-br1`
- 状态：`TCP_BOND_THROUGHPUT_ROOT_CAUSE_NOT_CLOSED`
- 调查方式：本地源码/提交/QA artifact 审阅，加生产环境严格只读核验
- 变更边界：未修改生产配置，未重启服务，未重新测速，未写入任何凭据

## 1. 用户原始问题

用户要求评估：

```text
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\findings20260808.md
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\maybesolution1.md
```

同时阅览最新的 `leigod-forward` 代码，并结合以下现场测试报告判断：

```text
C:\Users\frankniubi\Downloads\leikwan-br1\docs\2026-08-08-leigod-sidecar-wg-test.md
```

核心问题是：

```text
bond 后的速度和 single 一样。
```

用户同时提供了最新发布 handoff：

```text
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\2026-08-08-uot-feedback-scheduler-production-deploy-handoff.md
```

## 2. 最终答案

### 2.1 总结

`findings20260808.md` 与 `maybesolution1.md` 评估的是 UDP/WireGuard UoT sidepath 的多 leg 调度问题；当前 HKG `bond ~= single` 报告测的是 classic TCP bond。两者不是同一条数据路径。

最新提交 `8f0ec3148774cd3feeccaf5c41bc23732958645e` 发布了 UoT feedback scheduler artifact，但没有修改 `leikwan-bond`，也没有在现有 forward 上激活 `feedback_drr`。因此当前 HKG TCP 结果既不能证明新 scheduler 有效，也不能证明新 scheduler 无效。

当前结论必须保持为：

```text
TCP_BOND_THROUGHPUT_ROOT_CAUSE_NOT_CLOSED
```

### 2.2 当前 HKG A/B 不能隔离 bond 因果

测试报告中的两条路径不是同拓扑：

- HKG bond：`route15/combo13 -> current_mode/CNIX -> point10 sidecar/WG`。
- HKG single：临时 `route19/combo17`，`sidecar_point_id=NULL`，绕过 point10 sidecar/WG，直接终止在 node2。

两者分别得到约 `194.598 Mbps` 与 `193.915 Mbps`，只能说明最终用户吞吐接近，不能证明它们受同一个瓶颈限制，也不能单独证明多 leg 调度没有工作。

### 2.3 当前测试没有经过 UoT feedback scheduler

只读核验确认：

- 历史 HKG 测试对应 `forward118`，`transport_mode=tcp`，监听端口为 `8094`。
- 该 forward 不满足 UoT sidepath 必须包含 UDP transport 的选择条件。
- sh36、point10、HKG 四个 slave 的 authoritative `udp_uot_sidepath` 当前均为 `null`。
- sh36 当前没有 UoT unit/process/runtime JSON，且缺少 client UoT native binary。
- point10 当前没有 UoT unit/process/runtime JSON，且缺少 sidecar UoT native binary。
- 当前没有 `feedback_capable_active_legs` 或 `legacy_fallback_active_legs` telemetry；它们应记录为 `N/A/inactive`，不能写成 `0/0`。
- panel artifact 已支持 protocol v2、`leg-progress-v1` 与 `legacy/feedback_drr`，但 artifact 发布不等于运行主机已安装或激活。

因此当前 HKG 结果属于 classic TCP bond 基线，与 `feedback_drr` 无直接执行关系。

### 2.4 没有发现显式 200 Mbps 配置限速

只读核验显示：

- service 11 的带宽上限为 `500 Mbps`。
- client 3 没有单独的 `speed_limit_mbps`。
- point10 没有 `total_speed_limit_mbps`。
- bond contract 使用 `disable_local_rate_limit=true`。
- 历史 `8094` 监听者是 `leikwan-bond`，不是 `leikwan-rate-limit.py`。

所以约 `194-200 Mbps` 不是已发现的 panel/runtime 显式限速。公共物理入口、运营商限速、共享 CNIX 容量或主机处理上限仍需实测排除。

### 2.5 `latency_best` 是重要线索，但不能单独给本次 `-R` 下载定根因

现场保留了 `forward118` 的 strategy 文件，值为：

```text
latency_best
```

installed renderer 会生成：

```text
--strategy-file .../118.strategy --default-strategy latency_best
```

当前最新 `leikwan-bond` 源码明确规定：

```rust
ClientStrategy::SpeedMax => calculated,
ClientStrategy::LatencyBest | ClientStrategy::Random => TcpStripingStage::Sticky,
```

`Sticky` 最多使用一条 leg。因此对客户端正向上传流量而言，`latency_best` 会使一条 TCP stream 保持单 leg，bond 与 single 接近属于策略预期。

但是本次命令使用了 `iperf3 -R`。在当前最新源码中，反向数据由 aggregator 建立 upstream stream 时使用：

```rust
ClientStrategy::SpeedMax
```

所以不能把残留 `118.strategy=latency_best` 直接当成本次反向下载同速的已证实根因。

此外，当前 sh36 安装的 classic bond binary 为旧版本 `0.1.32`，其 hash 与当前 panel bond artifact 不一致。历史 forward118 测试没有在报告中保留完整 binary hash、版本、进程命令行、striping stage 与 per-leg bytes，因此无法确认历史 runtime 是否与当前源码具有相同的反向 `SpeedMax` 行为。

准确判断是：

```text
latency_best 已证明会影响正向上传聚合；
对于本次 -R 下载，它是 runtime/版本核验线索，不是已闭环根因。
```

### 2.6 当前剩余的 classic TCP bond 根因候选

需要在同拓扑复测中区分：

1. 历史运行 binary/config 与当前源码不一致，反向流量实际没有进入预期的 bulk `SpeedMax` striping。
2. 多个逻辑 leg 中只有一条在 bulk 阶段真正 active/available。
3. 多条 leg 共享 sh36、CNIX 或其他约 `200 Mbps` 的公共物理瓶颈。
4. classic bond 的 ordered reassembly、ACK、重传和 head-of-line blocking 抵消了多 leg 叠加收益。
5. 主机 CPU、softirq、socket buffer、NIC 或内核队列在约 `200 Mbps` 附近饱和。

报告中 bond 的 `427` 次 TCP retransmit 明显高于 single 的 `54` 次，支持继续检查 classic bond/路径质量，但单凭该计数仍不能在第 2、3、4 项之间定因。

## 3. 对两份 UoT 文档的评价

### 3.1 `findings20260808.md`

文档提出的 UoT 候选机制是合理的：旧 scheduler 只看到本地 queue/socket write，无法准确知道远端是否已解码并提交 UDP datagram；跨独立 TCP leg 逐包条带化会把延迟差暴露给 WireGuard 与内层 TCP。

但“核心判断正确”的措辞过强。当前证据最多支持：

```text
UoT 根因方向得到源码事实、本地模型和 netns 相对改善支持；
真实物理 LeiGod/CNIX 生产根因仍未证实。
```

该文档自身也承认：

- 300 Mbps 正式 netns 验收失败。
- 尚未在物理 slave/sidecar 与实际 LeiGod/SOCKS 路径完成 paired 验收。
- production artifact 已发布但 feedback 配置未激活。

### 3.2 `maybesolution1.md`

以下设计方向合理：

- remote UDP acceptance progress；
- root pacing；
- per-session DRR；
- bounded queue/deadline；
- contiguous chunks；
- per-leg outstanding window；
- predicted finish/finish skew；
- lagging/quarantine/probe；
- drop/error telemetry。

但它仍是候选实现，不是已经解决 HKG `bond ~= single` 的生产结论。

另外，UoT 默认 `aggregate_rate_bps=300000000`。即使 scheduler 正式启用，该默认 wire pacer 也不能用于证明 `400 Mbps useful throughput`；仅按 1200-byte payload 加 24-byte UoT framing 计算，400 Mbps useful 已至少需要约 408 Mbps wire pacing，实际还要考虑 WireGuard、IP/TCP 与其他开销。

## 4. QA artifact 审阅结果

### 4.1 300 Mbps 正式 paired netns

正式 verdict：

```text
formal_eligible                 true
all_passed                     false
feedback useful rate           225.871 Mbps
feedback receiver loss         24.4102%
feedback scheduler drop        2.5185%
feedback deadline drops        32,216
feedback queue p99             20 ms
legacy useful rate             194.960 Mbps
legacy receiver loss           34.7121%
```

该结果证明 feedback 相对 legacy 有改善，但没有达到绝对门槛，不能称为正式通过。

原始 stats 还表明 300 Mbps traffic sender 发出的 datagram 数明显高于 client runtime 实际读到的 datagram 数，主要缺口发生在 scheduler admission 之前，符合本地 WSL UDP ingress/socket buffer 饱和方向；这仍需在物理 Linux 主机复测。

### 4.2 240 Mbps 非正式稳定点

```text
formal_eligible                 false
all_passed                     true
useful rate                    239.943 Mbps
deadline drops                359
scheduler drop                0.0239%
```

该 run 约 `98.37%` 的发送 packet 集中在单一 leg。它证明当前环境中一条 leg 足以稳定承载约 240 Mbps，不证明五 leg 带宽叠加。

同时，`tools/uot-feedback/local_netns_run.py` 对所有非正式 run 直接写入：

```python
"formal_eligible": False,
"all_passed": True,
```

这会让任意非正式 run 在未经过门槛判断时显示绿色并返回成功，应单独修复。当前 handoff 虽然没有把 240 Mbps 冒充正式 300 Mbps 通过，但不能引用该 `all_passed=true` 作为验收证据。

## 5. 寻找与验证过程

### 步骤 1：确认仓库和提交边界

- 当前工作目录是 `C:\Users\frankniubi\Downloads\leikwan-br1`。
- `leigod-forward` 本地目录没有 `.git`；它包含 manager/wrapper、binary 与 handoff 副本。
- UoT feedback 实现位于隔离 worktree：

```text
C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\uot-feedback-scheduler-20260808
```

- worktree HEAD 为 `8f0ec3148774cd3feeccaf5c41bc23732958645e`。
- 该提交比 `master=11550550b3c6c36d0878443c79dd5814aa260a48` 多一个提交。
- worktree 只有未跟踪的 `artifacts/qa/`，没有修改现有 tracked 文件。

### 步骤 2：读取三份 handoff 与 WG 测试报告

逐项提取了：

- 测试方向、命令、目标、forward、route、combo 与 sidecar point；
- bond/single 是否同拓扑；
- UoT artifact 发布和 runtime 激活边界；
- 300 Mbps 与 240 Mbps verdict；
- TYO reset/cooldown 与 HKG retransmit 证据；
- 已清理的 forward/runtime 与仍保留的 catalog 状态。

### 步骤 3：区分 classic TCP bond 与 UoT sidepath

沿代码确认：

- `tcp+udp` forward 中，classic TCP primary 仍由 `leikwan-bond` 承载。
- UoT feedback scheduler 只用于额外的 UDP/WireGuard sidepath。
- panel 仅为 `bond + transport 含 UDP + plain_tcp_mux` 生成 `udp_uot_sidepath`。
- `scheduler_mode` 默认是 `legacy`；只有已有配置显式为 `feedback_drr` 才会保留。
- `feedback_drr` 还要求 unordered delivery、`wireguard_aware=true`、Rust native runtime、protocol v2 与 `leg-progress-v1`。

### 步骤 4：追踪 classic TCP 的 strategy 与方向

先发现 client renderer 默认生成 `latency_best`，并在 core 中被限制为 sticky 单腿。初步曾把它当作 `bond ~= single` 的直接解释。

随后按 `iperf3 -R` 方向继续追踪，发现当前源码中 aggregator 侧的 upstream stream 硬编码为 `SpeedMax`。因此撤回“`latency_best` 已直接解释本次反向下载”的过强判断。

最后结合现场 binary mismatch，结论收紧为：必须记录历史/重建测试的实际 binary hash、direction、strategy、stage 和 per-leg bytes，不能只从 strategy 残留或最新源码单独倒推历史 runtime。

### 步骤 5：审阅原始 QA JSON

读取了 formal/stable verdict、client stats 与 sidecar stats，核对：

- formal eligibility；
- absolute/pair failures；
- offered/admitted/scheduled/remote accepted 生命周期；
- per-leg sent packets 与 delivery rate；
- feedback/legacy active leg 数；
- queue、deadline、loss、fairness 与 UDP errors。

由此识别出：

- 300 Mbps 正式失败；
- 240 Mbps 非正式 run 主要由单 leg 承载；
- `all_passed=true` 来自非正式 runner 的硬编码，而非正式阈值通过。

### 步骤 6：生产环境严格只读核验

按项目约束通过 WSL 与合法 slave hop 核验 panel、sh36、四个 HKG slave 和 point10，没有使用 client 直连 sidecar。

核验内容包括：

- route/combo/forward 状态；
- historical forward118 transport 与监听者；
- authoritative agent config；
- UoT unit/process/config/stats；
- installed agent/native binary identity；
- strategy 文件；
- service/client/sidecar speed limit；
- 当前 active forward 数量。

现场没有执行配置修改、服务重启或测速。

### 步骤 7：本地测试验证

执行结果：

- `cargo test -p leikwan-uot`：共 78 项 unit/integration/load 测试通过。
- 普通 `cargo test -p leikwan-bond latency_best` 命中已知 rustc warning-rendering ICE。
- 使用 `RUSTFLAGS=-Awarnings cargo test -p leikwan-bond latency_best` 复跑：6 项通过。
- `leigod-forward` 的 classic bond script 渲染聚焦用例：1/1 通过，确认其 wrapper 使用 `--default-strategy speed_max`。
- `leigod-forward` 两个完整 pytest 文件组合运行在 124 秒超时，未得到完整 suite 结论。

## 6. 下一轮正确验收方案

### 6.1 classic TCP bond 测试必须先独立完成

创建隔离 HKG shadow forward，固定：

- 同一个 sh36；
- 同一个 point10；
- 同一个目标 iperf server；
- 同一组 slave/endpoint；
- 同一份 pinned `leikwan-bond` binary；
- 同一 target port 与测试时长；
- 不与其他测速共享 iperf target。

顺序执行：

```text
A: 同拓扑 one-leg bond
B: 同拓扑 two-leg bond
C: 同拓扑 four-leg bond
D: 重复 four-leg bond
E: 重复 one-leg bond
```

正向与反向分开：

```bash
iperf3 -c 127.0.0.1 -p <port> -P 1 -b 400M -t 60 -i 1 --json
iperf3 -c 127.0.0.1 -p <port> -R -P 1 -b 400M -t 60 -i 1 --json
```

每轮必须同步保存：

- local/remote binary SHA-256 与 `--version`；
- 完整进程 command line；
- strategy 文件与 effective strategy；
- `Sticky/Warmup/Bulk` stage transition；
- 每 leg sent/accepted bytes、inflight、pending 与 active state；
- `/run/leikwan/bond-leg-health-forward-<id>.json`；
- `ss -tinp` 的 retrans/cwnd/rtt；
- CPU、softirq、NIC、`tc -s`、socket 与内核 drop；
- iperf3 原始 JSON 和逐秒区间。

### 6.2 单独证明公共约 200 Mbps 瓶颈

再建立两条独立 one-leg 路径并发下载到 sh36，目标使用独立端口：

- 若两条流合计仍约 200 Mbps，公共 sh36/NIC/provider/CNIX 入口瓶颈成立。
- 若两条独立流合计明显超过 200 Mbps，而 four-leg bond 仍约 194 Mbps，则 classic bond 数据面成为主要嫌疑。

### 6.3 UoT feedback 必须作为另一组测试

只有 classic TCP 结论完成后，再单独执行：

```text
same topology legacy UoT
vs
same topology feedback_drr + unordered + wireguard_aware
```

要求：

- `feedback_capable_active_legs == active_legs`；
- `legacy_fallback_active_legs == 0`；
- receiver loss `<= 0.5%`；
- queue p99 `<= 10 ms`；
- scheduler drop `<= 0.1%`；
- deadline drops `== 0`；
- UDP/ingress/control errors `== 0`；
- 至少两条 leg 有实质数据量，不能只看 active leg 数量。

## 7. 建议的后续代码修正

1. 修正 `tools/uot-feedback/local_netns_run.py`：非正式 run 也必须执行明确阈值，不能无条件 `all_passed=True`。
2. 测试报告必须记录 `transport_mode`、direction、binary identity、strategy、striping stage 与 per-leg bytes。
3. UI/控制面应明确区分：
   - `latency_best`：优先单腿低延迟；
   - `speed_max`：允许 bulk 多腿吞吐聚合。
4. 不要用 UoT scheduler 去宣称修复 classic TCP bond。
5. 在 binary/runtime 不一致时 fail closed 或至少阻止正式性能验收。

## 8. 相关文件

```text
C:\Users\frankniubi\Downloads\leikwan-br1\docs\2026-08-08-leigod-sidecar-wg-test.md
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\findings20260808.md
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\maybesolution1.md
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\2026-08-08-uot-feedback-scheduler-production-deploy-handoff.md
C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\uot-feedback-scheduler-20260808
```

## 9. 最终状态（原始）

```text
问题记录：已完成
答案记录：已完成
寻找过程：已完成
生产变更：无
当前根因：尚未闭环
下一步：同拓扑 classic TCP one/two/four-leg paired 测试
```

---

## 10. 根因已闭环 — 2026-08-09 补录

**状态：`TCP_BOND_THROUGHPUT_ROOT_CAUSE_CLOSED`**

### 10.1 根因

`forwards.public_port = 443` 是 HKG bond ≈ single 的直接根因。

面板 `ProvisioningService.php` 第 5416 行存在以下守卫：

```php
$sharedSidecarRequired = $sidecarRequired
    && strtolower(trim($backendMode)) === 'current_mode'
    && $this->staticSidecarUsesSharedWireguardPeers($bondSidecarPointId);
if ($sharedSidecarRequired && $publicPort === 443) {
    throw new ApiException('shared_sidecar_public_port_forbidden', 422, [...]);
}
```

forward 82（以及历史 forward 118 所在的同一 combination 13 / sidecar 10 路径）的 `public_port = 443`，满足 `current_mode + shared-WG-peer sidecar + port 443` 三个条件，触发 `shared_sidecar_public_port_forbidden`，使 3 条 slave endpoint 的 ingress auth token 始终无法通过 `reconcileForwardEndpoints` 生成。

只有 node2（endpoint 4122）持有一个历史遗留的 auth token，bond 实际以 **单腿** 运行，吞吐量自然与 single-path 相同（约 194 Mbps）。

`runtime_preference = 'single'`、`latency_best` strategy、binary mismatch 等均为旁证线索，不是直接原因。

### 10.2 验证方式

在面板 VM（202.6.204.184）执行：

1. `UPDATE forwards SET public_port = 7002 WHERE id = 82`（其余同 combination 13 的已知可用 forward 均使用 7002）。
2. `UPDATE service_endpoints SET health_status = 'pending' WHERE forward_id = 82`。
3. 调用 `ProvisioningService::republishActiveForwardsForCombination(13)` → 成功，无 `shared_sidecar_public_port_forbidden`。

面板随即为全部 4 个 slave endpoint 生成了独立的 ingress auth token，生成的 `/usr/local/bin/leikwan-client-start-82.sh` 包含 4 条 `leikwan-ingress` 命令（node2/3/19/20，各自 auth-token 独立）。

sh36 上 `leikwan-client-82.service` 重启后，4 条 ingress TCP 全部进入 ESTABLISHED 状态。

### 10.3 修复后实测（面板托管，leikwan-client-82.service）

测试条件：

- binary：`leikwan-bond 0.1.32`（sha256 `8a827375…`）
- strategy 文件：`speed_max`（注意历史测试为 `latency_best`，本次以 `speed_max` 测试聚合能力）
- 4 条腿全部 healthy：ep 4122/4123/4124/4125 = node2/3/19/20
- 目标：`v4-hkt-b.mcbar.cc:5201`，iperf3 in sidecar 10 (yt-hk-a) → 219.77.234.12
- sh36：2 vCPU，负载轻

| 方向 | 发送侧 Mbps | retransmit | 接收侧 Mbps |
|------|------------|-----------|------------|
| FWD（上传，client → target） | **434.1** | 579 | 428.0 |
| REV（下载，-R，target → client） | **744.3** | 33,060 | 737.9 |

历史基准（错误配置，仅 1 腿实际生效）：约 194 Mbps（两方向均如此）。

修复后 4 腿：上传 **2.2×** 提升，下载 **3.8×** 提升。

反向重传数 33,060 偏高，尚未单独调查（与 bond 聚合本身的有序重组 / ACK / 头阻塞相关，非本次根因排查范围）。

### 10.4 其他重要发现

1. **panel DB 中不存在 forward 118**：IDs 从 114 跳至 119，118 已被删除（或从未存在于此 DB）。历史测试所在的 forward 与 combination 结构已通过 forward 82 重建复现。

2. **sidecar 10（yt-hk-a）状态良好**：4 条 WireGuard 隧道对应 node2/3/19/20 全部 ESTABLISHED，hanshake age 数十秒，rx/tx 各自数 GB，处于活跃生产使用状态。

3. **`shared_sidecar_public_port_forbidden` 未在面板 UI 暴露**：panel 在创建/编辑 forward 时没有将该错误转为用户可见的 validation 提示，只在 PHP error_log 中静默丢弃，导致配置错误长期无法被发现。

4. **node3（49.235.160.246）在频繁进程重启后积累了 1800s cooldown**：重启 service 后清除，4 腿恢复正常。cooldown 时长 `--leg-cooldown-ms 1800000` = 30 分钟，在调试期间会影响多腿实验。

### 10.5 建议修复（补充至第 7 节）

5. **面板 UI 应拦截 `public_port = 443` + `current_mode + shared-WG-peer sidecar` 组合**，在保存 forward 时提前报 validation error，而不是在 `reconcileForwardEndpoints` 中静默失败。

6. **`republishActiveForwardsForCombination` 的内部错误应输出到可观测日志**，当前仅写 `error_log()`，运维无法得知哪些 forward 的重发布静默失败。

### 10.6 最终状态

```text
问题记录：已完成
答案记录：已完成（根因已闭环）
寻找过程：已完成（见步骤 7-10）
生产变更：
  - forwards.public_port: 443 → 7002（forward 82）
  - service_endpoints.health_status: deleted → pending（4 条）
  - 面板 republishActiveForwardsForCombination(13) 执行一次
  - leikwan-client-agent 服务临时停止/恢复（调试期间）
  - leikwan-client-82.service 临时停止/恢复（调试期间）
当前根因：已闭环 — public_port=443 导致 shared_sidecar_public_port_forbidden，3 条腿无 auth token，实际单腿运行
下一步：无（如需进一步评估 bond 多腿聚合上限或反向高重传，需单独立项）
```
