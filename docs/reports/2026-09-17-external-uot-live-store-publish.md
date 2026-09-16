# External UoT sidecar — live store publish + fresh-install acceptance

Date: 2026-09-17 (UTC)  ·  Branch: `fix/ultra-probe-single-reader`  ·  HEAD before commit: `33d5d5a3`

## 1. 目标

Ultra runtime 的 `external_uot` 承载必须在对侧有成对的 external UoT sidecar 终结 UDP。
本报告记录：把该 sidecar 作为**标准 sidecar 安装包的一部分**发布到面板制品库，并在
**干净主机上从线上制品库全新安装**后用**真实 runtime** 验证 UDP 往返（消除新装主机的
`UDP candidate failed`）。

## 2. 交付物（已发布到 https://v3.leikwanhost.com）

| manifest key | version | sha256 | size | URL |
| --- | --- | --- | --- | --- |
| `bond_sidecar_installer` | 2026.09.17.1 | `14fcc9e250020bd3366dddfbfb72583d6dfe4c057f71580e37f47dbfe9ecf1c9` | 11263 | `/artifacts/install-bond-sidecar.sh` |
| `external_uot_installer` | 2026.09.17.1 | `0506d83841a6885804a1646e35ee791e524fbaf8151c4cb782f5a510c6a4d665` | 10158 | `/artifacts/install-external-uot.sh` |
| `uot_sidecar_linux_amd64` | 2026.09.17.1.33d5d5a3 | `18bd060abd04242dd4b3a9cbd13ce76876159a76ae8276a3eedc303621ac60ef` | 3014818 | `/artifacts/uot-sidecar/uot-sidecar_linux_amd64` |
| `uot_sidecar_linux_arm64` | 2026.09.17.1.33d5d5a3 | `9799779fa66e014430f06e9bea9e6cabe52cb3196c3478ff4b20761038ea6320` | 2949282 | `/artifacts/uot-sidecar/uot-sidecar_linux_arm64` |
> Note (fresh-install wiring deploy, 2026-09-17 20:38 UTC): bond_sidecar_installer was republished with the same version but new content - sha256 c7389c5a...e17c69261, size 11884 (was 14fcc9e2... / 11263). See docs/reports/2026-09-17-external-uot-panel-config-verification.md, section Fresh-install wiring.


参考实现（Python）仍在制品库：`/artifacts/leikwan-external-uot.py`（sha256 `810d5d2e…798d44`）。

面板路由（`master/src/Bootstrap.php` + `ArtifactController.php`）：

```text
GET /artifacts/install-external-uot.sh                  -> installExternalUot
GET /artifacts/uot-sidecar/uot-sidecar_linux_amd64      -> uotSidecarLinuxAmd64
GET /artifacts/uot-sidecar/uot-sidecar_linux_arm64      -> uotSidecarLinuxArm64
```

## 3. 安装方式

### 3.1 随 bond sidecar 新安装（推荐，新主机默认路径）

```bash
curl -fsSL "$MASTER/artifacts/install-bond-sidecar.sh" -o /tmp/install-bond-sidecar.sh
bash /tmp/install-bond-sidecar.sh \
  --master "$MASTER" --agent-token "$AGENT_TOKEN" \
  --external-uot-config "/etc/leikwan-external-uot.json"   # 或面板签发的 URL
```

* `install-bond-sidecar.sh` 会用 manifest 校验并安装 `install-external-uot.sh`，再由它安装
  `uot-sidecar_linux_{amd64,arm64}` 到 `/usr/local/bin/leikwan-external-uot`，并写
  `/etc/leikwan-external-uot.json`（0600）、`leikwan-external-uot.service`。
* 未传 `--external-uot-config` 时：unit 装好但**保持 disabled**（forward 列表属运维/面板所有）。
* 开关：`--skip-external-uot` / `--require-external-uot`（或 `LEIKWAN_SKIP_EXTERNAL_UOT=1`）。

### 3.2 单独安装 / 重装

```bash
curl -fsSL "$MASTER/artifacts/install-external-uot.sh" | bash -s -- \
  --artifact-base "$MASTER" --config "/etc/leikwan-external-uot.json" --listen-port 7443
```

`--check-config` 预检、`--uninstall`（停用+移除 unit，保留 bin/config）。

## 4. 本次验证（全部实跑，非静态检查）

### 4.1 面板发布回执

* preflight 全绿，`php -l` 干净，php-fpm reload 后 3 条新路由 200；
* 备份：`/root/leikwan-br1-backups/uot-sidecar-20260917-1`（可回滚）；
* manifest 最后发布（先载荷后 manifest）；无缓存旧副本（去掉 cache-buster 也是新字节）。

