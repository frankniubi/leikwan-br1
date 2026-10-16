# OpenWrt 会聚 App — 设计与运维手册

> 交付物：`openwrt/`（三个 opkg 包 + 固件构建脚本）、`tools/leikwand/`（守护进程源码与测试）、
> `master/`（面板的增量改造）。

## 1. 这是什么

一个运行在 OpenWrt 路由器上的 Leikwan 会聚客户端。**路由器**而不是每台终端成为会聚节点，
所以手机、游戏机、电视盒子等无法安装客户端的设备，只要连上这个路由器的 LAN 就自动获得加速。

它由四层组成：

| 层 | 位置 | 作用 |
|---|---|---|
| **面板** | `https://v3.leikwanhost.com`（备用 `https://api-leikwanbridge.nbwly.com`） | 唯一控制面：下发线路候选、授权、配额、远程指令 |
| **leikwand** | `/usr/bin/leikwand`（Go 静态二进制） | 常驻守护进程：对接面板、编排运行时、两层高可用、LuCI 后端 |
| **leikwan-runtime** | `/usr/libexec/leikwan/ultra-client` | 转发运行时：持有候选集、探测、隔离、快速切换、真实转发 |
| **luci-app-leikwan** | LuCI 视图 + rpcd/ucode 桥 | 图形界面：上线向导、线路、网关、双 WAN、日志诊断 |

## 2. 为什么这样分层

**`leikwand` 不实现选路，运行时也不实现面板协议。** 这条边界是整个设计里最重要的决定：

- 选路（探测、打分、隔离、切换）需要毫秒级的本地视角，放在运行时里；
- 授权（哪条线路可用、配额、撤销）只有面板知道，放在面板里；
- 守护进程只做"把面板的决定可靠地送达运行时，并在送不到时如实报告"。

因此路由器**不会**自己猜测线路，也**不会**在面板拒绝时静默降级 —— 它会保持已有会话，
并把无法获批的事实暴露给运维。

## 3. 两层高可用

### L1：中转线路高可用（自动、毫秒级）

面板在每次租约请求里最多下发 **4 条候选线路**。运行时把整批候选拿在手里，
自己探测、排序、隔离失败候选、在健康候选之间切换 —— 监听端口始终保持打开，
已经建立的连接不会被主动断开。守护进程的职责是：

- 在租约过期前 **90 秒**续租（`lease_renew_lead`）；
- 每次成功安装租约后，才认为运行时"应当有监听端口"；
- 运行时进程死亡时按 `restart_cooldown` 重启；
- 面板撤销授权（`service_state` 非 active、配额耗尽、目标摘要不匹配）时立即停止转发。

### L2：多 WAN 上行高可用（热备）

路由器本身接多条宽带时，守护进程会：

- 用 `SO_BINDTODEVICE` 把 DNS 探测钉在**指定那条上行**上，验证的是整条路径而不只是链路状态；
- 按健康度和实测时延给每条上行的默认路由排 metric，内核自动选最优；
- 失败达到 `uplink_fail_threshold` 次即降权，恢复达到 `uplink_recover_threshold` 次即回升；
- **检测到 mwan3 在运行时只读监控，不改路由** —— 一个盒子上不能有两个路由权威；
- 停止服务时恢复运维原本的默认路由。

> 注意：L2 提供的是**故障切换（热备）**，不是带宽叠加。真正的单流多腿上/下行叠加需要
> bond/MPTCP 数据面，本版运行时（Ultra）不提供，也没有在任何文档里假装提供。

## 4. 两种工作模式

| 模式 | 数据路径 | 适用 |
|---|---|---|
| `forward`（默认） | 面板分配本地监听端口 → 运行时 → 中转 → 目标 | 端口转发 / 单机加速 |
| `gateway` | LAN 设备 → 路由器 → sing-box TUN → 运行时本地 SOCKS5 门面 → 中转 | 整网透明加速（旁路由 / 主路由） |
| `hybrid` | 两者同时 | 既要转发又要整网加速 |

网关模式依赖面板的 **native outbound** 能力（`pull` 响应里的 `native_outbound`）。
面板没授权时，`leikwand` 会明确报告"面板尚未为本设备授权原生出口"，而不是假装工作。

### 4.1 网关模式 = 原生出口（native outbound）（leikwand 1.1）

