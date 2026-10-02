# 线路防盗 / 客户端暴露面 — 完整修复方案

**日期:** 2026-10-02
**作者:** frankniubi + Claude (Opus 4.8, 1M)
**状态:** P0/P1 已上线、lease 加密下发面板侧已上线（optional）、各端客户端代码完成待发版 — 见附录 B
**基线分支:** `master`

---

## 0. 威胁模型（先说清楚在防谁）

- **资产:** LeiGod 第三方共享线路账号（`line_user`/`line_token`/`speed_ip`/`pc_port`）、你自有的 slave/sidecar 入口 IP 与令牌、LeiGod 账号 `session.json`。
- **攻击者:** 一个**已付费、已登录**的正常用户，他：(a) 反编译客户端、(b) 在自己机器上抓内存 / 本机抓包 / 装根证书做 MITM、(c) 直接拿本机 `agent_token` 打 HTTP 接口。以及**任意匿名人**对公开面板接口的探测。
- **边界（诚实）:** 本项目所有服务器均为作者自有，不涉及任何 ToS / 法务问题。本方案是**纯防御性**的"防线路被白嫖/转卖"工程，不是攻击工具。
- **不可能目标:** 让一个付费用户用改过的客户端在**他自己这一台机器**上用不了服务——这是做不到的，只能抬高成本。可达目标是：**第三方 LeiGod 账号永不进客户端** + **偷到的东西不能多机复用/转卖** + **限额在数据面真正生效**。

---

## 1. 结论速览

| # | 项目 | 严重度 | 现状 | 修复动作 | 工作量 | 停机 |
|---|---|---|---|---|---|---|
| P0-1 | `/external-uot-config` 无鉴权 | 高（潜伏） | 匿名 HTTP 200，一旦启用 external-UoT 组合即泄露边车令牌 | 复用 `poolNode` 的 bearer/`agent_token` 鉴权 | 小 | 零 |
| P0-2 | 边车 `external_uot_token` 可能已泄露 | 高 | 固定令牌、`targets: []`=允许任意 | 轮换令牌 + 下发真实 targets | 小 | 边车短暂重拉 |
| P1-1 | lease 限额默认 0=无限 | 中 | 设备数/并发/频率全不限 | 配置三个 key 为非零（逻辑已存在） | 很小 | 零 |
| P1-2 | attestation 默认只记录 | 中 | `report` 模式 + 缺失即放行 | 切 `enforce` + 二阶段堵 fail-open | 小~中 | 零（灰度） |
| P2 | 非 Ultra 转发暴露自有拓扑 | 低~中 | client_token/边车IP/HMAC 明文下发 | **归入架构重构**，不做简单 scrub（会断功能） | — | — |
| P3 | 客户端无混淆 | 低 | Android R8 关、PC ultra-client 未混淆 | 开 R8、发 garble 构建 | 中 | 零 |
| **架构** | Ultra 客户端直连 LeiGod | **根因** | LeiGod 账号明文进客户端 | **插入自有入口 + Ed25519 每设备短票 + 按线最小权限投递** | 大 | 灰度 |

**一句话优先级:** 先做 P0（止血，零停机）→ P1（配置即生效）→ 架构（真正根治）→ P2 随架构一起 → P3 顺手。

---

## 2. P0 — 立即止血

### 2.1 给 `/external-uot-config` 加鉴权

**暴露（已现场核实 2026-10-02）:**
- 路由无任何鉴权：`master/src/Bootstrap.php:430`（`/external-uot-config`）、`:433`（`/artifacts/external-uot-config`）。
- `ExternalUotConfigController::sidecarConfig`（`:81`）经 `resolvePoint`（`:291-315`）允许用 `point_id` / `agent_token` / `host` / `ip` **甚至请求方来源地址**来选点；匿名也返回 200。
- 命中点后 `buildPayload` 吐出该点每个 `external_uot_enabled=1` 组合的固定 `token`，配 `forwards[id] = {token, targets: []}`（`:211`）。
- 边车端 `allowsTarget` 对空 targets 判为**允许任意目标**：`tools/uot-sidecar/protocol.go:243-247`。令牌用常量时间比较：`:265`。
- **现场证据:** `curl` 打生产 `117.55.193.164`，`?`、`?point_id=1..3`、`?host=161.248.136.183` 全部 HTTP 200；当前 `combinations: []`（面板里暂无 `external_uot_enabled=1` 组合），所以**此刻没在漏**，但门是开的。

**已有的正确范式（直接照抄）:** `poolNode`（`:45-49`）用 `$pool->authenticate($id, (string)$request->bearerToken())`，注释写着 *"Dedicated pool credentials; never accept a requested host as authorization."* —— 这正是 `sidecarConfig` 该有的态度。

