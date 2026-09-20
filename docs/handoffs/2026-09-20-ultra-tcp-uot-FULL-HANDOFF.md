# Ultra TCP / UoT 完整交接：暂停调查，未完成业务修复验收

整理日期：2026-09-20。历史证据同时使用 UTC 与 UTC+8，以下逐处标明；文件名日期不用于推断事件顺序。

**用户最新指令：停止继续消耗，只做完整交接。修复目标已设置为 `paused`。未经用户重新要求，不再部署、SSH 探测、重启、压测、开 agent 或自动续跑。**

本文件汇总本地现存报告、部署回执、测试日志及子 agent 结果；整理交接时没有重新连接生产环境。以下“最后确认”是证据采集时的状态，不保证之后没有外部变化。

## 1. 接手人先读的结论

1. **原始业务问题尚未闭环。** 用户报告广港/广新交替掉线，且把面板 `TCP+UDP` 改成 `TCP` 后恢复。这个观察值得优先验证，但还没有完成同候选、同材料、同目标、只改变 UDP 因素的严格对照。
2. 已找到并修复若干真实代码缺陷及 sidecar 授权配置缺陷；**不能把“修复了缺陷”写成“证明了它导致所有 EOF / 已解决互踢”。**
3. 最后有部署回执及 Panel 回读的测试 Mac helper 是 **`2026.09.20.6.52d81766`**。`.7` 只有本地构建和测试日志，**未部署、未回读、未做业务验收**。
4. Panel 已部署 `external_uot` lease 字段传递修复、状态接收修复；Mac 四个运行实例已回读 `udp_transport=external_uot`。这不是所有用户设备均已升级。
5. 六台 sidecar 服务与本机 UDP echo 通过；修复东京/洛杉矶缺少的三个 forward binding 后，40/40 规则实时 HELLO 准入通过，包括全部 17 条启用规则。**这不是经过 provider relay 的全方向端到端验证。**
6. 历史 600 秒 TCP-only 测试仍有 **400 次 `headers_eof`，判定 FAIL**。最后一次小流量 canary 在 SG 管理 SSH 阶段失败，未开始业务流量；不能说最新 `.6`/`.7` 已通过长测。
7. 无公共客户端发布，无本轮已证实的 Windows/Linux 新版部署或全方向验收，无本轮提交/push。工作区有大量混杂修改，不能整体提交。

## 2. 原始要求、关键事实和不能假设的事情

### 用户要求及线索

- 广港在线则广新掉线，反之亦然；要求查关闭会话原因、Mac TCP path、EOF 是否由特定线路造成。
- client3 增加 HK/SG forward，Mac + Linux 同时测试，10 分钟、20Mbps。
- 用户提供诊断包：`C:\Users\frankniubi\Downloads\Telegram Desktop\LeiKwan-diagnostics-20260920-191715.tgz`。
- 修复后还要对所有具备 external-UoT 能力的方向测试，不能只测 HK/SG。
- 用户实测面板模式改为 `TCP` 后可用，因此要求先核实 Ultra rules 的 UoT 配置，沿 UDP 探测/共享状态污染方向剥离。
- 用户明确指出：**旧/过期的 leigod 下发信息在以往 TCP 测试中仍可用。不得把 Panel TTL 过期直接当作 provider 拒绝连接的原因。**

### 环境和访问规则

