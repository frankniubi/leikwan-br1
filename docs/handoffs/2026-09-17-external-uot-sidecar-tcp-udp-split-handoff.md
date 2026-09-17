# External UoT sidecar · 客户端 TCP/UDP 分流 · 面板支持 — 会话交接（2026-09-17）

> 交接对象：接手「UoT sidecar 新装 + 面板路由 + 客户端分流」这条工作流的下一个 agent / 操作者。
> 本文只写**已核实**的事实，每条都带 hash / 路径 / 命令；推测一律标注为「未验证」。
> 不含任何凭据。源会话：`C:\Users\frankniubi\.paseo\uploads\upload_7a38a6a0-4484-4693-ade8-7b6e6cbe4a05\dsh-session-session-34688d5b-2b0d-4f3d-8731-97db51e16e9c_2.zip`（deepseek 会话归档，19.3 MB）。

## 0. 30 秒速读

- 目标（用户原话意图）：**新安装的 sidecar 主机不再出现 `UDP candidate failed`**；客户端 **TCP 走原有直接线路、UDP 走 sidecar UoT**；**admin 面板支持新 sidecar 安装**，且 **forward combination 可按路由走法指定** 这类承载。
- 结果：三件事都**已成立并逐条核实**（见 §4）。本轮另补掉一个遗留缺口（安装脚本失败语义），已上线（`b590846d`）。
- 当前**唯一**尚未在真实环境跑过的是：**全新 scratch 主机从线上制品库完整新装**（面板命令链已接通，未端到端走一遍）。
- 代码在 `fix/ultra-probe-single-reader` @ `b590846d`，比本地 `master` 领先 30 个提交，**未推送** `origin`。

## 1. Worktree / 分支 / 环境

| 项 | 值 |
| --- | --- |
| 主 worktree | `C:\Users\frankniubi\Downloads\leikwan-br1`（Windows PowerShell） |
| 分支 | `fix/ultra-probe-single-reader` |
| HEAD | `b590846d` (2026-09-17 12:32 +0800) |
| 本地 master | `55c0cbcb` (2026-09-16 04:45 +0800)，本分支领先 30 个提交、落后 0 |
| 上游 | `origin` = `https://github.com/frankniubi/leikwan-br1.git`；本分支无 tracking，未 push |
| 面板主机 | `root@173.208.183.93`（plink/pscp，凭据沿用本地既有配置，勿写入任何文件） |
| 面板站点根 | `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`（`master/` 下为面板代码） |
| 面板 DB | `v3_leikwanhost_c`（凭据在面板 `master/.env`） |
| 本地 e2e 机 | WSL（通过 `wsl.exe` 调用），用于本地 runtime + Go sidecar 联调 |

注意：仓库里有 40+ 个 side worktree（`.worktrees/`、`C:/tmp/...`），**本工作流只用主 worktree**。

## 2. 你的目标（用户意图，按原话拆解）

1. **「用作安装」**——把 external UoT sidecar 变成标准 sidecar 安装的一部分，并提供可直接用的安装方式。
2. **「新安装的就不会出现 `UDP candidate failed` 问题了」**——干净主机装完即具备 UDP 承载能力，不再因为缺 sidecar 而失败。
3. **「所有 client TCP 流量走直接线路，UDP 流量走 sidecar UoT」**——分流要由组合/平台配置控制，不靠人工。
4. **「admin 面板是否已经支持新 sidecar 安装，支持 forward combination 指定此类路由走法」**——面板要有安装命令 + 组合级开关。
5. 交付形态：证据可复现、改动可回滚、**先备份再改**、明确区分「已验证 / 未验证」。

## 3. 这项工作的整体结构（谁负责哪一半）

```text
客户端 ultra runtime ── tcp  ──►  原有直接线路（serveTCP / slave relay）      ← 不动
                    └─ udp  ──►  external UoT carrier ──►  sidecar :7443 ──► 目标 UDP
                                     ▲
                                     └── 由 forward combination 的 7 个 external_uot_* 字段开关
面板：AdminController / BaseController 给运维生成安装命令；ExternalUotConfigController 给主机下发配置文档；
      ArtifactController 发布安装脚本与二进制；ProvisioningService 保存/校验组合字段。
主机：install-bond-sidecar.sh → install-external-uot.sh → leikwan-external-uot.service（Go 或 Python 实现）
```

## 4. 已完成（DONE，全部附证据）

