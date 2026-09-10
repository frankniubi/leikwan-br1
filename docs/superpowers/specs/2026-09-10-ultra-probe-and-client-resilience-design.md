# Ultra 探测竞速与全客户端控制面韧性设计

**状态：已批准；2026-09-11 已落地 candidate reputation/isolation、Go 协议探测/候选重试、桌面 native lifecycle guard 和 Android 普通 Ultra 批量注入；真实依赖、完整 drain 和 canary 仍待验证**
**日期：2026-09-10**

## 目标

为 Windows、macOS、Linux、Android 的 Ultra forward 统一定义两件事：

1. 候选 relay 的可用性探测继续使用固定目标 `1.1.1.1:53`，但按实际传输协议并发竞速，避免“UDP 探测通过、TCP 实际连接超时”的假阳性。
2. 外部代理软件、系统代理切换或短暂网络切换导致控制面连接中断时，不能立即清理有效 lease、停止本地 listener 或主动掐断已建立 TCP；只有明确的 lease 失效、撤销、策略变更或本地安全错误才能触发对应 forward 的关闭。

目标是减少 forward 170 一类的间歇性 `provider connect ... i/o timeout`，并让所有客户端在控制面短断线期间保持可预期的服务行为。

## 已确认的现状与问题

- `tools/ultra-client/udp_probe.go` 当前将 `tcp+udp` 也交给 UDP 探测，`probeTarget` 把探测目标固定为 `1.1.1.1:53`；TCP runtime 的真实 provider 建连仍在 `main.go` 的 `dialStreamWithGreetingUntil`。
- Windows 与 macOS runtime host 只在启动/刷新时注入 lease；候选配置在两端都由 host 生成，默认探测窗口和目标目前存在重复定义。
- Linux 的 `client-agent.py` 已有 endpoint selector 和 lease 独立续租，但控制面异常、runtime 状态、lease 到期之间还没有统一的状态机契约。
- Android 已有 `ClientControlLoop` 的短错误继续轮询行为，以及 `UltraForwardRunner`/`UltraLeaseClient` 的本地控制 socket 注入；候选探测和控制面恢复仍需与桌面/Linux 语义对齐。
- 当前服务端 material 只有 line 级 UDP 状态，没有可供客户端直接消费的 per-relay 健康快照；客户端必须把探测结果当作运行时事实，而不是把 line 级 `verified` 当成每个 relay 都健康。

## 非目标

- 不把探测目标改为 `8.8.8.8:53`，不在本次变更中自动轮换探测目标。
- 不改变 `/api/client/configs/pull`、`/api/client/ultra/lease`、`/api/client/heartbeat` 的路径、请求字段、响应字段或 lease 协议版本。
- 不让客户端在 lease 到期后自行延长 credential 有效期；控制面恢复必须重新取得服务端签发的 lease。
- 不改变 `client -> slave -> sidecar` 拓扑，也不把 sh36 上独立的 Leigod 会话当作 panel 下发路径的健康证明。
- 不在本 spec 中修复某个特定 relay、CDN 或上游供应商；上游故障只作为候选失败原因记录。

## 2026-09-11 实现边界

本轮按照批准后的范围先落地可回滚的 candidate reputation/isolation 核心，动态服务端过滤仍由显式开关控制：