网关模式就是桌面/安卓客户端的 native outbound，只是承载者从一台电脑换成整个局域网。
契约与 [docs/api/native-outbound.md](api/native-outbound.md) 第 6 节完全一致：

- **原生线路不走普通转发路径**：`native_outbound=true` 的线路不绑定 `listen_port`、
  不申请普通租约、不做监听就绪检查；它们只由网关的本机中继承载。
- **原生租约与桌面同形**：每次申请前先做一次不带 `since_revision` 的完整拉取拿到最新授权，
  再带 `session_ticket`、`policy_generation`、随机 `nonce`、范围摘要（受限策略 v1、
  无审全路由 v2）请求 `purpose=native_outbound`。面板回的 `scope_digest` 按运行时要求放进
  `target_digest` 再投递；批次里任何一条字段不符都整批拒收（与桌面 `ParseNativeOutboundLease` 相同）。
- **两个进程、两套生命周期**：中继（`ultra-client`，`native_socks5`，127.0.0.1:2080）持有租约，
  续租通过控制套接字投递给正在运行的中继；sing-box 只在其配置变化时重启。续租不断开局域网会话。
- **撤销即停**：面板撤回原生出口、服务停用或配额耗尽时，sing-box、中继和直连例外全部撤掉，
  局域网回到普通路由（不会把流量黑洞掉），LuCI 显示原因。

#### 范围与策略

| UCI | 取值 | 说明 |
|---|---|---|
| `gateway_route_scope` | `auto`（默认）/ `intl_only` / `cn_only` / `global` | `auto` 按线路方向：国内→国际 = `intl_only`，国际→国内 = `cn_only`，其他 = `global`。线路不支持所选范围时回退到 `auto` 并在状态里提示 |
| `gateway_route_policy` | `auto`（默认）/ `restricted` / `unrestricted` | `auto`：套餐开了 `allow_native_outbound_unrestricted`（无审全路由）就用全路由，否则受限。手动选 `unrestricted` 但未授权时降为受限并提示 |
| `gateway_forward_id` | `0`（自动）或线路 ID | 只能选面板授权的原生出口线路；指定的线路不在授权里是错误，不会静默换线 |

受限策略与中继自身的限制一致：1–999 端口一律直连，只有 1000 以上端口进线路（游戏用途）。

#### 抗污染（与桌面/安卓同一套）

| 场景 | 解析 |
|---|---|
| 无审全路由，`global` / `intl_only` | Cloudflare DoH（1.1.1.1）**经线路**；`intl_only`（或开了绕过大陆的 `global`）下国内域名走国内解析 |
| 受限策略（`cn_only` 以外） | AliDNS DoH（223.5.5.5）直连 —— 中继拒绝 443 等低端口，DNS 不能走线路 |
| `cn_only` | 国外域名 Cloudflare DoH 直连；国内域名 AliDNS DoH，无审全路由时**经线路**（出口在国内，答案无污染且就近） |
| `gateway_dns_mode=custom` | `gateway_dns_servers` 以真 UDP 发送，允许时经线路（Windows 0.1.20.68 同一修复）；`cn_only` 不使用自定义服务器 |

- **局域网 DNS 自动接管**：TUN 里的所有 DNS 包都被 sing-box 劫持，包括 dnsmasq 自己的上游查询，
  所以把路由器当 DNS 的设备直接得到受保护的答案，**不改 dnsmasq 配置**；sing-box 退出时 TUN
  随之消失，DNS 自动回到原状。
- **"本地"解析器是明确的国内服务器**（223.5.5.5 UDP），不是系统解析器：路由器的系统解析器就是
  dnsmasq，它的上游会被劫持回 sing-box，形成环路。
- **IPv4 only**（与桌面一致），**交接前先解析**（无审全路由或分流范围），中继不会在另一条 DNS
  路径上二次解析。
- **国内规则集随包内置**（`/usr/share/leikwan/rule-set/`，与 macOS/Windows 同一份 SRS，哈希一致）：
  sing-box 在远程规则集首次下载完成前不开放入站，而 GitHub 在大陆不可达。
- **控制面直连**：面板域名与运行时的入口区域探测（`api.bilibili.com`）强制直连并本地解析，
  否则探测会报告出口所在地而选错线路。面板请求本身仍走严格 DoH + 绑定物理 WAN。

#### 中继不会拨到自己

