# 2026-04-19 TCP + UDP Ops Handoff

日期: 2026-04-19

适用范围:

- `forward 26`
- mode: `bond`
- local listen: `11024`
- topology: `client -> slave -> sidecar`

这份文档给下一位继续排障 / 发布 / 回滚的人用。

目标很简单:

- 讲清楚这轮我是怎么把 `TCP + UDP` 路径接起来的
- 讲清楚哪些改动已经真正上线
- 讲清楚为什么当前结论仍然是:
  - TCP 还会时不时断流 / 控制流异常
  - UDP 依旧不通，至少还远远不算可用
- 讲清楚现在应该从哪里继续，而不是重复已经验证过的工作

## 1. 当前基线

当前 live 已经恢复到用户认可的 `best-v6`。

本地备份:

- `artifacts/backups/best-v6/leikwan-br1-best-v6-20260418-231633.zip`
- `artifacts/backups/best-v6/README.md`
- `artifacts/backups/best-v6/manifest.json`

远端备份:

- panel: `/root/codex-backups/best-v6/panel`
- client: `/root/codex-backups/best-v6/client`
- slaves: `/root/codex-backups/best-v6/slaves/<slave-ip>`
- sidecar: `/root/codex-backups/best-v6/sidecar`

当前 live `leikwan-bond` SHA256:

- panel build target: `d9eedc2a3df4680a59465994288b00869e896b77ee004691044e656d015013e0`
- client `/usr/local/bin/leikwan-bond`: `d9eedc2a3df4680a59465994288b00869e896b77ee004691044e656d015013e0`
- sidecar `/usr/local/bin/leikwan-bond`: `d9eedc2a3df4680a59465994288b00869e896b77ee004691044e656d015013e0`

当前关键服务:

- client:
  - `leikwan-client-agent.service`
  - `leikwan-client-26.service`
- sidecar:
  - `leikwan-bond-sidecar-4.service`
  - `leikwan-bond-sidecar-4-agent.service`

## 2. 现场主机

- panel: `10.0.10.196`
- client: `172.21.255.189`
- sidecar point `4`: `138.252.162.120`
- 当前 forward 26 发布的 Guangzhou slaves:
  - `8.163.90.132`
  - `8.163.90.235`
  - `8.163.87.42`
  - `8.163.69.176`
  - `8.163.71.73`

最新用户口头覆盖:

- sidecar 可以本地直连
- slave 也可以本地直连

## 3. 这轮 TCP + UDP 是怎么接起来的

### 3.1 Panel contract

当前 panel 这边已经不是只发 TCP 了。

关键点:

- `ProvisioningService::buildBondClientConfig(...)` 会发:
  - `bonding.session_token`
  - `bonding.udp_session_token`
  - `bonding.ingress_mode = plain_tcp_mux`
  - `bonding.route_key`
- forward 仍带 `transport_mode`
- endpoint 仍带:
  - `host`
  - `port`
  - `ingress_auth_token`

所以这轮不是“从零发明 UDP 协议”，而是把已经存在的 UDP contract 真正接通。

### 3.2 Client runtime generation

真正把 `TCP + UDP` 接起来的核心文件是:

- `client-agent/install_forward_runtime.py`

对于 `bond + plain_tcp_mux`，当前正确的生成形状应该是:

1. 每个 slave endpoint 起一个 `leikwan-ingress client`
2. 起一个:
   - `leikwan-bond client --protocol tcp`
3. 再起一个:
   - `leikwan-bond client --protocol udp`

也就是同一套 published slave legs 同时承载 TCP 和 UDP。

当前 `forward 26` 正确生成后的脚本关键形状是:

- TCP local legs:
  - `20962-20966`
- UDP local legs:
  - `21162-21166`
- listeners:
  - TCP `0.0.0.0:11024`
  - UDP `0.0.0.0:11024`

### 3.3 Slave runtime

slave 仍然没有变成 bond aggregator。

它做的事仍然是:

- `plain_tcp_mux` ingress
- 校验 `route_key + ingress_auth_token`
- 接腿后转给 sidecar

关键文件:

- `slave-agent/slave_agent.py`

这轮没有把 slave 改成“懂 UDP 聚合”的中间层。

### 3.4 Sidecar runtime