- 项目：`C:\Users\frankniubi\Downloads\leikwan-br1`。
- 交接时实际分支：`fix/ultra-probe-single-reader`；HEAD：`b345d8a8276efde6dff20ec2686cafcfde450cd1`。
- 项目要求新工作默认以当前 `master` 为基线；历史构建标识含 `52d81766`，**不能由此认定整个脏工作区等于该 commit**。接手前先核对 ancestry 和文件级 diff；不要自动切分支、reset、clean、覆盖用户改动。
- Ultra 普通 TCP：`client -> leased Ultra provider relay -> target`。
- External-UoT：`client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`。
- **不得引入 LeiKwan slave/CDN，也不得读 cantons/shanghais 凭据用于 Ultra 调查。** 管理访问优先 WSL；sidecar 直接管理访问。
- `uot-sidecars.txt` 为五台 sidecar 清单，Tokyo 另见 `leikwan-v3.txt`。敏感值只留本地，不能复制到本文件或提交。
- 用户已提供 source 管理入口：`root@10.10.10.171`，经 `root@180.153.83.218 -p 2125`。口令见原会话，不在交接文档复写。旧报告中“source 管理入口未知”的描述已过时；**有入口不等于本轮已 SSH 核查 source 部署或 provider 会话行为**。

## 3. 做了什么：按阶段与证据分开记录

### A. 第一次 HK/SG、Mac/Linux 并发测试

- 窗口：2026-09-20 17:14:12–17:24:12 UTC+8，测量 600 秒，另有排空期。
- 新建 client3 / service30 测试 forward：HK `198` / 本地 `60151`；SG `199` / 本地 `60152`。
- **每路 UDP 20Mbps，同时 TCP 控制目标仅 1Mbps**，不是 TCP 20Mbps 验收。
- 四路 UDP 均收齐 1,250,000 包，零重复/无效包；吞吐约 19.99Mbps。
- TCP：Mac HK 520 成功 / 18 失败；Mac SG 505/0；Linux HK 511/0；Linux SG 480/0。
- 18 次 Mac HK 失败都是 `headers_eof`，UDP 同期持续工作。未复现 UDP 会话相互踢下线。
- target 与 sidecar 同机，未覆盖第三方 UDP 目标末跳；未强制 reliable datagram，未做 raw Internet UDP 基线。
- 测试后 198/199 禁用，测试端口和 target 清理；测试记录和 sidecar binding 保留。
- 当时常规停用 API `setForwardEnabled(false)` 经 `buildClientConfig()` 出现 `forward_not_found` 并回滚；最终严格核对 ownership 后，仅事务停用 198/199 并递增 revision。**这个 API 异常未修复。**
- 证据：`2026-09-20-client3-hk-sg-concurrent-600s.md`、`tmp/hk-sg-client3-20260920/`。

### B. TCP 路径定位及用户日志分析

- 历史 18 次 EOF 与本机 reset/closed/no_response 时间相符；line17 到 line447 切换与恢复存在时间关联，但无逐流完整因果证明。
- 后续 240 秒轻量对照中，Mac/Linux 都用 line17，forwarded 请求合计 958 次全部成功。不能认定 line17 永久坏。
- 用户 tgz 的 forward185 在 line17、line447 **两者**上都出现零下行 reset。
- 旧 `copyInbound` 在本地 Write 成功后才累计下行；`downstream=0` 也可能是本地写失败，不能直接表示“远端未回任何字节”。
- 240 秒中的 Linux 直连目标控制路径有超时，和 forward 路径独立记录，不能归算 tunnel 失败。
- 解包：`tmp/diag-tgz-20260920-a/LeiKwan-diagnostics-20260920-191715/`。

### C. TCP 业务反馈、心跳、恢复退避修复