sing-box `auto_route` 会捕获路由器上所有未绑定接口的套接字，包括 ultra-client 连中转的连接。
面板在每个候选里给出 `direct_ip_cidrs`（必含中转端点），leikwand 为每个地址装
`ip rule to <cidr> lookup main pref 8970`，排在 sing-box 自己的规则（固定从 9000 开始）之前。
混合模式下普通转发线路的中转地址同样加入例外，避免它们被推进原生出口。轮换时保留上一代地址让
旧连接自然结束；超过 /8（IPv6 /16）的前缀、回环和未指定地址一律拒收。

#### 分流线路（split carrier，例如 SHIXP）

网关可以同时承载第二条原生出口线路，只给一张目的网段表用：

| UCI | 默认 | 说明 |
|---|---|---|
| `gateway_split_forward_id` | `0`（关闭） | 第二条原生出口线路 ID，必须是面板授权的另一条原生线路 |
| `gateway_split_route_policy` | `auto` | 同 `gateway_route_policy`，单独作用于分流线路 |
| `gateway_split_cidr_file` | `/usr/share/leikwan/split/shixp-v4.txt` | 每行一个 IPv4 网段；随包附带 SHIXP **不在公网路由表里**的成员网段（357 条，见下） |

- **独立的中继**：分流中继监听 127.0.0.1:2081，有自己的租约、续租节奏、直连例外（owner `gateway:split`）和用量上报；
  它申请 `global` 范围，由路由器按网段表收窄。
- **规则常驻**：只要分流线路已授权、网段表非空，sing-box 就一直带着 `split` 出站和 `ip_cidr` 规则；分流中继重启或
  续租失败时，只有表里的目标连接失败，**sing-box 不重启**（重启会断开所有局域网会话，包括承载线路的）。
  授权缺失或网段表为空只记一条提示，承载线路照常工作。所以网段表只应放「只有这条线路能到」的网段。
- **规则位置**：先 `resolve`（按 IP 匹配），再分流规则，排在受限策略的低端口直连规则和国内直连规则之前——
  SHIXP 成员大多是大陆前缀，否则会被 `intl_only` 的国内直连规则截走。
- **网段表过滤**：IPv6、私网、回环、未指定、比 /8 更宽的条目一律丢弃，防止误把局域网或整个互联网推进分流线路。
- **随包 SHIXP 表（2026-10-17）**：SHIXP 全部成员地址空间（659 段）减去 bgp.tools 全球路由表里任何源的公告
  （精确、覆盖或更细），再用 RIPE RIS 复核（剔除 113.45.100.0/23、117.120.196.0/22），得到 357 段、约 394 万地址。
  云厂公网 BGP（华为云、百度、腾讯、阿里、UCloud 等）全部排除；旧的 328 条 IRR-only 表里有 146 条其实整段在公网路由。
- **面板侧**：组合 61（SHIXP 白名单）拒绝原生出口——原生 SOCKS 可以到达任意目标，白名单就失效了。
  所以 2026-10-17 为 plan 9 单独建了组合 72 / 路线 36「SHA -> SHIXP (native)」（同一条线路 22，无白名单），
  不挂到客户套餐上。

#### 防火墙

安装时创建 fw4 区域 `leikwan`（设备 `lkwtun0`，input/output ACCEPT，forward REJECT）和
`lan → leikwan` 转发。TUN 只在 sing-box 运行时存在，所以区域平时是空的；卸载时删除（升级不删）。

## 5. 快速上线（3 种方式）

### 方式 A：配对码（最快，推荐给最终用户）

1. 管理员/租户在面板点 **OpenWrt Pairing Code**，拿到一个形如 `ABCD-2345` 的一次性码。
2. 路由器 LuCI → **Leikwan → 上线向导** → 输入配对码 → 完成。

配对码 15 分钟有效、一次性、只绑定一个 client、数据库里只存哈希。
**密码从不经过路由器**。

### 方式 B：账号登录

LuCI 上线向导里输入面板账号密码，守护进程调 `POST /api/client/login` + `/api/client/select`
换取 `agent_token`。密码不出现在任何持久化文件里。

### 方式 C：工程师命令行

```sh
leikwand ctl enroll email=you@example.com password='...'
leikwand ctl pair code=ABCD-2345
leikwand ctl status
```