sidecar 仍然是唯一 reconverge 点。

关键文件:

- `sidecar-agent/sidecar_agent.py`
- `/usr/local/bin/leikwan-bond`

运行形状:

- `leikwan-bond server --listen 0.0.0.0:9000 --token-secret ...`

也就是说，client/slave/sidecar 的 TCP 与 UDP 都仍然共用这一套 bond runtime family。

## 4. 这轮实际改了哪些控制面逻辑

### 4.1 Client published-vs-live 校验

文件:

- `client-agent/client_agent.py`

做了什么:

- 增加了“panel 发布的 slave 集合”与“client 当前真实连出去的 slave 集合”的一致性校验
- 注意:
  - 最初我误判过一次，错误地拿 loopback bond-to-ingress socket 做 live 校验
  - 后来已修正为检查真实的:
    - `leikwan-ingress -> slave:7001` ESTAB 连接

这是这轮一个真实踩坑点。

### 4.2 Time sync / clock state

文件:

- `client-agent/client_agent.py`
- `slave-agent/slave_agent.py`
- `sidecar-agent/sidecar_agent.py`
- `install/install-client-agent.sh`
- `install/install-slave.sh`
- `install/install-bond-sidecar.sh`

做了什么:

- installer 增加 `timedatectl` 检查
- 缺失时自动安装 / 启动 `ntp`
- client/slave/sidecar agent 会记录本地时钟同步状态
- client/sidecar/slave 都会把 panel 响应头里的 `Date` 落到 pull-state 里

这部分已经本地回归通过，也已部署到现场。

## 5. 这轮上线与同步顺序

### 5.1 先同步控制面

先同步到 panel repo:

- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `slave-agent/slave_agent.py`
- `sidecar-agent/sidecar_agent.py`
- 三份 installer

然后同步到:

- client
- 5 台当前发布的 Guangzhou slaves
- sidecar

### 5.2 重要中途发现

这轮最关键的 live root cause 之一不是 transport 本身，而是现场有一份旧 runtime generator。

具体是:

- client 上旧的 `/usr/local/bin/install_forward_runtime.py`
- 只生成了 TCP bond runtime
- 没有生成 UDP bond runtime

症状:

- `forward 26` 会反复被 health check 重启
- 因为 UDP listener 根本没起

修复:

- 把当前 `client-agent/install_forward_runtime.py` 同步到 panel + client
- 重启 `leikwan-client-agent.service`
- 确认 `start-26.sh` 出现:
  - `--protocol tcp`
  - `--protocol udp`

这是这轮里必须记住的坑。

## 6. 现场验证结果

### 6.1 已验证正确的部分

在 `best-v6` 线上，以下几件事已经确认正确:

1. panel 发布的 slave 集合与 live 集合一致
2. client `ss -tnp` 能看到 5 个真实 `leikwan-ingress -> slave:7001` ESTAB
3. sidecar `ss -tn` 在 `:9000` 能看到 5 个来源 slave IP
4. `11024` 同时有:
   - TCP listener
   - UDP listener

### 6.2 TCP 测试结论

用户认可的 `best-v6` 代表性结果:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M
```

用户看到:

- receiver 大约 `370-377 Mbits/sec`

但 TCP 并没有完全稳住。

现场仍然会出现:

- 控制流异常关闭
- 偶发明显掉速
- `journalctl -u leikwan-client-26.service` 中仍可见:
  - `bond tcp stream ... local read failed: Connection reset by peer (os error 104)`

所以当前 TCP 的真实描述不是“稳定无问题”，而是:

- 在 `best-v6` 上可以跑出用户满意的速度
- 但仍然时不时断流 / 控制流异常

### 6.3 UDP 测试结论

基础上线后，最初 UDP 是“起来了但依旧不可用”。

代表性结果:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -u -t 10
```

观察到过的现象:

- receiver 只有大约 `76.0 Mbits/sec`
- 有明显 `out-of-order`
- 之后做了 UDP 试验性修正时，还出现过:
  - `Resource temporarily unavailable`
  - `connection refused`
  - UDP session 先起后死

所以当前应当明确写成:

- UDP 依旧不通
- 不是“完全没 listener”
- 而是“数据面 / session lifecycle / 重排语义还没正确”