- `UltraCandidateReputationService` 使用 `source_id|line_id|ingress_zone|destination_zone|protocol` 生成稳定 hash，保存 bounded failure/success evidence，支持 probation、quarantine TTL/backoff、独立 observer 和 availability floor。
- `UltraMaterialService` 在候选解析时登记 scoped identity；开启 `ULTRA_CANDIDATE_QUARANTINE_ENABLED` 后，在 `max_candidates`/offset 计算前过滤未过期 quarantine，并把 snapshot fingerprint 纳入 policy generation。开关关闭时仍登记 identity，保证 shadow feedback 可解析。
- `UltraLeaseService`、Go runtime、Linux agent、Windows/macOS heartbeat 传递 opaque `candidate_key_hash` 和 `lease_hash`。服务端反馈入口只接受已消费、未撤销且与 client/device/forward 绑定的 lease；未知 reason、协议/作用域不匹配、重复 evidence 和 `process_stderr` 均丢弃。
- Go `PolicyStore` 保留最多四个已签发候选；同一候选两次连续分类数据面失败后只对新连接本地 isolation，健康 sibling 继续可用，成功后清除 isolation。既有连接不被强制迁移。
- Admin Ultra 增加只读 reputation 表格，不提供未经审计的手工恢复或强制 quarantine。
- Go runtime 现在按 transport 选择探测：UDP 发送并验证 DNS A 响应，TCP 执行 relay greeting/target-connect，`tcp+udp` 要求两者同时可用；连接级失败在应用 payload 读取前尝试剩余候选。
- Windows/macOS 普通 auto-select 使用共享 bootstrap 常量；native outbound forward 在普通 polling/runtime manager 中被隔离，不会把 panel `listen_port` 当作 ordinary listener。
- Android 普通 Ultra 可以一次注入最多四个候选，控制 loop 明确报告 `READY`、`RECONNECTING`、`STOPPED`。

以下内容本轮没有被声称为完成：60 秒 per-relay 探测缓存、完整 `control_reconnecting`/`lease_at_risk`/drain 全生命周期、Linux watchdog 对等状态、四端 failure-injection、自动新线购买/组合挂载，以及生产 flag 开启。它们需要单独的失败注入测试、真实客户端证据和回滚窗口。

## 术语与状态

- **控制面**：panel API、配置 pull、heartbeat、Ultra lease 请求和本地 lease 注入。
- **数据面**：Ultra runtime listener、provider relay 连接、已建立的 TCP stream、UDP association。
- **传输错误**：DNS/连接超时、连接被对端重置、无 HTTP 响应的 socket/TLS 错误，以及 502/503/504/530。可触发 endpoint failover。
- **业务错误**：收到 HTTP 响应的 400/401/403/404/409/422、lease schema/policy 校验失败、明确的 revoke/expired/revision mismatch。不得伪装成 CDN 故障。
- **有效 lease**：当前时间小于 `expires_at`，policy generation、forward、target digest、transport 均匹配，并且未收到 revoke/invalid 的权威结果。
- **宽限排空**：lease 仍有效但控制面暂时不可达时继续接受既有 runtime；lease 到期后停止新连接，已建立 TCP 最多排空 30 秒，然后以明确原因关闭。此 30 秒是客户端本地资源上限，不会延长服务端授权。

## 统一探测契约

### 固定目标和输入

- canonical `probe_target` 为 `1.1.1.1:53`。配置缺失时写入该值；配置值存在时必须是合法 IPv4 `host:port`，本版本只接受 `1.1.1.1:53`，其他值拒绝并记录 `probe_target_invalid`。
- DNS probe 使用最小 DNS A 查询，必须校验 transaction ID、response QR bit 和完整的有效响应；仅收到 UDP 数据包、ICMP 或 TCP SYN 不算成功。
- 每个 candidate 的探测结果只包含 relay endpoint、selection ref、协议、发送/接收数、p50/p95 RTT、jitter、loss、错误分类和时间戳；绝不记录 session ticket、用户名、密码或 nonce。

### 按传输协议探测

- `udp`：先建立 relay 的 UDP association，再通过该 association 向 `1.1.1.1:53` 发 DNS 查询，按配置采样窗口计算 loss/RTT。
- `tcp`：通过 relay 的 TCP greeting/target-connect 流程连接 `1.1.1.1:53`，成功完成 provider greeting 即视为 candidate 的 TCP 路径可用；目标连接失败与 relay 建连失败分别记录。
- `tcp+udp`：同一 candidate 必须同时通过 UDP DNS 和 TCP greeting 两项硬门槛，之后才可被当前兼容协议选中。两项结果分别保留，排名使用综合可用性和最差协议指标，避免只因 UDP 快而把 TCP 超时的 relay 选中。
- 后续若需要为 TCP 与 UDP 选择不同 relay，必须新增明确的 wire/config 版本；本次不改变单 lease 兼容模型。

### 竞速、排名和失败

