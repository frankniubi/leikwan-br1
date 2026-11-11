# Handoff: Clash 分流 + SS2022 互通 + 用户功能包（2026-11-11 → 2026-11-12）

**分支**: `feat/clash-routing-user-device-limit`（worktree `C:\Users\frankniubi\Downloads\leikwan-feature`）
**HEAD**: `92a61be5`（相对起点 `426c35f5`：104 文件，+9990/-4054）
**CI 状态**: 全绿 —— Android/Windows/Linux/macOS client、Routing E2E、**SS2022 Interop 4/4**（iOS/Android × sing-box/Xray 真实握手成功）
**生产状态**: 面板侧已部署（2026-11-11 04:00Z，备份 clash-devlimit-20261111-035857）；**客户端未发版、分支未合 master**。用户明确要求：现阶段不动生产。

## 整个工作的目的

用户（面板运营者）提出的需求包，按四个 wave 推进：

1. **Clash 兼容分流**（已完成，面板侧已上线）：Android/iOS 支持 Clash 规则粘贴导入 + 面板同步路由方向。
2. **用户级设备上限**（已完成，面板侧已上线）：`users.online_device_limit` 覆盖套餐值。
3. **SS2022 密钥互通**（本次会话完成）：用户导入的节点来自三种服务端生态（sing-box 超长密钥 / Xray raw ASCII / 标准 base64），客户端原来一律连不上；做四端密钥规范化 + 互通 CI 证明。
4. **用户功能包**（F1/F2 已完成；F3/F4 已设计未实施）：iOS「Ultra 授权不可用」修复、隧道内延迟显示、Surge .list 规则导入、用户规则云端同步。

附带：`clash-assessment/` 是 GPL 的 Clash 客户端，仅作语法参考，**不嵌 mihomo、不复制 GPL 代码**（用户确认的路线）。

## 已完成（按 commit 顺序）

### Wave 1-2（上一个会话，已部署面板侧）
- `55466b18` Clash 路由 + 设备上限 + 刷新 + KB（面板+双端）
- `ecc6fe99`/`5058f69f` routing-e2e CI（App 真实生成配置跑真 sing-box core，双端 17/17×2）
- 面板迁移已上生产（只新增文件，无整目录覆盖）

### Wave 3：SS2022 密钥规范化（本次会话核心交付）
- `bc461f25` 四端 normalizer（Android/iOS/Desktop）+ 面板 HostedNodeSpec 放宽 + 单测
- `f8089cdb`→`92a61be5` 十五轮 interop CI 迭代修完所有问题（见下面"踩坑清单"）
- `tools/ss2022-interop/`：harness + 两个 server 配置 + README；CI workflow `ss2022-interop.yml`
- **最终验证**：`{ios, android} × {sing-box 1.13.12, xray v25.9.11}` 全部真实握手 + TCP 往返成功
- `6a0dfc35` 修复了一个**会漏到用户的真 bug**：四端 normalizer 原本输出无 padding base64，而 sing-box 客户端库要求标准 padded——互通测试的价值证明

### Wave 4a：F1 + F2（`5ae1ea92`，随互通 CI 全绿验证）
- **F1 iOS「Ultra 授权不可用」修复**（client 68 的根因）：首次失败在 plan-time grant 上时立即重试（下次尝试先 pull 新 grant），不再等 5-60s 退避；`UltraPolicy.validationFailure()` 记录具体是哪个校验拒绝 + expires_at；重试节奏抽成 Core 的 `UltraRetryPolicy`（可单测）
- **F2 隧道内延迟显示**（iOS + Android）：合成 `probe` urltest 组（成员 [活跃出口, "direct"]，不被任何 route rule/final 引用），libbox group 流把 URLTestDelay 推到 UI。Android：`writeGroups` 原本只进 logcat，现在喂 `TrafficMonitor.reportLatency`；iOS：`StatusMonitor` 订阅 `LibboxCommandGroup`（writeGroups 原是空 stub），经 `TunnelStatusSnapshot.latencyMs` 到 dashboard 的延迟 label。Ultra runtime-only 桥接模式跳过（probe 会测回环）；诚实的 relay-RTT 探测是 phase 2
- 测试：Android 100/100 套件 + Desktop 1652 全绿；iOS 走 CI swift test

