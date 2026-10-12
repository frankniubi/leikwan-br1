# 用户自带节点上云（平台托管）与转发配对

**日期:** 2026-10-12
**分支:** `feat/hosted-node-pairing`（基于本地 `master` 27d6e801）
**状态:** 面板 + Android 已实现并通过测试；未部署、未 push
**范围:** Shadowsocks、SOCKS5（必做），VLESS（已做）；按客户端 ID（`clients.id`）存储

---

## 1. 需求理解

原话："对用户自带节点上云(平台托管)做配对支持。自带节点只支持 shadowsocks, socks5。vless 最好也支持一下。"

现状：节点（ss/vless/socks5/wg…）是用户自己的，只存在一台设备的 Room 表 `proxy_nodes` 里；"转发 ↔ 节点"的配对只是 `ConnectionsViewModel.nodeByForward`（内存态）+ `prefs.selectedProxyId`。换手机、重装、多台设备都要重新导入、重新配对。

"上云（平台托管）"的含义（据代码与产品模型决定）：

- 面板**替用户保存**他自己的节点和"哪条服务转发走哪个节点"，按**客户端 ID** 存。该客户端的**每一台受信设备**收到同一份节点和同一份配对，新设备/重装后自动可用。
- 平台**只存储和同步**，不经手流量：设备上的 sing-box 仍然拨 `127.0.0.1:<转发本地端口>`，转发把字节带到用户节点的真实地址。节点永远不进入 relay/forward 配置。
- 不是厂商节点选择器：控制台页面叫"我的节点"，只列用户自己添加/上传的节点。
- 不削弱"只有官方客户端能用转发核心"：节点读取接口只对**已注册、已证明（attested）的 Android 设备**开放，和配置拉取同一套门禁；节点本身也不给转发核心任何新能力。

产品负责人追加的两条决定（已落实）：

1. 数据按 `clients.id` 存，而不是按用户/租户。归属判断 = "请求方客户端拥有这一行"。控制台按客户端管理。
2. 同步必须双向：本地 → 云（单个上传 + 全部同步），云 → 本地（新设备自动落地）；定义身份/去重、冲突规则、本地独有节点的处理。

## 2. 数据模型

迁移：`master/migrations/20261012_add_client_hosted_nodes.sql`（同时追加到 `schema.sql`）。只建两张新表，**无外键、无 ALTER**（生产库旧表类型不确定，外键失败会让自动迁移把全站打成 500，见 panel-auto-migrations）。

`client_hosted_nodes`

| 列 | 说明 |
|---|---|
| `id` | 服务端节点 ID（设备上存为 `proxy_nodes.hostedId`） |
| `client_id` | 归属客户端 |
| `name`, `protocol` | 仅有的明文列（显示用） |
| `sealed_config` | 整个规范化节点（server/port/凭据/参数）AEAD 密文 |
| `fingerprint` | 连接身份的 keyed HMAC，`UNIQUE(client_id, fingerprint)`，用于上传幂等 |
| `version` | 每次修改 +1 |
| `source` | `console` / `device` |
| `created_by_user_id`, `created_at`, `updated_at` | |

`client_hosted_node_pairings`：`PRIMARY KEY (client_id, forward_id)`，`node_id`，`source`，`updated_at`。删除节点时服务层同时删配对；转发不再属于该客户端或被删时，读取时直接忽略。

上限：每个客户端 32 个托管节点（`HostedNodeService::MAX_NODES_PER_CLIENT`）。

## 3. 加密

`HostedNodeVault`：

- 密钥：`HOSTED_NODE_ENCRYPTION_KEY`，未配置则沿用 `ULTRA_TOKEN_KEY` / `ULTRA_CACHE_KEY`；用 HMAC 派生两把子密钥（封装用、指纹用），不直接复用 Ultra 密钥。
- 算法：XChaCha20-Poly1305（sodium），无 sodium 时 AES-256-GCM（openssl）。生产 PHP 两者都有。
- AAD = `client_id`：把密文复制到别的客户端行上解不开。
- 无密钥或无 AEAD → 写入直接失败（`hosted_nodes_unavailable` 503），绝不明文落库。
- 不写日志；错误只返回字段名（`field`），不回显输入。控制台里密码/UUID/密钥**只写不读**。

## 4. 协议校验（`HostedNodeSpec`）

输入可以是分享链接（`uri`）或结构化字段；输出统一的规范结构，再渲染成 Android `ProxyUriParser` 能直接导入的规范分享链接。