**改法:**
1. `sidecarConfig` 要求携带有效 `agent_token`（query 或 `Authorization: Bearer`），且必须精确命中某个 `sidecar_points.agent_token`（该列 `VARCHAR(191) NOT NULL UNIQUE`，见 `master/migrations/20260402_create_sidecar_points.sql:9`）。
2. **删除按 `host`/`ip`/来源地址 的回退选点**（`resolvePoint` 的第 3 分支 `:306-312`）——这是匿名泄露的核心。新装边车首配改为：安装脚本带 `agent_token` 调用（见 `install-external-uot.sh`），而非靠来源 IP 自认。
3. 鉴权失败返回 `401 unauthorized`（对齐 `poolNode`）。
4. 命中点后**只返回该点自己的组合令牌**，绝不跨点。

**验证:**
- 无 token → 401；带错 token → 401；带该点正确 token → 仅返回该点组合。
- 回归：真实边车 agent 用其 `agent_token` 拉配置仍成功（跑一次边车自配置流程）。

**风险/回滚:** 低。改的是鉴权前置；若线上边车 agent 当前靠"来源 IP 自认"工作，需先确认它们都带 `agent_token`（看 `install-external-uot.sh` 与 systemd unit 的调用参数），否则会把真边车一起挡掉。**执行前必须先盘点现网边车的调用方式。**

### 2.2 轮换 `external_uot_token`

**为何:** 该接口长期无鉴权，历史上任何人都可能已抓走令牌。改完鉴权后，旧令牌仍然有效 = 止血不彻底。

**改法:**
1. 为每个 `forward_combinations.external_uot_token` 重新生成（`bin2hex(random_bytes(24))` 同风格）。
2. 面板更新后，边车需重拉配置才能接受新令牌 → **短暂中断**（取决于边车轮询间隔）。排在 2.1 之后做。
3. 若当前确实零 `external_uot_enabled` 组合（现场所见），此项**可暂缓**，等真正启用 external-UoT 前执行即可——但 2.1 必须现在做。

---

## 3. P1 — 配置即生效（逻辑已写好，只差开开关）

### 3.1 启用 lease 限额

**现状:** `master/src/Support/UltraLeaseService.php:17-20` 三个 key **默认 0 = 无限**，但**拒绝逻辑和独立错误码都已实现**（`:22-34` 的 `lease_issue_rate_limited` / `lease_device_live_limit` / `lease_client_device_limit`）：
- `ULTRA_MAX_DEVICES_PER_CLIENT` — 一个账号最多几台设备持有 lease。
- `ULTRA_MAX_LIVE_LEASES_PER_DEVICE` — 单设备同时持有的未过期 lease 上限。
- `ULTRA_LEASE_ISSUE_RATE_PER_MINUTE` — 单 client+device 每分钟签发上限（窗口 `RATE_WINDOW_SECONDS=60`，`:38`）。

**改法:** 在面板配置里把三者设为合理非零值。建议起点（按你实际套餐调）：
- `ULTRA_MAX_DEVICES_PER_CLIENT` = 账号允许的在线设备数（与 `online_device_limit` 对齐）。
- `ULTRA_MAX_LIVE_LEASES_PER_DEVICE` = 单设备正常转发数上限 + 少量余量。
- `ULTRA_LEASE_ISSUE_RATE_PER_MINUTE` = 正常轮询频率的 2~3 倍（防自动化批量抽线）。

**验证:** 超限请求返回对应错误码；正常客户端不受影响（先在测试账号压一轮）。
**风险:** 设太紧会误伤正常多转发用户。**灰度:** 先设宽松值观察拒绝日志，再逐步收紧。

### 3.2 启用 attestation enforce（含二阶段堵 fail-open）

**现状（已核实 `:390-440`）:** `ATTESTATION_ENFORCEMENT_KEY` 默认 `report`。即使切到 `enforce`，`attestationRefusal` 也**只在**"已知指纹 + 上报哈希与该版本全部已发布哈希不符"时才拒；**以下一律放行（fail-open）**：无 session 行、无 attestation、未知指纹、平台不在强制列表、manifest 读不出。

**含义:** PC 端 attestation 本身无密钥、可照抄发布清单里的哈希（见 secrets 审计），再叠加 fail-open —— 所以**单纯切 enforce 收益有限**，但仍是必要的第一步。