- `copyInboundObserved` 分开远端 ReadBytes、本地 WrittenBytes 及读/写/策略错误；处理短写、零进度及部分写失败。
- 取消“本地先结束 25ms 内仍算远端先结束”的宽松判断，采用严格结束顺序。
- 仅明确远端先 reset、已发请求、未收到远端字节等窄条件触发本 forward 的 TCP 局部失败/避让。
- 普通 EOF、本地取消、目标业务拒绝、收到响应后的 EOF 不升级为全局线路惩罚；不自动重放已经发送的业务 payload；保留 half-close。
- 补 Windows Winsock10054 识别。
- Desktop 心跳独立发送当前 `ultra_status` / `runtime_attestation`，过滤过期/停止状态；历史 usage 不覆盖当前身份。
- Panel 接收回执 `ultra_status_accepted`，保存有界 HA 失败历史及 TCP 上下文字段。
- forward 启动失败退避 30/60/120/240/300 秒；自动 ensure 不再冒充 `manual_apply` 清冷却；手动重试及 contract 变更另处理。
- **部署过程中曾引入身份回退问题**：首次读到 9 月 12 日历史 native-usage 身份，导致 `lease_runtime_unattested`。随后修正选择逻辑，只对当前 Mac session333 使用官方 `clearRuntimeAttestation` 恢复；未关闭 enforcement。曾临时移走的历史 usage 已恢复，备份保留。
- `.NET Core 591/591`、Mac headless 13/13、Windows/WSL/Mac 的若干定向 Go 回归通过。Go 全套曾在 `TestGameSim` 120 秒超时，**全套不算通过**。
- 详见 `2026-09-20-mac-tcp-log-fix-validation.md`。

### D. 600 秒 TCP-only 实测：仍然失败

窗口：2026-09-20 20:27:28–20:37:28 UTC+8；每平台 HK/SG 同时，目标每路 TCP 20Mbps，8 workers。

| 平台/方向 | 成功 | 失败 | 实收 Mbps |
|---|---:|---:|---:|
| Mac HK | 4425 | 37 | 19.82400 |
| Mac SG | 4443 | 18 | 19.90464 |
| Linux HK | 4446 | 11 | 19.91808 |
| Linux SG | 4129 | 334 | 18.49792 |

- **判定 FAIL。** 400/400 失败均为 `headers_eof`，目标无相应 request/sent 记录；成功请求全部对齐。
- 只能把范围缩到目标 HTTP handler 之前，不能区分 provider 关闭、请求不完整、本地取消或网络路径。
- 该轮发生在 `.4` 诊断 helper 之前，更不是 `.5/.6/.7` 的验收。有限状态历史被正常 EOF 冲掉，无法重建 400 条逐流结束顺序。
- 后续 180 秒 observe 轮在准备阶段出现本地 connection refused，属于 **INVALID setup**，不是有效业务测试。
- 清理证据确认 198/199 禁用、60051 不再监听、sidecar active；SG 一个临时 target unit 留有 failed 状态，不是 sidecar 故障。
- 证据：`tmp/mac-tcp-path-20260920/final-tcp-*`、`failure-correlation-report-FINAL.md`。

### E. TCP active fallback 安全修复（已含于 `.5`，后续 `.6` 保留）

确定性修前反例失败、修后通过：

- active fallback 原来可绕过业务隔离，仅靠握手就清除业务失败；改为业务隔离有效期内不能绕过，收到远端业务字节才恢复。
- dial 中撤销/过期/取消后仍可能返回连接；补返回前核查，晚到连接关闭。
- TCP 全路径收敛原来会清 UDP 失败计数；改为协议隔离。
- 保留探测误判时可用的 fallback，不把候选永久封死。
- Windows 专项、WSL race、Mac 原生专项及 UoT 回归通过；`.5` 在测试 Mac 部署、签名和 session333 回读通过。
- 详见 `2026-09-20-mac-tcp-fallback-safety-fix.md`。

### F. 沿 UDP/UoT 新线索：配置、lease 和健康域

**全规则配置盘点：** 40 条 Ultra rules，其中 17 条启用，均有 UoT enabled/target/token 配置，missing 列表为空。Linux 五份 runtime 配置显示 UoT enabled。注意 native SOCKS5 是独立合同，不能无条件强塞 external-UoT 字段；“全规则有配置”不等于“所有 native/所有设备都实际走同一种 UDP 数据面”。

**找到并部署的 lease 缺陷：**