### 面板诊断（client 68 / iOS「Ultra 授权不可用」）
根因 = pull 缓存的 ultra_access 过期 + generation ~15-20 分钟轮换（信誉指纹参与 hash）之间的空窗；面板侧零拒绝记录（ultra_lease_refusals 表 client 68 = 0 行）。F1 的立即重试 + 诊断日志就是修复。详见 memory `ios-ultra-unavailable-client68-20261111`。

## 踩坑清单（互通 CI 抓出的 6 个真问题）

1. **iOS Swift `case keyLength + 1...:`** 在 Int switch 里非法（编译错）
2. **iOS `Data(base64Encoded:)` 严格 padding**（43 字符无 padding 返回 nil → 掉进 raw-ASCII 分支）
3. **C# `Convert.FromBase64String` 同样严格 padding**（三端三个语言踩同一坑）
4. **sing-box multi-user 服务端 `password` 字段 = iPSK 单独**（不是 iPSK:uPSK；uPSK 走 `users[]`）——本地拉 sing-shadowsocks@v0.2.8 源码验证
5. **Xray v25.9.11 SS2022 入站严格 base64**（main 分支 ParseKey 的 raw-ASCII 回退是之后才加的）——本地拉 v25.9.11 源码验证
6. **CI artifact 传递**：upload-artifact 显式文件路径不带目录结构；xray chmod 要按 leg 条件执行

**铁律沉淀**：SS2022 密钥一律**标准 padded base64**（`base64.StdEncoding`）。详见 memory `ss2022-key-normalization-interop-green`。

## 未完成 / 下一步

### F3 — Surge .list 规则导入（Android + iOS，已设计未实施）
- 用户场景：`https://raw.githubusercontent.com/blackmatrix7/ios_rule_script/master/rule/Surge/ChinaMax/ChinaMax_All.list` 这类规则表
- 方案（plan agent 已文件级细化）：客户端 fetch → `SurgeListConverter` 转 sing-box source JSON → `type:local, format:source` 本地引用（ChinaMax 级别可行，无路由规则爆炸）
- 支持：DOMAIN*/IP-CIDR*/DST-PORT + AND/OR/NOT 逻辑规则；整表单一 outcome；PROCESS-NAME/URL-REGEX/IP-ASN/FINAL 跳过计数；限制 4MB/20000 条/RE2 检查
- 关键约束：iOS `RuleSetInstaller.ensureInstalled()` 会清 app group ruleSetDirectory → 用户列表必须放**独立目录**（新 `AppGroup.userRuleSetDirectory`）
- 新文件：Android `SurgeListConverter.kt` + `ImportedRuleListStore.kt`（文件存储，避免 Room 迁移）+ `RuleListFetcher.kt`；iOS `SurgeListConverter.swift` + `ImportedRuleLists.swift`；两端 `RuleSetRef` 加 `localPath`
- 测试：两端转换器全类别 fixture；builder 测试

### F4 — 用户规则云端同步（面板侧；**重大发现：repo 里已有批准方案**）
- **`docs/superpowers/plans/2026-07-22-backend-admin-route-direction-client-rules.md`** 是 2,679 行的已批准实施方案（精确 DDL、API 契约、TDD 任务清单），Android 客户端半**已完全实现并兼容**（PanelApi 端点、DTO、ClientRouteRuleStore pending-op 状态机、UI 全部就绪）——**F4 只发面板即可点亮整个 Android 功能面，零客户端发版**
- 实施 = 按批准方案 + 一个小修正：把规则摘要接入 `config_revision`（`stableConfigSnapshot` 的 `$revisionExtra`，让规则写入唤醒长轮询）——批准方案漏了这步
- 分两个 PR：PR1 = schema + services + client API + pull merge（激活端到端）；PR2 = admin 控制台（可延后）
- ⚠️ 批准方案的迁移用了 FK 约束，而项目纪律（hosted-nodes 迁移先例）是无 FK + 应用层清理——合并前先跑 schema 测试对 prod-shaped dump 验证，有漂移就发无 FK 变体
- ⚠️ 批准方案的 capability 字段需加进 `ClientAgentController::heartbeat` 的 `mergeRuntimeState`（Android 已发送，面板缺失，~line 1681）

