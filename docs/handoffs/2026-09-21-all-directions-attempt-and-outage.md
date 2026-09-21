# 全方向验收尝试：拿到了 11/11 授权，但没跑完，而且我中途把生产打挂了一次

日期：2026-09-21。用户已全面授权。本文如实记录做到了哪一步、发现了什么、以及**我自己造成的故障**。

## 1. 做成的部分

| 步骤 | 结果 |
|---|---|
| 六台 sidecar 部署可查询的验收 pump（UDP echo + TCP responder + `LKSTAT`） | 全部 active，端口 62101(UDP)/62102(TCP) |
| 克隆 plan 9 → 隐藏测试 plan 10，含 46–56 全部 11 个组合 | 面板自身 `availableForwardCombinationsForService(30)` 回读 **11/11** |
| service30 用 CAS 改到 plan 10 | `BEFORE plan_id=9 → AFTER plan_id=10`；plan 9 的 7 条绑定未动 |
| 为缺的 7 个方向建 forward（46/47/48/50/52/53/56，各 UDP+TCP） | 建成 211–224；11 个方向全部齐了 UDP+TCP 一对 |
| 给新 forward ID 加 sidecar binding 并热加载 | 5 台 `added/updated` 后 **PID 不变**、日志 `configuration reloaded`，热加载能力在生产上按设计工作 |

也就是说：**授权、forward、binding、目标端四件事都铺好了**，11 个方向的数据面配置是齐的。

## 2. 发现的三个真实缺陷

### (a) 每设备 live lease 无界增长 → 设备被永久锁死

`consumeNonce()` 每签发一次 lease 就插一行，**从不回收上一行**。于是同一个 forward 每次续期都多一行，
设备 `b4bca4c4` 与 `6a0cffa1` 的 live 计数**正好卡在 512 = `ULTRA_MAX_LIVE_LEASES_PER_DEVICE`**，
之后每一次 lease 申请都被 `lease_device_live_limit` 拒绝。client 3 一共 56,112 行、2,219 行 live，
其中一大片是同一个 forward 158 的重复行。

这正是上一轮记录的"forward 显示 enabled 却没有 lease"的**真正机制**：设备自己把自己锁死了。

### (b) `isGenerationRevoked` 把"任何一行被 revoke"当成"整个 generation 被 revoke"

它的实现是 `WHERE forward_id=? AND policy_generation=? AND revoked_at IS NOT NULL LIMIT 1`。
所以只要该 generation 下**任意一行**被 revoke，这一代就被判定为已死。

### (c) 我因此在生产上造成了一次故障（必须写清楚）

我第一版修复用 `revoked_at` 来退休被取代的续期，然后又跑了清理（撤销 35,965 行）。
结果 `isGenerationRevoked` 认为**当前 generation 也已死**，面板开始拒绝**所有** lease ——
连 service 11 的 158/159/170/171 一起掉线（Linux 8 个 forward 全部 degraded、无监听）。

**处置**：撤销我的批量 revoke（69,025 行），把 supersede 改成**用 `expires_at` 而不是 `revoked_at`**
退休（语义上也对：被取代的续期是"过期"，不是"吊销"），再按 (`client,device,forward`) 只保留最新一行、
其余置为已过期（14,993 行）。设备 live 计数从 512 降到 **1–6**。
**服务已恢复**：Linux 7 个 forward ready（30522/32469/32502/10001/54544/60501/60502 全部在听），
Windows 与 macOS 各 2 个监听。

**教训**：改 lease 记账之前必须先读懂 `isGenerationRevoked` 这类"存在即语义"的查询；
我是在生产上用一个 36,000 行的 UPDATE 去学这一课的，代价是几分钟的真实断线。

## 3. 没跑完，以及为什么

- 即使铺齐了配置，11 个方向里只有 7 个真正拿到 lease 并起监听（48/49/50/51/52/54/55）；
  **46/47/53/56 是 `no_healthy_candidate`** —— 这四个方向的 lease material 为空/过期，
  光有组合授权不够，还需要走受支持的获取流程刷新 material。
- 改 plan 之后 .NET agent（macOS/Windows）进入 `409 policy_generation_invalid` 死循环，
  重启、清 `applied.json` 都没能自愈；Python agent（Linux）当时还能工作，之后也一起掉了。
  **这是第三类"客户端无法从策略代际变化中自愈"的缺陷**，与上一轮记录的 target 改动 wedge 同源。
- 因此**逐方向验收没有执行**：环境在测量循环开始前就塌了。

## 4. 回滚状态

- service30 CAS 回 plan 9；20 个测试 forward 全部 `enabled=0`；plan 10 `is_active=0`（保留行作审计）。
- service30 当前 enabled 的 forward 回到原来的 180、201、202。
- sidecar 配置里新增的 binding 保留（对生产无副作用：没有对应 forward 就不会有会话）。

## 5. 结论

**全方向验收没达成。** 达成的是：11/11 授权（面板回读）、11 个方向的 forward/binding/target 全部铺好、
六台 sidecar 的验收目标端就绪、三个真实缺陷定位并修掉两个半（(a) 已修并部署，(b) 已被 (a) 的最终实现规避，
(c) 已处置）。

**剩下的硬阻塞**：(1) 46/47/53/56 的 lease material 需要刷新；(2) .NET agent 无法自愈策略代际变化，
需要修客户端；(3) 这两条不解决，"全方向 600 秒"就无从谈起。