- `ClientAgentController::resolveUltraLease` 获得含 `external_uot` 的 broker grant，却直接返回不含它的 v2 lease。
- Desktop 从 lease 解析该字段，因此 **Panel 开启 UoT 不保证客户端实际开启 UoT**。
- 新增 `attachExternalUotToLease`，只附加服务器授权且 forward/generation/target digest 匹配的配置；native SOCKS5 不注入不支持的合同；不匹配拒绝。
- PHP syntax、字段传递、endpoint wiring、status/candidate 合约定向回归通过。

**已含于 `.6` 的 UoT 健康域修复：**

- UoT carrier 使用独立 `uot` 域，不再共用普通 `tcp` 失败/恢复域。
- sidecar HELLO 成功不清普通 TCP 业务失败、不覆盖 LatestTCP、不作为普通 TCP 全局成功信誉。
- UoT 失败不隔离普通 TCP/原生 UDP 候选；确定性反例和定向回归通过。
- 新增运行时 `udp_transport` 状态：`disabled/native_udp/external_uot`；Panel 缺字段记 `unknown`，不猜测。
- 14:34 UTC 最后回读：测试 Mac 158/159/170/171 四个实例均 `external_uot`，Panel session333 同步版本与 hash；170 出现 UoT session。**不等于持续业务载荷已通过。**

**另一个已修代码、未部署的分支（`.7`）：**

- `active_candidate_fallback.go` 的 TCP+UDP+UoT 控制面 fallback 曾依赖 sidecar HELLO；sidecar 不可用会否决仍然可用的普通 TCP。
- 已改为 TCP+UDP 场景只用有效 TCP 探测策略；UDP-only 仍要求 UoT 检查。保留授权、取消和结果可用性校验。
- 专项反例修前 FAIL、修后 PASS；本地 `.7` race 日志 PASS，1.068 秒。
- 产物 `tmp/uot-rule-audit-20260920/ultra-client-split7` 已构建；**没有 `.7` deploy receipt 或线上回读，不得称已上线。**

### G. 六台 sidecar 独立核查与修复

| host | 本机 UDP echo | 修复后规则 HELLO | 本轮 binding 修复 |
|---|---|---|---|
| `root@70.36.96.183` | 3/3 | 11/11 | 无 |
| `root@77.93.90.45` | 3/3 | 4/4 | 无 |
| `root@103.246.219.39 -p 27789` | 3/3 | 3/3 | 无 |
| `root@157.228.138.23` | 3/3 | 1/1 | 无 |
| `root@208.122.17.50` | 3/3 | 4/4 | 补 disabled forward196 |
| `root@185.220.238.143` | 3/3 | 17/17 | 补 disabled197、enabled200 |

- 六台服务 active、TCP7443、native `--check-config` 通过；运行版本与 hash 一致。
- 修前 37/40 HELLO 通过；三个缺失 ID 实际返回 `forward is not allowed`。修后 40/40，通过全部启用的 17 条规则。
- 东京/LA 采用配置 CAS、备份、原子更新、独立回滚 watchdog、各一次重启与回读；其他四台在这次 fleet 修复中未重启。
- 东京重启前有 7 条 carrier，会被迫重连；0.305 秒只是服务重启耗时，**不是完整业务恢复时间**。LA 重启 0.032 秒，原无 carrier。
- 历史 HK/SG 建立测试规则阶段另外曾各重启一次，不能把“本次只重启两台”写成“整段调查总共只重启两次”。
- 检查使用新随机 session ID，避免冒用现有会话；全规则准入主要是 HELLO/CLOSE，无 UDP DATA。六台独立 echo 才验证本机 UDP 转发。
- 仍无新 forward binding 自动同步/安全热加载，后续新增规则可能重现缺项。
- 证据：`tmp/sidecar-health-20260920-current/REPORT.md` 及 `admission-before-repair.json`、`admission-results.json`、各主机 repair JSON。

### H. 最新小流量 canary 没有实际运行

