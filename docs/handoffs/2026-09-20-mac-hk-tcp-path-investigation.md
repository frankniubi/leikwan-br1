# Mac → HK TCP path 调查

> 后续纠正：本文件保留早期调查记录，但“Mac session 上报滞后”和“line17 是系统性根因”的末段推论已被新证据修正。旧查询曾匹配到历史 session；当前测试 Mac 是 session 333。用户 tgz 中 line17、line447 均有零回包 reset，且旧版 downstream 字节计数不能区分远端未回复与本地写失败。应以 `2026-09-20-mac-tcp-log-fix-validation.md` 的复核和验收结果为准，不能把所有 EOF 当作线路故障。

日期：2026-09-20；下述时间均为 UTC+8。

## 结论

**已定位历史失败阶段，尚未定位发出 reset 的具体网络节点；本轮没有复现 forward 失败。**

- 原 10 分钟并发测试中，Mac HK 的 18 次 `headers_eof`，结束时间逐一与本机运行时 `reset`、`closed`、`no_response` 事件相符（±25ms，18/18）。
- 这不是“收到错误 HTTP 响应”，而是尚未收到任何下行业务字节时 TCP 流结束。`no_response` 的代码条件说明已向上游 socket 写入数据，但不能据此证明数据到达目标服务。
- 失败期间 Mac 最近选择的候选为 line17；17:19:40 最近选择变为 line447，此后原测试没有再失败。同期 Linux 使用 line447，没有失败。这是候选路径与故障的强时间关联，不是物理故障点证明。
- 新一轮 Mac/Linux TCP 请求对照中，两者均使用 line17，所有 forwarded 请求成功。因此不能声称 line17 永久损坏、Mac 固有缺陷，或问题已经修复。
- Ultra 业务 TCP 经 provider relay 到目标，不经 external-UoT UDP 处理逻辑。本次证据不支持将这些 TCP EOF 归因于 UoT 会话互踢；也不能由此排除目标所在主机或公共网络故障。

## 历史失败与候选

原测试窗口：17:14:12–17:24:12；Mac HK TCP 520 成功、18 失败。

- 失败请求开始：17:18:55.586–17:19:38.614。
- 每次约 1.022–1.035 秒后 EOF，尚未收到响应头。
- 最后一次失败完成：17:19:39.649。
- Mac 最新 TCP 选择于 17:19:40 切到 line447。
- 候选 `40ac894c610f…` → line17；`3af46bfb8094…` → line447。
- 当前面板映射均属于 source-home-171 / Guangzhou → Hong Kong，协议 tcp+udp。

限制：历史状态为周期采样，HA 事件没有完整逐流方向信息；事件时间匹配不能替代双端抓包。现存 Mac 日志未保留可用的逐流 `remote_stopped_first` / 字节数诊断记录，不能确定 RST 由 provider、目标还是中间网络产生。

## 代码路径解释

核查基线为 `master`（`52d81766`），未改动产品代码或候选策略。

- `tools/ultra-client/ha_candidates.go`：候选在拨号失败时可切换；拨号成功后交给业务 relay。
- `tools/ultra-client/ha_tcp.go` / `relayTCP`：双向复制；复制异常会关闭流两端。
- `noteRelayOutcome`：短时间内有上行、无下行时记录 `no_response`，但保持候选可用。协议未知时，无响应本身不够证明 relay 不健康。
- `tools/ultra-client/udp_probe.go` / `probeTCPLease`：验证 `dialStreamConfig` 成功后关闭，不读取实际应用响应。
- `tools/ultra-client/main.go`：默认 TCP 探测目标为 `1.1.1.1:443`，并非此次业务目标。

这解释了为什么业务请求短暂失败时，候选仍可能保持 healthy：当前探测证明的是指定探测目的地的建链，不是实际目标的应用请求成功。**这是一项健康覆盖限制，不等于已证明客户端代码是故障根因。**

## 新一轮带请求编号的 TCP 对照

发起窗口：17:59:21–18:03:21，240 秒；最后一个 Linux 直连请求完成于 18:03:29.874。

- 临时恢复 client3 的 HK 测试 forward #198，监听 60151。
- 每台主机两个 forward worker、一个直接访问自有 TCP 测试目标的 control worker。
- 每个 worker 最多每秒一次请求，响应体 33,600 字节；校验请求编号与完整内容。
- 目标端记录 accept / request / sent / closed，以请求编号关联，避免依赖跨主机时钟完全一致。
- 直接 control 访问的是独立 TCP 测试服务，不是 UoT sidecar 服务。

| 路径 | 成功 | 失败 | 目标收到请求 | 成功请求耗时中位数 |
|---|---:|---:|---:|---:|
| Mac → HK forward | 478 | 0 | 478 | 81.561 ms |
| Linux → HK forward | 480 | 0 | 480 | 165.529 ms |
| Mac → TCP 目标直连 | 228 | 0 | 228 | 805.262 ms |
| Linux → TCP 目标直连 | 4 | 14 | 16 | 6561.682 ms |

两台客户端有效候选样本均为 line17。全部 958 个 forwarded 请求均有目标 request/sent 记录，以及客户端完整响应校验。