凭据落在 `/etc/leikwan/secret.json`（root 0600），**不进 UCI**（UCI 默认可被普通用户读取）。
守护进程会监视这个文件：手工写入或从备份恢复后，运行中的进程会在一个轮询周期内自动接管。

## 6. 构建

### 构建 ipk

```sh
# 需要 OpenWrt 24.10 SDK（x86_64 / aarch64）
export LEIKWAN_SDK=$HOME/leikwan-build/sdk
openwrt/build-ipk.sh amd64
openwrt/build-ipk.sh arm64
```

产出三个包：

| 包 | 内容 |
|---|---|
| `leikwand` | 守护进程、`/etc/config/leikwan`、procd init、uci-defaults |
| `leikwan-runtime` | `/usr/libexec/leikwan/ultra-client` |
| `luci-app-leikwan` | LuCI 视图、menu.d、acl.d、rpcd ucode 桥 |

> `luci-app-leikwan` **不依赖 `luci.mk`**：它是纯脚本包、没有 .po 翻译文件，
> 依赖 luci.mk 只会强迫每次构建都克隆整个 LuCI feed。只需要目标机上有 `luci-base`。

### 构建固件

```sh
export LEIKWAN_IB=$HOME/leikwan-build/ib
openwrt/image/build-image.sh amd64
```

固件里预装 luci + 三个包，并预置面板地址。**固件里不含任何机密** —— 刷完机只需要一个配对码。

## 7. 日常运维

```sh
# 服务
/etc/init.d/leikwand status | restart
uci show leikwan
uci set leikwand.main.mode='hybrid' && uci commit leikwand && /etc/init.d/leikwand reload

# 状态与线路
leikwand ctl status | jsonfilter -e '@.data.forwards'
leikwand ctl forwards
leikwand ctl logs limit=200
leikwand ctl diagnose          # 面板连通性、运行时、每条线路、网关
leikwand ctl test_connection

# 单条线路
leikwand ctl forward_action forward_id=3 action=restart

# 退出登录（清除凭据并停止全部运行时）
leikwand ctl sign_out
```

### 关键 UCI 选项

| 选项 | 默认 | 说明 |
|---|---|---|
| `enabled` | 1 | 总开关 |
| `panel_url` / `fallback_panel_url` | v3 / aliyun | 控制面端点池，传输失败时自动轮换 |
| `mode` | forward | forward / gateway / hybrid |
| `poll_interval` | 30 | 长轮询配置周期（秒） |
| `heartbeat_interval` | 30 | 心跳与用量上报周期 |
| `health_interval` | 20 | 本地健康检查周期 |
| `cold_start_grace` | 90 | 冷启动宽限，避免误判重启 |
| `restart_cooldown` | 30 | 重启冷却 |
| `lease_renew_lead` | 90 | 租约到期前多久续租（秒） |
| `max_candidates` | 4 | 每次向面板申请的候选数（上限 4，硬约束） |
| `uplink_enabled` | 0 | 多 WAN 高可用 |
| `uplink_interfaces` | 空 | 留空=自动探测默认路由设备 |
| `gateway_enabled` | 0 | 透明网关（见 4.1） |
| `gateway_route_scope` / `gateway_route_policy` | auto / auto | 见 4.1 |
| `gateway_dns_mode` | auto | auto = 防污染 DoH；custom = `gateway_dns_servers` |
| `gateway_tun_mtu` | 1280 | 与桌面相同；只影响 UDP（TCP 在 sing-box 本地终结） |
| `gateway_rule_set_dir` | /usr/share/leikwan/rule-set | 内置国内规则集 |
| `gateway_split_forward_id` / `gateway_split_route_policy` / `gateway_split_cidr_file` | 0 / auto / SHIXP 表 | 分流线路（见 4.1） |
| `log_level` | info | debug / info / warn / error |

### 端口与文件

| 路径 | 用途 |
|---|---|
| `/etc/config/leikwan` | 非机密配置（UCI） |
| `/etc/leikwan/secret.json` | agent token 与设备身份（root 0600） |
| `/etc/leikwan/state/` | applied / usage / pull / uplink 状态（0600） |
| `/var/run/leikwand/ctl.sock` | 本地控制套接字（0600，目录 0700） |
| `/var/run/leikwand/ctl.token` | 每次启动生成的控制令牌 |
| `/var/run/leikwand/<forward_id>/` | 运行时配置、控制套接字、状态文件 |
| `/var/run/leikwand/gateway/sing-box.json` | 网关模式生成配置 |