- 最多 4 个候选并发探测，worker 数受配置限制且不得超过候选数；任一候选达到硬门槛后继续完成短质量窗口，随后取消尚未开始的工作，保留已完成结果。
- 先排除硬失败，再按现有 selection tier 业务优先级排序；同 tier 内按可用性、loss、jitter、p95、p50、原始候选顺序排序。不得因为“更快但丢包超过 25%”压过稳定候选。
- `tcp+udp` 的综合结果只有在两项均成功时 `usable=true`；单项失败必须返回 `probe_tcp_failed` 或 `probe_udp_failed`，而不是笼统 `probe_failed`。
- 全部候选失败时保留旧 runtime（若旧 lease 仍有效），新连接返回明确的 `no_healthy_candidate`；冷启动则 listener 不宣称 ready，并持续等待下一次 lease/policy。
- provider connect 发生 timeout/reset 时，runtime 在当前 lease 有效期内按候选顺序尝试剩余已探测候选；不得为同一连接重复提交已发送到 provider 的应用 payload。

### 结果缓存与隔离

- 客户端保存短期 per-relay 结果（默认 60 秒 TTL）；连续两次硬失败将 candidate quarantine 30 秒，新的 lease/candidate 仍可覆盖该状态。
- 缓存只用于减少重复探测，不替代每次新 lease 的协议/expiry 校验；跨 forward、跨 policy generation 不复用健康结论。
- 服务端 material 可以增加 per-relay health projection，但客户端仍必须能在没有该字段时正常工作；响应向后兼容。

## 控制面断线与 lease 生命周期

### Endpoint 与代理策略

- Windows/macOS 复用现有 Core `ControlPlaneEndpointSupervisor`；Linux 复用 `ControlPlaneEndpointState`；Android 为 `PanelRepository`/`ClientControlLoop` 增加同等的 primary/mirror 状态。
- 控制面默认使用 direct/no-proxy transport，避免本地 data-plane 代理把 panel 请求回环到正在等待 lease 的 runtime。若用户显式选择“跟随系统代理”，只作为有界 fallback，并在状态中标记 `system_proxy`。
- 仅网络错误、无响应 timeout、连接断开和 502/503/504/530 触发备用 endpoint。收到 HTTP 响应后，401/403/409 等业务结果不得切换 endpoint。
- GET 和无副作用请求允许有限指数退避；lease/heartbeat POST 只有在未收到 HTTP 响应时才可向备用 endpoint 重试。收到响应后禁止重复提交同一 nonce。
- 备用 endpoint 成功后继续使用，并在后台按冷却时间探测 primary；primary 连续成功后回切。endpoint 切换不停止任何有效 forward。

### 控制面状态机

每个 forward 独立维护：

`ready` -> `control_reconnecting`（短暂 transport error） -> `ready`（重新 pull/refresh 成功）或 `lease_draining`（lease 到期） -> `stopped`。

- `control_reconnecting` 期间：保留有效 policy、lease、listener、既有 TCP/UDP；允许已建立 stream 继续转发，允许新连接直到 `expires_at` 前 30 秒的本地保护线。
- 距离 lease 到期小于 30 秒且仍无法续租：停止接受新 TCP/UDP association，既有 TCP 进入排空；状态为 `lease_at_risk`，但不立即杀进程。
- 到达 `expires_at`：拒绝新连接，按 30 秒 drain 上限关闭既有 TCP；UDP association 在下一次 packet/keepalive 时以 `lease_expired` 关闭。不得继续注入旧 credential。
- 明确收到 revoke、policy generation 改变、forward disabled、schema/policy 校验失败或本地安全错误：立即关闭对应 forward，原因必须可区分。
- 一个 forward 的 lease 失效不能停止其他 forward；控制面双站都不可用时保留所有仍有效的 forward。

### Windows/macOS 外部代理专项