**改法（两阶段）:**
1. **阶段 A（现在）:** 切 `ULTRA_ATTESTATION_ENFORCEMENT=enforce`，确认在用的各平台客户端版本都会上报 `ultra_runtime_fingerprint` + `ultra_runtime_self_sha256`（查 `client_device_sessions` 两列的覆盖率）。只要覆盖率足够，先把"已知指纹但哈希不符"的改包客户端挡掉。
2. **阶段 B（架构期）:** 把"无 attestation / 未知指纹"从 fail-open 改为 **fail-closed**（在确认全部存量客户端都上报后）。PC 端 attestation 要真正有意义，还需要配合架构里的"每设备签名票"——因为无密钥的自证哈希可伪造，真正的信任锚是设备私钥，不是自报哈希。
3. Android 已是硬件 keystore 真 attestation（`AndroidDeviceIdentity` + 服务端 `AndroidDeviceAttestationVerifier` 校验 Google 根/包名/签名摘要/硬件等级），**保持即可**。

**风险:** 阶段 B 若在存量客户端未全覆盖时切 fail-closed 会误杀老客户端 → 必须先看覆盖率，灰度放量。

---

## 4. P2 — 非 Ultra 转发的拓扑暴露（定性修正）

**子代理曾建议"对非 Ultra 转发也跑 `scrubUltraClientForward`"——这个建议是错的，会直接断功能。** 已核实 scrub 会删掉 `client_token`、`bond_sidecar_host/port`、`bond_sidecar_point_agent_config_json` 等（`master/src/Controllers/ClientAgentController.php` scrub 字段表）。而 `current_mode`/bond/mptcp 模式下，**客户端本身就是 bond/mptcp 的一端**，它必须拿到这些拓扑才能建运行时。删了就连不上。

**正确定性:**
- 这些模式里客户端连的是**你自己的 slave/sidecar**（不是 LeiGod），泄露的是**你自有的 IP + 每转发 HMAC 令牌**，不是第三方 LeiGod 账号——严重度明显低于 Ultra。
- HMAC 令牌绑 target、7 天过期（`BOND_TOKEN_TTL_SECONDS`，`ProvisioningService.php` `bondTokenTimestamps`），`token_secret` 随机且不下发客户端（`:2548`，fallback 到 `client_token`）。
- 所以 P2 的**真正解法 = 架构重构里的"每设备短票 + 入口端验签"**，而不是 scrub。在架构落地前，非 Ultra 的拓扑暴露**接受现状**（代价仅是你自有 box 的 IP 被看见 + 可在 7 天 TTL 内复用一个不记名令牌）。
- **可立即做的小收紧（可选）:** 把 `BOND_TOKEN_TTL_SECONDS` 从 7 天压到更短（如 24h），缩短令牌复用窗口；代价是客户端重渲染/重启更频繁（`bondTokenTimestamps` 有 rotation 防抖，需一并调）。

---

## 5. P3 — 客户端加固（抬高门槛，非根治）

| 端 | 现状 | 动作 | 备注 |
|---|---|---|---|
| Android | `leikwan-android/app/build.gradle.kts:42` `isMinifyEnabled=false` | 开 R8 + 规则 | 当初关是因旧工具链 R8 bug，需验证新链路 OK；开后类名混淆、反编译接近源码 → 接近混淆后 |
| PC ultra-client (Win) | garble 默认关（Defender 误报） | 评估重新开 garble 或签名白名单 | 权衡误报 vs 混淆 |
| PC ultra-client (Linux/Mac) | 脚本用 garble 但产物仍保留 `main.*` 符号 | 修构建脚本，确认产物真混淆 | 只 `-s -w`/`strip` 保不住函数名 |
| Desktop (.NET) | 纯 IL 可反编译 | 可选上 obfuscator | 收益有限（架构修完后客户端里没线路可偷） |

**定性:** P3 只增加"读协议"的难度，**偷不到线路的根本原因是架构**，不是混淆。优先级最低，架构修完后其收益进一步下降。

---

## 6. 架构级重构（核心根治）

### 6.1 现状数据流（已逐处核实）

**控制面（谁登录 LeiGod / 谁分发）:**
```
LeiGod ──登录──  源主机 10.10.10.171 (ultra_source_agent.py)
                 · 持账号: session_path / login_mobile / LEIGOD_PASSWORD / 调 leigod 登录二进制
                 · 取每条线 material: line_id/speed_ip/pc_port/line_user/line_token
                 · HMAC(source_secret) 上传 ──▶ 面板 (统一分发者)
```
- Ultra 模式：面板把 material 经 `/api/client/ultra/lease` **直接发给客户端**（`UltraLeaseService.php:283-290`，返回 `endpoint{ip,port}` + `credential{user,password}`）。
- bond/mptcp/leigod_mode：面板把**整份共享 `session.json`** 推给 slave（`NodeController.php:623` `leigodSharedSessionJson()` → `slave_agent.py:2295 apply_leigod_session`，落地 0600）。slave 自己不能登录（需手机+验证码+短信）。
- sidecar（uot-sidecar）：**完全不碰 LeiGod**。