## 8. 安全模型

- **UCI 里没有机密。** 令牌只在 `/etc/leikwan/secret.json`（0600）。
- **运行时控制套接字**只接受同 uid 的本地连接，父目录强制 0700。
- **LuCI 从不回显令牌**，日志与诊断输出在写入前统一脱敏（Bearer/token/password/pairing_code）。
- **面板是唯一权威。** 授权被撤销时立即停止转发，不做"降级继续跑"。
- **opkg 是唯一的运行时更新权威。** 守护进程只比对面板期望的哈希并报告漂移，
  不自己下载替换二进制 —— 一台盒子上不能有两个更新器。

## 9. 面板侧改动（全部为增量）

| 改动 | 位置 | 说明 |
|---|---|---|
| 接受 `openwrt` 平台 | `AccessPolicyService::clientContext` | `openwrt` / `openwrt-*` / `router` → `openwrt`；未知平台仍然 422 |
| 位置策略放行 | `ClientAgentController::accessPolicyPlatform` | 复用同一个分类器，geo-lock 账号下的路由器不再被挡 |
| 配对码换令牌 | `POST /api/client/devices/openwrt/pair` | 新增端点，返回 `agent_token` |
| 配对码管理 | `POST /admin/clients/{id}/openwrt-pairing-code` | 一次性展示，只存哈希 |
| 建表 | `master/migrations/20260911_add_openwrt_pairing_codes.sql` | 新表，不动现有表 |

**部署时必须设置 `OPENWRT_PAIRING_ENABLED=true`**，否则配对端点返回 503
（`openswrt_pairing_unavailable`）。账号登录路径不需要这个开关。

现有 Windows / macOS / Android / Linux 客户端的契约**逐字节未变**：
`pull`、`heartbeat`、`ultra/lease` 的请求与响应结构没有任何修改。

## 10. 已实测的行为

以下全部在真实 OpenWrt 24.10 SDK、真实运行时与真实 sing-box 上验证过。

### 总览

| 套件 | 项数 | 结果 |
|---|---|---|
| 守护进程单元测试 `go test` | 93 | 全部通过，`go vet` 干净 |
| LuCI 界面测试（Node，真实渲染 7 个页面） | 101 | 全部通过 |
| LuCI 测试变异检查（注入 6 个真实缺陷） | 6 | 6/6 被抓到 |
| 网关配置 × 真实 sing-box 1.12.22 | 48 | 全部接受，零告警 |
| 网关端到端（网络命名空间，真 TUN，2 种策略） | 24 × 2 | 全部通过 |
| 端到端集成测试（真守护进程 + 真运行时） | 39 | 全部通过 |
| 固件 rootfs chroot（真 OpenWrt busybox/uci） | 首次启动 + 升级迁移 | 通过 |

### 守护进程（`go test`，42 项）

覆盖面板摘要契约（与 PHP 侧**独立算出**的向量比对）、UCI 解析与钳制、授权校验矩阵、
契约签名、用量计数器回绕、控制帧编解码、面板端点轮换与平台降级、
运行时配置键白名单（运行时会拒绝未知字段）、网关配置结构、
**L2 多 WAN 决策过程**（发现、阈值、故障降权、恢复、路由还原、mwan3 让权）。

### L2 多 WAN 是怎么被测的

多 WAN 逻辑本质上是"对 `ip`/`ubus` 输出做决策"，测试无法提供两张真实上行，
所以把命令执行做成了可注入的接缝（`runCommandFunc`），用假的 `ip`/`ubus` 驱动
真实的决策代码：故障超过阈值后必须把该上行的 metric 严格抬高、恢复需要连续成功、
停止服务必须把运维原本的路由**原样装回**、检测到 mwan3 时必须只读。

### LuCI 界面（Node，83 项 + 变异检查）

`node --check` 只能证明语法正确。这里搭了一套 LuCI 运行时桩
（`openwrt/luci-app-leikwan/tests/luci-stubs.cjs`），**真正把 7 个页面渲染一遍**，
用贴近真实的守护进程响应驱动，任何 render 路径上的运行时错误都会变成测试失败。
另外交叉校验三份手写契约：视图里的 `rpc.declare`、rpcd ucode 里的方法表、ACL 授权，
并断言只读方法没有被授予写权限、ACL 没有放开 `/etc/leikwan`。

