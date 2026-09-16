# LeiGod Forward 高可用影响评估提案

**状态:** Proposal（评估提案，未声明 `/leikwan-br1` 已完成改造）  
**日期:** 2026-09-15（Asia/Taipei）  
**关联事件:** `10.10.10.171` 上 `leigod-forward-udp` 的实际故障端口为 `20055/UDP`（不是 `20050/UDP`）  
**目标项目:** `/leikwan-br1`

## 1. 决策摘要

本次事件对 `/leikwan-br1` 有直接架构启发：面板下发的 LeiGod `session.json`、源端登录会话、各线路运行时和转发守护进程之间存在共享状态与生命周期耦合。一个会话被替换后，旧线路可能仍保持监听，却已经失去有效数据面；只检查进程、监听端口或 UDP ACK 会把“僵尸线路”误判为健康。

建议把本项目的生产高可用目标定义为：

1. **会话隔离:** 生产者会话、源端登录会话、每条线路的运行时副本分目录管理，禁止跨角色直接复用同一文件。
2. **按线路恢复:** 只重启失效的 native line/forward leg，不做全主机或全服务重启。
3. **数据面证据:** 健康判断必须同时覆盖本地监听、线路 SOCKS/TCP 功能、真实目标探测和 UDP 行为；控制面 `ActiveState=active` 不能单独作为验收。
4. **可回滚发布:** 配置、二进制和 session 更新均采用备份、校验、原子替换、版本/代数记录和 watchdog 回滚窗口。
5. **新建线路默认 HA:** `leigod-forward-udp` 新建规则自动带上稳定名称、HA 策略和重复监听校验，避免新线路绕过守护机制。

本文件提出评估范围、验收标准和实施门槛。本轮交付为文档；生产实施沿用用户已授权的高可用目标，按评估、备份、灰度和验收顺序推进。

证据来源：事件部分整理自本会话已有诊断与修复记录，本轮未重新连接生产主机；项目影响面来自本地源码核对。历史 watchdog 与 lease 经验仅用于设计参考，不代表当前生产状态。源码行号可能随代码更新变化。

## 2. 事件输入与已知事实

### 2.1 已观察到的故障模式

- `10.10.10.171` 的故障对象最终定位为 `leigod-forward-udp` 的 `20055/UDP`。
- 源端登录替换共享的 `/opt/leigod/session.json` 后，旧的 patched LeiGod client 可能吞掉凭据刷新失败，继续监听但不再转发。
- 仅以 UDP 预热或端口监听选线，会把 TCP SOCKS 路径已坏的线路纳入候选。
- 旧线路模板的 `TimeoutStopSec` 过长，故障恢复动作可能在 systemd 超时前卡住。
- 进程重启计数为零不等于没有数据面中断；反之，单次受控重启也不等于故障已根治。

### 2.2 证据与待验证项

| 项目 | 当前结论 | 在 `/leikwan-br1` 的处理 |
|---|---|---|
| `20055/UDP` 的单线路断线 | 已有现场诊断输入 | 作为回归场景，不把 `20050` 继续当作目标 |
| 源端会话与线路会话共享 | 现场已证明可导致旧客户端失效 | 核查面板、slave-agent、source-agent 的所有读写路径 |
| 监听端口仍在 | 只能证明 control plane 存活 | 增加 authenticated TCP/SOCKS 和真实目标探测 |
| watchdog 能否正确隔离 native/legacy | leigod-forward 已有实现经验 | 在本项目先做只读审计和 staging 演练 |
| 多 slave/多源端切换 | 当前没有完整生产证明 | 作为 P2/P3 的明确验收缺口 |

## 3. `/leikwan-br1` 影响面

### 3.1 直接代码面

