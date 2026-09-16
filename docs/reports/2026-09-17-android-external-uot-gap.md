# Android external UoT 引导缺口分析

## 范围与方法

只读分析，不构建、不改代码、不跑设备。目标：回答 `2026-09-17-external-uot-live-store-publish.md`
第 134 行留下的未决项 —— Android 客户端的 `external_uot` 引导是否存在、缺在哪里。

对比基线：`master` 工作树（含 `82c01071` / `26047618` / `99b9d32f`），Android 应用
`0.2.29`（versionCode 31），Linux client-agent 活体验收见
`2026-09-17-external-uot-forward-170-live-acceptance.md`。

## 结论

Android 目前**完全没有 external UoT 支持**，且缺口有三层，缺一层都跑不起来：

1. **运行时二进制缺功能（硬阻塞）**：APK 内打包的 `ultra-client` 早于 external UoT
   功能提交，二进制里根本没有 `external_uot` 配置键 —— 即使面板下发了授权、Kotlin
   侧写进了配置，运行时也会静默忽略，继续走原生命令 3 UDP。
2. **Kotlin 侧缺解析、缺配置写入、缺重启语义**：策略解析丢弃该块，`buildPlan` 不写
   该块，`refreshUltraIfDue` 的重启判据也不包含该块（而运行时只在启动时读取它）。
3. **交付面已就绪，无需面板改动**：Android 读策略的 `ultra_access` grant 本身就是
   面板投放 `external_uot` 的通道（与 Linux agent 同款，forward 170 现场验收走的就是
   它）。Android 应按 grant 面实现，不要依赖 `/api/client/ultra/lease` 的 lease item。
4. **附带发现（互操作，非 Android 专属）**：桌面端从 lease item 里解析 `external_uot`，
   但面板 lease 面不产出该块，因此 Windows/macOS 的 sidecar 激活目前在生产上不会发生；
   见下文“交付面”一节。

## 证据

### 1. 打包的运行时二进制早于 external UoT

三处副本字节一致：

```text
leikwan-android/app/src/main/assets/runtime/arm64-v8a/ultra-client   7889D078706A3A8A1EA77D3E2ABEC2B27D8D3FBDA06B2144C4CDED4951DD9E69
leikwan-android/app/src/main/jniLibs/arm64-v8a/libultra_client.so    7889D078706A3A8A1EA77D3E2ABEC2B27D8D3FBDA06B2144C4CDED4951DD9E69
artifacts/ultra/ultra-client_android_arm64                          7889D078706A3A8A1EA77D3E2ABEC2B27D8D3FBDA06B2144C4CDED4951DD9E69
```

来源提交与祖先关系：

```text
git log --oneline -1 -- artifacts/ultra/ultra-client_android_arm64
  dd53391d release(ultra): publish stability package 2026.09.15.2

git merge-base --is-ancestor 82c01071 dd53391d   # exit 1：资产不含 external UoT 提交
git merge-base --is-ancestor dd53391d 82c01071   # exit 0：dd53391d 早于它
git merge-base --is-ancestor dd53391d 6d189471   # exit 0
git merge-base --is-ancestor dd53391d b652d940   # exit 0
```

二进制字符串判定（garble 保留结构体 tag 与配置键字面量）：

```text
external_uot        False
sidecar_target      False
queue_packets       True
native_socks5       True
target_digest       True
policy_generation   True
udp_prewarm         True
```

即：该运行时认识同期（2026.09.15）之前的全部配置键，唯独不认识 `external_uot` /
`sidecar_target`。external UoT 的全部运行时实现（`tools/ultra-client/external_uot.go`、
`main.go` 的 `ExternalUoTConfig`）都由 `82c01071`（2026-09-16）引入，晚于本资产。

### 2. Kotlin 侧三处缺口

- **解析**：`ForwardContract.kt:209` 已把 `ultra_access` 整块保留为 `JsonObject`，
  所以数据在手里；但 `UltraModels.kt:29-60` 的 `UltraPolicy.from` 只挑校验过的固定字段
  （protocol/session_ticket/policy_generation/transport/target_digest...），未知子树被丢弃，
  仓库内也不存在任何 `ExternalUot` 模型（全树 `rg -n 'external_uot' leikwan-android` 为空）。
- **配置写入**：`UltraForwardRunner.buildPlan`（`runtime/UltraForwardRunner.kt:49-105`）的
  静态配置键列表没有 `external_uot`；该函数是唯一的 Ultra 引导配置生产者，运行时只读该文件
  （`--config <file>`）。