### 4.2 外部（Cloudflare）探测 —— Windows 侧

```text
OK    /artifacts/install-bond-sidecar.sh  14fcc9e250020bd3
OK    /artifacts/install-external-uot.sh  0506d83841a68858
OK    /artifacts/uot-sidecar/uot-sidecar_linux_amd64  18bd060abd04242d
OK    /artifacts/uot-sidecar/uot-sidecar_linux_arm64  9799779fa66e0144
OK    /artifacts/leikwan-external-uot.py  810d5d2ec8818e48
```

### 4.3 干净主机从线上制品库全新安装（WSL, root）

``.tmp/uot-e2e/live-accept.sh`` → `LIVE-ACCEPT-ALL-PASS`（FAILED=0），关键项：

```text
PASS  manifest external_uot_installer / uot_sidecar_linux_amd64 / uot_sidecar_linux_arm64 / bond_sidecar_installer
PASS  live bond installer sha == manifest
PASS  bond installer accepts --external-uot-config
PASS  bond installer manifest-verifies external_uot_installer
PASS  arm64 artifact 200 + sha
PASS  binary sha256 verified against LIVE manifest
PASS  installed config mode 600
PASS  reinstall exits 0 / keeps verified binary
PASS  sidecar listening on 17443 (our pid)
```

安装器回执：

```text
sha256 ok (uot_sidecar_linux_amd64 18bd060abd04242dd4b3a9cbd13ce76876159a76ae8276a3eedc303621ac60ef)
installed /tmp/uot-live/bin/leikwan-external-uot version=2026.09.17.1.33d5d5a3
config=/tmp/uot-live/etc/leikwan-external-uot.json forwards=1 port=17443
EXTERNAL-UOT-INSTALL-OK
```

### 4.4 真 runtime 端到端（Windows runtime → 假 provider relay → 线上装出的 sidecar → 真 UDP）

```text
[relay] bridged to real sidecar 172.18.220.75:17443
[harness] sequential 20/20 replies, min=294ms median=342ms max=358ms
[harness] burst 50/50 replies duplicates=0 loss=0.0%
[harness] VERDICT udp_roundtrip=PASS sustained=20/20 burst=50/50
```

sidecar 侧会话计数（无丢包/无重挂）：

```text
external UoT sidecar ready build=2026.09.17.1.33d5d5a3 forwards=1 queue_packets=256 keepalive_seconds=10
external UoT session closed sid=ff330b7f udp_in=50 udp_out=50 queue_drops=0 oversize_drops=0 reattaches=0
```

## 5. 回滚

```bash
# 面板制品库（路由/manifest/载荷）
ssh root@panel 'cp -a /root/leikwan-br1-backups/uot-sidecar-20260917-1/. /www/wwwroot/v3.leikwanhost.com/leikwan-v3/'
# 单机二进制/配置
cp -a /root/backups/leikwan-external-uot-20260917-033822/leikwan-external-uot /usr/local/bin/leikwan-external-uot
cp -a /root/backups/leikwan-external-uot-20260917-033822/leikwan-external-uot.json /etc/leikwan-external-uot.json
systemctl restart leikwan-external-uot.service
```

## 6. 未完成 / 风险

1. **面板在安装时下发 `--external-uot-config`（最后一段）**：不带 config 的全新安装会得到
   *已安装但 disabled* 的 unit，主机仍会 `UDP candidate failed`。需要在 sidecar 安装/开通流程里
   由面板生成并传入（含 forward 列表与 token）。
2. `install-bond-sidecar.sh` 的 UoT 步骤当前会因 `set -e` 中断整个安装；建议改为 warn+continue,
   仅 `--require-external-uot` 时硬失败。
3. Android 客户端的 `external_uot` 引导未验证（Windows / macOS / Linux agent 已确认；客户端
   不需要额外 sidecar 二进制，UoT 承载在 runtime 制品内）。
4. Linux client-agent 会把 `external_uot.token` 持久化到 `/etc/ultra/forward-{id}.json`（0600）,
   与桌面端的内存态 bootstrap 不同（既有设计）。

## 7. 证据位置

* 本机：`.tmp/uot-e2e/live-accept.sh`（验收脚本）、`.tmp/uot-e2e/live-e2e.log`（runtime E2E 全量日志）、`.tmp/uot-e2e/status.json`
* 发布：`.tmp/uot-publish/`（stage + promote 脚本、probe-live.ps1、promote 回执）
* 被测主机（WSL）：`/tmp/uot-live/{install.log,check.json,live-sidecar.log,reinstall.log,etc/leikwan-external-uot.json}`

