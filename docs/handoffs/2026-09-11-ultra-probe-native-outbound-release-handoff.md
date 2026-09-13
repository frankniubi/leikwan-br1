# Ultra Probe / Native Outbound 综合交接

- 日期：2026-09-11
- 范围：Ultra probe、candidate quarantine/isolate、client resilience、native outbound forward 180，以及本轮面板和客户端发布
- 当前结论：**面板 PHP、Android/client-agent/manifest 已在生产提交；源码和测试大部分完成；完整客户端可执行文件发布及 native outbound 数据面验收仍未完成。**

## 1. 交接摘要

这次工作的核心是把不可用线路从重复下发路径中隔离，并为 Ultra 增加探测、候选信誉和客户端恢复能力；同时修复 native outbound forward 180 将控制面错误误判成普通 Ultra 故障的问题。

已经完成的部分包括：

- Ultra candidate reputation、quarantine/isolate 数据结构、迁移、反馈入口和管理读取面。
- Go probe/selection/stream retry、candidate identity 和隔离逻辑。
- Desktop Core 的 native lifecycle guard、字段传递和共享 probe contract。
- Android lease/control-plane 状态、manifest 和本轮 Android 0.2.23 发布。
- Linux client-agent 状态/反馈能力，以及生产面板上的 client-agent/manifest 更新。
- PHP 面板迁移、服务和控制器的原子发布；线上迁移状态为空 pending。
- 相关设计、计划、事故和现场 handoff 文档已补齐或更新。

尚未完成的部分：

- 没有从包含真实 `opengfw` 的当前快照重新构建并发布 Windows/macOS/Linux 全套 Ultra/native 可执行文件。
- 没有完成 native outbound forward 180 的真实 HTTP proxy/TUN/native relay 数据面 canary。
- candidate quarantine 默认开关仍为关闭状态，未进行生产 cohort enablement。
- ARM64、闲置恢复、真实客户长连接、多客户端负载和物理设备 TUN 验收尚未完成。

## 2. 遇到的问题、根因与处理状态

| 问题 | 已确认根因或证据 | 已做处理 | 当前未决 |
|---|---|---|---|
| native outbound forward 180 持续不可用 | native listener 使用 `listen_port 54544`；控制面返回 `409 lease_scope_invalid`，且端口不在允许的 7000-9000 范围。旧逻辑把它归为普通 Ultra 故障并触发错误恢复。 | 已加入 native lifecycle guard、错误分类和状态传播；PHP/面板侧已发布。 | 没有新的 native 可执行文件发布，也没有真实 forward 180 数据面验收，事故不能标记为完全关闭。 |
| `process_stderr` 被误读为断线证据 | `process_stderr` 只是进程 stderr 事件；周期性 stderr、health 200 或 tunnel 连接都不能单独证明数据面断开。必须结合 PID、监听器、lease、route journal 和实际流量。 | 在调查与文档中明确控制面、进程面和数据面证据边界。 | 需要把这些证据纳入持续告警和 canary 判定。 |
| 广港线路重复下发不可用候选 | 失败候选没有足够的信誉/隔离状态，下一轮仍可再次进入选择结果。 | 增加 candidate identity、reputation、quarantine/isolate 和反馈存储；默认 feature flag 关闭。 | 需要在小流量 cohort 打开并验证候选抑制、TTL、恢复和回滚。 |
| 普通 Ultra 重启风暴/控制面超时 | 控制面超时与本地进程退出、lease 失效、真实转发失败混在一起；旧客户端恢复边界过宽。 | 已完成共享 Core/Linux grace、状态和恢复路径调整，并覆盖主要单测。 | macOS 全套测试仍有既有 hang；真实多客户端和长连接仍需观察。 |
| Go 旧 checkout 缺少 `opengfw` | `tools/ultra-client/go.mod` 依赖本地 `../../opengfw`，旧 checkout 没有有效 `go.mod`；历史 GitHub sanitized snapshot 与本地分支也曾发散。 | 使用保留真实依赖的 curated snapshot 验证并推送 `origin/master`。 | 本地 legacy `master` 仍不能作为完整 Go 发布源；后续构建必须从 curated snapshot/明确 commit 进行。 |
| 线上发布路径和大文件 | 直连 GitHub push 遇到 `github.com:443` 连接失败；APK/DMG/EXE 触发 GitHub 大文件建议警告。 | 使用本机代理重试成功；当前大文件 push 成功。 | 后续应固定代理/发布策略，并评估 artifact storage。 |
| PHP 原子发布预检失败 | 首轮预检使用了历史文件 size/hash，脚本在写入前拒绝；没有造成半发布。 | 重新读取线上文件后执行 `php7` 备份优先、原子替换和逐文件 hash 校验，最终提交成功。 | 继续保持 manifest/hash 由线上实际文件生成，避免复用过期清单。 |
| client-agent 版本/内容漂移 | 线上 agent 内容和 manifest 版本曾不同步。 | 发布 `2026.09.11.1`，线上 SHA 与下载 canary 一致。 | 仍需验证 updater 在真实客户端上检测、安装、重启和退避，不得只依赖下载成功。 |

