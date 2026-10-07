# API / 计费 / 带宽 安全审计（2026-10-07）

基线：`fix/android-ultra-dns-fast-start` @ `ccc98071`（面板代码与 master 一致部分）。只读代码审计，未改代码、未访问生产。
标注：✅ = 已人工复核关键代码；其余为审计 agent 追踪完整路径后确认（CONFIRMED），个别标 PLAUSIBLE。

## Critical

| # | 问题 | 位置 | 利用方式 |
| --- | --- | --- | --- |
| C1 ✅ | **节点自去重 = 接管任意节点** | `NodeController.php:116-224`(heartbeat 自报 name/public_ip/private_ip/machine_id/group_key)、`766-920`(deduplicate)、`970-1001` | 拿到节点 token（全局 enrollment token，或任何 `allow_custom_slaves` 租户自己生成的 BYOS token）→ heartbeat 冒充受害节点的 name+public_ip+group → `POST /api/node/self/deduplicate` 删除受害节点并把其 `service_endpoints`/任务迁到攻击者节点 → `tasks/pull` 拿到其他租户的发布 payload（target、client_token）并成为其数据面 |
| C2 ✅ | **注册节点可窃取 Cloudflare Global API Key** | `NodeController.php:52-60` 接收任意 `credential_profile_id`；`562-575` pull 时合并 `cloudflare_global_key` | BYOS token 注册时带 `credential_profile_id=1..N`（404 可枚举）→ 下一次 `tasks/pull` 返回 CF 邮箱+Global Key → 整个 DNS zone 失守 |
| C3 | **带宽限制服务端完全不执行** | slave/sidecar/Rust 服务端无任何 tc/令牌桶；`total_speed_limit_mbps` 被 `install-bond-sidecar.sh:148` 丢弃；签名 token 不含速率 | 所有“限速”都只在客户端设备；拿到配置后用原生 wstunnel/bond/SOCKS 客户端即可跑满线路 |
| C4 | **bond / MPTCP / leigod_mode 连客户端限速都没有** | `ProvisioningService.php:11484` 硬编码 `disable_local_rate_limit=true`；MPTCP 的 `speed_limit_mbps` 在 relay 中声明但未使用 | 无需任何技巧：100M 套餐在 bond/leigod 转发上 = 各腿带宽之和 |
| C5 | **Ultra / Native Outbound 流量仅靠客户端自报计费** | 唯一写入点 `recordUltraUsage`(`ProvisioningService.php:6147-6250`) 来自客户端 heartbeat `ultra_usage`；`billing-deduct.php` 只算 slave 计量；lease 直接下发 provider SOCKS 凭据(`UltraLeaseService.php:283-310`)；lease sealing 仍为 optional | 改客户端不上报 `ultra_usage` 或直接拿 lease 里的 SOCKS 凭据 → 无限流量、永不 `traffic_suspended`；路由策略/限速也只在客户端 |
| C6 ✅ | **钱包转账竞态 = 凭空增发 WHMCS 余额** | `WhmcsIdentityService.php:393-440`：无锁读余额 → 远程 addCredit → 绝对值写回 | N 个会话并发 `panel_to_whmcs amount=A` → WHMCS 加 N×A，面板只扣 A；WHMCS 余额可付真实账单 |

## High

| # | 问题 | 位置 | 利用方式 |
| --- | --- | --- | --- |
| H1 ✅ | 钱包扣款双花 | `ProvisioningService.php:13560-13590` `chargeWallet` 无锁读 + 绝对值写 | 并发购买套餐/重置流量 → 付一次得两次；还会覆盖 billing-deduct 的扣减 |
| H2 ✅ | 租户可改写任意 client（IDOR）且自定设备数 | `TenantController.php:2551-2561` → `saveClient`(`ProvisioningService.php:2697`) 按 id 无属主校验，`online_device_limit` 取自表单 | `POST /tenant/clients id=<他人client>` 抢走 client 及其绑定转发；自己的 client 填 `online_device_limit=999` |
| H3 | 设备数限制可绕过 | `ClientOnlineSessionService.php:143-145` 对 `legacy-` 前缀豁免；Android 设备控制只在 `X-Device-Platform: android` 时执行(`ClientAgentController.php:1830`) | 每台设备发 `X-Device-Id: legacy-<随机>` 且不带 Android 头 → 一个账号无限设备 |
| H4 ✅ | 节点可给任意服务记流量 | `applyUsageReport`(`ProvisioningService.php:6286+`) 只校验 endpoint 属于该节点，`service_id` 取自请求 | BYOS 节点上报受害者 `service_id` + 10TB → 受害者被 `traffic_suspended`；自己节点则可不报 |
| H5 ✅ | 转发目标可指向节点自身/内网（SSRF） | `ForwardLineApiContract::validateTargetHost`(237-254) 只校验格式；`leikwan-bond/src/server.rs:103-110`、`leikwan-uot sidecar_runtime.rs:1140`、slave 直接连接 | `target.host=127.0.0.1 / 169.254.169.254 / 10.x` → 我方 slave/sidecar 替租户访问本机管理端口、其他租户的 loopback 后端、云元数据；域名在节点侧解析，需节点侧过滤 |
| H6 | 过期服务不会被停 | `services.status` 从不写 expired；API 变更检查(`ForwardLineApiController.php:239`、`ServiceController.php:375`、`lockForwardMutationService`)、republish(`7857-7876`)、sidecar heartbeat、`requireClientToken` 都只看原始 `status=active`；`billing-deduct.php:158-175` 置 `enabled=0` 但不 revoke 端点；slave ingress HMAC 无过期 | 过期后仍可创建/修改转发；保留旧配置的客户端可无限期继续使用非 Ultra 转发 |
| H7 | bond QUIC 腿流量不计量 | `slave_agent.py:7140-7157` 只计 `backend_port`；QUIC relay 走 `public_port → 127.0.0.1:relay_client_port` 直达 target(`4400-4405`) | bond 客户端全走 QUIC 腿 → 用量为 0 |
| H8 | 租户 sidecar point 泄露 external-UoT token 与他人 target | `TenantController.php:1695-1714`(无 feature gate)、`ProvisioningService.php:2583-2631` 仅按 host 匹配 | 建一个 `sidecar_host` = 运营方 external-UoT host 的 point → 用自己的 token 拉 `/external-uot-config` → 拿到所有匹配组合的 token 和其他租户的 `target_host:port` |