- 将 panel API、lease refresh、heartbeat、diagnostics 统一接入 endpoint supervisor 和同一个 direct/no-proxy handler；不得让某个页面/登录调用单独使用系统代理而破坏 runtime 续租。
- 检测到 WinINET/PAC/SystemConfiguration 代理变化时，不重启 Ultra runtime；仅重建控制面 transport、清空坏连接池并触发一次有界 pull。
- 应用退出、用户显式登出、设备撤销仍可取消控制 loop；普通 socket timeout、`TaskCanceledException`、`URLError`、`IOException` 不得调用 Stop All。
- UI/诊断显示 `control_reconnecting`、当前 endpoint、下次重试时间、lease 剩余时间和最近一次明确错误，不能显示 credential 或完整 Authorization header。

## 全客户端实现边界

| 客户端 | 探测/运行时 | 控制面与续租 | 必须保持的语义 |
| --- | --- | --- | --- |
| Windows | `tools/ultra-client` + `WindowsUltraRuntimeHost` | Core `MasterApiClient`、`ControlPlaneEndpointSupervisor`、polling | 外部代理短断线不停止 listener；TCP/UDP 竞速一致 |
| macOS | `tools/ultra-client` + `MacUltraRuntimeHost` + `UnixUltraLeaseClient` | 同一 Core endpoint supervisor | 与 Windows 的 bootstrap、状态、drain 语义一致 |
| Linux | `tools/ultra-client` + `client-agent/install_forward_runtime.py` | `client-agent/client_agent.py` endpoint selector、renew loop、watchdog | 续租失败保留旧 runtime，过期才按 forward 排空 |
| Android | packaged `ultra-client` + `UltraForwardRunner`/`UltraLeaseClient` | `PanelRepository`、`ClientControlLoop`、`TunnelOrchestrator` | coroutine 短错误不杀 VPN；lease 到期和 revoke 仍 fail closed |

所有平台共用错误码集合：`probe_udp_failed`、`probe_tcp_failed`、`no_healthy_candidate`、`control_reconnecting`、`lease_at_risk`、`lease_expired`、`lease_revoked`、`policy_changed`、`listener_not_ready`、`provider_connect_timeout`。

## 状态、日志与证据

runtime status 至少增加或规范化以下字段：

- `listener_ready`、`control_plane_state`、`control_plane_endpoint`、`last_control_success_at`、`next_retry_at`；
- `lease_expires_at`、`lease_renew_after`、`lease_state`；
- `probe_target`、`probe_protocols`、`selected_tcp_ref`、`selected_udp_ref`、每个 candidate 的失败分类和最后探测时间；
- `active_tcp_streams`、`active_udp_associations`、`new_connections_blocked`、`drain_deadline`、`stream_close_reason`。

日志必须使用 forward ID、selection ref、relay IP/port（按现有敏感信息规则处理）和错误类别；禁止 session ticket、credential、nonce、完整请求头。服务端 usage/lease 记录继续作为控制面证据，不能单凭 `/health` 或本地 `ready` 字段宣称数据面成功。

## 兼容性、安全与回滚

- 新字段全部 optional；旧 panel、旧 runtime、没有 per-relay health 的 material 都必须可解析。
- `probe_target` 变更不涉及 API wire；Windows/macOS/Linux/Android 的默认值和窗口参数由各 host 测试锁定为同一 contract。
- 采用 feature flags：`ultra_probe_race_v2`、`ultra_control_plane_grace_v1`，默认先在内部/灰度客户端开启；关闭 flag 时保留旧选择路径，但仍不得把传输错误当作业务 revoke。
- 回滚只需关闭 flags、恢复旧 runtime artifact 和 host bootstrap；不删除 lease/endpoint 状态文件，不执行 Stop All。

## 验收标准

