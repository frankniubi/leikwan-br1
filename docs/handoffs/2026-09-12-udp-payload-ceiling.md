# 原生 UDP 大包丢包：自适应载荷上限（普世方案）

Date: 2026-09-12
Repo: `leikwan-br1`（`tools/ultra-client` Go runtime）
数据来源: 171 上的 189 条全量探针（`/opt/leigod-native/scripts/udp_bigpkt_probe.py`）

## 问题

runtime **完全没有 MTU 概念**（`grep -i mtu tools/ultra-client` 零命中）：TUN 给的 1500 字节帧会被包进
16 字节 envelope → 1516 字节 UDP 数据报 → 超出路径 MTU，被中间设备静默丢弃，客户端只看到“游戏卡”。

实测（171，190 条线逐条 arm + 阶梯回显）：

- 丢包是**按尺寸确定性**发生的，与 4 MiB 收缓冲无关（4 MiB 在 171 上真生效，`rmem_max=128 MiB`）；
- **墙在 relay 侧，不在线路侧**：同一条线不同 relay 表现不同（946 一条 relay 1400B 起全丢、另一条直接不回包；
  347 一条 relay 1472B 起全丢、另一条 1470B 仍 0%），而 material 里 relay 每次 `startacc` 都轮换；
- 观测到的墙集中在 **1440~1472 字节 payload**（envelope 后 1456~1488，线缆 1484~1516）——正是“刚好过 1500”的位置。

## 方案：runtime 自测载荷上限并按上限执行（已实现）

`tools/ultra-client`：

1. **惰性测量**：只有当一个数据报 > `defaultPayloadCeiling`(1400) 时才做一次阶梯回显
   （`1500,1472,1440,1400,1360,1280`，每档 **2 次** 都回包才算过，单次 300 ms 预算；2 次是为过滤实测到的
   1440 档 1/12 抖动）。测到的值缓存在 session 上（`ceilingOnce`）。
2. **执行**：两条发送路径（`main.go` 的 datagram 发送、`native_socks5.go:sendUDPRequest`）都先过
   `session.allowsPayload(len(payload))`；超限**不发进黑洞**，直接计入既有 `onRuntimeDrop()` 计数（status 可见）。
3. **默认与下限**：默认 1400（envelope+IP+UDP = 1444，低于实测所有墙）；测不出时取 1280 下限；
   ≤1400 的包**零成本**（不触发任何探测）。
4. **测试**：`payload_ceiling_test.go` 5 项（最大可过尺寸、抖动拒绝、下限回退、小包零探测、超限拒绝）+
   全包 `go vet ./...`、`go test ./...` **通过（156s）**。

代码位置：`tools/ultra-client/main.go`（`DatagramSession.payloadLimit/allowsPayload/probeEcho/measurePayloadCeiling`、
常量 `defaultPayloadCeiling/floorPayloadCeiling/payloadCeilingLadder`）、`tools/ultra-client/native_socks5.go`、
`tools/ultra-client/payload_ceiling_test.go`。

## 配套（按优先级，尚未做）

1. **把上限交给 TUN**：hosts（Windows/macOS C#、Linux client-agent）读 runtime 的上限，把 TUN MTU 设为
   `min(面板 MTU, ceiling - 48)`，让 TCP MSS/QUIC 自己收敛 —— 这才是根治（不产生大包），runtime 的拒绝只是兜底。
2. **上报**：`udp_payload_ceiling` + `udp_oversize_dropped` 进 status/遥测，面板就能看到“哪条 association 窄”。
3. **选路联动**：`rankUDPProbeResults` 在大包场景优先选 ceiling 高的候选；ceiling < 下限的候选判不可用。
4. **服务端预测（可选）**：源代理在 `startacc` 后顺手测一次并写进 material（`relay_payload_ceiling`），
   客户端可直接挑好 relay，省掉本地探测。
5. **relay 淘汰**：连续 N 次 ceiling < 下限的 relay 从 material 里剔除（946 的 154.83.196.112 已实测全丢）。
---

## 全量扫描结果（2026-09-12 06:07-07:00Z，171，189 条线路）

扫描方式：逐线 `startacc` → arm → 对 relay keep-echo 打 1200/1400/1440/1472 字节各 5 发，统计丢包。
原始数据：`docs/reports/2026-09-12-udp-full-sweep.tsv`。

| 分类 | 条数 | 含义 |
|---|---|---|
| clean | **116** | 1200~1472 全 0% |
| intermittent | **34** | 多数尺寸 0%，1440 常见 20%（1/5）抖动 |
| wall ≤ 1472 | **20** | 1472 起 100% 丢（1400 及以下正常） |
| wall ≤ 1440 | **10** | 1440 起 100% 丢；其中 7 条（1/2/7/907/947/951/954）连 1200 都零回显（关联不可用），3 条（943/946/953）1200/1400 正常 |
| native UDP 不可用 | **9** | 7 条 relay 拒答（376/377/378/380/382/383/432，其中 376-383 正是【WireGuard模式】线），2 条 `startacc` 失败（691、766） |

结论：

1. **native UDP 覆盖 180/189**，不可用的 9 条里 7 条是 WireGuard-only（与名字一致），2 条缺材料。
2. **大包丢包是普遍现象**：30/180 有硬墙 + 34 条抖动 = **35% 的线路/relay 组合在 1440~1472 附近会丢**；
   但 **≤1400 字节对 170/180 都是安全的**（只有那 10 条连 1200 都丢）。
