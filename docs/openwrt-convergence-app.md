# OpenWrt 会聚 App — 设计与运维手册

> 交付物：`openwrt/`（三个 opkg 包 + 固件构建脚本）、`tools/leikwand/`（守护进程源码与测试）、
> `master/`（面板的增量改造）。
>
> **leikwand 1.2（router edition）**：动线与其他平台的客户端对齐——粘贴面板签发的
> client id + agent token 上线；本地监听转发端口 + 监控；面板授予 native outbound 时
> 提供 127.0.0.1:2080 的本机 SOCKS5 中继。**透明网关（sing-box TUN / DNS 劫持 / 分流）、
> 多 WAN 高可用、账号登录与配对码均已移除。** 1.1 的网关行为保留在 git 历史里。

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

（多 WAN 上行高可用已移除：多 WAN 切换交给 OpenWrt 原生的 mwan3 或多 wan
配置，leikwand 不再触碰默认路由。）

## 4. 连接功能

路由器版只有两层数据面，没有模式开关：

| 功能 | 数据路径 | 条件 |
|---|---|---|
| 转发线路 | 面板分配本地监听端口（0.0.0.0:listen_port）→ 运行时 → 中转 → 目标 | 面板下发即自动启动 |
| 原生出口中继 | 127.0.0.1:2080 SOCKS5 门面 → 面板选定的原生线路 | 面板 pull 响应里的 `native_outbound` 授权；撤销即停 |

原生出口中继就是桌面客户端的 native outbound，只是它**只**提供本机回环 SOCKS5 入口，
路由器版不做透明代理：是否把 LAN 或本机流量指向它（防火墙重定向、独立 sing-box 等）
是运维自己的决定。

### 4.1 原生中继契约（与桌面一致）

- **原生线路不走普通转发路径**：`native_outbound=true` 的线路不绑定 `listen_port`、
  不申请普通租约、不做监听就绪检查；它们只由中继承载。
- **原生租约与桌面同形**：每次申请前先做一次不带 `since_revision` 的完整拉取拿到最新授权，
  再带 `session_ticket`、`policy_generation`、随机 `nonce`、范围摘要（受限策略 v1、
  无审全路由 v2）请求 `purpose=native_outbound`。面板回的 `scope_digest` 按运行时要求放进
  `target_digest` 再投递；批次里任何一条字段不符都整批拒收（与桌面 `ParseNativeOutboundLease` 相同）。
- **续租不断开**：续租通过控制套接字投递给正在运行的中继，中继不重启。
- **撤销即停**：面板撤回原生出口、服务停用或配额耗尽时，中继停止，LuCI 显示原因。

#### 范围与策略

| UCI | 取值 | 说明 |
|---|---|---|
| `gateway_route_scope` | `auto`（默认）/ `intl_only` / `cn_only` / `global` | `auto` 按线路方向：国内→国际 = `intl_only`，国际→国内 = `cn_only`，其他 = `global`。线路不支持所选范围时回退到 `auto` 并在状态里提示 |
| `gateway_route_policy` | `auto`（默认）/ `restricted` / `unrestricted` | `auto`：套餐开了 `allow_native_outbound_unrestricted`（无审全路由）就用全路由，否则受限。手动选 `unrestricted` 但未授权时降为受限并提示 |
| `gateway_forward_id` | `0`（自动）或线路 ID | 只能选面板授权的原生出口线路；指定的线路不在授权里是错误，不会静默换线 |

受限策略与中继自身的限制一致：1–999 端口一律直连，只有 1000 以上端口进线路（游戏用途）。

## 5. 快速上线（1 种方式）

与其他平台（headless Linux、Docker）相同：直接使用面板签发的凭据。

1. 在面板客户端详情复制安装命令（其中含 `--client-id N --agent-token T`）。
2. 路由器 LuCI → **Leikwan → 配置向导** → 粘贴（整条命令或两个值均可）→ **上线**。
3. 转发线路随下一次配置同步自动出现；面板授予原生出口时中继自动启动。

命令行等价：

```sh
leikwand ctl register client_id=42 agent_token='...'
leikwand ctl status
```

凭据落在 `/etc/leikwan/secret.json`（root 0600），**不进 UCI**（UCI 默认可被普通用户读取）。
守护进程会监视这个文件：手工写入或从备份恢复后，运行中的进程会在一个轮询周期内自动接管。

> 1.1 及更早版本的账号登录（`/api/client/login` + `/select`）与配对码
> （`/api/client/devices/openwrt/pair`）已移除；面板侧端点仍在，其他用途不受影响。

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

固件里预装 luci + 三个包，并预置面板地址。**固件里不含任何机密** —— 刷完机只需要粘贴面板签发的凭据。

## 7. 日常运维

```sh
# 服务
/etc/init.d/leikwand status | restart
uci show leikwan
uci commit leikwan && /etc/init.d/leikwand reload

# 状态与线路
leikwand ctl status | jsonfilter -e '@.data.forwards'
leikwand ctl forwards
leikwand ctl logs limit=200
leikwand ctl diagnose          # 面板连通性、运行时、每条线路、原生中继
leikwand ctl test_connection

# 退出登录（清除凭据并停止全部运行时）
leikwand ctl sign_out
```