### 4.1 代码与制品（仓库侧）

| 提交 | 内容 | 规模 |
| --- | --- | --- |
| `82c01071` | feat(ultra): add opt-in external UoT sidecar carrier | 15 files, +2112 |
| `65b15395` | feat(ultra): expose external UoT sidecar per forward combination | 10 files, +314 |
| `ecc2f82a` | fix(ultra): invalidate client config when UoT contract changes | 1 file, +18 |
| `26047618` | fix(ultra): carry the external UoT sidecar contract through the client surface | 6 files, +400 |
| `deb9ea5e` | feat(ultra): accept external UoT forward 170 with allow-any targets | 3 files, +172 |
| `df6f43c4` | feat(sidecar): ship the external UoT sidecar inside the standard install | 13 files, +1423 |
| `a4fb5f5f` | chore(uot-sidecar): track the windows/macos sidecar builds and the target manifest | 4 files, +53 |
| `b7eac420` | feat(uot-sidecar): add the operator-owned UoT sidecar source (Go) | 9 files, +2409 |
| `99b9d32f` | feat(desktop): carry the external UoT grant into the Windows/macOS runtime bootstrap | 13 files, +869 |
| `8c62f672` | chore(panel): record the published UoT installer and sidecar digests in the local cache | 1 file |
| `ce9512c3` | feat(uot): make fresh sidecar installs wire the host's external UoT forwards | 12 files, +1337 |
| `b590846d` | fix(uot): keep a failed sidecar step from aborting a host that does not require it | 4 files, +123 |

新增报告（全部在 `docs/reports/2026-09-17-*`）：`external-uot-sidecar-go-parity.md`、`external-uot-live-store-publish.md`、
`external-uot-panel-config-verification.md`、`external-uot-forward-170-live-acceptance.md`、`external-uot-sidecar-go-fleet-canary.md`、
`android-external-uot-gap.md`、`tcp-udp-split-and-panel-routing-verification.md`（本轮的三个问题答案）。

### 4.2 线上制品库（https://v3.leikwanhost.com，均已实测可下载）

| manifest key | version | sha256 | size | URL |
| --- | --- | --- | --- | --- |
| `bond_sidecar_installer` | 2026.09.17.1 | `c9dd7f6a...26715d` | 12517 | `/artifacts/install-bond-sidecar.sh` |
| `external_uot_installer` | 2026.09.17.1 | `0506d838...a4d665` | 10158 | `/artifacts/install-external-uot.sh` |
| `uot_sidecar_linux_amd64` | 2026.09.17.1.33d5d5a3 | `18bd060a...ac60ef` | 3014818 | `/artifacts/uot-sidecar/uot-sidecar_linux_amd64` |
| `uot_sidecar_linux_arm64` | 2026.09.17.1.33d5d5a3 | `9799779f...ea6320` | 2949282 | `/artifacts/uot-sidecar/uot-sidecar_linux_arm64` |

Python 参考实现仍在库：`/artifacts/leikwan-external-uot.py`（`810d5d2e...798d44`）。
`docs/reports/2026-09-17-external-uot-live-store-publish.md` 第 16/20 行那张表已两度过期（`14fcc9e2` → `c7389c5a` → `c9dd7f6a`），下次触碰该文件时顺手更新。

### 4.3 面板部署态（本轮实测 sha256，方向：panel ← repo）

| 文件 | 面板 sha256 | 与 repo 是否一致 |
| --- | --- | --- |
| `master/src/Controllers/BaseController.php` (80369 B) | `a39aa49e...1897ad3` | **不一致**，见 §8 漂移 |
| `master/src/Controllers/AdminController.php` | `1b5b3ba9...a9d5c7` | 一致 |
| `master/src/Controllers/ExternalUotConfigController.php` | `fa021a2f...347d57` | 一致 |
| `master/src/Controllers/ClientAgentController.php` (78260 B) | `d503abbf...c71dbf` | **不一致**，见 §8 漂移 |
| `master/src/Support/ProvisioningService.php` | `5cd84808...177f888` | 一致 |
| `master/src/Support/LeigodTokenBroker.php` | `9dc5069a...79a45bf` | 一致 |
| `install/install-external-uot.sh` | `0506d838...a4d665` | 一致 |
| `install/install-bond-sidecar.sh` | `c9dd7f6a...26715d` | 一致（本轮部署 + 服务端回读校验） |