**数据面（谁在建连那刻握着 LeiGod 账号）:**
- Ultra = **❌ 客户端**（终端用户设备）→ 这是线路泄露的根因。
- bond/mptcp = slave（你自有）→ 已经是对的。

**令牌:** 全对称 HMAC-SHA256。bond 会话票 `b64(payload).b64(hmac)`，claims = target_host/port/protocol/service_id/forward_id/issued_at/expires_at，默认 7 天（`ProvisioningService.php buildBondSessionToken` + `bondTokenTimestamps`）。无非对称、无设备身份、无数据面吊销。

### 6.2 目标数据流

```
控制面 (不变):
LeiGod ──登录──  171 (唯一登录点) ──material──▶ 面板 (统一分发)

数据面 (变):
客户端 ──每设备 Ed25519 短票──▶ 你的 slave 入口 ──LeiGod 账号(只留这)──▶ LeiGod ──▶ 目标
          ▲ 客户端永远看不到 LeiGod 账号         ▲ slave 用 171→面板投递的 material，不自己登录
```

**核心原则:**
1. **控制面不动:** 171 继续唯一登录 + 取线，面板继续统一分发。**不让任何 slave/sidecar 各自登录 LeiGod。**
2. **数据面改投递对象:** 把 Ultra 的 material 从"发给客户端"改成"发给你自有的 slave 入口"。
3. **客户端换凭证:** 客户端对 slave 只出示**每设备、短时效、非对称签名**的票，永不接触 LeiGod 账号。
4. **最小权限:** 面板按线把 material 发给"需要跑这条线的那台 slave"，不广播整份 `session.json`。

### 6.3 组件改动清单

| 组件 | 改动 |
|---|---|
| **171 源主机** | 不变（仍是唯一 LeiGod 登录 + 取线 + 上传面板）。唯一能持 `session.json` 全账号的机器。 |
| **面板** | ① 新增 Ed25519 签发端点，lease 改为签"每设备票"而非下发 LeiGod 明文；② 新增"按线 material 投递给指定 slave"的分发路径（替代给客户端）；③ 公钥分发给各 slave 入口；④ 收紧 `session.json` 广播为按线 material（最小权限）。 |
| **slave（Ultra 新入口）** | ① 接收面板投递的 per-line material（像今天客户端那样被动收，**不登录 LeiGod**）；② 对外接受客户端的 Ed25519 票，用公钥验签 + 查过期 + 查设备绑定 + nonce 防重放；③ 对内跑 LeiGod UoT 腿（复用现有 bond/mptcp slave 跑 leigod 的能力）。 |
| **客户端** | ① 不再从 lease 取 LeiGod endpoint/credential；② 改为向 slave 入口建连，出示面板签的每设备票；③ 其余 UoT/传输逻辑保留。 |
| **relay/数据面** | 验签逻辑放在 slave 入口（你自有），不是 LeiGod（第三方、改不了）。 |

### 6.4 Ed25519 每设备短票设计

**为什么从 HMAC 换非对称:** 现在的 HMAC 共享密钥一旦从某个入口泄露，任何人都能**伪造任意用户的票**。换 Ed25519 后，入口端只有**公钥**（泄露无害），私钥只在面板，谁都造不出有效票。

**票结构:**
```
token = base64url(payload) + "." + base64url(ed25519_sig)
payload = {
  device_id,            // 设备指纹（Android 硬件 keystore / PC 设备标识）
  client_id,            // 账号
  forward_id,           // 绑定具体转发
  line_binding,         // 绑定具体线路/目标（防跨线复用）
  issued_at,
  expires_at,           // 短: 建议 60~300s
  nonce                 // 入口端缓存去重防重放
}
```