### 其它遗留
- **分支未合 master、客户端未发版**——F1/F2/SS2022 修复要以可选更新发版（绝不强推）
- **iOS 内存 50MB quick wins 未做**（Surge 逆向结论，memory `ios-ne-50mb-memory-surge-lessons`）：phys_footprint 三阈值监控、memory-pressure source、MTU 9000→1500
- **F2 phase 2**：Ultra 桥接模式下 probe 测的是回环；诚实修法是对 relay endpoint 的 RTT 探测（iOS activeEndpoints / Android protectedConnectivity 已有数据）
- 接打电话中断 = 蜂窝↔WiFi 切换触发 NE 重建 + 同样的 pull 空窗（F1 已缓解）；不是选线频率问题

## 验证清单（接手后跑一遍）

```bash
# Android
cd leikwan-android && ./gradlew :app:testDebugUnitTest --no-daemon   # 100/100 套件
# Desktop
cd LeikwanBridge.Desktop && dotnet test                               # 1652 个
# 面板
php -d extension=openssl -d extension=pdo_sqlite master/tests/hosted_node_spec_test.php
# iOS —— 本机无 Xcode，靠 CI（codex/macos-client push 触发）
# 互通 —— SS2022 Interop workflow 4/4
```

## 关键文件索引

| 功能 | 文件 |
|---|---|
| SS2022 normalizer | `leikwan-android/.../shadowsocks/SS2022KeyNormalizer.kt`、`ios/Core/.../Shadowsocks/SS2022KeyNormalizer.swift`、`LeikwanBridge.Desktop/.../Shadowsocks/SS2022KeyNormalizer.cs` |
| 互通 harness | `tools/ss2022-interop/{harness.py, server-*.json}` + `.github/workflows/ss2022-interop.yml` |
| F1 重试策略 | `ios/Core/.../Ultra/UltraRetryPolicy.swift`（+测试）、`ios/PacketTunnel/UltraLeaseManager.swift`（catch 块） |
| F1 诊断 | `ios/Core/.../Ultra/UltraModels.swift` `UltraPolicy.build/validationFailure` |
| F2 probe 组 | 两端 `SingboxConfigBuilder`（`latencyProbeOutbound`）；Android `LeikwanVpnService.writeGroups` + `TrafficMonitor`；iOS `StatusMonitor.writeGroups` + `TunnelProtocol.TunnelStatusSnapshot.latencyMs` |
| F2 UI | `ios/App/Views/DashboardView.swift`（LatencyLabel）、`leikwan-android/.../ConnectionsScreen.kt`（ms chip） |
| F3 落点 | 两端 `SingboxConfigBuilder`（F2 刚改过，rebase 后再动）、iOS `AppGroup`（新 userRuleSetDirectory）、`RuleSetInstaller`（勿清用户目录） |
| F4 权威方案 | `docs/superpowers/plans/2026-07-22-backend-admin-route-direction-client-rules.md` |
| F4 面板落点 | `master/src/Controllers/ClientAgentController.php`（heartbeat ~1681、pull ~1083、revision ~1170）、`Bootstrap.php`（路由 ~390） |
| Android 已就绪的 F4 半 | `PanelApi.kt`（client-rules 端点）、`data/clientrules/ClientRouteRuleStore.kt`、`PanelModels.kt`（@SerializedName 契约） |

## 运维注意事项

- 生产冻结令（用户 2026-11-11 下达）在本 handoff 写就时仍有效——不要 SSH 面板/171/任何线上机器
- CI 走 GitHub Actions（sanitized snapshot 流程推 `codex/macos-client`；本机代理端口可能变动——检查 `netstat -ano | grep LISTENING | grep sing-box` 对应端口）
- 本机代理曾从 2080 变 2082（sing-box 重启），git/curl 的 `http.proxy` 参数要跟着改
