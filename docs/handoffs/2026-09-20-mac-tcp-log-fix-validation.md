# Mac TCP 日志复核、修复与验证

日期：2026-09-20，时间 UTC+8。未提交、未 push；未发布公共 DMG/Windows/Linux 更新。

后续补充：已修复active fallback绕过业务隔离及返回撤销连接的两个确定性缺陷；测试Mac已升级`.5`。当前安装身份、备份和回读见`2026-09-20-mac-tcp-fallback-safety-fix.md`。下文`.4`是该阶段历史记录，不是最新部署。

## 范围与结论边界

- 用户日志：`LeiKwan-diagnostics-20260920-191715.tgz`，本地解包保留。
- 普通 Ultra TCP：client → leased provider relay → target。未接触 LeiKwan slave/CDN。
- EOF 是流结束，不等于线路故障。收到业务字节后的 EOF、正常半关闭、主动取消，不进入全局信誉。
- tgz 的 fwd185 在 line17、line447 候选上均出现零下行字节 reset；不能只指认 line17。
- 旧版 `copyInbound` 的计数在本地 Write 成功后才增加：本地拒收同样可能留下 `downstream=0`。旧日志不能确定 reset 来源。
- 受控 TCP 回归已复现部分 `headers_eof`；修复客户端误判/恢复机制不等于所有 provider 路径故障已经消失。

## 已修复

### 1. TCP 错误来源和安全局部避让

- `copyInboundObserved` 分离远端 ReadBytes、本地 WrittenBytes、ReadError、WriteError、PolicyError。
- 处理短写、部分写失败、零进度写；原 `copyInbound` 接口保留。
- 去掉允许“本地先结束 25ms 仍视为远端先结束”的判定。
- 仅远端 Read 明确 reset、严格先结束、短连接已发请求但未收到任何远端字节时，累计本 forward TCP 局部失败。
- 重复失败触发原有短时局部路径避让；不影响 UDP、其他 forward 或全局信誉。
- 不重放已经发送的业务 payload；保留 TCP half-close 排空响应。

### 2. 心跳实时状态与当前 runtime 身份

- Desktop 心跳独立携带 `ultra_status` 和当前 `runtime_attestation`，不再依赖有流量的 usage 行。
- 过滤过期/已停止状态，按最新状态选择身份；历史 usage 保留用于计费，不得覆盖当前 runtime 身份。
- 无当前身份时显式发送空 attestation，避免服务器回退到历史 usage 中旧身份。
- 服务器返回 `ultra_status_accepted`，保存有界 HA events、失败计数、UDP queue/setup drops 和流量计数。
- 实测发现首次部署会读到历史 native-usage 的 9 月 12 日身份并导致 `lease_runtime_unattested`；已修正选择逻辑。恢复使用官方 `clearRuntimeAttestation`，只作用于 client3 的当前 Mac session333，未关闭 attestation enforcement。
- 历史 native-usage 曾临时移出收集目录用于恢复，随后完整恢复；备份仍保留。

### 3. 启动失败退避与自动/手动恢复区分

- 每 forward 独立退避 30/60/120/240/300 秒。
- contract 变更或显式手动重试可重置；成功清除；授权失效检查不被退避屏蔽。
- 修复 Mac headless 每 15 秒自动恢复错误使用 `manual_apply` 的路径。
- 自动 `EnsureForwardAsync` 使用 `automatic_ensure`，不会反复清空冷却；UI 手动 Start 保留原语义。

## 受控部署和回读

Mac：

- 安装目录 `/Applications/LeiKwan Bridge.app`。
- 当前 runtime fingerprint：`2026.09.20.4.52d81766`。
- helper SHA-256：`1cc55501fb728f7cb4d8b1ec41e1da4a2c806c93ea4ee94cedd54c3904fe04a8`。
- Core.dll：`e59b27287bc8b2a1a3b5b3e7da5bb2ac7f700ee6266170a9bb053747909ab926`。
- Mac.dll：`16076ed84d7abc7b2c90feb71933249c9636db7b3b19dd527b2f710ce5bd2723`。
- 较早部署曾在20:25:48启动PID84246并通过`codesign --verify --deep --strict`；后续diagnostic helper替换又重启了app，不能把旧PID当当前进程。
- 21:20:04（13:20:04 UTC）面板 session333 已读回上述 `.4` helper 身份及各 forward 当前状态。
- Panel已保存`no_response`事件中的candidate/lease哈希、远端Read字节/错误、结束顺序与局部避让结果。此为上报链路证明，不是故障全部消失的证明。
- app 备份：`/Users/leikwan/backups/mac-tcp-fix-20260920/`、`/Users/leikwan/backups/mac-tcp-final-20260920/`。
- 后续 DLL 更新前版本另外保留在 `/Users/leikwan/tmp/mac-tcp-desktop4-20260920/`、`mac-tcp-desktop5-20260920/` 的 `.before` 文件。

Panel：