- `slave-agent/slave_agent.py:2295` 附近的 `apply_leigod_session`：接收面板会话并写入 `<leigod_root>/session.json`。
- `slave-agent/slave_agent.py:6181` 附近的线路 unit 生成：线路通过 `--session <leigod_root>/session.json` 启动，需评估是否改为受控 runtime copy。
- `slave-agent/slave_agent.py:2781`、`2905` 附近的会话存在性与管理消息处理：需补充代数、来源和原子发布语义。
- `source-agent/ultra_source_agent.py:1015`、`1367`：源端已有 `/var/lib/ultra-source-agent` 隔离模式，应作为目标模式而不是再造一套共享路径。
- `source-agent/ultra-source-agent.example.json`：确认 `state_dir`/`session_path` 的配置契约和迁移兼容要求。
- `master/src/Support/ProvisioningService.php:11675` 附近：面板向 slave 广播共享 LeiGod 会话，需评估 fan-out、版本和失败重试语义。

### 3.2 运行与发布面

- slave 上的 LeiGod systemd template、`leigod-forward-udp` supervisor、线路规则目录和日志目录。
- master 的会话下发、线路编排、候选线路健康状态、更新器/制品发布链路。
- sidecar 路径必须保持 `client -> slave -> sidecar`；本提案不改变现有拓扑。
- 所有端口审计遵守现行约束：slave 的 MPTCP/bond ingress 仅使用 `7000-9000`；业务监听端口按规则目录单独登记。

## 4. 评估目标与非目标

### 目标

1. 证明 session 更新不会让未受影响线路变成僵尸状态。
2. 证明单条 native UDP/TCP 线路可在有限窗口内恢复，且不会破坏其他客户会话。
3. 证明新建 `leigod-forward-udp` 规则自动进入 supervisor + watchdog 管理。
4. 建立可审计的 generation、selection reference、failure history、最后有效 runtime 和回滚点。
5. 明确 single-host HA 与 multi-slave HA 的边界，禁止用控制面健康冒充端到端可用。

### 非目标

- 不在本评估中延长授权有效期、绕过撤销或配额限制。
- 不把 TCP 已发送业务数据透明迁移到另一条连接。
- 不因一次端口探测失败就重启整台主机或所有 forward。
- 不改变 sidecar 拓扑、不直接连接 sidecar、不复制任何凭据到仓库或普通报告。

## 5. 目标架构假设

```text
Panel/master
  |  versioned session publication (atomic, validated)
  +--> slave control state (source/session metadata only)
          |
          +--> per-line runtime session copy + generation
          +--> leigod line unit (isolated state, bounded stop/start)
          +--> leigod-forward-udp supervisor
          +--> dynamic watchdog (native per-leg repair)

Source host
  +--> /var/lib/ultra-source-agent/session.json (source login only)
  +--> authenticated line probes from source vantage point
```

要求：

- session JSON 先做结构校验（例如必需的 `client_token`）；来源、generation、hash 使用独立发布元数据，不擅自改变上游 session 格式。写临时文件后 `fsync`/原子 rename，再更新状态记录。
- 线路运行时只读取自己的受控副本；源端登录状态不可直接覆盖正在服务的任意线路文件。
- 文件隔离不能解决上游账号的全局会话撤销。必须验证同一账号再次登录是否使旧 token 全部失效；若存在该语义，应统一登录写入者、串行轮换，并评估独立授权账号/会话故障域。不可将复制 session 文件等同于账号级 HA。
- watchdog 采用连续失败阈值、退避和冷却时间；健康恢复后清零失败计数，并保留最近故障历史。
- native 与 legacy catalog 分离；legacy 共享会话的修复动作默认隔离并告警，不得误伤 native 线路。
- 选线至少需要：监听存在、线路 SOCKS/TCP 成功、目标可达、UDP 关联/ACK 行为四类证据中的适用集合。

## 6. 分阶段评估计划

### P0：只读资产与依赖审计

**输出:** 线路/会话/发布关系图、端口清单、风险清单、回滚点。

- 枚举 master、slave-agent、source-agent 对 `session.json` 的全部读写点和 systemd unit。
- 将每条线路映射为：规则 ID、稳定名称、监听端口、协议、LeiGod leg、session 来源、generation、supervisor/watchdog 所属。
- 对比 native 与 legacy 规则；检查重复 enabled listener、空名称、孤儿 unit、失效 session、过期 token。
- 仅收集 hash、权限、时间戳和状态，不把 token 或原始 session 写入报告。
- 记录基线：`systemctl show`、监听快照、最近 1h/24h 日志、真实目标探测结果。