路由（`Bootstrap.php` + `ArtifactController.php`，线上 GET 全部 200）：`/artifacts/install-external-uot.sh`、
`/artifacts/uot-sidecar/uot-sidecar_linux_{amd64,arm64}`、`/external-uot-config`（别名 `/artifacts/external-uot-config`）、
`/artifacts/versions.json`。

### 4.4 线上运行态（2026-09-17 实测）

- UoT 已开启的组合：**51、52、53、55、57**（全部 `backend_mode=ultra`、`client_mode=single`、`transport_mode=tcp+udp`、无 bond sidecar 点）。
- 其下启用中的 forward：**158、180、185、190**（组合 51）、**169**（52）、**170**（57）、**187、194**（55）= 8 个。
- forward 总量 75、启用 16，其中 **8 个在 UoT 组合上**；其余 11 个启用 forward 走原生 UDP（预期默认）。
- `sidecar_points` 现有 5 条：`5 tokyo-nrt1 / 185.220.238.143`、`7 frankfurt-fra1 / 89.144.30.80`、`8 seoul-icn1 / 85.155.177.115`、`9 hongkong-hkg1 / 23.141.244.219`、`10 yt-hk-a / hk-bgp-b.zhuji.host`。
- forward 最大 id 194；**id 4 与 66 均不存在**。

### 4.5 验收证据

1. **forward 170 端到端**（`docs/reports/2026-09-17-external-uot-forward-170-live-acceptance.md`）：
   `UDP client → Japan runtime → Ultra TCP carrier → Tokyo sidecar :7443 → fixed UDP target`，
   `VERDICT udp_roundtrip=PASS sustained=20/20`，且明确「forward 与全部 TCP 流量保持原生路径」。
2. **Go sidecar 车队 canary**（`...-go-fleet-canary.md`）：真实 sidecar 主机（`purely-new`）把 `leikwan-external-uot.service` 的 `ExecStart` 切到 Go 二进制
   （`18bd060a...ac60ef`，版本 `2026.09.17.1.33d5d5a3`），协议一致性 5 相位 **45/45**、切换窗口客户端连续性 **132/132**、
   每次重启 3–5 s 内载波回到 21–22，`NRestarts=0`，配置哈希 `f2afe1f0...c873d4` 未变；
   回滚已验证：`python tmp/fleet-canary-20260917/scripts/orchestrator.py swap --mode python`，备份 `/root/backups/external-uot-python-20260917-0345/`。
3. **面板安装命令内容**：`BaseController::bondSidecarInstallCommand` 在 point 带 `sidecar_host` 时追加
   `--external-uot-config <panel>/external-uot-config?host=<host> --require-external-uot`（线上第 259/263 行实测）。
4. **配置端点语义**：`?host=` / `?ip=` / 调用方地址三级解析；**不信任** `X-Forwarded-For` / `X-Real-IP`；不对应 UoT forward 的主机拿到合法空文档（注入 XFF 伪造地址实测无效）。

### 4.6 测试

- 本地/面板 PHP 7 项专项全 PASS：`artifact_versions_manifest_consistency_test`、`installer_artifact_hash_verification_test`、
  `sidecar_agent_update_artifact_regression_test`、`external_uot_config_endpoint_test`、`external_uot_install_wiring_test`（18/18）、
  `client_agent_update_artifact_regression_test`、`ultra_client_artifact_distribution_test`。
  快捷入口：`powershell -NoProfile -ExecutionPolicy Bypass -File .tmp\uot-config-deploy\targeted-tests.ps1`。
- Go sidecar 单测 + Python/Go 差分：`tools/uot-sidecar/*_test.go`、`tests/test_external_uot_sidecar_go.py`。
- 桌面端：`LeikwanBridge.Core.Tests` 下 `ExternalUotGrantTests`、`ForwardContractSignatureTests`、`MasterApiClientDesktopLoginTests` 等（`99b9d32f`）。
- **已知噪声**：面板全量 PHP 套件 402 项中 16 项 fail（android 契约类 + `public_port_recalculation_test`），为既有失败，与本工作流无关。

## 5. 本轮特别补掉的一件事（补丁 + 上线 + 回滚）

问题：`install/install-bond-sidecar.sh` 在 `set -euo pipefail` 下直接执行 `install-external-uot.sh`，
**UoT 子步骤任何失败都会把整台主机的 bond 安装一起中断**（即使该主机并不需要 UoT）。

