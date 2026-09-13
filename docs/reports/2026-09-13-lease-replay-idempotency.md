# 租约重放 409 拆掉 forward — 根治 + 客户端兜底

日期：2026-09-13 · 面板：173.208.183.93 · App：**0.1.20.41**

## 现象（本机 13:45–13:46）

```
[WRN] Ultra lease refresh failed for forward 171 (error=MasterApiException, status=409, code=lease_replayed)
[WRN] Ultra lease unavailable for forward 171; stopping because no running managed runtime can be preserved
```

forward 被停掉，用户侧就是路由直接断。

## 根因

1. **面板**：`UltraLeaseService::consumeNonce()` 以 INSERT 撞唯一键判定重放，同一个 nonce 第二次提交一律回 409 `lease_replayed`。
   但 nonce 的键已经含 (client, device, forward)，**不可能是别人在重放**——实际是同一客户端重试：
   配置轮询与定向刷新并发抢同一张票，或首个请求超时后面板其实已提交，客户端重试。
2. **客户端**：`ConfigPollingService.IsGrantRefreshError()` 把 409 分为"可由下次配置拉取修复"与"必须停机"两类，
   白名单收了 `lease_scope_invalid` / `policy_generation_invalid` / `policy_scope_invalid` / `policy_unavailable` /
   `no_approved_candidate` / `quota_exhausted`，**唯独漏了 `lease_replayed`** → 落到 `TryPreserveRunningUltra` 失败 →
   `StopForwardForPolicy()`。代码注释里已经写明"票据轮换类 409 绝不能拆 forward"，漏项让这条规则失效。

面板历史拒绝码分布（印证漏项恰好是最常见的可修复类之一）：
`no_approved_candidate` 659 · `policy_generation_invalid` 230 · **`lease_replayed` 184** · `policy_expired` 6。

## 修复

**面板（根治，已上线 05:49:26Z）**：`consumeReplayNonce()` — 同 client+device+forward 的 nonce 在
`REPLAY_GRACE_SECONDS=300` 内按 `MAX_REPLAY_ATTEMPTS=5` 有界重试，用派生 nonce（`<hash>#retry:N`）开新租约，
**旧行保留**（正在跑的 runtime 仍能就它上报 feedback）。撤销的、或超过窗口的 nonce 依旧拒绝。
日志留痕 `ultra_lease_nonce_retried client=.. forward=.. attempt=..`。

**客户端（兜底，0.1.20.41）**：`lease_replayed` 加入 `IsGrantRefreshError` 白名单 → 走"排队一次配置拉取"，
不再拆 forward。附带把那组错误码抽成可按需注入的假服务器字段，补了
`SyncOnceAsync_QueuesConfigRefreshWhenTheLeaseWasReplayed` 用例。

## 验证

| 检查 | 结果 |
|---|---|
| 部署后 `lease_replayed` 拒绝 | **0**（部署前 05:37–05:48 反复出现） |
| 部署后重试被受理 | `ultra_lease_nonce_retried` 3 次（client 25/162、client 3/158） |
| 部署后其余 409 | 仅 `policy_generation_invalid` 4 次 —— 该码本就在客户端可修复白名单内，不拆 forward |
| 面板契约测试 | `native_outbound_lease_contract_test` 改为新契约（重试放行、洪泛拒绝）→ ok |
| 客户端测试 | `ConfigPollingService` 26/26 通过 |
| App 0.1.20.41 | `1ae2b5f8b682692a` 69,556,756 B，两端点 200 且哈希一致；pin 仍为 runtime `ecbee8f7` |

## 同日的面板掉线（已自行恢复）

13:56–14:40 本机时间整机不可达：22/443/8888 全超时、ICMP 不通，且**从国内堡垒机同样 100% 丢包**（同刻堡垒机与 Mac VM 均正常）。
面板于 **06:40Z 重启**（上一 boot 日志止于 06:38Z），磁盘 49%、nginx/mariadb/php-fpm 全 active、health 200。
带外通道不可用，未能取到崩溃现场；整机连 ICMP 都不回更像主机/机房层故障，而非磁盘写满或单服务崩溃。
重启后确认：`max_connections=500` 的持久化配置生效（连接数 5），租约修复文件完好。
