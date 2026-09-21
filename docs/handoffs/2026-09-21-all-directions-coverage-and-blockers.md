# 全方向 E2E 矩阵 —— 本轮实际覆盖范围与逐方向阻塞原因

日期：2026-09-21。本文只记录本轮（2026-09-21 session）真正执行了什么、以及每个未覆盖方向的**具体**阻塞点。
前置盘点见 `2026-09-20-uot-all-directions-test-matrix.md`（只读盘点，11 方向）。

## 结论（一句话）

**11 个方向里，本轮真正跑通数据面验收的只有 1 个方向** —— `70.36.96.183`（HK/Mainland）那台
sidecar，经测试 forward 201/202，在 Linux / macOS / Windows 三平台各跑到 20 Mbps。其余 10 个方向
**全部 BLOCKED**，且阻塞原因不是"没时间测"，而是三类硬前置条件，逐条列在下面。

不许把 HK 方向的结果当成 11 方向的结果。也不许把 sidecar 磁盘配置里存在某个 forward ID 的 binding，
当成"该方向已授权"。

## 唯一被实际覆盖的方向

Ultra 路径始终是 `client -> leased Ultra provider relay -> configured external UoT sidecar -> UDP target`。

| 项 | 值 |
|---|---|
| sidecar | 70.36.96.183（HK / Mainland 映射） |
| 测试 forward | 201（UDP 60501 → 70.36.96.183:62101）、202（TCP 60502 → 70.36.96.183:62102），client 3 / service 30，`transport=tcp+udp` |
| 目标 | 自建验收 pump：UDP echo + TCP responder，两侧 `RuntimeMaxUSec=infinity`（见 2026-09-21 会话记录，2 小时 `RuntimeMaxSec` 曾在中途杀掉 pump 并污染过一整轮测量） |
| 生产 forward | 158/159/170/171/180 全程只读，未承载任何验收流量 |
| 三平台 | Linux `172.21.255.189`、macOS `100.103.253.21`、Windows `172.21.255.4`，均为 client 3 下的设备 |

## 逐方向阻塞表

阻塞类别：
- **A 授权**：client3 / service30 没有该组合授权，且**明令禁止**修改共享 plan 9 补权限。
- **B 候选 material**：该组合的 lease material 在快照时已过期或为空，需要走受支持的获取流程刷新。
- **C sidecar binding**：新 forward ID 在该 sidecar 配置里没有匹配的 token/HMAC binding；热加载能力
  已在本轮实现（见 `2026-09-21-sidecar-binding-hot-reload.md`）但**尚未部署**到任何一台现网 sidecar。
- **D 无目标**：该目的地没有已授权的专用 UDP 验收目标；现存 forward 多为生产业务，不能拿原 target 做
  20 Mbps 压测。

| 组合 | 方向 | sidecar | 阻塞 | 说明 |
|---|---|---|---|---|
| 46 | shanghai → hong-kong | 70.36.96.183 | **A + D** | client3 无 forward、无授权；material 为空 |
| 47 | shanghai → singapore | 77.93.90.45 | **A + D** | 同上 |
| 48 | shanghai → south-korea | 103.246.219.39 | **A + B + D** | material 过期/为空 |
| 49 | beijing → frankfurt | 157.228.138.23 | **B + C + D** | material 全过期；service11/30 有授权但需新 binding |
| 50 | beijing → moscow | 157.228.138.23 | **A + B + D** | 无授权；material 过期 |
| 51 | guangzhou → hong-kong | 70.36.96.183 | **C + D** | 生产 forward 158 在这条路上 —— 不许压测 |
| 52 | guangzhou → singapore | 77.93.90.45 | **C + D** | 199 测试 forward 已禁用，其 60051 固定 target 已被清理 |
| 53 | fuzhou → taiwan | 103.246.219.39 | **B + D** | material 过期；160 已禁用 |
| 54 | shanghai → tokyo | 185.220.238.143 | **C + D** | 159/171/170 是生产/试点 forward —— 不许覆盖 target |
| 55 | shanghai → los-angeles | 208.122.17.50 | **A + D** | client3 无该方向 forward |
| 56 | hong-kong → china-mainland | 70.36.96.183 | **B + D** | material 已过期；174 已禁用；`detected_only` |

**11 个方向没有一个可以"只改 panel 组合/target"就宣称通过**：`2026-09-20` 盘点已证明
`160/174/176` 这组可复用 ID 里只有 53/54/56 具备「同 ID + token 匹配 + wildcard target」的磁盘条件，
而且 wildcard 只放开 target，不放开 token 或 forward ID。

## 如果要做完，必须按顺序满足的前置条件

1. **授权**：克隆 plan 9 为独立隐藏的一次性测试 plan（46–56 十一方向），用 CAS 只改
   `services.id=30 AND user_id=3 AND plan_id=9`，affected_rows 必须为 1；结束时 CAS 还原并停用。
   原 plan 9 与其绑定**不得修改**。这一步必须在业务协调窗口内做，且要接受 service30 自己的
   catalog 会变化。
2. **sidecar binding 热加载**：把本轮的 `tools/uot-sidecar` 热加载实现部署到 5 台 sidecar，并逐台
   验证 HELLO/ACK + 载荷；否则新 forward ID 只能靠重启 sidecar 接入，会切断存量会话。
3. **material 刷新**：7 个方向的候选 material 已过期，须走受支持的获取流程刷新；刷新失败的方向
   应明确标 BLOCKED，不能把"旧候选静态存在"当成功。
4. **每方向独立目标**：每个目的地需要一个已授权的专用 UDP 验收目标。目前只有 70.36.96.183 上有。
5. **端口/forward 隔离**：每方向独立 listen 端口与独立测试 forward，绝不触碰 158/159/170/171/180。

## 本轮实际交付的相关能力

- **sidecar binding 热加载**：实现 + 单测 + 真机验证完成，代码在仓库里，**未部署**。
- **panel 改 target 后重新签发 lease**：实现 + 单测完成，**未部署**；这条正是"改 target 后 forward
  静默失去 lease 却仍显示 enabled"的根因修复。
- **agent 监听探测改为读内核表**：实现 + 回归测试 + Windows 真机验证完成，**已部署到 Windows 测试机**。

也就是说：**阻塞 10 个方向的三类前置条件，本轮各自有了对应的代码能力，但都还没有在生产侧
部署/授权。** 矩阵本身仍未跑。