处置（提交 `b590846d`）：

- 改为 `if ! "$BIN_DIR/install-external-uot.sh" ...; then ...`：失败即 **warn + continue**；
  带 `--require-external-uot`（**面板命令始终带**）时依旧 `exit 1` 硬失败，保证面板驱动的 UoT 主机不会静默缺载体。
- `master/tests/external_uot_install_wiring_test.php` 增加 3 条断言 pin 住该语义（由 15 → **18 checks**）。
- `artifacts/versions.json` 的 `bond_sidecar_installer` 重录：`c9dd7f6a...26715d`、12517 B，**version 字段保持 `2026.09.17.1` 不 bump**（避免车队被动重装）。
- 上线校验：面板文件 sha256 = repo = `/artifacts/install-bond-sidecar.sh` **实际服务字节**（三者一致），面板 `bash -n` 通过。
- 备份：`/root/codex-backups/panel-uot-installer-guard-20260917-043114/`（含 `install-bond-sidecar.sh.before`、`versions.json.before`）。

## 6. 还没做完 / 未验证（LEFT）

按优先级：

1. **P0 — 全新主机端到端新装未跑**：`curl /artifacts/install-bond-sidecar.sh` → `install-external-uot.sh` → unit enabled → 真实 UDP 会话。
   面板命令链已接通、安装脚本已就绪，但**没有一台干净主机真的从零装过**。这是「新装不再 UDP candidate failed」的最后一段证据。
2. **P1 — Go sidecar 只在一台主机上跑**（`purely-new`，forward 170 链路）。其余 UoT 主机仍是 Python 实现；
   车队报告提到的 5 台 sidecar 主机（`185.220.238.143`、`70.36.96.183`、`77.93.90.45`、`103.246.219.39`、`208.122.17.50`）里，
   **4 台没有 `sidecar_points` 行**，走裸 URL 解析路径 → 它们的首次重装/agent 升级值得盯。
3. **P1 — Android 客户端 `external_uot` 引导未验证**（`docs/reports/2026-09-17-android-external-uot-gap.md`），其 Step 1（重建 + 实机日志确认）属另一条工作流，未动。
4. **P2 — `SIDECAR_DIRECT_TCP_*` env 陈旧**：线上 `.env` 写 `SIDECAR_DIRECT_TCP_FORWARD_IDS=66`、`SIDECAR_DIRECT_TCP_POINT_IDS=4`，但这两个 id 都不存在（最大 forward id 194；point 4 不在 sidecar_points）→ 该闸门当前**空转**。若本意是 hongkong-hkg1（现 id 9），需要改 env；**未擅动**。
5. **P2 — 合成压测 `up-20M` 偶发空结果**（Go 2 次 / Python 1 次，31–40 s；sidecar 侧 `udp_in=0` 说明丢点在客户端 runtime/网桥侧）。既有问题，两套实现同签名，未定位。
6. **P2 — 面板 ClientAgentController 未带 `forward_revision` 投影**：只改旋钮（不改 enable/token/sidecar）的组合编辑要等 2–3 分钟续租窗口才对客户端生效。这是**已记录的行为差异**，不是缺陷；若要即时生效需要把 repo 版部署上去（但会引入 §8 的漂移，见下）。

## 7. 建议下一步（NEXT，可直接照做）

### P0：干净主机新装验收（建议 1 个 agent / 半天）

```bash
# 在面板给目标 point 配好 sidecar_host 后，页面会给出这条命令（形态如下）
curl -fsSL https://v3.leikwanhost.com/artifacts/install-bond-sidecar.sh -o /tmp/install-bond-sidecar.sh
bash /tmp/install-bond-sidecar.sh --master https://v3.leikwanhost.com --agent-token <TOKEN> \
  --external-uot-config "https://v3.leikwanhost.com/external-uot-config?host=<SIDECAR_HOST>" --require-external-uot
systemctl status leikwan-external-uot.service --no-pager   # 期望 active，NRestarts=0
curl -fsS https://v3.leikwanhost.com/external-uot-config?host=<SIDECAR_HOST> | python3 -m json.tool
```

验收判据：unit active、config 文档含该主机的 enabled forward、真实 UDP 往返成功（复用 `.tmp/uot-e2e/live-accept.sh` 的探测方式）、
`/root/backups/leikwan-external-uot-*` 备份生成、回滚可执行。