- 脚本：`tmp/uot-rule-audit-20260920/run_canary.py`、`canary_endpoint.py`。
- 首次准备时 HK target 启动，随后 SG 管理 SSH handshake 失败（`No existing session`）；尚未启用 198/199，未开始 Mac/Linux 业务请求。
- HK target 已清理，回执 `canary-setup-failed-ssh.json` 的 `tests` 为空、HK cleanup 输出 sidecar `active`。
- 后续脚本已准备调整 setup 顺序和 preflight，但未重跑。
- **SSH 故障不是 UoT 数据面故障，空 tests 不是 PASS。** 整理交接时没有远端重新核查，因此不能额外承诺当前全网无残留任务。

## 4. 最后确认的部署身份、备份与回滚边界

| 组件 | 最后有证据的版本/状态 | 备份/证据 |
|---|---|---|
| 测试 Mac helper | `2026.09.20.6.52d81766`，签名及原生专项通过 | `/Users/leikwan/backups/mac-uot-split-20260920-143311`；`uot-split-deploy-receipt.json` |
| Mac `.7` helper | 只在本地构建，不是安装版本 | `ultra-client-split7`、`go-split7-race.log` |
| Panel 两个 PHP 文件 | lease UoT 字段修复 + UDP 模式状态接收，部署回读通过 | `/root/codex-backups/uot-lease-split-1789914530`；`panel-deploy-receipt.json` |
| Tokyo sidecar config | 已补 197/200 | `/root/codex-backups/sidecar-bindings-20260920T142645Z` |
| LA sidecar config | 已补 196 | `/root/codex-backups/sidecar-bindings-20260920T142752Z` |
| 其余 sidecar | 此轮未替换二进制/重启 | fleet report / per-host JSON |
| Windows/Linux 客户端 | 本轮最新修复未完成部署验收 | 本地测试不替代发布 |

SHA-256：

```text
Mac .6 已安装、重签名后:
9d0192c213338b40eb7d29b84442ce168d49b77f6dfa7526ab50bec7b4b709b5
Mac .6 构建输入、签名前:
9215c2f56c0b22c5b9e15383158953ab7b35eb5273f11685aab486e9ebd7064e
Mac .7 本地构建、未部署:
8cf1904b6241917f8a0291070a6da776176c4d4b2fe7a05a3f32f2eb250b9936
Panel ClientAgentController.php:
b11090f0531bc4a4ae8240580fcf4b6a2227b02044463f9b99224634ba0394e9
Panel UltraClientStatusService.php:
0aec051929d36f90f5d0a902150ad2d73483b56da7b8328fc2a52492e6e5839b
六台 sidecar 运行二进制，version=2026.09.19.1.f18d01fb:
c6d11563b8c1b8a28f2d8b3a9422d4215ccc67755a0d0d32e059eba40dfd5317
```

回滚不是当前指令，不自动执行。需要时先核对当前 hash，避免覆盖后来的修改。Sidecar 的 `<backup-dir>/rollback.py` 有条件恢复旧配置，但恢复后也会恢复缺 binding 的故障。Mac 需匹配 helper/manifest/签名与版本，Panel 回滚需兼顾已下发合同；不要单文件盲回退。历史 `.4/.5` 备份及 DLL hash 见旧部署报告。

## 5. 做过但错误、无效、或容易误导的部分

