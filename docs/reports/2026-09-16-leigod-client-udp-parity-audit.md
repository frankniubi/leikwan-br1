# LeiGod 官方客户端 UDP 能力差异审计

日期：2026-09-16。状态：DONE_WITH_CONCERNS（静态排查完成，运行时协议未验证）。

## 结论

官方 Windows 客户端包含我们当前 Ultra command-3 路径未实现的 UDP-over-TCP 代码线索，值得优先解构。不能据此认定当前游戏使用 UoT、现有 relay 支持某个命令号，或大包故障必然来自遗漏的官方机制。

更直接的问题是本地 Ultra 的大包探测与业务接收共用 socket，存在竞争读取和读取 deadline 相互覆盖的代码路径。原报告中“大包后关联失效”不能单独用于证明上游 MTU 或 relay 故障。

## 原报告的证据边界

`2026-09-16-ultra-udp-cause-separation-and-canary.md` 记录的是 Linux forward-170 与自有 UDP echo 端点。其 Direct UDP baseline 并非官方客户端游戏测试。

- 256-byte Ultra canary：1200/1200；1400-byte 测试：0/450。
- `udp_oversize_drops` 包含本地发送前拒绝，因此不代表这些包全部已经发送到 provider。
- 1272/1273/1274/1280 的确切边界未测定。
- 游戏可以正常运行，不等于相同 relay、相同封装下的任意大小 UDP 都可通过。

## 安装目录静态证据

根目录：`C:\Program Files (x86)\LeiGod_Acc\resources\leishenSdk`。
下列偏移均为文件偏移，不是虚拟地址。仅提取协议标识和编译日志字符串，未执行 DLL。

| 文件 | 偏移 | 字符串证据 | 可支持的判断 |
| --- | --- | --- | --- |
| tun2socks.dll | 0x162f44 | `over_tcp` | 存在 TCP 承载配置线索 |
| tun2socks.dll | 0x165e50 | `onlyuot` | 存在 UoT 模式配置线索 |
| tun2socks.dll | 0x165e58 | `multi_udp_route` | 存在 UDP 路由策略线索 |
| tun2socks.dll | 0x16a734 / 0x16a790 | `uot recv from target` / `uot send to target` | UDP 会话包含 TCP 收发实现线索 |
| tun2socks.dll | 0x16b4b8 / 0x16b4f0 | `udp_switch_session::handshake_once` / `start_check_available` | 应追踪握手及可用性检查分支 |
| GateProxyEx.dll | 0x75c85c | `write first uot pkt` + `leaf\src\proxy\socks\outbound\udp_over_tcp.rs` | 独立 UoT 模块线索 |
| GateProxyEx.dll | 0x768b66 | `uot handle sock5` | 应追踪 SOCKS 到 UoT 的转换 |
| GateProxyEx.dll | 0x76f605 | `quic outbound mtu change` | 另有 QUIC/MTU 处理能力线索 |

其他组件包括 `msquic.dll`、`VpnProxy.dll`、`nne.dll`、Wintun/TAP 和网络过滤驱动。组件存在不能证明当前流量经过它们；QUIC/KCP 的分片或 MTU 字符串也不能证明普通 command-3 有专用分片机制。

SHA256：

- tun2socks.dll：`9d86799c05de5effdcc4cd3662c2b70b8f5529b9c9d4e47213dd7faf6ecbcca1`
- GateProxyEx.dll：`2d337549db55beaabe6e5116f6cc4f28e0c76f3e3b3e860f13584767a01cae1b`

检查时未发现名称匹配 leigod/leishen/lsacc/accdrv 的运行进程，因此没有本轮官方客户端动态会话证据。

## 本地实现中更直接的疑点

### 1. 探测破坏单 reader 约束

- `tools/ultra-client/main.go:816`：`probeEcho()` 直接调用 `session.remote.Read()`，设置 socket 级读取 deadline；该函数没有在返回前恢复 deadline。
- `tools/ultra-client/main.go:907`：大包上限测量调用该探测。
- `tools/ultra-client/main.go:1780`：业务会话启动 runtime reader。
- `tools/ultra-client/ha_udp.go:117`：runtime reader 同样调用 `remote.Read()` 和 `SetReadDeadline()`；读取超时触发运行时失败并关闭会话。
- `tools/ultra-client/main.go:2139`：正常发送路径可在 reader 已启动后进入大包测量。
- `tools/ultra-client/native_socks5.go:1039,1076`：native SOCKS 路径也有发送时探测和独立 socket reader。

这是代码中可确认的并发设计冲突；其对 9 月 16 日生产故障的贡献尚未复现，也未核对部署二进制。可能后果包括：业务 reader 取走探测回包；探测取走业务/保活回包；300 ms 探测 deadline 影响正常接收循环。`ceilingMu` 只串行化测量，没有协调业务读取。

### 2. 把大包能力当成整个关联的可用性

`main.go:780` 附近的测量阶梯最低是 1280-byte payload。所有阶梯失败后 ceiling 归零；发送路径可把会话判为不可用并关闭。小包原本可用的窄路径、探测应答格式不匹配，以及回包被另一 reader 取走，都需要与真正 relay 不可用区分。

保留地址 `2.0.0.0:0` 对固定保活应答有效，不自动证明其对任意长度、任意内容的 payload 都提供透明 echo。应分别测试保留目标和自有业务 echo。

## 下一步验证顺序

1. 本地回归实验：在可控 UDP echo 模拟器上同时启用 runtime reader 和 payload probe，核对探测回包归属、deadline、会话是否关闭。先证实上述竞争，不改生产。
2. 官方二进制解构：追踪 `onlyuot`、`over_tcp`、`udp_switch_session::handshake_once`、`udp_over_tcp.rs` 对应字符串交叉引用，确定模式选择、握手字段、帧长度、字节序和服务端能力协商。不要预设命令号为 4。
3. 官方会话对照：在隔离测试窗口记录已加载模块、脱敏后的有效传输模式，以及实际 relay；确认游戏 UDP 确实被代理，避免把直连绕过流量算成加速成功。
4. 同一 relay、目标和凭据条件下对比官方与自研；控制速率，测试 256/1000/1200/1272/1280/1360/1400-byte payload，再回到 256。每组记录内外层长度、源端口、控制连接和回包，不靠反复重启建立边界。
5. 仅在确认官方线协议和 endpoint 支持后实现兼容 UoT；不能把当前 command-3 失败解释成必须建设新 command-4 relay。

本次未修改运行时代码、客户端安装文件、服务或生产配置；没有启动官方加速、切换游戏或抓取业务流量。
