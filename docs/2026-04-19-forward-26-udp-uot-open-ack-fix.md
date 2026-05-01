# 2026-04-19 forward 26 UDP UoT open-ack fix

日期: 2026-04-19

范围:

- `forward 26`
- mode: `bond`
- local listen: `11024`
- topology: `client -> slave -> sidecar`

## 结论

`client-agent/leikwan_uot_bond.py` 的 `open_plain_tcp_leg()` 在发送

`LEIKWAN-INGRESS 1 OPEN <route> <token>\n`

之后，没有先消费 plain TCP mux 返回的 `OK\n`，就直接把连接交给 framed UoT 读循环。

这会把 `OK\n` 残留在 reader 里，导致后续 `readexactly(4)` 把 `OK\n` 当作 frame length 前缀的一部分解析，出现：

- `3 bytes read on a total of 4 expected bytes`
- `0 bytes read on a total of 4 expected bytes`
- 部分 legs 长时间起不来，sidecar `8999` fan-in 少于 5

## 根因证据

### 1. 代码证据

修复前 `open_plain_tcp_leg()` 只做了：

- 建立到 slave `7001` 的 TCP 连接
- 写入 `OPEN` 行
- `drain()`
- 直接返回 `reader, writer`

但 slave `leikwan-ingress mux` 的协议实现会先返回：

- `OK\n`

然后才进入双向 relay。

### 2. 现场证据

修复前现场同时满足：

- client `ss` 能看到 5 条 `python3 -> slave:7001` UoT legs
- sidecar `ss -tnp | grep 8999` 经常只有 3 条 slave backend legs
- client journal 出现：
  - `udp-uot leg down endpoint=186 error=3 bytes read on a total of 4 expected bytes`

### 3. 定点探针证据

对缺腿 slave 手工发：

- `LEIKWAN-INGRESS 1 OPEN a43547b6350f72cd.uot <auth>\n`

可以稳定拿到：

- `OK`

且 sidecar `8999` 会立刻出现对应 slave 的 `ESTAB`，说明：

- slave `.uot` route 正常
- slave -> sidecar `8999` backend 正常
- 问题在 client UoT OPEN 握手消费 `OK\n` 的位置

## 本地修复

修改:

- `client-agent/leikwan_uot_bond.py`
- `client-agent/tests/test_uot_bond_runtime.py`

修复内容:

- `open_plain_tcp_leg()` 现在会先 `reader.readline()`
- 仅在返回值为 `OK\n` / `OK\r\n` 时才认为 OPEN 成功
- 非 `OK` 响应直接报错，避免把文本握手残留交给二进制 frame reader

## 本地验证

执行:

- `python -m unittest client-agent.tests.test_uot_bond_runtime -v`
- `python -m py_compile client-agent/leikwan_uot_bond.py client-agent/tests/test_uot_bond_runtime.py`

结果:

- 新增 regression test 先 red，再 green
- 当前测试文件全绿
- Python 语法检查通过

## 现场发布

已做最小 live patch：

- client `/usr/local/bin/leikwan-uot-bond.py`
- panel repo `client-agent/leikwan_uot_bond.py`

并备份到：

- client: `/root/codex-backups/udp-uot-ackfix-20260419/leikwan-uot-bond.py.pre-ackfix`
- panel: `/root/codex-backups/udp-uot-ackfix-20260419/panel/leikwan_uot_bond.py.pre-ackfix`

## 现场验证

修复后 fresh evidence：

- client `leikwan-client-26-udp-uot.service` = `active`
- client `ss` 可见 5 条 `python3 -> slave:7001`
- sidecar `ss` 可见 5 条 `*:8999` slave backend legs
- sidecar `udp-uot-runtime-stats.json` 中 `live_legs = 5`

并在 client 上执行：

- `iperf3 -c 127.0.0.1 -p 11024 -R -u -b 10M -t 3`

返回：

- `rc = 0`
- receiver 约 `9.70 Mbits/sec`
- `5/116 (4.3%)` datagram loss

同时 stats 有增长：

- client `datagrams_out: 0 -> 115`
- sidecar `datagrams_out: 185926 -> 186047`
- sidecar `sessions_active: 0 -> 1`

## 当前状态

当前已经确认：

- 5 条 `client -> slave -> sidecar(8999)` UDP UoT legs 都能稳定建立
- reverse UDP payload path 可以成功跑通

如果后续还要继续追，就不该再从 “为什么只有 1~3 条 `8999` legs” 开始，而应转向：

- UDP reorder / loss 表现
- `iperf3` 语义层剩余问题（如果再次出现）