### 关键 UCI 选项

| 选项 | 默认 | 说明 |
|---|---|---|
| `enabled` | 1 | 总开关 |
| `panel_url` / `fallback_panel_url` | v3 / aliyun | 控制面端点池，传输失败时自动轮换 |
| `poll_interval` | 30 | 长轮询配置周期（秒） |
| `heartbeat_interval` | 30 | 心跳与用量上报周期 |
| `health_interval` | 20 | 本地健康检查周期 |
| `cold_start_grace` | 90 | 冷启动宽限，避免误判重启 |
| `restart_cooldown` | 30 | 重启冷却 |
| `lease_renew_lead` | 90 | 租约到期前多久续租（秒） |
| `max_candidates` | 4 | 每次向面板申请的候选数（上限 4，硬约束） |
| `gateway_route_scope` / `gateway_route_policy` | auto / auto | 原生中继范围与策略（见 4.1） |
| `gateway_forward_id` | 0 | 指定承载线路；0 = 面板默认 |
| `log_level` | info | debug / info / warn / error |

### 端口与文件

| 路径 | 用途 |
|---|---|
| `/etc/config/leikwan` | 非机密配置（UCI） |
| `/etc/leikwan/secret.json` | agent token 与设备身份（root 0600） |
| `/etc/leikwan/state/` | applied / usage / pull 状态（0600） |
| `/var/run/leikwand/ctl.sock` | 本地控制套接字（0600，目录 0700） |
| `/var/run/leikwand/ctl.token` | 每次启动生成的控制令牌 |
| `/var/run/leikwand/<forward_id>/` | 运行时配置、控制套接字、状态文件 |
| `/var/run/leikwand/gateway/relay/` | 原生中继的运行时目录 |

## 8. 安全模型

- **UCI 里没有机密。** 令牌只在 `/etc/leikwan/secret.json`（0600）。
- **运行时控制套接字**只接受同 uid 的本地连接，父目录强制 0700。
- **LuCI 从不回显令牌**，日志与诊断输出在写入前统一脱敏（Bearer/token/password/agent_token）。
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

> 1.1 及更早版本在真实 SDK、真实运行时与真实 sing-box 上完成过整套验证（多 WAN 决策注入测试、
> 网关配置 × sing-box 矩阵、网络命名空间网关端到端等），细节见 git 历史。1.2（router edition）
> 删除了那些代码路径；下列是 1.2 当前实际执行的验证：

### 总览

| 套件 | 结果 |
|---|---|
| 守护进程单元测试 `go test` | 全部通过，`go vet` 干净 |
| LuCI 界面测试（Node，真实渲染 4 个页面） | 全部通过 |
| LuCI 测试变异检查 | 全部被抓到 |

### 守护进程（`go test`）

覆盖面板摘要契约（与 PHP 侧**独立算出**的向量比对）、UCI 解析与钳制（含 1.1 旧配置
升级后遗留的 gateway/uplink 键必须被忽略）、授权校验矩阵、契约签名、用量计数器回绕、
控制帧编解码、面板端点轮换与平台降级、运行时配置键白名单（运行时会拒绝未知字段）、
原生租约请求与校验契约（与桌面同形）、原生中继续租打到运行中继、中继用量上报、
面板远程停止指令的粘滞。

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

- **1.2 没有真机验收。** router edition 的验证是单元测试 + LuCI 测试 +
  （有 SDK 环境时的）构建与集成测试；没有在物理路由器上跑过。首次上真机按第 12 节顺序灰度。
- **原生中继没有端到端实测**（需要真实面板下发 `native_outbound`），契约由单元测试
  对照桌面向量保证；上真机后用 `curl --socks5 127.0.0.1:2080` 验证。
- **UDP 路径未端到端实测**：假中转只实现 TCP provider 握手，所以集成测试发布的是
  `transport=tcp` 的线路。运行时对 `tcp+udp` 线路要求两条路径都健康，这是正确行为。
- **IPv6**：转发监听由运行时决定；原生中继与桌面一致只收 IPv4 目标。
- **运行时尚未在面板发布 OpenWrt 制品**，所以 `runtime_drift` 通常为"未知"。
  在面板补上 `ultra_client_runtime_linux_amd64_* / _linux_arm64_*` 之前，
  运行时通过 `opkg upgrade leikwan-runtime` 更新。

## 12. 首次上真机的建议顺序

1. 在**非生产**路由器上 `opkg install` 三个包，确认 `/etc/init.d/leikwand status` 正常。
2. `leikwand selftest`，确认运行时存在、目录权限正确。
3. 用面板凭据上线，`leikwand ctl status` 应显示 `enrolled: true`。
4. **先只开一条 forward 线路**，`leikwand ctl forwards` 看 `health` 与 `rtt_ms`；
   用真实应用验证，不要只看端口通。
5. 观察至少一个完整租约周期（≥5 分钟），确认没有反复重启。
6. 面板给该账号的线路开 Native outbound 后，`leikwand ctl diagnose` 里 `native_relay`
   与 `native_lease` 应为 ok；`curl --socks5 127.0.0.1:2080 https://ifconfig.me`
   应返回线路出口地址。

回滚：`opkg remove luci-app-leikwan leikwan-runtime leikwand` 彻底移除。