## 3. 用户指定的三个 Markdown

| 文件 | 状态 | 已包含内容 | 尚未覆盖 |
|---|---|---|---|
| `docs/superpowers/plans/2026-09-10-ultra-probe-and-client-resilience.md` | 已完成文档更新 | 实施范围、测试结果、真实依赖阻塞、Mac hang、Python installer mismatch、发布边界 | 不代表所有剩余客户端二进制和真实数据面已经发布 |
| `docs/superpowers/specs/2026-09-10-ultra-probe-and-client-resilience-design.md` | 已完成文档更新 | contract、状态机、隔离/恢复策略、验证矩阵和当前状态 | 生产 flag enablement、ARM64/物理设备验收仍开放 |
| `docs/2026-09-11-native-outbound-180-incident.md` | 已完成文档更新 | 根因、native lifecycle guard、测试和事故证据 | 尚无 native forward 180 的真实转发 canary，因此数据面 acceptance 仍开放 |

同批完成或维护的相关设计文档：

- `docs/superpowers/plans/2026-09-10-ultra-quarantine-isolate.md`
- `docs/superpowers/specs/2026-09-10-ultra-quarantine-isolate-design.md`

## 4. 相关 handoff / release 文档盘点

| 文档 | 本轮如何使用 | 当前判定 |
|---|---|---|
| `docs/handoffs/2026-09-06-ordinary-ultra-release-handoff.md` | 复用普通 Ultra 生命周期、Linux renewal、snapshot 和 Mac 跳过项背景 | 历史约束仍有效；不能代替本轮 native 数据面验收 |
| `docs/reports/2026-09-10-linux-ultra-ha-release.md` | 作为 Linux HA、agent fallback、forward 170/180 历史基线 | 历史 Linux canary 可参考；不自动证明本轮全客户端已更新 |
| `docs/2026-09-11-ultra-ha-fast-failover.md` | 当前 forward 170 现场交接：目标机、备份、版本目录、TCP/UDP canary 和稳定 PID | forward 170 定向 canary 已记录；Agent updater、ARM64、真实负载和源/产物收敛仍未完成 |
| `docs/handoffs/2026-09-05-windows-native-tun-batch-handoff.md` | native lease freshness、批量边界和 TUN 约束 | 可作为实现背景；没有新的物理 Windows TUN 验收 |
| `docs/handoffs/2026-09-05-windows-native-tun-lease-renewal-handoff.md` | native renewal/lifecycle 边界 | guard 已延伸到当前代码；真实 native 180 仍需 canary |
| `docs/handoffs/2026-09-06-macos-1.0.15-public-release.md` 及同日 Mac handoffs | Mac 发布、relay、installer 和公开 artifact 约束 | 本轮没有重新发布 Mac 可执行文件，也没有物理 Mac 数据面验收 |
| `docs/handoffs/2026-09-03-*`、`2026-08-29-windows-proxy-client-handoff.md`、`2026-08-27-macos-client-handoff.md` | 旧版 Windows/Android/Mac 基础边界 | 仅作历史参考，未在本轮重新接受全部结论 |

## 5. 本轮实现与线上发布证据

### 源码和提交

- 旧本地实现提交：`cc726273`、`1efe4204`。
- 基于 `origin/master` 的 curated snapshot：`d037d854`。
- 已推送线上基线：`421e71e8`（`origin/master` 已核对）。
- 生产发布应以选定的 curated commit 为准，不要把本地 legacy `master` 误当成完整发布源。

### 面板和 artifact

- 真实面板：`173.208.183.93`。
- 线上 webroot：`/www/wwwroot/v3.leikwanhost.com/leikwan-v3`。
- PHP 备份：`/root/codex-backups/ultra-resilience-20260911-php7`。
- artifact 备份：`/root/codex-backups/ultra-resilience-20260911`。
- Android APK：0.2.23，SHA-256 `dc5caed055c3278c1bd54a14030201b7d37c17cb957d453273aa45a099e12bfc`，大小 88879451 bytes。
- client-agent SHA-256 `54d14675149a53155921b1cc5328ea1e5b843039babc7b2431c96284342dfa3c`，大小 333677 bytes。
- manifest agent version：`2026.09.11.1`。
- PHP lint、迁移状态、public manifest/client-agent/APK 下载 hash 均通过；`/api/health` 返回 HTTP 200、`status=ok`；`php-fpm-83-source196.service` active。