**通过门槛:** 100% enabled native 规则可追溯到唯一规则 ID、唯一监听 tuple 和唯一 runtime session 路径；所有未映射对象有 owner 和处置结论。

### P1：staging 故障注入与恢复评估

**输出:** 自动化测试结果、状态机和超时参数建议。

测试矩阵：

| 场景 | 注入 | 必须观察 |
|---|---|---|
| session 轮换 | 发布新 generation，保留旧 runtime | 新连接使用新会话，既有健康线路不被全量重启 |
| token 无效 | 提供结构正确但认证失败的 session | 失败被标记为 auth，不进入健康候选；只有旧授权仍有效时才保留旧 runtime，撤销/到期须停止服务 |
| TCP SOCKS 黑洞 | 保持 UDP/监听正常，阻断 TCP 目标 | watchdog 不被 UDP-only 结果欺骗，隔离并修复对应 leg |
| UDP 无 ACK | 丢弃连续 keepalive 响应 | 达到阈值后仅重建该关联，pending 队列有界 |
| stop 超时 | 让旧 client 延迟退出 | 受控终止在预算内完成，不阻塞其他线路 |
| supervisor 重启 | 仅杀 child forward | supervisor 可恢复 child，其他监听和会话保持稳定 |
| 重复规则 | 新建相同监听/协议的 enabled 规则 | 创建被拒绝或进入显式冲突状态 |

### P2：单 slave canary

**输出:** 真实流量 canary 报告和发布/回滚收据。

- 选一台低风险 slave 和一条 native UDP 线路，先部署配置与脚本，再启用 watchdog。
- 运行至少 60 分钟稳定观察；覆盖空闲、持续 UDP、TCP SOCKS、session 轮换四种流量。
- 记录每次修复的 rule ID、generation、原因、动作、耗时、前后 PID、监听和真实目标结果。
- 任何回滚必须能在不重启整台主机的情况下恢复到上一个 session/runtime hash。

**通过门槛:** canary 期间 native 线路无未解释断线；watchdog 误修复为 0；单次修复不影响其他 enabled rule；告警可关联到具体规则。

### P3：多 slave/生产推广评估

**输出:** 分批发布顺序、容量与故障域模型、长期 SLO 建议。

- 按源端、运营商、协议和 ingress 故障域分批，不同时切换所有 slave。
- 验证 master fan-out 部分失败时的重试、幂等、过期消息拒绝和回滚。
- 验证一台 slave 完全不可达时，面板状态不会错误覆盖其他 slave 的有效 runtime。
- 评估跨 slave 切换是否需要应用层重连；不承诺已有 TCP 流透明迁移。
- 盘点 source host、slave、sidecar、账号、数据库和发布源的共同故障域；至少两个候选必须具有实际独立的可用路径。若所有 slave 仍依赖同一源端或账号，应明确记录剩余单点。
- 设计入口切换和控制面写入者互斥，验证主机失联而非仅进程退出的场景；检查备用容量、客户端重连以及配置/状态恢复时间。
- 形成月度演练：session 轮换、单线路黑洞、源端不可达、发布源不一致、回滚。

## 7. 验收标准

### 功能与可靠性

- 新建规则默认具备稳定 name、`ha_enabled=true`、`ha_policy=supervisor+watchdog` 等可查询元数据。
- 重复 enabled listener/leg 在创建或加载时被拒绝，并提供冲突对象。
- native 与 legacy 规则的修复边界明确；legacy 共享状态不会触发 native 全量重启。
- 连续 3 次失败且跨越最小冷却窗口后才执行修复；成功探测立即清零失败计数。
- 单线路修复不会关闭其他线路的监听、supervisor 或已建立会话。

### 可观测性

- 每个健康结论包含 probe 类型、时间、generation、selection reference、目标和失败分类。
- 至少有 active、degraded、quarantined、repairing、rollback 五种可区分状态。
- 监控能区分 control-plane active、data-plane healthy 和 target reachable。
- 日志/指标可按 rule ID、监听端口、协议、leg 和源端过滤，且不记录 token/session 原文。