1. 四个平台都把 `1.1.1.1:53` 作为唯一探测目标；UDP 通过该目标完成真实 DNS probe，TCP 通过同一目标完成 relay greeting/target-connect；`8.8.8.8` 不出现在生成配置、代码常量或测试期望中。
2. 4 个候选可并发探测；UDP、TCP、`tcp+udp` 的硬门槛与错误码按本 spec 工作，且 all-fail 不会杀掉仍有效的旧 runtime。
3. provider TCP 建连 timeout 时能在同一 lease 的剩余候选中重试，已建立 stream 不因一次候选失败被全部关闭。
4. Windows/macOS 在系统代理/PAC 改变或控制面 socket timeout 时，listener 和 established TCP 保持；控制面经备用 endpoint 恢复后能完成一次 full pull 和 lease refresh。
5. Linux 与 Android 对相同故障注入表现一致：短断线为 `control_reconnecting`，lease 到期为 `lease_at_risk`/`lease_expired`，明确 revoke 才立即关闭。
6. 收到 401/403/409 或 policy/schema 错误不会 endpoint failover，也不会伪装为 transient network failure。
7. status、panel heartbeat、usage、日志能区分控制面失败、候选失败、listener 缺失、lease 过期和 stream drain；敏感值不落盘、不出日志。
8. 既有 API 路径/字段快照、客户端安装产物校验、Windows/macOS/Linux/Android 单元测试和跨平台故障注入测试全部通过。

## 当前验证记录

- PHP Ultra 相关契约测试 24/24 通过，包含 reputation、candidate filtering、lease identity、feedback boundary、policy generation 和 admin surface；相关 PHP 文件 lint 通过。
- Python status/artifact consistency 定向测试 12/12 通过；Android artifact manifest 通过；Windows/macOS/Linux 共享 heartbeat/opaque identity 的 Desktop Core 定向测试 70/70 通过，macOS Ultra host 3/3 通过。
- Python client-agent 全量 432 个测试项中 431 个通过、1 个失败（另有 19 个参数化子用例通过）；唯一失败是现有 installer contract 对 `$ARTIFACT_BASE`/`$DOWNLOAD_BASE` 路径的预期不一致，本轮未改动该 dirty installer。
- Go 定向 isolation/identity 测试只在临时最小 `opengfw` stub 下验证过；真实依赖路径 `../../opengfw` 当前没有可用 `go.mod`，所以没有全量 Go 通过结论。
- Desktop solution build 通过（0 warning/0 error）；Mac 全量测试曾在既有 `StopDuringForwardPreparationCancelsWithoutLaunchingProxy` 用例挂起，单测单独重跑同样复现，因此不作为本轮功能失败归因。
- 尚未启用 `ULTRA_CANDIDATE_QUARANTINE_ENABLED`，也未执行生产迁移、重启、灰度或 canary；Go 全量还受缺失的真实 `../../opengfw/go.mod` 阻塞。当前证据是代码契约和本地测试，不等同于 provider/CDN 数据面验收。

### 本轮实现与证据边界

| 范围 | 当前结果 | 证据/限制 |
| --- | --- | --- |
| Candidate reputation/isolation | 已实现 | PHP 24/24；Go isolation 定向测试曾在临时 stub 下通过，stub 已删除 |
| Go protocol-aware probe + stream retry | 已实现 | DNS/排序/双协议/取消/连接重试定向测试通过；真实 OpenGFW 全量未能运行 |
| Windows/macOS ordinary Ultra contract | 已实现 | Core 70/70、macOS 3/3、solution build 0 warning/0 error |
| Native outbound ordinary-runtime guard | 已实现 | native apply/refresh/polling 回归测试通过；未部署 |
| Android batch/control state | 已实现 | `:app:testDebugUnitTest` BUILD SUCCESSFUL；artifact manifest 通过；instrumentation 未运行 |
| Linux full grace/drain + live canary | 未完成 | 仅有现有 status/artifact 定向证据，未做线上故障注入 |

## 分阶段发布

1. **Contract phase**：先锁定共享错误码、默认探测配置、状态字段和测试 fixtures，不改变默认运行开关。
2. **Probe phase**：启用 `ultra_probe_race_v2`，先覆盖内部客户端，比较 candidate timeout、all-fail、选中路径与实际 provider connect 的相关性。
3. **Grace phase**：启用 `ultra_control_plane_grace_v1`，注入 Windows/macOS 外部代理断线、mirror failover、lease 临界到期和恢复场景。
4. **All-client phase**：Linux、Android 通过相同故障矩阵后全量开启；保留按客户端和 forward 的独立回滚开关。
5. **Post-rollout**：观察至少一个完整 lease 周期和一次 primary/mirror 回切，确认没有提前 TCP close、无界重试、credential 泄露或 listener 假 ready。