| 项目 | 错误/不足 | 当前纠正和处理 |
|---|---|---|
| 把 line17 关联当成系统性根因 | line447 也失败；line17 后续又成功；没有 RST 来源证据 | 保留为假设，不认定 specific lines failure 导致全部 EOF |
| 把所有 EOF/no_response 算线路差 | 正常 EOF、half-close、本地 write 失败均可能出现类似日志 | 已收窄到明确远端 reset 的局部 TCP 避让；不惩罚全局信誉 |
| 旧 `downstream=0` 解读 | 计数依赖本地写成功，不能代表远端读取为零 | 已拆读/写；历史日志不能事后补精度 |
| “Mac session 上报滞后”早期结论 | 曾查询到历史 session，当前测试 Mac 是 333 | 旧报告顶端已加纠正；用当前 session + runtime hash 对齐 |
| 首次 attestation 部署回退 | 旧 usage 覆盖当前身份，引发 `lease_runtime_unattested` | 已修、恢复 session333、恢复临时移走的 usage；这是部署引入的问题，不隐去 |
| “Panel UoT 配置齐全，所以客户端一定 UoT” | v2 lease 丢字段，配置不等于实际 runtime | 已修下发；增加并回读 `udp_transport` |
| UoT 成功/失败共用 TCP 健康域 | HELLO 可掩盖 TCP 业务失败，sidecar 失败可污染 TCP | `.6` 已隔离 carrier 域；控制面另一分支仍需部署 `.7` |
| 把旧平台比较当严格 UDP 对照 | 某些旧阶段未同时证实两平台实际 UDP 实现相同 | 历史数据保留，但不作严格同路径因果论据 |
| 600 秒后仍称已修好 | 400 次业务失败，最新修复未在该轮运行 | 明确 FAIL；部署/单测不是业务验收 |
| 180 秒 observe | setup 未 ready，本地 connection refused | 标 INVALID，不比较吞吐或作为线路失败 |
| 最新 canary | 管理 SSH 失败，未开始 tests | 标 SETUP FAILED / NOT RUN，不算 sidecar 失败 |
| 材料 TTL / refresh 推断 | 本地 TTL 不证明 provider 凭据失效；用户已有反例 | 材料过期是控制面筛选状态，不是 TCP 不可用的证明 |
| source shared-session 假设 | 未实证 startacc/login 撤销旧材料；upload 与失败不完整对应 | 未改 source；不重复 login/startacc 制造副作用 |
| 仅检查 active/listener/磁盘配置 | 不证明进程已载入新 binding，更不证明业务通路 | 这次增加 live HELLO 和本机 echo；仍不等于 provider E2E |
| 长测和部署迭代效率 | 做了多轮测试及 helper 修订，但未先锁定每轮唯一假设、固定 runtime/逐流证据，导致仍不能回答因果 | 承认这些轮次没有完成闭环；停止继续盲跑，不虚构费用或无证据地把每轮都称有价值 |
| 分支与混杂工作区 | 当前分支不是默认 master，大量 unrelated 修改 | 接手先确认 ownership；不能 `git add .`、整体回退或把全部脏文件归本任务 |

**没有证明、也不要写成事实：** sidecar 普遍崩溃、UDP 必然导致所有 TCP EOF、provider 一定只允许单条线、材料过期一定断线、MAC 本身 TCP 有系统性缺陷、全方向/全平台零丢包。

## 6. 没做什么、还欠什么

- `.7` 未部署，故最后部署的 `.6` **仍不包含**控制面 TCP+UDP fallback 与 sidecar HELLO 解耦补丁。
- 最新修复未完成 HK/SG 两平台业务验收，更未证明“广港/广新互踢”消失。
- 没有完成严格的 TCP-only vs TCP+UDP+UoT vs UoT 故障注入对照。
- 没有建立适用于真实业务目标的稳定、低副作用应用层健康探测闭环；现有建连探测通过不代表目标请求响应成功。
- 未确认 400 次 EOF 的网络发起方；缺 provider 侧日志/双端逐流观测。
- 未完成 source 主机 hash、进程/会话竞争、真实 provider 撤销行为验证；未实施 source 锁、epoch、review 改造或诊断协议。
- 全 11 方向 Mac/Linux E2E 未完成；Windows 未做新版本真实线路验收。
- 11 方向为：Shanghai→HK/SG/KR，Beijing→Frankfurt/Moscow，Guangzhou→HK/SG，Fuzhou→Taiwan，Shanghai→Tokyo/LA，HK→Mainland。
- client3/service30 原盘点仅授权 7 方向，缺 Shanghai→HK/SG/KR、Beijing→Moscow；没有修改共享 plan9 或临时扩大授权。
- 全方向盘点中的“material过期”只能说明控制面候选前提，不能据此断言旧凭据数据面不可用。未来验证须保持该区别。
- 未完成 raw UDP baseline / tunnel UDP / reliable datagram / TCP 控制 / 受控故障切换的分项验收。
- 未实现 sidecar binding 自动同步与安全热加载；未修停用 API 的 `forward_not_found`。
- 未发布公共 DMG/Windows/Linux 更新；未提交/push；不保证所有线上用户设备已使用修复。