### 性能与恢复预算

- 设定并实测：检测阈值、修复启动预算、停止预算、退避上限、观察窗口。
- 业务恢复时间以真实 TCP/UDP 数据面成功为准，不以 systemd `ActiveState` 或端口重新监听为准。
- 压测中 pending/Recv-Q/丢包计数有界；单条异常关联不会耗尽全局资源。

### 发布与回滚

- 每次发布具备制品 hash、配置 hash、session generation、操作者、时间和目标主机收据。
- session/runtime/config 均有 root-only 备份和可验证回滚脚本；回滚前做 hash/版本前置检查。
- 发布源、面板下载内容和备用源的版本/hash 一致性可自动验证；不一致时拒绝安装。
- 真实 canary 未通过时，不得扩大到下一批。

## 8. 建议的最小交付物

1. `session-publication`：校验、原子发布、generation/hash、失败保留最后有效 runtime。
2. `line-health`：按协议的 authenticated probe 与证据分类。
3. `stack-watchdog`：动态 catalog、连续失败阈值、退避、按 leg 修复和冷却。
4. `rule-contract`：新建/加载规则的 HA 元数据、唯一性校验和迁移兼容。
5. `release-receipt`：制品/配置/session hash、canary、回滚和未覆盖范围报告。
6. 自动化测试：Python 单测、shell 语法/规则测试、Linux `go test -race`（若涉及 Go runtime）、真实 UDP/TCP canary 脚本。

## 9. 风险与控制措施

| 风险 | 控制措施 |
|---|---|
| session 轮换导致全线失效 | producer/consumer/runtime 分层；原子发布；保留最后有效副本 |
| watchdog 误杀健康线路 | 只修复明确失效的 native leg；阈值+冷却；修复前后快照 |
| UDP 探测假阳性 | 强制协议适配；TCP/UDP/目标探测分开记账 |
| 发布源内容漂移 | 多源 hash/版本校验；不一致即拒绝安装 |
| systemd 停止卡死 | bounded stop timeout；分阶段终止；单线路隔离 |
| 多 slave 状态覆盖 | 消息带目标、generation、幂等键和过期拒绝；按故障域分批 |
| 观测泄露凭据 | 只记录 hash/元数据；session 文件 root-only；报告脱敏 |

## 10. 回滚与停止条件

出现以下任一条件，立即停止扩大范围并回滚 canary：

- 未授权的全量重启或超过单线路范围的连接中断。
- 健康线路被错误 quarantine/repair，且 5 分钟内无法解释。
- session/hash 不一致、原子发布失败或回滚前置检查失败。
- 真实数据面连续失败超过既定恢复预算，或 pending/Recv-Q 无界增长。
- 无法从日志定位 rule ID、generation、probe 和修复动作。

回滚顺序：停止扩大 -> 保存证据 -> 恢复上一个已验证 session/runtime/config hash -> 仅重启受影响线路 -> 运行 TCP/UDP 真实 canary -> 发布回滚收据。不得用未经验证的新 token 或强制全主机重启绕过故障。

## 11. 执行顺序与待定参数

文档固定归档到 `docs/assessments/`。建议实施顺序为 P0 资产审计 → P1 隔离环境测试 → P2 单线路灰度 → P3 分批推广。

P0 可从现有配置中确定 canary slave、线路与真实目标，优先选择低流量且可回滚对象。恢复预算、备用容量和维护窗口在 P0 基线报告中落实；本文中的失败阈值与观察时长为建议起点，需由测试校准。将监测间隔、连续失败次数、停止/启动耗时和探测耗时合并计算恢复预算，并分别报告 p50、p95、最大值。

实施完成应交付：资产清单、源码补丁与测试结果、故障注入报告、生产灰度收据、回滚脚本和剩余单点清单。只有满足数据面验收与主机故障演练，才能分别标记“单机自愈完成”和“跨主机 HA 完成”。

## 12. 明确边界

本提案借鉴 `leigod-forward` 的 `20055/UDP` 事件和已有 HA 实践，用于指导 `/leikwan-br1` 的风险评估。文档完成不代表代码改造、全量部署或长期可用性验收完成。