- **重启语义**：`TunnelOrchestrator.refreshUltraIfDue`（`service/TunnelOrchestrator.kt:793-830`）
  只在 `forwardId` / `targetDigest` / `transport` 变化时重启运行时，其余变化通过控制 socket
  原地换 lease。而运行时只在启动时读取 `external_uot`，因此“授权出现/消失/换 token/换
  sidecar 地址”都不会生效，需要一个 bootstrap 指纹参与重启判据。

### 3. 交付面：grant 有，lease 没有

面板投放路径（两处都在 `PanelRepository` 同一份数据上）：

- `ClientAgentController::attachUltraAccessGrants`（`master/src/Controllers/ClientAgentController.php:918-920`）
  把 forward combination 元数据里的 `external_uot` 拼进 broker forward，随后由
  `LeigodTokenBroker`（`master/src/Support/LeigodTokenBroker.php:216` 一带）挂到 grant 上；
  `pullConfigs`（约 660 行）与 `resolveUltraLease`（419 行）都会调用它。
- 也就是说：**`ultra_access.external_uot` 是真实存在的**，Linux client-agent 就是从这里读
  （`client-agent/install_forward_runtime.py:1190-1208`，写进 0600 的
  `/etc/ultra/forward-{id}.json`），forward 170 的现场验收即该路径。
- **`/api/client/ultra/lease` 的 lease item 不携带该块**：`UltraLeaseService::scrubLease`
  （`master/src/Support/UltraLeaseService.php:970-1000`）是显式白名单，没有 `external_uot`；
  全历史验证：`git log -S 'external_uot' --oneline -- master/src/Support/UltraLeaseService.php`
  无输出。
- 部署树复核（`.tmp/deploy-panel/`，由
  `2026-09-17-external-uot-panel-config-verification.md` 从线上拉取）：
  线上 `LeigodTokenBroker.php:299-301` 只把该块挂到 **grant** 上；线上
  `ClientAgentController.php` 中该键仅出现在 874-875（grant 挂接点）；lease 组装处
  （531 行 `$leases[] = $candidateLease;`）没有回填该块。注意该 audit 样本未包含
  `UltraLeaseService.php`，若要 100% 闭合可在面板上对该单文件复核一次。
- 桌面端结论：`99b9d32f` 让 `MasterApiClient.ParseUltraLease`（`Services/MasterApiClient.cs:547-578`）
  从 **lease item** 解析该块，`ConfigPollingService.cs:421/539` 用它填充 `RuntimeLeases`，
  `ForwardRuntimeManager.cs:161-163` 再折叠进契约签名。由于 lease item 从不带这个块，
  桌面 sidecar 在生产上目前不会被激活 —— 该 commit 的消息与测试证明的是代码路径，
  不是线上事实。若团队要让 Windows/macOS 真正吃到 sidecar，面板必须把该块投影进 lease
  item（等于把 sidecar token 扩面到 lease 消费者，需要单独评审）。

Android 不需要等这件事：它读的是 `pullConfigs` 的 grant，与 Linux agent 同源、已活体验收。

## 建议实施清单

### Step 1（阻塞项）重建 Android 运行时

```powershell
scripts/build-ultra-android.ps1 -Version 2026.09.17.x -Architectures arm64,x86_64
```

- 该脚本同时刷新 `assets/runtime/<abi>/ultra-client`、`jniLibs/<abi>/libultra_client.so`
  与 `artifacts/ultra/ultra-client_android_*`，并做可复现性校验（garble `v0.17.0`）。
- 重建后先用字符串判定复核：`external_uot` / `sidecar_target` 必须为 True。
- 随包发布：`versionCode`/`versionName` bump（当前 31/`0.2.29`），并同步面板
  attestation 指纹清单（`ULTRA_ATTESTATION_ENFORCEMENT=enforce` 时哈希不匹配会被拒）。

### Step 2 Kotlin 解析层

新增 `ExternalUotGrant`（建议放 `runtime/ExternalUotGrant.kt`），从
`forward.ultraAccess?.get("external_uot")` 解析，钳制规则与桌面
`LeikwanBridge.Core/Models/ExternalUotGrant.cs` / Go `normalized()` 对齐：