## 7. 这轮做过的 UDP 试验

### 7.1 试验 A: UDP datagram_id 有序交付

文件:

- `wstunnel-main/leikwan-bond/src/session.rs`

动机:

- 之前 `UDP` 分片重组完成后就立刻往本地应用送
- 但不会保证不同 `datagram_id` 的全局有序交付
- 这能直接解释 `iperf3 -u` 的 `out-of-order`

做法:

- 增加 `UdpDeliveryBuffer`
- 按 `datagram_id` 顺序释放重组完成的数据报

本地 Rust 单测:

- `udp_delivery_buffer_releases_datagrams_in_id_order`
- `udp_delivery_buffer_ignores_already_delivered_duplicates`

结果:

- 这个修正命中了一个真问题
- `out-of-order` 现象明显缓解
- 但 UDP 仍不通，说明这不是全部根因

### 7.2 试验 B: UDP 会话级 leg affinity

动机:

- 为了进一步压重排，试过把 UDP datagram 固定走同一条 leg

结果:

- UDP 更少乱序
- 但 receiver 吞吐掉到接近 `77.7 Mbits/sec`
- 看起来更像单腿能力

结论:

- 这个试验不是最终方案
- 后来我已经撤掉

### 7.3 当前 live 不应继续保留试验版

用户后来明确要求:

- “这版特别垃圾，恢复到 best-v6”

已经执行:

- client / sidecar / panel target `leikwan-bond`
  全部回退到 `best-v6` 的同一份 binary

所以现在 live 不是实验版，而是回到 `best-v6`。

## 8. 当前最值得继续追的根因

到目前为止，最像根因的仍然是 UDP transport/session lifecycle，而不是 panel/slave 发布错误。

现场已知强信号:

- client:
  - UDP session 可能先打开，再因为
    - `no active bond legs remaining for udp session`
    提前失败
- sidecar:
  - 仍会看到一批
    - `failed to accept bond leg ... failed to read bond frame header`
    - `early eof`
- TCP/UDP 共存时，bash `wait -n` 监督模型也会把子进程异常传播成整个 `leikwan-client-26.service` 的抖动

所以如果下一位继续做，不要先去调 TCP 参数。

更应该继续看的点:

1. UDP session 为什么会在 leg 已存在的情况下报:
   - `no active bond legs remaining for udp session`
2. sidecar 为什么仍会批量看到:
   - `failed to read bond frame header`
   - `early eof`
3. `start-26.sh` 这种 `wait -n` 监督形状，是否会把 UDP 子进程波动放大成整条 forward 重启
4. 如果继续做 UDP 修正，优先顺序应该是:
   - 先修 session 生存性
   - 再修重排窗口
   - 最后才谈聚合吞吐

## 9. 不要重复踩的坑

1. 不要再把 client 现场的 `/usr/local/bin/install_forward_runtime.py` 忘了同步
2. 不要把 published-vs-live 校验写成 loopback socket 检查
3. 不要在 live 上混用不同 hash 的 `leikwan-bond`
   - client / sidecar / panel target 必须一致
4. 不要先动 TCP 参数
   - 用户已明确:
   - TCP 当前够用
   - 之后应主要调 UDP

## 10. 下一位接手建议

建议起手顺序:

1. 先确认当前 live hash 仍然是 `best-v6`
2. 先跑:
   - `ss -tnp` on client
   - `ss -tn` on sidecar
   - 核对 panel 发布 slave 集合
3. 再跑:
   - `iperf3 -c 127.0.0.1 -p 11024 -R -b 500M`
   - `iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -u`
4. 继续只追 UDP
5. 每次试验都单独 build 新 binary
6. 如果试验结果变差，立即回退到:
   - `best-v6`

## 11. 一句话交接结论

这轮已经把 `TCP + UDP` 的控制面、发布面、安装面、运行面全接起来了。

但当前真实状态仍然是:

- TCP 可以跑出用户满意速度，但仍然时不时断流
- UDP listener 已起来，published/live slave 集合也已对齐
- 但 UDP 数据面依旧不通，根因仍在 bond transport 的 UDP session lifecycle

继续做时，不要再从 panel/schema 或 TCP 参数开始，直接盯 UDP session、sidecar `early eof`、以及 UDP 子进程存活性。