- `ClientAgentController.php`：`4591a1080e437f43cad24d4ceb8d34d8f3298117c292769ce749f5a1e89ca67c`。
- `UltraClientStatusService.php`：`fa2129d6a4ae7e43863b957c5471da43ff681ef35811070ba21dc9a39df1e845`（追加有界TCP上下文字段后的版本）。
- 两文件均先备份至 `/root/codex-backups/mac-tcp-fix-20260920*`，PHP lint 通过。
- session 恢复前快照：`/root/codex-backups/mac-tcp-session333-before-clear-1789906781.json`，0600。

## 自动测试

- .NET Core 全套：591/591 PASS。
- Mac headless 专项：13/13 PASS。
- WSL/Linux Go HA、真实 socket RST、half-close、copyInbound：`-race -count=5` PASS。
- 最新上下文字段/Windows兼容修复后的同一专项再次`-race -count=5` PASS（3.503秒，`go-final-race.log`）；PHP四项合约也再次全部PASS（`final-ultra_*_contract_test.log`）。
- Go 排除长时 `TestGameSim` 的原有回归集合：PASS（26.519 秒，新增 loopback 测试前运行）。
- Go 全套 120 秒超时停在 `TestGameSim`，不能记为全套 PASS。
- PHP status、runtime attestation、candidate feedback/reputation 合约测试通过。
- 新增真实 reset loopback 暴露 Windows Winsock10054 与 `syscall.ECONNRESET` 不匹配，随后补上平台限定识别。当前Windows `go test . -run 'TestHATCP|TestHAStatus|TestCopyInbound|TestLocalAbort' -count=10` PASS（11.764秒）。这仅是本地回归，本轮无Windows部署/真实线路验收。
- Mac原生ARM64对应socket/half-close专项已PASS，日志`mac-native-loopback-tests.log`。

## 600 秒真实 TCP 回归

- client3 临时启用既有测试 forward198(HK/60151)、199(SG/60152)，不改业务 forward。
- Mac 为修复版，Linux 为未升级对照。
- 每平台 HK/SG 同时运行，目标每路 20Mbps TCP，8 workers。
- 每次响应校验 request ID 和完整 336,000 字节内容；记录客户端结果及目标 request/sent。
- 独立临时 HTTP target 位于 HK/SG 自有主机 TCP60051，不修改/重启 UoT sidecar 服务。
- 自动 target TTL1200秒，panel cleanup watchdog16分钟；结束后另做主动清理和回读。
- 本轮是 TCP-only，不等于此前每路20Mbps UDP + TCP control 的混合负载验收。

窗口：2026-09-20 20:27:28–20:37:28（UTC+8）。

| 平台/方向 | 成功请求 | 失败请求 | 实收Mbps |
|---|---:|---:|---:|
| Mac HK | 4425 | 37 | 19.82400 |
| Mac SG | 4443 | 18 | 19.90464 |
| Linux HK | 4446 | 11 | 19.91808 |
| Linux SG | 4129 | 334 | 18.49792 |

**结论：FAIL，不能认定业务故障已修复。** 400/400失败都是`headers_eof`，目标没有对应request/sent事件；成功请求与目标request/sent完全对齐，目标没有匹配send_error。只能定位到目标HTTP handler之前，不能据此区分provider提前关闭、请求未完整到达或本地取消，更不能归罪某一条线路或sidecar。

上述600秒发生在最新`.4`诊断helper部署之前；其16条状态历史被正常EOF冲掉，无法回溯每次失败的结束顺序。`.4`已加故障历史保留与上下文字段，但尚无同条件600秒重测。

后续180秒observe轮在准备阶段出现本地端口connection refused，不作为有效数据面验收，也不与上表比较。

清理回读：临时forward198/199均`enabled=0`；HK两个observe target unit inactive/dead；SG observe target unit failed/failed但无TCP60051监听；HK/SG sidecar仍active。测试unit失败状态是保留的历史状态，不作为sidecar故障。未重启sidecar。

## 全方向UoT追加验收

用户追加要求已纳入：全部11方向，Mac/Linux同时测试，每方向每平台20Mbps、600秒；分别记录raw UDP基线、tunnel UDP、negotiated reliable datagram、TCP控制与受控切换恢复，不能用TCP-only替代UoT。

完整清单和前置条件见`2026-09-20-uot-all-directions-test-matrix.md`。现有client3/service30缺4方向授权，且多方向材料过期/缺少可复用binding；当前sidecar未实现热加载。11方向都未完成端到端验收，不删除阻塞行、不改共享套餐或共享token、不以直连sidecar绕过Ultra relay。

源端共享session实验及证据缺口见`2026-09-20-ultra-source-material-session-root-cause-review.md`。现有时间线不能证明共享session导致全部EOF；source实际管理入口仍未知，未把测试fixture中的地址当管理地址。

## 本地证据

`tmp/mac-tcp-path-20260920/`：

- `final-deploy-receipt.json`、`desktop4-deploy-receipt.json`、`desktop5-deploy-receipt.json`
- `panel-post-release-readback.json`
- `final-tcp-plan.json`、`final-tcp-monitor.jsonl`
- `final-tcp-*-summary.json`、`final-tcp-*-events.jsonl`
- `dotnet-full-core.log`、`go-regression-excluding-gamesim.log`、`go-full-test.log`

凭据、测试 token、session 私有快照仅留本地或远端受保护备份，不纳入报告/提交。