- **通用**：server 为域名或 IP（IPv6 规范化、去方括号）；拒绝 loopback / 0.0.0.0 / link-local / localhost（设备拨的是 127.0.0.1，这些地址不可能是可达节点）；端口 1–65535；名称最多 96 字节、按 UTF-8 整字截断（不用 mbstring）；URI 最多 4096 字节、拒绝控制字符。
- **Shadowsocks**：只收 AEAD 与 2022 方法（`2022-blake3-aes-128-gcm/-256-gcm/-chacha20-poly1305`、`aes-128/192/256-gcm`、`(x)chacha20-ietf-poly1305`），拒绝 rc4/cfb 等旧流密码；2022 方法校验 base64 PSK 长度（支持多用户 `iPSK:uPSK`）；插件只收 `obfs-local`（`simple-obfs` 归一化）和 `v2ray-plugin`；支持 UoT。解析 SIP002（base64url 或百分号编码 userinfo）和旧式整段 base64。
- **SOCKS5**：无认证，或用户名+密码（RFC 1929，各 1–255 字节，必须成对）；兼容 `socks://` 与 v2rayN 的 base64 userinfo。
- **VLESS**：UUID 格式；`encryption` 必须为 none；security = none / tls / reality；transport = tcp / ws / grpc / httpupgrade；flow 只允许 `xtls-rprx-vision` 且要求 TLS/REALITY + 原始 TCP；REALITY 要求 43 位 base64url 公钥、≤16 位偶数长度十六进制 short id、真实 SNI，缺省 uTLS 指纹补 `chrome`；TLS 支持 SNI、ALPN（h2/http/1.1/h3）、uTLS 指纹、allowInsecure；ws/httpupgrade 校验 path/host，grpc 要求 serviceName。

Android 侧配套修正（VLESS 能真正跑起来所必需）：

- `ProxyUriParser`：VLESS 的 query / fragment 只解码一次（原来 `/ray?ed=2048` 这类 path 会被二次解码），IPv6 去方括号。
- `SingboxConfigBuilder`：出站拨 127.0.0.1，TLS 没写 SNI 时回落到节点自身域名（否则握手里报的是 127.0.0.1）；REALITY 缺省启用 uTLS（sing-box 必需）；支持 fp/alpn/allowInsecure 与 httpupgrade。

## 5. 面板接口

### 5.1 客户端接口（仅受信 Android 设备）

