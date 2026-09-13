# Ultra 客户端：防逆向 / 防转卖 设计说明

Date: 2026-09-12
范围：`tools/ultra-client`（Go runtime）、`scripts/build-ultra-*.ps1`、面板 `UltraClientStatusService` / `UltraLeaseService`

## 结论先说（边界）

**没有任何打包手段能阻止一个有心人拆开客户端。** Go 二进制即使经过 garble 混淆，仍然可以被逆向、
被改包、被剥壳——这是行业共识，不是本项目的疏漏。所以本项目把防护分成两层：

| 层 | 目标 | 手段 | 能做到 | 做不到 |
|---|---|---|---|---|
| 二进制层 | **抬高拆包成本、让改包可被发现** | garble `-literals -tiny`、`-s -w`、`-buildid=`、`-trimpath`、可复现构建 + 指纹注入 + 自哈希 + 调试/注入检测 | 逆工程需要大量人力；改过的 runtime 会在面板上暴露 | 阻止静态分析；阻止有账号的人拿来自己用 |
| 后端层（真正的护城河） | **让拆出来的东西没有转卖价值** | 材料短 TTL + 每次 `startacc` 轮换 relay、租约绑定 `device_hash`、一次性 nonce、策略 generation、按设备/账号撤销、并发与速率上限 | 拆包者拿到的凭据几分钟后失效；转卖"服务"必须持续经过面板鉴权 | 阻止账号共享（只能限制 + 检测 + 吊销） |

一句话：**防的不是"看代码"，防的是"拿你的后端去卖"。** 后者靠面板的鉴权与吊销，前者只是拖延。

## 1. 已有的二进制加固（2026-09-11 起）

`scripts/build-ultra-{linux,macos,android}.ps1` 已经：

- 用 **garble v0.17.0 `-literals -tiny`** 构建：标识符重命名 + 字符串/字面量加密 + `-tiny` 去掉调试元数据；
- `-ldflags "-s -w -buildid="`：去符号表、去 DWARF、去 build id；
- `-trimpath -buildvcs=false`：不留本机路径与 git 信息；
- **可复现**：同一 (version, 源码哈希) 连编两次比对 SHA256，不一致就报错 —— 这条同时是"面板能拿哈希做比对"的前提；
- seed 由 `version + 全部源文件哈希` 派生，源码一变混淆布局就变。

`artifacts/versions.json` 里每条 runtime 都记录了 `obfuscator` 与 `hardening` 数组，可被面板与审计核对。

## 2. 本次新增

### 2.1 版本指纹注入补齐（三个平台此前是 "development"）

- `build-ultra-linux.ps1` / `build-ultra-android.ps1`：补上 `-X main.runtimeBuildVersion=$Version`（macOS 早有）；
- 新增 `scripts/build-ultra-windows.ps1`：此前 Windows runtime 没有对应脚本（制品是手工构建的），现在与 linux 同规格：
  garble + 可复现校验 + 指纹注入 + 输出 sha256/size/obfuscator 的 JSON。

### 2.2 运行时自证（`tools/ultra-client/attest.go` + 平台文件）

runtime 在 status JSON 里额外发布：

```json
"runtime_attestation": {
  "fingerprint": "2026.09.12.1",
  "self_sha256": "<运行中可执行文件的 sha256>",
  "debugger": false,
  "instrumented": false
}
```

- `fingerprint`：链接期注入，本地构建为 `development`；
- `self_sha256`：`os.Executable()`（解析符号链接）后整文件哈希，缓存一次；
- `debugger`：Linux 读 `/proc/self/status:TracerPid`；Windows `IsDebuggerPresent` + `CheckRemoteDebuggerPresent`；
  **macOS 刻意不猜**（`P_TRACED` 在 kinfo_proc 里的偏移是私有的，猜错会误伤正常用户）；
- `instrumented`：`LD_PRELOAD`/`LD_AUDIT`（Linux）、`DYLD_INSERT_LIBRARIES`/`DYLD_FORCE_FLAT_NAMESPACE`（macOS）。

设计原则：**只上报，不自杀、不拒绝流量**。误判一个付费用户比放过多一个拆包者贵得多；
判定权在服务端（面板拿 fingerprint + self_sha256 与发布清单比对）。

### 2.3 面板侧（见 `UltraClientStatusService` / `UltraLeaseService` 的改动）

- 接收并保存 attestation，派生 `attestation_state = known | unknown | mismatch`；
- `ULTRA_RUNTIME_FINGERPRINTS` 白名单（空 = 只报告不拦截）；
- 限额开关（默认 0 = 不限制，保持现状）：`ULTRA_MAX_DEVICES_PER_CLIENT`、
  `ULTRA_MAX_LIVE_LEASES_PER_DEVICE`、`ULTRA_LEASE_ISSUE_RATE_PER_MINUTE`，超限返回独立错误码并留日志。

## 3. 为什么"转卖"这条路会被后端掐死

1. **材料是短命的**：source 侧 `material_lifetime_seconds=1800`，面板侧 `leaf` 材料带 `expires_at`，
   且每次 `startacc` 轮换 relay —— 拆包者拿到的 IP:port 与密钥有保质期。
2. **租约绑定设备**：`issueLease` 校验 `hash(deviceId)`、一次性 nonce、策略 generation；
   换设备/重放 nonce 直接失败。
3. **可撤销**：`revokeDevice` / `revokeGeneration` 能按设备或按代次立即失效。
4. **限额**：新增的三个上限把"一个账号养一堆机器对外卖"的成本抬高到可被发现。
5. **改包可见**：改过的 runtime 上报的 `self_sha256` 与发布清单不符 → `mismatch` → 管理员可封。