**变异检查**（`tests/mutation-check.cjs`）证明这套测试有牙齿：注入 6 个真实缺陷
（表单类型拼错、rpc 方法名写错、render 抛异常、页面没有任何可操作按钮、
菜单指向不存在的视图、ACL 漏授权），**6/6 全部被抓到**。

### 网关配置（真实 sing-box 1.12.22，60 项）

`tools/leikwand/integration/validate_gateway_config.sh` 会下载 OpenWrt 24.10 发布的
sing-box 与目标 rootfs 里的 musl 运行库，用 `sing-box check` 逐个校验
3 种 scope × 2 种 policy × 2 种 bypass × 2 种 DNS 模式 × 2 种规则集来源的**全部 48 种组合**，
外加分流线路（随包 SHIXP 表）在 3 种 scope × 2 种 policy × 2 种分流 policy 上的 12 种组合，当前全部通过且**零告警**。

这一步抓到了三个真实缺陷（单元测试全都发现不了）：
1. 配置里用了 `geoip` 规则字段 —— sing-box 1.12 **已移除**，网关直接 FATAL 起不来；
2. DNS 用了旧版服务器写法与 `outbound` 匹配器 —— 1.12 弃用、1.14 移除；
3. `cn_only` 的语义是错的：final 恒为 proxy，等于把 `cn_only` 静默变成了 `global`。

### 网关模式（native outbound）端到端（1.1 新增）

`tools/leikwand/integration/run_gateway_e2e.sh`（root）搭三个网络命名空间：局域网终端 →
路由器（真 leikwand + 真 ultra-client + OpenWrt 发布的 sing-box 1.12.22）→ 上游（假中转放在
网关之后的公网测试地址 198.51.100.10）。模拟面板按 `resolveUltraLease` 的规则校验原生租约
（ticket、nonce、摘要、范围、策略），拒绝时写明错误码。`auto/auto` 与 `auto/restricted` 各 24 项全部通过：

- 局域网连一个上游根本不存在的地址（203.0.113.80），回复来自中转后的回显服务 —— 证明 TUN 真的接管了转发流量；
- 面板接受原生租约请求（`intl_only` + `unrestricted` / `restricted` 由 `auto` 推出）；
- 直连例外装在 8970、sing-box 规则从 9000 开始；**手动删掉例外后新连接失败（中继拨到了自己），恢复后通路恢复** —— 例外是承重的；
- 受限策略下 80 端口不进线路，无审全路由下进线路；
- 100 秒租约在到期前续租，中继与 sing-box 的 PID 不变，续租后通路仍通；
- 中继承载的流量作为原生线路 #50 的 Ultra 用量随心跳上报（与桌面 native-usage 相同）；
- 面板撤回原生出口后中继停止、TUN 消失、所有例外删除，状态给出原因。

配置矩阵 `validate_gateway_config.sh` 扩展为 3 范围 × 2 策略 × 2 绕过 × 2 DNS 模式 × 2 规则集来源 = 48 种，全部被真实 sing-box 接受。

固件 rootfs chroot（用构建出的 x86_64 镜像自己的 busybox/uci/musl）：首次启动脚本创建 fw4 区域一次且幂等；
1.0.1 配置升级迁移（`global→auto`、`remote→auto`、MTU `1420→1280`、删除 `gateway_redirect_lan`，保留显式 `unrestricted`）；
包内 leikwand 与固件内 sing-box 对 6 种范围/策略都 `check` 通过且使用内置规则集。

### 构建与集成

- 真实 SDK 构建：**x86_64 与 aarch64 各一套** `leikwand` / `leikwan-runtime` /
  `luci-app-leikwan` ipk（`luci-app-leikwan` 载荷逐项核对：7 个视图、menu.d、acl.d、
  rpcd ucode 桥为 `0755`、uci-defaults 为 `0755`；control 元数据里的
  `Architecture` 分别为 `x86_64` / `aarch64_generic` / `all`）。
- 真实 ImageBuilder 固件，**两个平台各一套**：
  `openwrt-24.10.4-leikwan-1.0.0-x86-64-generic-*.img.gz`（174 包）与
  `openwrt-24.10.4-leikwan-1.0.0-armsr-armv8-generic-*.img.gz`（194 包），
  两份 manifest 中都确认含三个包。