门禁与 `/api/client/configs/pull` 相同：客户端令牌 → 访问策略（地域/黑名单）→ `X-Device-Platform` 必须是 android → `DeviceControlService::requireActive`（已注册、已证明、设备会话有效）→ 在线设备数限制。响应 `no-store`。写操作每客户端每分钟 30 次。

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/client/hosted-nodes` | 完整列表：`revision`、`nodes`（含凭据、`settings`、规范 `uri`、`version`）、`pairings` |
| POST | `/api/client/hosted-nodes` | 上传一个（`uri` 或字段，可带 `pair_forward_id`）；同一连接重复上传返回已有 ID（`created:false`） |
| POST | `/api/client/hosted-nodes/batch` | "全部同步到云端"：最多 32 条 `{ref, uri, name}`，逐条成功/失败，算一次写 |
| PUT | `/api/client/hosted-nodes/{id}` | 修改（`uri` 或仅 `name`）；与其他节点重复则 409 |
| DELETE | `/api/client/hosted-nodes/{id}` | 删除（所有设备） |
| PUT | `/api/client/hosted-node-pairings/{forwardId}` | `{node_id}` 设定配对；缺省/null 清除显式配对 |

所有写接口都返回完整列表，设备一次调用即可重同步。错误格式：`{ok:false, error, field?}`，错误码 `hosted_node_invalid / _not_found / _forward_not_found / _limit_reached / _duplicate / hosted_nodes_unavailable / rate_limited`。

### 5.2 配置拉取

`/api/client/configs/pull` 的 `client` 里新增 `hosted_nodes_revision`（只是变更标记，**不含任何节点内容**）。没有托管节点的客户端为空串，且不进入 `config_revision`，所以对现有客户端（Linux/Windows/mac/Docker 长轮询）零影响；有托管节点时它进入 `config_revision` 的 extra，变化会打破 304。查询失败时静默为空，不影响拉取。

### 5.3 租户控制台

侧栏 Services 下新增"我的节点"（`/tenant/hosted-nodes`），默认客户端，拥有多个客户端时可切换（只能选自己的）。

- 添加：粘贴分享链接，或按协议填写字段。
- 列表：名称、协议、地址、参数摘要、来源（控制台/设备）、删除。凭据不再显示（要改就删掉重加；设备端可直接编辑）。
- 配对：每条转发一个下拉框（"自动（与目标一致）"/具体节点）；若配对节点地址 ≠ 转发目标，给出提示。
- CSRF 校验；错误提示固定文案，只说明哪个字段不对，不回显输入。中文（简/繁）文案已加入 i18n。

## 6. 配对规则

- **显式配对**：控制台或设备设定，存在 `client_hosted_node_pairings`。
- **目标匹配（自动）**：没有显式配对、且恰好一个托管节点的 `server:port` 等于转发目标时，自动配对。理由：转发本来就把流量送到这个地址，只有这个节点能通过它工作。新设备因此即使没人手动配对也能直接用。
- 显式优先于自动；清除显式配对后回落到自动。
- 设备上：`ConnectionsViewModel` 给每条转发选节点时，顺序是"本次会话的手动选择 → 面板配对 → 第一个节点"；面板配对变化（控制台或另一台设备改了）时会重新覆盖；用户在设备上选了**托管节点**会写回面板（所有设备生效），选了**本地独有节点**只在本机生效（面板无法引用本地节点）。

## 7. 双向同步、身份与冲突规则

### 7.1 身份与去重

- **服务端节点 ID** 是托管节点的唯一身份，设备上存为 `proxy_nodes.hostedId`（Room 迁移 3 → 4，可空列）。一个 hostedId 在设备上只对应一行（同步时发现多行会删掉较新的重复行）。
- **服务端去重**：`fingerprint = HMAC(client_id + protocol + server + port + settings)`，同一客户端重复上传同一连接（来自同一或不同设备）总是得到同一个 ID。
- **设备端去重**（`HostedNodeIdentity`）：按"协议 + 地址 + 端口 + 认证/传输关键参数"计算身份键，忽略名称、uTLS 指纹、ALPN 等外观参数和缺省值写法差异。用于：
  - 云 → 本地时，新托管节点若与本机已有本地节点相同，**收编该行**（保留本地 ID，选中状态和配对不丢），不新增；
  - 本地导入（链接/扫码/剪贴板/订阅刷新）时，跳过与已托管节点相同的条目。

### 7.2 本地 → 云

- 单个上传：节点列表里的云朵按钮；本地行直接变成托管行（写入 hostedId，并脱离订阅，避免订阅刷新把它删掉）。若面板返回的是已存在的 ID 且本机已有该托管行，则删掉这份本地副本。
- 全部同步：把所有本地 SS/SOCKS5/VLESS 节点一次批量上传（每批 ≤32），返回"已上传 / 云端已有 / 不支持 / 失败"计数。WireGuard、VMess 等不支持的协议留在本地，计入"不支持"。
- 本地独有节点：同步**从不自动上传、也从不修改**它们，只有用户点上传/全部同步才会上云。

### 7.3 云 → 本地

- 拉配置后比较 `hosted_nodes_revision` 与本机记录（含客户端 ID）；变化才去取列表。没有托管节点且本机也没有托管行时不发请求。
- 落地：按 hostedId 更新同一行（名称/地址/参数以云端为准，`enabled`、`createdAt`、`forwardId` 等设备本地字段保留）；新节点插入或收编；云端已不存在的托管行删除。
- 切换到另一个客户端登录时（客户端 ID 变化）强制重新拉取，旧客户端的托管行会被清掉。
- 取列表失败不影响拉取；本机记录的 revision 只在成功落地后更新，下次拉取自动重试。
- 设备上也有"从云端刷新"按钮。

### 7.4 冲突规则：写穿透 + 服务端顺序决定（Last-Writer-Wins）

- 托管节点在设备上只是面板副本的缓存。设备端的修改、删除、配对都是**写穿透**：先调面板接口，成功后按面板返回的列表落地；面板拒绝（校验失败/离线）时本地不变并提示错误。
- 不做离线写队列，所以设备副本永远不会和面板分叉，也就不需要比较 `updated_at`：**哪个写入先到面板、哪个后到，面板的到达顺序就是最终顺序**，后到者覆盖前者（LWW）。其他设备在下一次拉配置（revision 变化）时收敛。
- 删除优先级：任何一端删除即对所有设备删除（设备端删除托管节点 = 调面板 DELETE）。若另一台设备随后对已删除的节点做修改，面板返回 `hosted_node_not_found`，该设备重新同步后节点消失。
- 选择这个方案的理由：节点数量小、改动频率低、编辑必须在线校验（面板做协议校验和加密），离线编辑的价值远小于合并冲突带来的复杂度和"节点被悄悄改坏"的风险。

## 8. Android 实现位置

| 文件 | 作用 |
|---|---|
| `data/Entities.kt`、`data/AppDatabase.kt`（v4, `MIGRATION_3_4`）、`data/ProxyNodeDao.kt` | `hostedId` 列与查询 |
| `api/HostedNodeApi.kt` | 独立 Retrofit 接口与 DTO（不改 `PanelApi`，测试里的 fake 不受影响） |
| `data/hosted/HostedNodeSync.kt` | 双向同步、写穿透、批量上传、配对推送 |
| `data/hosted/HostedNodeIdentity.kt` | 设备端身份键与导入去重 |
| `data/hosted/HostedNodePairingStore.kt` | 客户端 ID、revision、已解析的 forward → 本地节点配对（无凭据） |
| `proxy/ProxyShareUri.kt` | 本地节点 → 分享链接（上传用） |
| `api/PanelRepository.kt` | 拉配置后按 revision 触发同步；`hostedNodes` 访问器 |
| `ui/connections/ConnectionsViewModel.kt` | 面板配对作为默认值、变化时覆盖、选择托管节点时写回（只加了两个构造参数和少量逻辑） |
| `ui/proxies/ProxiesViewModel.kt`、`ProxiesScreen.kt` | 上传/全部同步/刷新、托管节点编辑删除走面板；UI 只有最小钩子 |
| `res/values*/strings_hosted_nodes.xml` | 独立字符串文件，不碰 `strings.xml` |

托管节点在设备上**可编辑**（经面板写穿透），理由：用户在手机上修改密码等是常见操作，写穿透保证所有设备一致，而不必回控制台。

### 希望在 UI 重设计时做的（本分支未做）

- 节点卡片上的"云端"徽标改为正式样式；上传/全部同步/从云端刷新放进节点页的溢出菜单。
- 删除托管节点前二次确认，并说明"会从该客户端所有设备删除"。
- 连接页的"转发 ↔ 节点"选择器里标出哪些配对来自面板（显式/自动）。
- 全部同步完成后展示失败条目及原因（`HostedNodeBatchResult.error/field` 已有数据）。

## 9. 测试

面板（`php -n -c .tmp/lkdev/php.ini master/tests/<file>.php`）：

- `hosted_node_spec_test.php`：三种协议的解析/校验/规范链接往返、拒绝项、名称截断、摘要不含凭据、无 mbstring。
- `hosted_node_service_test.php`（sqlite）：加密落库不含明文、AAD 防挪用、错误密钥/无密钥、幂等上传、客户端隔离、显式/自动配对、分组转发、revision 变化、更新/删除/上限。
- `hosted_node_client_api_test.php`（sqlite + 真实 `DeviceControlService`）：令牌/平台/设备会话/信任状态门禁、列表/上传/批量/修改/配对/删除、跨客户端隔离、错误不回显。
- `hosted_node_tenant_console_test.php`（`Bootstrap` 端到端）：登录、CSRF、只能操作自己的客户端、表单与分享链接两种添加、密码只写不读、配对与目标不一致提示、删除连带配对。
- `hosted_node_contract_test.php`：路由、门禁、拉取只带 revision、迁移无外键/无 ALTER/无明文凭据列、导航与中文文案。

Android（`./gradlew.bat --offline -q :app:testDebugUnitTest`）：

- `HostedNodeSyncTest`：落地与配对解析、云端修改/删除、本地节点不受影响、收编本地行、revision 门控与切换客户端、上传与重复上传、全部同步计数、写穿透失败不改本地、配对推送。
- `ProxyShareUriTest`：三种协议导出/导入往返、ws path 只解码一次、IPv6、身份键去重。
- `AppDatabaseHostedNodeMigrationTest`、`HostedVlessOutboundTest`（REALITY uTLS、SNI 回落、httpupgrade）、`ConnectionsViewModelHostedPairingTest`、`PanelRepositoryRouteRuleSyncTest`（拉取触发同步、旧面板无字段、失败不影响拉取）。

## 10. 部署注意（本分支未部署）

- 迁移文件随代码上传即可，由面板自动执行；**不要手动 ALTER**。
- 注意 `prod-vs-master-drift`：master 有若干生产未记录的迁移，整包部署前先处理，否则会在本迁移之前失败。
- 确认生产有 `ULTRA_TOKEN_KEY`（或另配 `HOSTED_NODE_ENCRYPTION_KEY`）；轮换该密钥会让已有托管节点解不开（列表里会跳过），需先规划迁移。
- 需要发布新 Android 版本，设备才会同步；旧版本客户端看到的只是 pull 里多一个字段。

## 11. 不在本次范围（后续）

- 桌面客户端（Windows/macOS/Linux）：需要各自的受信设备方案后才能放开读取接口（目前接口只认 Android 已证明设备）。
- iOS（`feat/ios-client`）：可直接复用同一接口与同步模型。
- 专线 API（用户 `api_token`）下的节点管理接口。
- 控制台内直接编辑节点（目前删了重加；设备端可编辑）。
- VMess / WireGuard / Trojan / Hysteria2 等更多协议；VLESS 的 xudp packet encoding、mux。
- 密钥轮换工具（重新加密所有 `sealed_config`）。