3. 墙与线路无关、与 relay 有关（材料每次 `startacc` 轮换 relay，同一条线不同轮次表现不同）——
   所以任何“按线路写死 MTU”的方案都会失效，必须是**按关联自适应**。

### 方案据此做的强化

- 阶梯最小档（1280）也测不到回显时，不再“兜底到 1280”，而是把该关联标记为 **unusable**
  （`payloadLimit()==0`），发送路径直接拒绝并计数，HA 会走既有的候选切换；
- 测量加总预算 `payloadCeilingBudget=900ms`，避免在发送路径上长时间阻塞；
- `allowsPayload` 对 **≤1280 的包零探测**直接放行，只有可能撞墙的包才付一次测量成本。
- `go vet ./...` + `go test ./...` 全绿（含 6 项 ceiling 测试，156s）。

---

# 落地：大包过不去的线路按低优先派发（2026-09-12）

## 1. 声明链路（源侧声明 → 面板）

- 面板 `ultra_lines.dispatch_class`（`normal`/`low`，migration `20260911_add_ultra_line_dispatch_class.sql`）
  此前只能由面板按 provider/title 里的 `腾讯` 推导。
- 现在**源代理可以自己声明**：inventory 推送的 line 带 `dispatch_class` 时优先采用，未声明或非法值才回落
  到 `UltraInventoryService::dispatchClass()`（腾讯启发式）。面板补丁（live md5 `e4d7a7f511de132c596d51870ba715e9`）：

  ```php
  'dispatch_class' => ($declaredDispatch = self::normalizeEnum($raw['dispatch_class'] ?? '', self::DISPATCH_CLASSES)) !== 'unknown'
      ? $declaredDispatch : self::dispatchClass($raw),
  ```

- 源代理 `source-agent/ultra_source_agent.py`（171 上 `/usr/local/bin/ultra-source-agent.py`，md5 `dbedf7024d4cf4410198ca101129ca69`）
  按 **audit manifest 优先、index 兜底** 读取 `dispatch_class`。
- 派发顺序（`ProvisioningService::UltraLineRefsForForwards`）：
  `ORDER BY f.id, us.priority, CASE WHEN ul.dispatch_class='low' THEN 1 ELSE 0 END, pricing…, fcul.sort_order, fcul.id`
  —— low 永远排在 normal 之后，只有没得选时才回落到它们。
- 实测 27 个有 live material 的 ultra forward，全部 `low_last=yes`。

## 2. 声明了哪 30 条（来源＝上面的全量扫描）

| 组 | 条数 | 线路 |
|---|---|---|
| wall ≤ 1440 | 10 | 1, 2, 7, 907, 943, 946, 947, 951, 953, 954 |
| wall ≤ 1472 | 20 | 32, 37, 41, 262, 347, 433, 448, 453, 463, 660, 709, 742, 743, 744, 886, 974, 993, 998, 999, 1000 |

写入位置（171，脚本 `.tmp/171-stamp-dispatch.py`，均在改动前 `*.bak-dispatch-<stamp>`）：

- `/opt/ultra-source/line-audit.json`（audit manifest，27/30；742/744/998 不在 manifest 里）
- `/opt/ultra-source/lines/index.json`（30/30）

刷新脚本 `refresh_lines.py`（171 `/opt/leigod/refresh_lines.py`，md5 `5f0babd3dcb5c99aa22b7000938501f6`，旧版
`d7fbeb90…` 已备份）新增：index 行**保留上一轮 `dispatch_class`**，并**优先采用 manifest 的声明**
（非法值回落），因此 17/47 的定时全量刷线不会再把声明抹掉。

面板结果（source-home-171）：`low=62`（30 条新声明 + 32 条腾讯），`normal=127`。
同一批 line_id 在第二个源 `source-home-192-168-50-211` 上仍是 `normal` —— 那是**另一个出口没测过**，
且这些行 `native_udp_state=unknown`、`is_enabled=0`（不参与派发），所以没有把 171 的墙跨源照搬。
若该出口也要 low，需在它的出口上跑一次 `udp_bigpkt_probe.py`。

## 3. 事故与修复：面板 `UltraInventoryService.php` 被写成 0 字节

- **07:46:26Z** 一次部署脚本因本地路径拼接错误（`$b64` 为 `$null`），把 **0 字节**文件装到了
  `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/UltraInventoryService.php`。
  `php -l` 对空文件返回 “No syntax errors”，所以旧流程没拦住 → 客户端 provisioning API 报错，
  **macOS 连接中断**。
- **07:48:12Z** 从覆盖前的备份恢复（`cp -a` 先跑，`/root/leigod-forward-backups/ultra-dispatch-declared-20260912T074626Z/`），
  md5 回到 `11827614a47dcc50ecc16460aad4c3bd`，reload php-fpm-81/82/83-source196，macOS 连接恢复。
- 之后部署加了硬护栏：本地 base64 长度校验 → 远端 `[ -s ]` 非空校验 → `php -l` → 才 `install`。
- 教训：**`php -l` 不能证明文件非空**，部署脚本必须在服务端校验大小（或比对 md5）后才落盘。

## 4. 仍然待办

1. 9 条 native UDP 不可用（376/377/378/380/382/383/432 为 WireGuard-only，691/766 缺材料）**未**进 low 名单：
   它们不是“大包过不去”而是“根本没起来”，需要单独处理（修材料或从目录摘除）。
2. 源 `source-home-192-168-50-211` 的墙未测。
3. 客户端侧 TUN MTU 接线（见本文上半部分 `配套`）仍是根治项。