## Medium

- **M1** 流量重置默认免费且不限次数：`resetServiceTraffic`(`ProvisioningService.php:3050+`) 只在 `traffic_reset_price>0` 时扣费，schema 默认 0.00，无频率限制。需核对生产套餐该字段。
- **M2** 余额停机的转发可在负余额下手动启动：`setForwardEnabled`(`5531-5540`) 清 `balance_suspended_at` 不查余额。
- **M3** 禁用的 client / 非 banned 的停用用户仍可拉配置和 lease：`Auth::requireClientAgent` 不查 `clients.status`。
- **M4** 无任何限流：`/api/client/login` 可在线爆破；v1 PATCH 每次都 revoke+reconcile 端点，可刷出端点/任务风暴（与 06-01 514K 行同类）。
- **M5** 限速按 forward 而非按 service，且 TCP/UDP 各一个桶：N 条转发 × 每条满额、`tcp+udp` 双倍（`ProvisioningService.php:5998-6001`、`install_forward_runtime.py:1407/1413`）；Linux 单/竞速模式可直连 `127.0.0.1:<rate_base>` 绕过限速进程。
- **M6** v1 PATCH 的 native_outbound 处理错误：`mergePatch` 读 `$current['native_outbound']` 而列名是 `native_outbound_enabled`（`ForwardLineApiContract.php:111-113`），结构性判断漏了 `native_outbound` → 普通转发 PATCH `native_outbound:true` 会静默把 target 改成 `127.0.0.1`；native 转发可直接改 target。
- **M7** v1 错误码折叠：`native_*`、`forward_combination_not_allowed`、`line_client_*` 等在 `errorResponse` 状态表外 → 一律 `500 operation_failed`（文档写的是 403/409/422）。

## Low

- 所有 token（`users.api_token`、`nodes.api_token`、`clients.agent_token`、`services.client_token`）明文存库、SQL 等值查找；admin/client/sidecar token 接受 `?token=` query → 进 nginx/CDN 日志。
- 租户看不到也无法轮换 `users.api_token`，v1 API 实际只能由运营代发；泄露后无轮换手段。
- 旧版 `regenerate-client-token` 跳过 `allow_rotate_client_token` 与服务状态检查；旧版 `client-config` 忽略 `allow_view_client_config`。
- 节点 heartbeat 可自设 `status`（offline 节点可自我恢复 active）和 group。
- v1 资源的 `ingress.port` 恒为 0（forwards 表无该列），`ingress.host` 取 `cdn_host`（与“不暴露 CDN host”承诺矛盾）。
- 旧限速器 `mbps*1024*1024/8` 比 Ultra 的 `1_000_000/8` 多放约 4.9%。

## 已确认无问题

v1 跨租户（forward/service/target_service_id/幂等重放均带属主）、套餐线路白名单、并发创建配额（`FOR UPDATE` 复核）、隐藏套餐购买、价格服务端计算、WHMCS webhook/SSO、node task ack 作用域、sidecar heartbeat 身份、`/api/admin/*`、Web 表单 CSRF、错误信息不泄露内部细节。

## 建议修复顺序

1. **立即（小改动、高收益）**：C1 deduplicate 改为仅管理员或同 owner + 服务端校验 machine_id；C2 BYOS 注册忽略 `credential_profile_id` 并轮换 CF Global Key；H4 `service_id` 从 endpoint→forward 推导；C6/H1 改为 `UPDATE ... SET wallet_balance = wallet_balance - :amt WHERE id=:id AND wallet_balance >= :amt` 并检查影响行数（WHMCS 转账先本地扣再远程加，失败回滚）；H2 `saveClient` 校验 `existing.owner_user_id`、`online_device_limit` 由套餐决定；H3 去掉 `legacy-` 豁免。
2. **短期**：H5 节点侧目标过滤（拒 loopback/link-local/元数据/本机地址，解析后校验）；H6 增加过期 sweep 下发 suspend、balance auto-stop 调用 revoke；M1 核对生产重置价格/加频率限制；M2 启动检查余额；M3 校验 client status；H8 sidecar point 加 feature gate + host 唯一/归属校验。
3. **架构级**：C3/C4/M5 在我方可控节点（slave/sidecar）按 service 聚合限速，或把 `rate_limit_mbps` 签入 session token 由 bond/UoT 服务端执行；C5 Ultra 计量必须来自运营方 relay 或 provider 侧数据，lease sealing 切 require + attestation deny；H7 QUIC relay 端口纳入计量。