### P1：Go sidecar 车队换装（建议 1 个 agent）

- 复用 `tmp/fleet-canary-20260917/scripts/orchestrator.py`（已验证的 swap / rollback 流程），逐台 `--mode go` → 验证 → 保留 Python 兜底。
- 先给 4 台无 `sidecar_points` 行的主机确认裸 URL 解析路径能拿到各自文档（这步只需只读 curl）。

### P1：客户端侧（Windows/macOS/Android，建议 1 个 agent）

- 桌面：`99b9d32f` 的 `ExternalUotGrant` 已进 Core，需在真实 Windows/macOS 安装包上验证 grant 透传到 runtime（尚未做端到端）。
- Android：按 `android-external-uot-gap.md` 的 Step 1 起步。

### P2：清理与收口（顺手做）

- 更新 `docs/reports/2026-09-17-external-uot-live-store-publish.md` 第 16/20 行的 installer 表格（现为两代前的值）。
- 决定 `SIDECAR_DIRECT_TCP_*` 是改 id 还是删掉。
- 给 `up-20M` 空结果留一次定点抓包（客户端 runtime 的 UDP mapping 状态）。
- 全量 PHP 套件里的 16 项既有失败，要么修要么在 CI 里标注 baseline。

### 多 agent 拆分建议

三块互不阻塞：**(A)** 面板 + 干净主机新装（P0）、**(B)** 客户端（桌面/Android）、**(C)** 车队换装 + 回滚演练。
每块都需要读不同文件集，适合并行；共享的事实边界就是本文 §4/§8。

## 8. 漂移与陷阱（接手前必读）

### 8.1 面板文件 ≠ 仓库文件（两处，**不要用仓库整文件覆盖面板**）

**`BaseController.php`**：repo `517f1d26...`（86489 B）vs 面板 `a39aa49e...`（80369 B）。逐行 diff（`git diff --no-index`）结果：

- 只在 repo、**未上线**：`linuxArm64ClientInstallCommand()` 及两处 ARM64 安装面板渲染；
  bridge-home 双语首页整段（`zh-Hant` lang、无语言切换、SEO/OG/JSON-LD head、`brand-mark.png`、`bridge-home.css?v=20260917b`、双语「会话/退出」文案）。
- 只在面板、repo 没有：`space-grotesk-var.ttf` 预载 + `bridge-home.css?v=20260916c`（面板首页还停在 Space Grotesk 时代）、首页语言切换器、`{$this->i18nBootstrapHtml()}` 内联写法。

**`ClientAgentController.php`**：repo `853b922b...`（82140 B）vs 面板 `d503abbf...`（78260 B）。

- 只在 repo、**未上线**：`OpenWrtPairingCodeService` + `pairOpenWrtDevice` + 两个 helper + `AccessPolicyService::isRouterPlatform` 平台判定；
  `external_uot_revision` 投影（第 ~806 行）。
- 只在面板、repo 没有：`ultra_lease_forward_refused` 的 `error_log` 与响应里的 `failures` 诊断映射（面板侧热修）。

结论：部署任一侧都要**逐段 patch**，不能整文件覆盖；上面两处「面板独有」的改动是真实运维资产。
（对比用副本已落到 `.tmp/drift/panel-*.php` 与 `.tmp/drift/{bc,cac}.diff`，可复用。）

### 8.2 制品库护栏

- `artifacts/versions.json` 的 `version` 字段**不要随手 bump**：客户端/主机 agent 以它判断是否需要重装/升级，改了会触发车队批量动作。
  （本轮两次 republish 都刻意保持 `2026.09.17.1`。）
- 每次改安装脚本必须同步 `sha256` / `size_bytes` 并跑 `artifact_versions_manifest_consistency_test` + `installer_artifact_hash_verification_test`，否则面板会拒绝下发。
- `/external-uot-config` **不信任** `X-Forwarded-For`；调试时不要用伪造 XFF 的方式定位问题。

### 8.3 工作区卫生

- 本工作流**未提交**、且**不要动**的脏文件：`LeikwanBridge.Desktop/src/LeikwanBridge.Core/packages.lock.json`、`storage/cache/artifact-digests.json`，以及 `tmp/` 下大量截图/脚本（属其它工作流）。
- 提交时逐文件 `git add`，不要 `git add -A`。
- `master/` 目录与 `master` 分支同名：`git log master` 会歧义，用 `refs/heads/master`。

