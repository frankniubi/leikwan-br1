# 更正：Windows 测试机不是瓶颈 —— 是我的两个测量 bug

日期：2026-09-21。本文**推翻** `2026-09-21-three-platform-acceptance-and-supervision-cascade.md`
第 4 节、`2026-09-21-final-verification-deployed-stack.md` 第 3 节里"Windows 测试机线路承载不了
40 Mbps"的结论。那个结论是错的，原因是我自己的两个测量缺陷。

## 1. 第一个 bug：用单条 HTTPS 流测线路带宽

我用 `curl -o NUL https://speed.cloudflare.com/__down?bytes=...` 单流下载，得到 15.25 Mbps，
就当成"这台机器的线路带宽"。**单条 TCP 流在 80 ms RTT 下受接收窗口限制**，测出来的是窗口/RTT，
不是线路带宽。

改成**多流并行、并按磁盘上真实落地的字节数统计**之后：

| 方式 | 实测 |
|---|---|
| 1 条流 | 15.25 Mbps（窗口受限，**这个数字没有意义**） |
| 8 条并行（400 MB 落盘核对） | **79.72 Mbps** |
| 16 条并行（800 MB 落盘核对） | **149.29 Mbps** |

**这台机器的线路是 150 Mbps 量级，不是 15 Mbps。用户是对的。**

## 2. 第二个 bug：harness 从不读 TCP 回包

目标端的 TCP responder 是这样的：

```python
chunk = self.request.recv(1 << 16)
if chunk.startswith(MAGIC):        # MAGIC = b"LKT1"
    self.request.sendall(MAGIC + b"ACK")
```

`recv(65536)` 返回的是**已经到达的任意长度**，而我发的每帧 1400 字节都以 `LKT1` 开头 ——
**所以它每一帧都回一个 7 字节的 ACK。**

而我的 harness 在 TCP 侧**从来不读**，注释里还写着"目标只在 64 KiB 读以 LKT1 开头时才回包，所以不用读"。
那句话是错的。于是回包在我的接收缓冲里越堆越多（约为发送量的 0.5%），最终 TCP 流控**从目标一路回压到
整条路径**：吞吐塌陷、把回压造成的速率当成"路径容量"、连接最后被重置。

这解释了之前所有说不通的现象：

- 不限速 TCP "上限 31.4 Mbps" —— 那其实是回包积压填满的时刻；
- `CV 159%`、`median 7.7 Mbps` 这种诡异分布 —— 突发式回压；
- 600 秒跑到后半段突然 4 条流一起被 RST —— 缓冲填满；
- 30 Mbps 那轮 2 秒就断 —— 起跑就是满速灌；
- `windows_idle` 恰好 40 —— 那是我另一个 bug（切片先截断再取长度）。

修好之后（TCP worker 起一个读协程持续排空，并把读到的字节数 `tcp_reply_bytes` 报出来自证）：

| 项 | 修前 | 修后 |
|---|---|---|
| 隧道内 TCP 不限速 | 31.4 Mbps | **62.3 Mbps** |
| TCP 20 Mbps 单跑 30 s | median 20.001 但 min 3.5 / CV 159% | **median 20.001，min 19.959，CV 0.88%，idle 0/30，reply_bytes=347,102** |
| TCP 20 + UDP 20 跑 600 s | 有时 idle 0，有时 4 条流全断 | **600/600 窗口，idle 0，interruptions 0，delivered 1.0，errors {}**，reply_bytes=2,800,665 |

## 3. 更正后的结论

- **Windows 测试机不是瓶颈**，"换一台 Windows 测试机"的说法作废。它线路 150 Mbps 量级，
  TCP 20 Mbps 单跑 600 秒/30 秒都是零中断。
- **TCP 20 Mbps × 600 秒在 Windows 上达标**：600/600 窗口、idle 0、零中断、零错误类。
- **UDP 20 Mbps 仍有约 4% 前向丢包**（目标端计数确认丢在去程）。这是 **Ultra 的 UDP 路径本身**
  在这个 client 的 relay 腿上大约只能承载 20 Mbps 量级：把 UDP 开到 100 Mbps 时，
  发出 254,398 个包、目标只收到 66,555 个（**74% 丢**），实际通过约 20 Mbps。
  这条与测试机线路无关，需要单独查 relay 腿或 sidecar 的处理能力。

## 4. 我该怎么做才对

第一次拿到 15 Mbps 时就该问一句"单流在 80 ms RTT 下能跑多少"，第一次看到 CV 159% 时就该怀疑
测量而不是路径。**用一个自己写错量具的读数去下"硬件不行"的结论，是这次最严重的错误。**
现在 harness 会自报 `tcp_reply_bytes`，量具本身可被核对。

## 5. 补充：把剩下的 UDP 丢包按段拆开

用目标端计数器 + sidecar 宿主 `/proc/net/snmp`，在 **TCP 20 + UDP 20 并发 90 秒**下同时记账：

```
client sent         = 187,032
target received     = 186,944      -> 前向丢包 0.0471%（基本为零）
client observed loss= 1.4377%      -> 多出来的约 1.4% 在回程（ACK 回客户端）
sidecar host deltas : OutDatagrams=392,636  RcvbufErrors=0  SndbufErrors=0  MemErrors=0  InErrors=0
TCP                 : idle 0/90，CV 2.14%，error_classes {}
```

sidecar 宿主的 socket 计数**全是零**，它发出的包与目标收到的包一致 —— 说明丢包既不发生在
sidecar 的收发缓冲，也不发生在 sidecar→目标这一段。

而在 **600 秒**那一轮，同一个组合的前向丢包是 3.92%。同一 harness、同一路径，90 秒 0.047%、
600 秒 3.92% —— **这部分随运行波动，不是固定比例**，而且客户端观察到的数值里还混着回程那 ~1.4%
（那一部分更可能是客户端 UDP 接收缓冲，属于量具）。

所以现在的准确表述是：

- **TCP 20 Mbps**：idle 0、零错误类，稳定达标（CV 0.88%–2.14%）。
- **UDP 前向丢包**：90 秒 0.047%、600 秒 3.92%，随运行波动；单跑 20 Mbps 时是 0.0000%。
- 要把这部分也钉死，需要给 harness 的 UDP 接收侧加接收缓冲/丢包统计，把回程与去程彻底分开 ——
  目前客户端观察值里这两者是混在一起的。