- 端到端集成测试（32 项全通过）：上线 → 配置拉取 → 运行时拉起 → 控制套接字租约投递 →
  真实流量往返（监听端口 → 运行时 → 中转 → 目标）→ 面板拒绝时不掉线 →
  心跳 → **杀掉运行时后自动恢复并重新服务** → 面板远程停止指令生效 → 诊断。

集成测试自身也需要被信任，因此它在 `tools/leikwand/integration/` 里公开了
模拟面板、假中转与回显目标；假中转按真实 provider 握手逐字节解析。

## 11. 尚未验证 / 边界

诚实列出，不含糊：

- **没有真机验收。** 全部验证在 WSL 里的真实 SDK 构建 + 真实运行时进程上完成，
  没有在物理路由器上跑过，固件也没有实际刷入启动过。首次上真机请按第 12 节顺序灰度。
- **没有真机验收（重申）。** aarch64 的 ipk 与固件都已真实构建，但**没有在任何 ARM
  硬件上运行过**；两个平台的固件也都没有刷入启动过。
- **`luci-app-leikwan` 的 ipk 在本环境是用"去掉 `+luci-base` 依赖"的副本构建的** ——
  因为本机 github.com 不可达，packages feed（lua/libnl）装不上，`luci-base` 自身无法编译。
  构建与产物内容（文件清单、权限、control 元数据）都真实可用，
  但在有完整 feed 的环境里应再跑一次完整依赖构建。
- **网关 TCP 数据面已在网络命名空间里端到端实测（见第 10 节），但没有在真路由器上跑过**；
  fw4 区域只在 chroot 里验证了配置写入，**没有在运行中的 fw4 上验证转发放行**。
- **网关 DNS 防污染未做数据面实测**：DoH 需要真实公网，测试环境离线；验证的是生成配置
  （单元测试逐条断言 + 真实 sing-box 接受），不是"答案确实干净"。上真机后请在 LAN 终端
  `nslookup www.google.com` 对照。
- **网关 UDP 未端到端实测**（假中转不实现 UDP），与普通线路相同。
- **IPv6**：与桌面一致只接管 IPv4，DNS 只返回 A 记录；LAN 设备直接使用的 IPv6 字面地址不经线路。
- **范围 `cn_only` 的数据面没有单独实测**（测试目标地址在国外，cn_only 下按设计直连）。
- **多 WAN L2 未做双上行实测** —— 决策过程有注入式测试，但没有在真的接了两条宽带的路由器上跑过。
- **UDP 路径未端到端实测**：假中转只实现 TCP provider 握手，所以集成测试发布的是
  `transport=tcp` 的线路。运行时对 `tcp+udp` 线路要求两条路径都健康，这是正确行为。
- **运行时尚未在面板发布 OpenWrt 制品**，所以 `runtime_drift` 通常为"未知"。
  在面板补上 `ultra_client_runtime_linux_amd64_* / _linux_arm64_*` 之前，
  运行时通过 `opkg upgrade leikwan-runtime` 更新。

## 12. 首次上真机的建议顺序

1. 在**非生产**路由器上 `opkg install` 三个包，确认 `/etc/init.d/leikwand status` 正常。
2. `leikwand selftest`，确认运行时存在、目录权限正确。
3. 用配对码上线，`leikwand ctl status` 应显示 `enrolled: true`。
4. **先只开一条 forward 线路**，`leikwand ctl forwards` 看 `health` 与 `rtt_ms`；
   用真实应用验证，不要只看端口通。
5. 观察至少一个完整租约周期（≥5 分钟），确认没有反复重启。
6. 再开网关模式（面板先给该账号的线路开 Native outbound），并且**先在一台测试终端**上把网关指向路由器验证：
   `leikwand ctl diagnose` 里 `gateway`、`gateway_native_lease`、`gateway_relay_bypass` 都应为 ok；
   终端上 `nslookup www.google.com` 与 `nslookup www.baidu.com` 分别应得到国外 / 国内地址。
7. 多 WAN 单独灰度：先只开监控（`uplink_enabled=0` 时也能在 LuCI 看到只读视图），
   确认探测结果符合预期后再置 1。

回滚：`/etc/init.d/leikwand stop` 会恢复原始默认路由；
`opkg remove luci-app-leikwan leikwan-runtime leikwand` 彻底移除。