## 9. 回滚手册

| 层 | 回滚动作 | 证据 / 备份 |
| --- | --- | --- |
| 面板代码（本轮那次部署） | `cp -a /root/codex-backups/panel-uot-install-wiring-20260916-203802/*.before<->原名` | 该目录含 6 个被替换文件的 `.before` |
| 面板安装脚本 + manifest | `/root/codex-backups/panel-uot-installer-guard-20260917-043114/{install-bond-sidecar.sh.before,versions.json.before}` | 本轮 |
| 主机 sidecar 单元 | `python tmp/fleet-canary-20260917/scripts/orchestrator.py swap --mode python`，或手工从 `/root/backups/external-uot-python-20260917-0345/` 还原 `ExecStart` + `daemon-reload` + `restart` | 已演练 |
| 主机 external UoT 安装 | `install-external-uot.sh --uninstall`；或还原 `/root/backups/leikwan-external-uot-<ts>/`（脚本 receipt 会打印路径） | 脚本内备份 |
| 仓库 | `git revert <commit>`（`b590846d` / `ce9512c3` 各自独立可回） | — |
| forward 组合 | 面板组合编辑器把 UoT 关掉即可；客户端下个续租窗口生效 | — |

## 10. 证据 / 复现索引

| 用途 | 位置 |
| --- | --- |
| 三个问题的完整答案 + 证据 | `docs/reports/2026-09-17-tcp-udp-split-and-panel-routing-verification.md` |
| 制品发布 + 新装接线 | `docs/reports/2026-09-17-external-uot-live-store-publish.md`、`...-panel-config-verification.md` |
| forward 170 端到端 | `docs/reports/2026-09-17-external-uot-forward-170-live-acceptance.md` |
| 车队 canary（Go/Python 切换） | `docs/reports/2026-09-17-external-uot-sidecar-go-fleet-canary.md` |
| Python↔Go 差分 | `docs/reports/2026-09-17-external-uot-sidecar-go-parity.md` |
| Android 缺口 | `docs/reports/2026-09-17-android-external-uot-gap.md` |
| 本地 e2e 脚本与日志 | `.tmp/uot-e2e/{live-accept.sh,install-e2e.sh,runtime_uot_e2e.py,status.json,*.log}` |
| 面板部署脚本 | `.tmp/uot-config-deploy/*.sh|ps1`（含 `targeted-tests.ps1`、`deploy-installer-guard.sh`、`panel-hashes.sh`） |
| 制品晋级脚本 | `.tmp/uot-publish/{promote-uot-sidecar-panel.sh,panel-precheck.*,verify-committed.sh}` |
| 车队 canary 工件 | `tmp/fleet-canary-20260917/{scripts,live,01-recon,local-test}` |
| 面板文件对比副本 | `.tmp/drift/{panel-BaseController.php,panel-ClientAgentController.php,bc.diff,cac.diff}` |
| 之前的 UoT 侦察/选路记录 | `tmp/{external-uot-pilot-report-20260916.md,host_recon*.py,host_recon_spec*.json,jp_uot_recon.py}` |

常用命令（本地）：

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1
powershell -NoProfile -ExecutionPolicy Bypass -File .tmp\uot-config-deploy\targeted-tests.ps1   # 7 项 UoT 专项
php master\tests\external_uot_install_wiring_test.php                                        # 18 checks
git --no-pager log --oneline refs/heads/master..HEAD                                         # 本分支 30 个提交
```

## 11. 交接边界声明（proven vs unproven）

**已证**：脚本/二进制/端点的字节与哈希一致；面板能看到并渲染安装命令；配置端点解析与 XFF 处理；
forward 170 的真实 UDP 往返；Go sidecar 在真实主机的协议一致性与回滚；组合字段的确被保存/下发（代码 + 线上文件哈希 + DB 事实三者一致）；
本轮安装脚本 guard 已上线并被测试 pin 住。

**未证**：全新主机从零安装的完整链路；Go sidecar 在其余主机的表现；桌面/Android 客户端的 grant 透传；
旋钮级改动的即时生效；`up-20M` 空结果成因；`SIDECAR_DIRECT_TCP_*` 的真实意图。

**没碰**：其它工作流（homepage 设计、sub2api、mihomo/Android 干线、`docs/handoffs/` 里 40+ 个 side worktree）。