## 7. 以后建议怎么做：最小成本、逐关停止

**以下仅建议，不授权现在执行；等用户重新要求。不要一接手就重跑全部测试。**

### 第 0 关：本地恢复上下文

1. 先读本文件、`.6` receipt、`.7` 本地测试和差异；不再重扫整个仓库或全部历史日志。
2. 确认当前分支、HEAD、相关文件 diff 与未提交内容的归属。源代码需查时遵守 CodeGraph 规则。
3. 不自动执行 `deploy_mac7.py` / `run_canary.py`：脚本含远端变更，必须先审核旧 hash guard、ownership、原状态和 cleanup。

### 第 1 关：先验本地机制，再考虑部署

只针对已有 `.7` fallback 回归核实以下四个条件，不先重跑全套或 GameSim：

- sidecar HELLO 失败时，TCP+UDP 中普通 TCP 仍可独立可用。
- UDP-only 不允许绕过 UoT 检查。
- UoT HELLO 成功不清普通 TCP 业务失败。
- 撤销/取消/业务隔离仍然有效。

已有 PASS 可复用；仅代码变化、证据缺失或不一致时重跑。通过只证明代码机制，不宣布用户故障解决。

### 第 2 关：若用户同意恢复，仅做一个短业务对照

1. 先只读确认测试 Mac 实际 helper、session333（需复核当前 ID）、forward、当前候选/材料匿名标识；不要换身份又拿旧日志比较。
2. 需要上线 `.7` 时只改测试 Mac，一次备份、一次部署、一次版本/hash/Panel 回读；无公共发布。
3. 使用已授权独立 target，先验证 listener / lease / HELLO / 单个 payload。若 setup 失败，记录具体阻塞后停止，不自动换端口/换拓扑/反复重试。
4. 对同一候选、目标、材料快照做短 A/B：TCP-only 与 TCP+UDP+实际 UoT。记录逐请求 rid、远端 read、本地 write、结束顺序、候选、模式与 target request/sent。
5. 若需要故障注入，只作用于测试 forward/自有 target；**不要为模拟 UDP 失败停掉共享生产 sidecar**。
6. 若失败证据不足，停止扩流；若失败发生在 target request 前，基于该条流缩小范围，而不是再次满载 10 分钟。

### 第 3 关：有判别结果后再决定下一条线

- 只在“关闭/开启 UDP”稳定改变普通 TCP 结果时，把调查聚焦于仍共享的取消、lease/refresh、探测/选路生命周期；不要先改 provider。
- 如果 UoT 关闭后仍失败且 target 无请求，再用具体 candidate/connection 证据讨论 provider 路径与源材料；源端先只读，不主动 login/startacc。
- 如果只是新增规则 HELLO 拒绝，先修 binding 同步事务/回读机制，不将其扩大为 TCP 根因。
- 只有短测通过且用户确认预算，再执行原要求的 600 秒、20Mbps；先 HK/SG，然后在权限/target/binding 都满足时逐方向扩大。
- 每轮预先写明唯一假设、成功/失败门槛、时长/流量上限、停止条件和 cleanup。一轮结果汇报后再推进，不能静默连跑。

## 8. 文件与测试入口索引

### 产品修改的主要范围（不是对全部 git dirty 文件的认领）

- `tools/ultra-client/`：
  `ha_tcp.go`、`ha_candidates.go`、`ha_status.go`、`status.go`、`main.go`、`external_uot.go`、
  `active_candidate_fallback.go`，以及相关既有测试。