| 字段 | 规则 |
| --- | --- |
| `enabled` | 必须为 JSON true，否则整块视为不存在（静默回退原生 UDP，不产生告警噪声） |
| `sidecar_target` | `host:port` 或 `[v6]:port`，长度 ≤ 512，否则该块作废并记原因 |
| `token` | 非空、无 NUL、UTF-8 ≤ 8192 字节；永不入日志/状态/UI |
| `queue_packets` | 1..4096，越界按“侧车默认值”（写 0 / 不写） |
| `keepalive_seconds` | 5..30 |
| `reconnect_grace_seconds` | 5..300 |
| `frame_payload_max` | 256..65507 |

### Step 3 配置写入与互斥

- `UltraForwardRunner.buildPlan`：`grant != null && transport in {"udp","tcp+udp"}` 时写入
  `external_uot`（`enabled: true` + 钳制后的字段）。
- 与 `nativeSocks5` 互斥：当前 Go 运行时在两者同时出现时直接 `log.Fatal`
  （`external UoT is not supported by native SOCKS5 mode`）。Android 应在 `buildPlan` 里
  `require(!nativeSocks5 || grant == null)`，并且 `purpose = native_outbound` 的 lease 路径
  一律不写该块。
- Token 落地卫生：配置文件已进 `privateRuntimePaths`，`cleanup()` 会删除；保持
  `makePrivate` 语义，token 不得进入 status JSON、崩溃上报或 UI 文案。

### Step 4 重启指纹

在 `refreshUltraIfDue` 的重启判据中加入 external UoT 指纹（`enabled` + `sidecar_target` +
四个钳制后的旋钮 + `sha256(token)` 十六进制），语义对齐桌面
`ForwardContractSignature`：**只折叠指纹、绝不折叠 token**，token 轮换或 sidecar 迁移各
触发一次重启。判定应与 `forwardId`/`targetDigest`/`transport` 放在同一处，避免重复重启。

### Step 5 可观测性与行为差异

- 运行时新增状态：`external_uot_config_invalid`、`external_uot_no_healthy_candidate`、
  `external_uot_packet_invalid`（`tools/ultra-client/external_uot.go:498/765/793`），应映射到
  Android 的状态/日志层，而不是被当作未知字段静默吞掉。
- 启用 sidecar 后运行时跳过原生命令 3 UDP 探测、改用 TCP 级证据选路
  （`82c01071` 改动 `udp_probe.go` / `active_candidate_fallback.go` / `control.go`）。
  Android 现有逻辑对 `transport == "udp"` 不 await 本地端口
  （`TunnelOrchestrator.kt:547`），sidecar 场景通常是 `tcp+udp`，此分支不受影响；但任何
  依赖“UDP 探测成功”措辞的 UI/诊断需要复核，避免误导。

## 验收方案

1. **单测（JVM）**：对齐桌面 `WindowsUltraRuntimeHostTests` 的四类用例 —— grant 存在 →
   配置出现且钳制正确；grant 缺失 → 键不存在；native → 键不存在；`sidecar_target` 非法 →
   回退原生且告警不含 token。外加“重启指纹变化触发一次重启、同 generation 续租不重启”。
2. **设备/模拟器 canary（对齐 forward 170）**：测试账号指向东京 sidecar（`185.220.238.143:7443`）
   的 UDP 目标；断言端到端 UDP 流（iperf3）经 carrier 到达固定目标、运行时
   `reconnects=0`/`drops=0`、停掉 `leikwan-external-uot.service` 立即可复现中断（A/B）。
3. **回归**：非 sidecar forward 仍走原生命令 3 UDP；TCP 路径、MPTCP/bond 路径不受影响。
4. **发布记账**：APK 版本号、运行时哈希、面板 attestation 指纹清单三者一致。

## 未验证与风险

- 本文未构建、未跑设备/模拟器；“二进制不认识 `external_uot`”为哈希 + 祖先关系 + 字符串
  三重证据推得，仍建议在 Step 1 重建后用运行日志做一次实机确认。
- Android 上 sidecar 路径的实际吞吐/丢包未知；现有验收数据来自主机/Linux 侧。
- Token 在 Android 会在运行期落到 app 私有目录（停止时删除）；macOS 走 stdin 不落盘，
  Android 做不到（与 Linux agent 的 0600 落盘同级）。若不可接受，需要单独的密钥库方案。
- 桌面 lease 面缺口（见“交付面”）不影响 Android 实施，但会影响“Windows/macOS 已确认
  external UoT”这一说法的准确性，建议在未发布报告中改为“引导代码已就绪、端到端未生效”。