**入口端校验（slave）:** 验签 → 查 `expires_at` → 查 `device_id` 绑定 → nonce 未用过 → 放行；并在入口端按 `device_id` 执行设备数/限速/**即时吊销**（面板推一个 revoked device 列表或短 TTL 自然失效）。

**偷到票的后果:** 几十秒过期 + 绑单设备 + 入口可即时拒 → 不能转卖、不能多机复用。**挡不住**的仍只有"付费用户用改包客户端在自己这台机器上用"——用设备绑定 + Android 硬件 attestation 抬成本。

### 6.5 最小权限：material 投递替代 session.json 广播

**现状过度授权:** bond/mptcp 把**整份 `session.json`（=整个 LeiGod 账号）**推给每台 leigod slave。某台 slave 被攻破 = 整个账号沦陷（能枚举/取任意线）。

**改法:**
- `session.json` **只留 171 一台**。
- 面板只把**按线 material** 发给"被指派跑这几条线"的 slave，不发整份 session。
- slave 被攻破时，泄露面收敛到"它手上那几条线的临时 material"，不是整个账号。
- 这同时覆盖了 §4（P2）的非 Ultra 拓扑收紧。

### 6.6 吞吐代价与分线取舍（必须认的 trade-off）

- Ultra 当初**直连 LeiGod 就是为了省掉这一跳**，榨吞吐。插入 slave 入口 = 多一段 CPU/延迟。
- 记忆在案：LeiGod UoT 是**用户态逐包 + WG crypto，2vCPU slave 上单腿就打满 CPU**（见 `leigod-uot-cpu-bound`）。多这一跳会进一步吃 CPU。
- **结论：这条线上"保密"和"吞吐"真实对立。** 按线取舍：
  - 对**吞吐敏感的大带宽线** → 可保留直连 + 认命（或上更大 slave）。
  - 对**要防盗/防转卖的线** → 走入口化。
  - 用配置门控（per-line / per-combination 开关），不搞一刀切。

### 6.7 分阶段迁移（灰度）

1. **阶段 1:** 面板加 Ed25519 签发 + 公钥分发（不改数据面，先让客户端"额外"带票，入口先 report-only 验签）。
2. **阶段 2:** 选 1~2 条线做入口化 canary：material 改投 slave、客户端改连 slave、入口 enforce 验签。对照吞吐/CPU/稳定性。
3. **阶段 3:** 收紧 `session.json`→按线 material（§6.5）。
4. **阶段 4:** attestation 阶段 B（§3.2）fail-closed。
5. **阶段 5:** 按线取舍放量（§6.6），非 Ultra 拓扑随之收紧（§4）。

---

## 7. 落地顺序与里程碑

```
里程碑 M0 (本周, 零停机):   P0-1 鉴权 + P1-1 限额 + P1-2 阶段A enforce
里程碑 M1 (止血闭环):        P0-2 轮换令牌(若启用 external-UoT) + BOND_TOKEN_TTL 压缩(可选)
里程碑 M2 (架构地基):        §6.7 阶段1 Ed25519 签发/公钥分发/report-only 验签
里程碑 M3 (架构验证):        §6.7 阶段2 单线入口化 canary + 吞吐/CPU 对照
里程碑 M4 (收口):            §6.5 最小权限 + §3.2 阶段B fail-closed + 按线放量
里程碑 M5 (加固):            P3 Android R8 + garble 构建
```

---

## 8. 验证与回滚矩阵

| 项 | 验证 | 回滚 |
|---|---|---|
| P0-1 | 无/错 token→401；真边车带 token→成功 | 还原路由鉴权；**前置盘点现网边车调用方式** |
| P0-2 | 新令牌生效、旧令牌被拒 | 保留旧令牌一段重叠期再废 |
| P1-1 | 超限→对应错误码；正常客户端不受影响 | key 调回 0 即恢复无限 |
| P1-2 A | 改包客户端(已知指纹)被拒；正常放行 | key 调回 `report` |
| P1-2 B | 无 attestation 被拒；存量覆盖率已确认 | 调回阶段 A |
| 架构 canary | 单线：握手通、吞吐可接受、CPU 不爆、客户端拿不到 LeiGod 明文 | 该线切回直连（配置门控） |

---

## 9. 需你拍板的开放问题

1. **现网边车调用方式:** P0-1 执行前必须确认线上 external-UoT 边车是带 `agent_token` 还是靠来源 IP 自认（决定会不会误挡真边车）。要我去 SSH 盘点吗？
2. **external-UoT 是否还要用:** 若已弃用（现场 0 组合），P0-1 仍要做（锁门），P0-2 可免。确认一下。
3. **架构范围:** 入口化是**全 Ultra 切换**，还是**只对指定线**（§6.6 分线）？后者更稳、代价可控，我倾向后者。
4. **slave 容量:** 入口化吃 CPU，现有 2vCPU slave 够不够？要不要先备更大 box 再做 canary？
5. **限额初值:** P1-1 三个值你想设多少（给我套餐/正常用量我来定具体数）。

---

---

## 附录 A — 2026-10-02 现场实测结论（源主机 10.10.10.171 + 生产面板）

### A.1 凭证机制（实测敲定，推翻多个早先假设）
- **源主机 `ultra_source_agent.py` 是唯一 LeiGod 登录点**（手机 15601584917），取 material 上传面板；面板分发。`material_lifetime=1800`、`poll=60s`。
- **凭证按时间窗全局轮换、与账号无关。** A-B-A 实测：同一条 idle 线 742，用原账号→新账号（独立 hardware_id）→原账号 各拿一次，三次拿到的 `line_user`/`line_token` **完全相同**。
  - 推论①：**多账号给用户分组隔离行不通**（原 §6.5/分组方案作废）。
  - 推论②：**"测试污染生产"是误判** —— 不同账号本就拿到同值，新账号不可能把生产带偏；早上看到的变化全是自然轮换。
- **偷到的凭证实测存活 ~8 分钟。** 抓取后第 6 分钟面板轮换（`eef54665`→`50c45fa7`），旧凭证仍活；第 8 分钟才真失效（轮换后还多撑 ~2min）。全程零用户失败（client 自动跟随）。
- **轮换是 re-login/acquire 驱动，非纯定时。** 不人为触发时，单条线可稳定数分钟不变。

### A.2 "让旧 S5 失效" 的正确理解（写入 plan）
- 雷神每隔几分钟 flush 本线 S5 凭证，旧的在轮换后约 8 分钟内彻底失效 → **静态抄一份 S5 的人，活不过 10 分钟。**
- **但前提是小偷拿不到新的。** 若他能自动续（curl 拉面板 / 挂真客户端抠内存），每次 flush 后 8 分钟内续上 → 自然 flush 对他无效。
- 结论：**"雷神 flush 负责让旧的过期；我们必须负责让他拿不到新的。" 两者叠加才有效。** 核心动作 = 堵 curl（lease 每设备非对称加密下发）。

### A.3 计费与限速为什么会被绕过（关键，回答"新 S5 流量如何计费/限速"）
现网实现（已核对代码）：
- **计费 = 客户端自报字节。** 客户端 config-pull 时 POST `ultra_usage`/`usage_bytes_in|out`（`ClientAgentController.php:1272-1379`）→ `recordUltraUsage`（`ProvisioningService.php:6082`）累加 `services.traffic_used_bytes`/`billing_cycle_traffic_used_bytes`。
- **配额 = 套餐额度 − 自报用量。** `quota_remaining_bytes`（`ProvisioningService.php:5942`）；发 lease 时 `<=0` 即 `quota_exhausted` 拒发（`UltraLeaseService.php:197-199`）。
- **限速 = 客户端内 `Limiter`。** 速率在 ultra-client 本地算。
- **三者的度量点全在客户端里。** 他的翻译脚本替掉了客户端：不自报 → 用量永不累加 → 配额永不扣 → lease 永不拒 → 免费无限；本地 `Limiter` 没了 → 不限速。

**结构性真相：计费(字节)与限速(Mbps)本质上要求"在数据路径上"。Ultra 直连模式刻意不在数据路径上，所以这两样在直连路径上永远做不到防绕过。** 他直连 LeiGod，路径上你能控制的只剩一个点——**lease 签发**（他每 ~8 分钟必须来面板要一次新 S5）。

### A.4 可行的计费/限速方案（不需要 slave，把度量从"字节"挪到"lease 水龙头"）
1. **按 lease 时间/并发计费，不按自报字节。** 他必须每 ~8 分钟领一次 lease；把"lease-分钟数 / 并发 lease 数 / 每周期 lease 总时长"作为计费与限制单位——这些**在面板度量，堵 curl 后他绕不过**。额度用尽 → 拒发 lease → 8 分钟内他手里的自动 flush 掉。
2. **lease 水龙头硬上限：** 每账号并发 lease 数、签发频率、每周期 lease-分钟。现网已有半成品（`ULTRA_MAX_LIVE_LEASES_PER_DEVICE=512` 等），但当前靠"自报字节配额"驱动，需改成**时间/lease 驱动**。
3. **限速（Mbps）在直连路径上做不到**，只有两条诚实选项：
   - (a) 用 lease-时长/配额近似"限量"（粗粒度，不是真·Mbps 帽）；
   - (b) 要真·Mbps 帽，**必须让流量过一个你自有的限速 hop**（即 slave）——与 200Mbps 约束冲突，需更大带宽的 box。没有第三条路，因为限速必须在字节流里。
   - 注：线路本身有 LeiGod 的 `bandwidth` 字段（**全线共享**、LeiGod 设定），天然封顶他的峰值，但那不是你的 per-user 控制手段。
4. **建议改计费模型：** 直连模式从"按 GB"改为"按时间 / 按并发线路数"订阅。这样"数不到他的字节"不再是问题——他付的是并发/时长，而并发/时长你在 lease 水龙头能强制执行。
5. **多机复用仍是硬伤：** 一份抽出的 S5 在 8 分钟内可被多台 Linux 同时用，LeiGod 不绑 IP、你也看不到这种扇出。唯一缓解 = lease 短 + 每设备 + 不可导出（curl-fix + 加密），让他能同时持有的 lease 数 ≈ 他装的真客户端数，且每个只活 8 分钟。

### A.5 实测补充的生产事实
- 生产限额**其实已配**（早先"默认 0"为误，以线上 .env 为准）：`ULTRA_MAX_DEVICES_PER_CLIENT=32`、`ULTRA_MAX_LIVE_LEASES_PER_DEVICE=512`、`ULTRA_LEASE_ISSUE_RATE_PER_MINUTE=600`、`ULTRA_ATTESTATION_ENFORCEMENT=enforce`、`BOND_TOKEN_TTL_SECONDS=604800`。多数账号 `online_device_limit=1`（35 个）→ 32 太松，应对齐。
- attestation 虽 `enforce` 但代码 fail-open（缺失/未知指纹放行）+ 存量客户端指纹停在 09.11-12 → 实际几乎不拦。
- `/external-uot-config` **确认正在泄露**：`?host=185.220.238.143` 匿名返回 23 个转发的 token（`targets:[]`=allow-any）；12/22 组合启用 external_uot。
- 原生产账号 A=幸福的金铃子（`is_pay_user=1`，剩 ~4249h，健康）。

### A.6 客户端 SOCKS5 转译（用户已被人实现）
Win 原生 SOCKS5 只听 127.0.0.1（`native_socks5.go:578`）、不验密码、目标仅端口≥1000 限制（`native_policy.go`，默认 restricted）；LeiGod 握手 = SOCKS5 + 2 自定义标记 + `credentialChecksum`（`main.go:128`，两行）。用户拿 S5 明文 + 几十行翻译脚本即可在 Linux 直连 LeiGod，绕开客户端全部限速/attestation/设备计数。根因同 A.3 = 客户端直连 LeiGod。

### A.7 最终简化方案（据实测收敛）
| 项 | 结论 |
|---|---|
| 关 `/external-uot-config` + 轮换 token | ✅ 第一优先，零停机 |
| **lease 每设备非对称加密下发（堵 curl）** | ✅ **核心防线**；配 ~8min 自然 flush → 偷到的难续 |
| 计费/限速改走 **lease 水龙头**（时间/并发，非字节/Mbps） | ✅ 唯一不需 slave 又不可绕过的手段 |
| 真·Mbps 限速 | ⚠️ 必须过自有 hop（slave），与 200Mbps 冲突 |
| 多账号分组 | ❌ 实测无效，删除 |
| 主动 re-login 一键作废全部 S5 | ⚠️ 可行但只能全局、短暂影响所有人 |
| 限额对齐 `online_device_limit` + 修 attestation fail-open | ⚠️ 已开但等于没拦，需收紧 |

---

*🤖 本方案由 Claude Code 生成，所有代码位置与默认值均已对照 `master` 分支源码核实；`/external-uot-config` 无鉴权、凭证 8 分钟存活、多账号不隔离均于 2026-10-02 现场实测确认。*

---

## 附录 B — 2026-10-02 实施记录（分支 `feat/line-protection-2026-10-02`，基于 master `e49abe02`）

按附录 A.7 收敛方案实施；附录前的"slave 入口化 + Ed25519 票"架构**未做**（A.7 已放弃走 slave）。

### B.1 已上线生产（117.55.193.164）

| 项 | 实施 | 验证 |
|---|---|---|
| P0-1 `/external-uot-config` 鉴权 | 只认 sidecar_point 自己的 `agent_token`（Bearer 或 `?agent_token=`）；删 point_id/host/ip/来源地址选点；401 | 匿名、`?host=`、`?ip=`、`?point_id=`、错 token、`/artifacts/` 别名全 401；point 5 Bearer 拿到 23 forward |
| 目标绑定（原 `targets:[]`=allow-any） | 每个 forward 只放行自己的 target；pool 绑定额外放行健康探测 `1.1.1.1:53`（`uot_pool_sync.py` 用它） | 本地真 Go sidecar：自身 target 放行，8.8.8.8/127.0.0.1/错 token 拒；两个 pool 节点新配置 `healthy:true` |
| P0-2 令牌轮换 | 12 个组合全部换新；5 台独立 sidecar 原地替换 token + 绑定 target（保留各自 forward 集合，不重渲染）；配置改 0600 | 12/12 已换；5 台 HUP 热载 `credentials_changed` 全量；每台全部 forward 新 token 放行、外部目标/本机/错 token 拒；切换 12:00:51–57 UTC |
| `/api/client/ultra/materials` | 退役返回 410（客户端无调用方；它吐全部线路明文，只用客户端已有 token 派生的密钥封装） | 410 `ultra_materials_retired`；`ULTRA_MATERIALS_ENDPOINT=enabled` 可回滚 |
| lease 加密下发（面板侧） | `X-Leikwan-Lease-Key`（P-256）→ endpoint/credential/external_uot 封进 `sealed`（ECDH-P256+HKDF-SHA256+A256GCM，AAD 绑 lease_id）；设备 key 钉在 `client_device_sessions`（迁移 20261002）；`ULTRA_LEASE_SEALING=optional` | 协议见 `docs/specs/2026-10-02-ultra-sealed-lease-v1.md`（含测试向量，PHP/Python cryptography/.NET/Kotlin/Go/纯 Python 六种实现互通） |
| P1-1 限额 | 实测：设备并发 live lease 最大 6、每分钟签发峰值 184、lease 平均 TTL ~5 s；设 `ULTRA_MAX_DEVICES_PER_CLIENT=10`（=最大 online_device_limit）、`MAX_LIVE_LEASES_PER_DEVICE=256`、`ISSUE_RATE_PER_MINUTE=300`。每账号设备数真正的闸门是已有的 `online_device_limit`（lease 请求也走它） | 改后 0 refusal，签发速率正常 |
| P1-2 attestation fail-open | 新 `ULTRA_ATTESTATION_UNATTESTED=allow|deny`：deny 时无会话/无 attestation/未发布指纹/缺 platform 头一律拒 | 生产保持 **allow**：24h 活跃 8 台桌面/Linux 设备里 4 台无已发布指纹，切 deny 会误杀一半 |

**事故：** 首次部署时我手动跑了迁移，而面板会在请求时自动执行 `master/migrations/*.sql`，重复 ALTER 失败导致**全站 500 约 70 s（11:47:00–11:48:09 UTC，176 个请求）**。已在 `schema_migrations` 补记录后重新上线。以后面板迁移**只放文件让 MigrationRunner 执行，不要手动 ALTER**。

### B.2 已完成代码、未发布（需各端发版）

| 端 | 状态 | 发版前须知 |
|---|---|---|
| Desktop .NET（Win/mac） | 发 header + 开 sealed；Win 私钥 DPAPI（按账号 SID 分文件），mac 0600 文件；Core 915/App 122/Mac 288 测试全过 | Win GUI 与 headless 服务共用 device_id 但 key 不同 → `require` 模式下会互相 `lease_device_key_conflict`；mac 0600 路径未在真 Mac 上跑 |
| Android | API31+ Keystore `PURPOSE_AGREE_KEY`，26–30 软件 key 由 Keystore AES 包裹；389 单测全过 | Keystore 路径只编译未真机跑；R8 规则已写、构建零警告但**仍关**，需真机一轮（登录→拿 lease→起 VPN）再开 |
| Linux client-agent | 纯 Python P-256/HKDF/AES-GCM（新模块 `leikwan_lease_seal.py`，~3.6 ms/次），key 0600 在 state_dir | 发版要加 `client_lease_seal` manifest 条目、刷新 agent/安装器哈希、重建 frozen 二进制；面板需同时上 `/artifacts/leikwan-lease-seal.py` 路由 |
| leikwand（OpenWrt） | crypto/ecdh，key `<data_dir>/lease_key.json` 0600；vet/test 过、5 个架构交叉编译过 | 需重建 openwrt 包 |
| ultra-client 混淆（P3） | 真因：发布的 Win/Linux/mac 产物走的是另一条纯 `go build` 发布路径（versions.json 记 `obfuscator:none`），保留 285 个 `main.*` 名；构建脚本已加产物校验，garble 固定 v0.17.0（v0.18 需 Go 1.27） | 重新发一次 garble 构建会改变 attestation 哈希，需走正式 runtime 发版 |

### B.3 切 `require` 的前置条件

1. 上述客户端全部发版且存量升级完（看 `client_device_sessions.ultra_lease_key_sha256` 覆盖率）。
2. 解决 Windows GUI/服务同 device_id 不同 key 的冲突。
3. 然后 `ULTRA_LEASE_SEALING=require`；回滚 = 改回 `optional`。

### B.4 另发现（未处理）

- 一台 sidecar（`2a0f:5707:aaf1:ede6::1`，与 89.144.30.80 同组）自 09-23 起约每 2–3 分钟重装一次 `install-bond-sidecar.sh`（日志 5308 次），属更新循环 bug，与本次无关。
- 独立 sidecar 主机上有 target 为 `127.0.0.1:24977`（fwd 190）、`70.36.96.183:62101`（fwd 246）的 forward，绑定后仍按原样工作。

### B.5 回滚点

- 面板文件：`/root/codex-backups/line-protection-20261002/`；`.env` 备份同目录 `master.env`。
- 旧令牌：同目录 `external_uot_tokens_before.tsv`（0600）；独立 sidecar 旧配置：各主机 `/root/backups/lp-20261002/`。