- 新回归：
  `copy_inbound_test.go`、`ha_tcp_loopback_reset_test.go`、`ha_tcp_outcome_status_test.go`、
  `ha_status_failure_history_test.go`、`ha_fallback_safety_regression_test.go`、
  `external_uot_health_domain_test.go`、`udp_transport_status_test.go`、
  `active_candidate_uot_boundary_test.go`。
- Desktop：`HeartbeatPayload.cs`、`HeartbeatService.cs`、`ForwardRuntimeManager.cs`、
  Mac `MacHeadlessHost.cs` 及 runtime/status 相关文件和测试。准确范围以 scoped diff 和旧部署报告为准，不能把整个 Desktop dirty 集合都归入本次。
- Panel：`master/src/Controllers/ClientAgentController.php`、
  `master/src/Support/UltraClientStatusService.php`；
  `master/tests/ultra_lease_external_uot_response_test.php`、
  `master/tests/ultra_client_status_contract_test.php` 等。

### 报告优先级

本文件为最终状态入口；以下文件按阶段保留，不覆盖历史：

1. `2026-09-20-ultra-uot-rule-convergence.md`：`.6` UoT 修复及读回；其“控制面分支正在修复”现在更新为“代码已修、`.7` 未部署”。
2. `2026-09-20-mac-tcp-fallback-safety-fix.md`：`.5` 阶段。
3. `2026-09-20-mac-tcp-log-fix-validation.md`：日志、Desktop、`.4`、失败长测。
4. `2026-09-20-client3-hk-sg-concurrent-600s.md`：第一轮 UDP20M + TCP1M。
5. `2026-09-20-mac-hk-tcp-path-investigation.md`：早期推论，部分已纠正，不能直接引用末段旧结论。
6. `2026-09-20-uot-all-directions-test-matrix.md`：11 方向/权限/binding 前提；部分 sidecar 缺口后来已修，TTL 与数据面可用性需区别。
7. `2026-09-20-ultra-source-material-session-root-cause-review.md`：未证实的 source 假设；管理入口已由用户补充。

### 原始证据目录

| 路径 | 用途 |
|---|---|
| `tmp/hk-sg-client3-20260920/` | 第一轮混合负载、target 回执、清理 |
| `tmp/mac-tcp-path-20260920/` | 400 EOF、TCP 修复回归、历史部署回执/备份 |
| `tmp/diag-tgz-20260920-a/` | 用户 tgz 解包 |
| `tmp/ultra-source-session-review-20260920/` | source 时间线与只读元数据 |
| `tmp/uot-all-directions-20260920/` | 全方向盘点、权限/binding 审计 |
| `tmp/sidecar-health-20260920-current/` | 六台服务、echo、40 规则准入、两台修复 |
| `tmp/uot-rule-audit-20260920/` | Panel 全规则、`.6` 部署/回读、`.7` 本地产物、失败 canary |

**这些目录可能含私有 snapshot、测试 token 或受保护配置；不要整体打包外发、上传或提交。** 本 handoff 不包含口令/token。原始证据保留本地，清理不能用 `git clean`。

## 9. 暂停时的任务/进程边界

- 子 agent `verify_sidecars` 已完成，`diagnose_tgz` 的 fallback 修复任务已完成；没有要求它们继续新任务。
- 本轮交接只读取本地证据、查询 git/任务状态、写文档，不运行部署脚本或测试流量。
- 本地 Windows 进程快照未发现命令行匹配本任务 canary/deploy/soak 的 `python/python3/wsl/ssh/go` 进程；**这不是完整 WSL/远端后台进程扫描**。
- 远端清理依据此前 receipt，未为整理交接再次 SSH；需要恢复工作时先做窄范围只读核查。
- 任务状态为 **PAUSED / 未完成业务验收**，不是 COMPLETE。保留线上最后确认的 `.6` 和已修 sidecar 配置，不自动回滚，不自动上线 `.7`。