### 现场 forward 170 记录

`docs/2026-09-11-ultra-ha-fast-failover.md` 记录了 `172.21.255.189` 上 forward 170 的 TCP/UDP 定向 canary 通过、配置 hash 未变和 PID 稳定。该记录还明确：只对目标 forward 使用了 `90-ha-canary.conf` 覆盖，尚未把 override 正式纳入 Agent updater，也未覆盖所有 forward、ARM64 和真实客户负载。

## 6. 验证矩阵

| 验证项 | 结果 | 说明 |
|---|---|---|
| PHP contract tests | 24/24 通过 | 6 个 Ultra contract 加 artifact manifest |
| PHP lint | 通过 | 变更 PHP 文件逐个 lint |
| Go | curated snapshot `go test ./... -count=1` 通过 | 旧本地 checkout 因缺少 `opengfw` 不可作为完整验证源 |
| .NET Core | 70 个通过 | 重点 Core 生命周期/状态覆盖 |
| macOS host tests | 3 个通过 | 全套 suite 在既有 StopDuringForwardPreparation 测试处 hang |
| Desktop solution build | 通过 | 0 warnings / 0 errors |
| Android unit tests | Gradle `BUILD SUCCESSFUL` | 未运行物理设备/instrumentation TUN 验收 |
| Python targeted tests | Ultra 29 + 2 subtests；source-agent 23 通过 | 目标范围通过 |
| Python full client-agent | 431 通过、1 失败、19 subtests | 唯一失败是既有 installer contract 期待字面量 `$ARTIFACT_BASE`；dirty installer 使用 `$DOWNLOAD_BASE`，本轮未改该文件 |
| 线上 health/service/migrations | 通过 | pending migrations 为空，新迁移已 applied |
| 线上 public artifact canary | 通过 | manifest、agent、APK 下载内容与预期 hash 一致 |
| native forward 180 数据面 | 未完成 | 没有真实 HTTP proxy/TUN/native relay canary |

## 7. 回滚与操作边界

- PHP 和 artifact 均先备份再原子替换；回滚优先使用上述 root-only backup 和原有部署脚本，不要手工覆盖 `.env` 或服务器专属状态。
- candidate quarantine 默认 feature flag 关闭；启用前必须先做小流量 canary，确认 quarantine、TTL、恢复和重复下发抑制，再保留可逆开关。
- 本轮没有部署新的 native forward 180 可执行文件，因此不存在“回滚一个已发布的 native 180 二进制”这一事实；需要回滚时应回到上一个选定的源码/artifact release，并重新做 public canary。
- 不要把 `/api/health`、`process_stderr`、tunnel registration 或下载 hash 单独作为 native 数据面恢复证明。
- 不要在没有明确产品策略和供应商接口证据时声称系统会自动购买或自动补充新线路；当前实现是候选反馈、信誉和隔离，不是自动采购。

## 8. 后续工作（按优先级）

### P0

1. 从含真实 `opengfw` 的 curated snapshot 构建并签发 Linux amd64/arm64、Windows、macOS 需要的 Ultra/native 可执行文件；同步 manifest，做原子 artifact 发布和逐平台下载 hash canary。
2. 为 native outbound forward 180 发布对应 client package，并运行真实 HTTP proxy、TUN/native relay canary；同时回归普通 forward 170/171 和 native/ordinary 状态转换。
3. 把 forward 170 现场使用的 `90-ha-canary.conf` 迁入受支持的 Agent updater 流程，验证 update detection、安装、重启、退避和无循环。

### P1

4. 在受控 cohort 打开 `ULTRA_CANDIDATE_QUARANTINE_ENABLED`，验证不可用候选不再重复下发，记录恢复和回滚证据。
5. 完成 ARM64 实机、闲置恢复、真实应用 failover、长连接和多客户端负载测试。
6. 修复或明确 Python installer contract 的 `$ARTIFACT_BASE`/`$DOWNLOAD_BASE` 约定；定位 macOS 全 suite hang，并补跑 Android instrumentation/物理 TUN。
7. 清理本地 legacy branch 与 curated remote 的分歧，继续禁止 force-push；发布记录必须绑定实际构建 commit、artifact hash、备份路径和 canary 结果。

## 9. 交接时的事实边界

当前可以确认：**线上面板和本轮指定的 Android/client-agent/manifest artifact 已更新且可下载，控制面迁移和 contract 验证通过，forward 170 有定向现场记录。**

当前不能确认：**所有客户端已经运行新源码、native outbound forward 180 已恢复、广港所有线路已可用、candidate quarantine 已在生产启用、系统具备自动补充新线路能力。**

