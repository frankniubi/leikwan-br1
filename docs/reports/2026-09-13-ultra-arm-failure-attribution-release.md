# Ultra 假故障流：归因修复 + 兜底去重 + 设备恢复 — Release

Date: 2026-09-13
Repos: `leikwan-br1`（runtime + panel）
发布：runtime **2026.09.13.1**（`ecbee8f7…`）、Windows App **0.1.20.40**（`4a808494…`）

## 现象

用户本地网络正常，但客户端"一直报错"，且全站 24h 只有 **6 个 observer**，失败几乎全部来自其设备。

## 证据（168.13 04:0x–04:3xZ 实测）

- **本机隧道是活的**：4 个 forward 的 `*.status.json` 全部 `state=ready`，
  各自 `probe=30/30`、`udp_keepalive_acks` 6008–6221、RTT 36–38ms。
- **从本机用真实凭据直打 relay 三个全过**：`106.75.249.175` / `106.75.251.131` / `106.75.227.43`
  各 4/4 轮大包（1410B 回显）+ DNS 全干净；而 `106.75.249.175` 在同一份日志里"失败"过 17 次。
- **从 171 用同一账号并发开两个会话也双双通过** → 与"一个账号一个会话"无关。
- 失败串是 `probe_udp_failed: datagram handshake failed after greeting fallback:
  initial=datagram handshake: EOF; legacy=…EOF`。`openDatagramControlUntil()`（`main.go:1164`）
  是先 `net.DialTimeout` TCP 到 relay:5438 再写握手读回复，**EOF = TCP 连上了、服务端收下握手后不回包就关**。
- 归因才是致命处：arm 失败被写成**线路**健康证据（`ha_udp.go:48`、`native_socks5.go:940`），
  且**每次重试换一张 lease → 新的 evidence_id**，15 秒一轮的重试变成 15 条"独立失败证据"。
- 放大器：client 3 在 **30 分钟内领了 1197 张 lease，却只有 5 个不同候选**（fwd 159/170/171 共用同一条）。

## 三处修改

1. **根治（runtime）**：新增 `errProviderDial` 哨兵，`udpArmFailureReason()` 区分两种 arm 结局：
   连不上（`udp_endpoint_unreachable`，仍计线路）与连上后被拒（`udp_arm_failed`，**不计线路**）。
   建立后断开的 `udp_association_failed` 保持线路语义。错误文本不变，分类靠 `errors.Is` 而非字符串。
2. **兜底（panel）**：失败证据按 **(候选, observer, reason, 10 分钟窗口)** 去重，忽略客户端自带的
   evidence_id；`udp_arm_failed` 列入 transport 类，计数入日志但不进信誉。
3. **降级通道（panel）**：`ultra_observer_devices.last_decay_at`（迁移 `20260914`）；
   一周无新反驳衰减一次 strike，掉到阈值以下即清 `demoted_at`、恢复独立观察者身份。

## 线上验证

- 去重前 30 分钟：client 3 失败证据 27 条 / 2 observers = **13.5 条/observer**；
  部署后：8 条 / 3 observers = **2.7 条/observer**。
- 单点核对：设备 `legacy-8631a9c4…` × 候选 `212e6ca3…` 部署后**只剩 2 条**
  （04:42:45、04:51:07，横跨两个窗口），部署前同一组合 15+ 条。
- 隔离候选从 5 条收敛到 **1 条**（line 325，level 2）。
- 面板哈希审计：**0 mismatch / 2 missing**（既有 gost、leigod 旧产物）。
- 两端点（`v3.leikwanhost.com` 与 `api-leikwanbridge.nbwly.com`）runtime/安装包/助手三个 URL 全部
  200 且哈希一致。

## 发布与回滚

- 备份：面板 `/root/leikwan-br1-backups/release-20260913T044634Z/`（旧 runtime、旧安装包、旧 versions.json）
  与 `.../20260913T044230Z/`（三个 PHP 文件）。
- 本地产物：`artifacts/ultra/ultra-client_windows_amd64.exe`、`artifacts/windows/leikwan-bridge-setup.exe`。
- 客户端仍持旧 runtime 时会看到 app 0.1.20.40 → 装上即得新 runtime，pin 与产物一致，不再报漂移。

## 遗留

- Android / macOS / Linux 的 runtime 尚未带本次归因修复（它们的 arm 路径同源，需要各自重新构建发布）。
- 旧客户端（< 0.1.20.40）仍把 arm 失败报成 `udp_association_failed`：兜底去重把单个设备压到
  每窗口 1 条，但 3 台同 bug 设备仍可在同一窗口凑够 3 条触发隔离 —— 源主机复审会自动解除，
  升级到 0.1.20.40 后不再产生这类证据。