Linux 直连失败单独列示：2 次 connect timeout、1 次 headers timeout、11 次 body timeout。除 connect 失败外，目标均记录 request/sent，但服务端 write 成功不等于客户端收到响应。该直连路径异常不能算作 tunnel 失败，也不能解释原先 Mac-only 的故障。

**本轮是 4 分钟轻量 TCP 定位测试，不是重跑原来 600 秒、每路 20Mbps UDP 同时加载的验收，也不是修复验证。**

## 清理与变更范围

- #198/#199 均已禁用，面板读回确认；未更改其它 forward。
- 临时 `mac-tcp-path-target.service` 已停止，目标 TCP 60051 无监听。
- 回收定时器已停止。
- Mac 测试监听已消失，主应用仍为 PID39511、启动于 2026-09-19 16:57:18。
- Linux 初次清理读回仍有 60151；后续再次检查已无监听，agent 为 active。
- HK external-UoT 服务仍 active，PID1045490；本轮没有重启 sidecar。
- 没有部署修复、升级二进制、强制剔除候选或提交/push。

## 下一步定位条件

1. 若复现，保留逐流 candidate/lease/path、两个方向结束顺序和字节数，并对自有目标同步记录请求编号。
2. 在原并发负载下重测，必要时分别固定 line17/line447 做 TCP 对照；目前没有执行这一轮。
3. 若目标未见请求，调查 provider relay 到目标之前的路径；若目标已处理，则调查回程和 relay 转发。要锁定 RST 来源，需要对应连接的两端捕获或 provider 日志。
4. 不对已发送业务 payload 自动重放，避免重复非幂等操作；也不因 TCP 失败整条封禁 UDP 候选。

## 对“客户端上报、服务器可用性、线路打分”的当前验证

### 客户端上报

- 本机 Mac status 文件是实时更新的：当前 Mac arm64 runtime fingerprint
  `2026.09.19.1.f18d01fb`、self SHA 已读到；forward 171 的 status 中有
  `ha_failures=681`，并持续产生 TCP `eof` / `broken_pipe` 事件。
- 但面板 `client_device_sessions` 中同一 Mac self SHA 的最近记录仍是
  `ultra_status_updated_at=2026-09-20 07:53:36 UTC`，而本机 status 已更新到约
  `10:28:57 UTC`。因此“客户端本地上报文件有数据”已证实，**“这些最新数据已被服务器接收”未证实，当前读回反而显示 Mac 会话上报滞后**。
- Mac 日志同时出现 `policy_generation_invalid`、`lease_device_live_limit` 的 409 刷新失败；
  这会妨碍控制面刷新和服务器看到最新状态，但没有证据证明它直接制造了历史 18 次 EOF。

### 服务器测试可用性

- 本轮自有 HK TCP 目标的服务端日志对 Mac forward 478/478、Linux forward 480/480 均记录
  `request` 和 `sent`；客户端也校验了完整 33,600 字节响应。
- 因而“测试服务可用、forward 数据面当前可通”已证实。
- 但客户端选择探针仍只对 `1.1.1.1:443` 做 TCP 建链并立即关闭，不能证明具体业务目标的响应可用。Linux 直连控制路径 14/18 失败、且目标多数已记录发送，说明独立公网回程本身也可能抖动；不能把它算作 tunnel 失败。

### 线路打分／信誉机制是否生效

- 合约测试全部通过：`ultra_candidate_feedback_contract_test.php`、
  `ultra_candidate_reputation_contract_test.php`、`ultra_client_status_contract_test.php`。
- 生产 DB 读回显示机制对**显式探针结果**确实在工作：line17 的 `tcp+udp` 记录
  `consecutive_failures=0`、`consecutive_successes=4784`；line447 为
  `0 / 3612`。失败/成功事件均以候选 hash、lease hash、observer 及 evidence 关联。
- 但是历史 Mac EOF 时间窗内，line17 信誉事件只有 `canary_probe_ok`，没有对应的
  TCP `failure`；客户端的 `no_response` 只写入 `ha_events`，`noteRelayOutcome` 明确
  “keeping candidate eligible”。因此**打分机制对探针失败起正面作用，对本次已发送
  payload 后的 EOF 没有起作用**，这正是 line17 没有被降权/隔离的原因。

### 根因判定

当前最完整的因果链是：

1. Mac 当时选中的 line17 业务 TCP 路径发生短时 reset/no-response，导致 EOF；
2. Mac 运行时记录了本地 HA 事件，但普通用户流没有转成候选 failure；
3. Mac 最新 status 又没有及时到达面板，服务器无法获得完整实时证据；
4. 面板信誉因此继续看到探针成功，保持 line17 healthy，不能对该类业务 EOF 做正向避让。

所以，**数据面根因高度指向 line17 时段性路径故障；系统性根因则是“业务流 EOF 未进入线路信誉闭环 + Mac status 上报滞后”**。具体 RST 发出节点仍需 provider/两端抓包才能定案。

## 本地证据

目录：`tmp/mac-tcp-path-20260920/`

- `historical-failure-event-correlation.json`
- `historical-candidate-timeline.json`
- `candidate-map.txt`、`panel-candidate-events.txt`
- `mac-requests.jsonl`、`linux-requests.jsonl`、`target-events.jsonl`
- `analysis-summary.json`、`cleanup-readback.json`
- `target.py`、`client_probe.py`、`analyze.py`

证据和测试令牌保持本地；报告不含认证信息。
