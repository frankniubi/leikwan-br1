# API / 计费 / 带宽 审计 —— 修复落地（2026-10-07）

分支 `fix/security-audit-20261007`（从 `master` 切）。审计见
[2026-10-07-api-billing-bandwidth-audit.md](2026-10-07-api-billing-bandwidth-audit.md)。
本文件记录每条的处理状态。所有修复只改面板（PHP），用 `php -l` + 仓库 PHP 测试套件验证：
**189 passed，0 回归**（另有 6 条因本机 CLI PHP 缺 `pdo_sqlite`/`openssl` 无法在此运行，与本次改动无关）。

## 已修复（面板代码，已测）

| # | 问题 | 修复 | 文件 |
| --- | --- | --- | --- |
| C1 | 节点自去重接管任意节点 | 去重候选限定同 `owner_service_id`（NULL-safe），且删除资格**必须**命中硬件 `machine_id`；name/IP 不再单独构成资格 | `NodeController.php` |
| C2 | 注册节点窃取 CF Global Key | BYOS（user-enrolled）注册强制 `credential_profile_id=0`，只有平台注册可绑凭据档 | `NodeController.php` |
| C6 | WHMCS 转账竞态增发 | panel→whmcs 先原子扣面板（`WHERE wallet_balance>=:amt` + 行数判定）再 credit 远端，远端失败回退；whmcs→panel 先扣远端再原子加面板，失败补偿 | `WhmcsIdentityService.php` |
| H1 | 钱包扣款双花 | `chargeWallet` 改条件扣减 + 影响行数校验，不再 read-then-absolute-write | `ProvisioningService.php` |
| H2 | 改写他人 client / 自定设备数 | 编辑前校验 `owner_user_id` 归属；`online_device_limit` 由套餐 `defaultClientOnlineDeviceLimitForTenant` 封顶，不信表单 | `TenantController.php` |
| H3 | 设备数限制绕过 | 客户端自带 `legacy-*` device id 被忽略（落回服务端 UA+IP 派生 id，受限）；attested-Android device id 一律走 `requireActive`，不靠平台头 | `ClientOnlineSessionService.php`、`DeviceControlService.php`、`ClientAgentController.php` |
| H4/M2 | 节点给任意服务记流量 | `applyUsageReport` 的 `service_id` 由 endpoint→forward 推导，不信请求字段；节点只能给自己承载的服务计费 | `ProvisioningService.php` |
| H5 | 转发目标 SSRF（部分） | 面板侧 `validateTargetHost` 拒绝 loopback/link-local/metadata/unspecified 等保留段字面 IP（保留 RFC1918 给自有后端）及 `localhost`/`*.localhost`（见下方“数据面待办”） | `ForwardLineApiContract.php` |
| H6 | 过期服务不停（API 层） | v1 变更、legacy 变更、`client-config`、`requireClientToken` 全改用 `ServiceStatus::effective` / `expire_at` 谓词，过期服务无法再管理转发或拉新配置（数据面拆除见待办） | `ForwardLineApiController.php`、`ServiceController.php`、`Auth.php` |
| H8 | 租户 sidecar point 泄露 external-UoT token | `/external-uot-config` 的 `resolvePoint` 只认平台点（`owner_user_id IS NULL`）；租户保存 sidecar point 时剥离自选 `agent_token` | `ExternalUotConfigController.php`、`TenantController.php` |
| M3 | 停用 client 仍有权 | `requireClientAgent` 加 `status='active'` | `Auth.php` |
| M6 | v1 PATCH native 处理错 | `mergePatch` 读正确列 `native_outbound_enabled`；controller 把 `native_outbound` 纳入结构性判断 | `ForwardLineApiContract.php`、`ForwardLineApiController.php` |
| M7 | native/placement 错误码塌成 500 | errorResponse 补全 `native_outbound_*`/`native_direction_conflict`（403/409/422），内部 combination/node/line-client 码别名到 `route_unavailable`/`forward_route_not_allowed` | `ForwardLineApiController.php` |
| Low | `ingress` 泄露 CDN host / 假 0 端口 | 不再回退 `cdn_host`，空值返回 `null` | `ForwardLineApiContract.php` |
| Low | admin token 走 query string | `requireAdmin` 只收 Authorization header | `Auth.php` |