## 4. 明确不做（以及为什么）

- **服务端按源站测得的墙做派发/预测**：171 上测到的 1440/1472 墙是"171 → relay"这段路的属性，
  客户端的路径（家宽 ISP、跨国链路）完全不同。**墙必须由客户端按关联自测**，这正是
  `tools/ultra-client` 的自适应载荷上限做的事。源站扫描结果只用来标记"这条线在源站视角下不可用"。
- **ptrace 自杀 / 反调试直接退出**：会误伤 WSL、容器、企业 EDR、以及我们自己的排障；改为上报。
- **内核反作弊级别的对抗**：成本远超收益，且会触犯用户设备上的安全软件。

## 5. 尚未做（诚实清单）

0. **现状（2026-09-12 已上线）**：指纹注入已覆盖 linux/windows/android/macOS 四个构建脚本，attestation 由 runtime 上报 → 心跳/agent → 面板落库，管理页有 Attestation / Refusals 两个标签；三个限额开关默认关闭。发布细节见 `docs/reports/2026-09-12-ultra-runtime-2026.09.12.1-release.md`。

1. attestation 目前是**只报告**；要真正拦住改包客户端，需在面板把 `attestation_state=mismatch`
   接到租约签发（灰度：先只对 ultra 新会话生效）。
2. 面板白名单现在是 env 列表；更稳的做法是直接读 `artifacts/versions.json` 的 `sha256`，
   发布即生效（避免两处维护）。
3. macOS 的调试器检测只有 DYLD 注入这一条弱信号（原因见 2.2）。
4. 二进制层的进一步加固（控制流平坦化、反模拟）属于另一个量级的投入，暂不做。

---

## 6. 第二轮：把"只报告"变成"能拦"（2026-09-12 晚）

### 6.1 先修一个会让整个功能失效的 bug

构建脚本注入的指纹是 `2026.09.12.1.4b106b02`（版本.短 sha），但**三处校验都只接受纯 hex**
（面板 `RUNTIME_FINGERPRINT_PATTERN`、agent `re.fullmatch(r"[a-f0-9]{8,64}")`、C# `IsLowerHex`）。
结果是：真实指纹会被当作"格式非法"丢掉，attestation 永远停在 `unknown`——功能等于没上线。
三处已统一为：接受 `<version>.<shortsha>`（4~5 段，最后一段 7~40 位 hex）或旧的纯 hex build id。

### 6.2 真正能拦的策略（不是"白名单一刀切"）

`ULTRA_ATTESTATION_ENFORCEMENT=enforce` 时，只有**同时满足**这三条才拒绝租约：

1. 设备上报了指纹，且该指纹**确实是面板发布过的**（从 `artifacts/versions.json` 的
   `attestation.fingerprint` / `attestation.self_sha256` 读取，发布即生效，不在配置里重复维护）；
2. 上报的 `self_sha256` 与该指纹已发布的哈希**都不匹配**（= 用着我们的版本号、装着别人的二进制 = 改包）；
3. 设备平台命中 `ULTRA_ATTESTATION_ENFORCE_PLATFORMS`（默认 `linux`）。

不满足就放行。**旧客户端 / 未发布版本 / 干脆不报 attestation 的，一律不会因此被拒**，所以打开它
不会误伤任何存量用户；能拦住的是"拿我们的 released 指纹配改过的二进制"。拒绝码
`lease_runtime_unattested`，写入 `ultra_lease_refusals` 并记日志。

macOS 暂不纳入：bundle 里是**重签名后**的 runtime，哈希天然与发布制品不同，纳入就会误杀。
Windows 的 runtime 嵌在安装包里，同理待补。要纳入它们，需要先把"签名后哈希"也写进
`attestation.self_sha256`（发布脚本已支持一个指纹对应多个哈希）。

现状：已开启（`enforce` + `linux`），线上拒绝数 0。

### 6.3 顺手抓到的时区 bug（真实影响，非测试问题）

面板 PHP 的 `date.timezone=PRC`，而库里时间戳都是 UTC（gmdate 写入）。原代码用
`strtotime("2026-09-12 10:00:00")` 解析 → 被当成东八区 → **每个设备会话在管理页里提前 8 小时**
变成 Stale/Offline。已改为显式 UTC 解析（`utcTimestamp()`），线上契约测试从 fail 转 ok。
这个 bug 与本次功能无关，是既有代码的隐患。

### 6.4 审计与限额已开启（按真实分布取值）

先查了 30 天真实分布：单客户最多 **9** 台设备、单设备最多 **200** 条有效租约、单客户+设备
每分钟最多 **96** 次签发。据此设置（约为观测峰值的 3~5 倍，留足余量）：

| 开关 | 值 | 观测峰值 |
|---|---|---|
| `ULTRA_MAX_DEVICES_PER_CLIENT` | 32 | 9 |
| `ULTRA_MAX_LIVE_LEASES_PER_DEVICE` | 512 | 200 |
| `ULTRA_LEASE_ISSUE_RATE_PER_MINUTE` | 600 | 96 |

`ultra_lease_refusals` 增加保留策略：`master/cli/cleanup-tasks.php` 新增
`--ultra-refusal-retention-days`（默认 30 天，分批删除）。

### 6.5 仍然做不到的

- **不报 attestation 就不受约束**：改包者只要把 `runtime_attestation` 从 status 里删掉，
  就回到 `unknown` 放行。要堵这一步得让面板"强制要求 attestation"，但那会打断所有
  还没升级的存量客户端，只能等新 runtime 铺开后再开。
- macOS / Windows 未纳入（原因见 6.2）。
- 管理页目前只读；没有"一键封禁某设备"的按钮（用既有的 revokeDevice 走后台即可）。