## 未在本次代码落地（架构 / 数据面，需单独带测试部署）

这些**不是面板一处能安全改完**的；在无法编译 Rust、无法对生产转发集群做数据面测试的前提下，把未测代码推给生产集群本身就是事故源（见 memory 里多次 churn/宕机教训）。给出设计，建议各自带数据面测试单独上。

- **C3 / C4 / M5 —— 服务端带宽限速（最高优先）**：现状是所有限速只在客户端，bond/MPTCP/leigod 连客户端都不限。签名 token（`ProvisioningService.php:~13230`、`leikwan-bond/src/token.rs`、`leikwan-uot/src/sidecar_runtime.rs`）里**不带速率**。
  方案：把 `rate_limit_mbps` + `service_id` 签进 session token，由 bond/UoT Rust 服务端做**按 service 聚合**的令牌桶（不是按 forward，否则 N 条转发 ×N）。或在我方可控的 slave/sidecar 入口用 HTB/EDT 按 service 分类限速。两者都要 Rust/agent 改动 + 现网测产能。
- **C5 —— Ultra/native 计量只靠客户端自报**：`recordUltraUsage` 只认客户端 heartbeat 上报；lease 直接下发 provider SOCKS 凭据；attestation 自报且 optional。
  方案：计量改由运营方 relay 或 provider 侧用量回传；lease sealing 从 optional 切 require 并把 runtime attestation 置为 deny（见 memory `runtime-release-attestation-rollover`，切 require 前先灌旧哈希）。
- **H6 数据面拆除**：API 层已挡，但已持有旧配置的客户端在过期后数据面仍转发（slave 无 desired-state 同步、ingress HMAC 无过期）。需要一个 expiry→`suspend` 的扫描作业（queue lifecycle `suspend` + `revokeForwardEndpoints`），并让 `billing-deduct.php` 在置 `enabled=0` 时一并 revoke。这是计费 cron 里的 DB 逻辑，必须对库测过再上。
- **H7 —— bond QUIC 腿不计量**：`slave_agent.py:~7140` 计量规则只覆盖 `backend_port`，QUIC relay 走 `public_port`。需给 QUIC relay 端口加计量规则，属 Python 数据面改动，需现网验证。
- **H5 数据面补齐**：域名在节点侧解析，面板字面 IP 过滤只是第一层；权威过滤应在 bond/uot/slave 连接目标时做（解析后拒保留段）。
- **M1 流量重置免费**：本质是套餐配置（`traffic_reset_price` 默认 0.00），非代码 bug。建议把需要收费的套餐该字段设 >0，并视需要加重置频率限制。
- **M4 无限流**：`/api/client/login`、node register、enrollment-code 等无限流，需接入限流中间件/网关层。
- **Low**：node heartbeat 可自设 `status`/`group`（offline 自恢复）；client/sidecar token 仍可走 `?token=`（installer 在用，动需同步改 installer）。低危，单独处理。

## 验证

```
# 本机 CLI PHP 无 DB 驱动，跑纯逻辑/源码级测试
php -l <每个改动文件>            # 全部 No syntax errors
php tests/forward_line_api_*.php              # ok
php tests/native_outbound_forward_mutation_test.php  # ok
php tests/forward_target_validation_regression_test.php  # ok
php tests/legacy_forward_api_security_regression_test.php # ok
php tests/client_online_*_test.php            # ok
# 全量相关套件：189 passed，0 回归（6 条环境缺驱动跳过）
```

需 DB 的测试（usage/billing/whmcs/attestation）须在装有 `pdo_mysql`/`openssl` 的环境复跑，尤其
`ultra_usage_duplicate_ack_test`、`billing_cycle_stops_at_expiry_test`、`whmcs_*`。
