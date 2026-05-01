# 运维交接手册: Bond 11024 / MPTCP 11023

日期: 2026-04-18

适用范围:

- `forward 26`, `bond`, 本地监听 `11024`
- `forward 25`, `mptcp`, 本地监听 `11023`

这份手册是给下一位继续排障、发布、复测的人用的。目标不是解释所有历史，而是让接手的人在 5 分钟内知道:

- 现网拓扑是什么
- 哪些已经修过
- 哪些已经上线
- 当前真正剩下的阻塞是什么
- 应该怎么构建、发布、回滚、验证

## 1. 一页摘要

当前结论:

- `plain_tcp_mux` 入口层已经打通, 不是当前主瓶颈。
- `forward 26` 的多 slave leg 已经真实建立, 不是“根本没聚合起来”。
- `forward 26` 的单线程 reverse 吞吐仍然明显偏低。
- 最新 live 症状已经收敛到 bond transport 内部 stream reset:
  - `bond tcp stream 3 local read failed: Connection reset by peer (os error 104)`
- `forward 25` 仍未修复, 主要嫌疑还是 client MPTCP endpoint convergence 和 relay EOF / half-close。

当前线上版本:

- panel `client_agent`: `2026.04.17.4`
- panel `leikwan_bond_linux_amd64`: `0.1.13`
- 当前线上 `leikwan-bond` SHA256:
  - `40652f9dd5cde8423fd6b8ac6c902f55e53beb61ea0efd676e855613f4b3cf72`

## 2. 拓扑与主机

固定拓扑:

- `client -> slave -> sidecar`

不要改成:

- `client -> sidecar`

关键主机:

- panel: `10.0.10.196`
- client: `172.21.255.189`
- sidecar point `4`: `138.252.162.120`
- Guangzhou slaves:
  - `8.163.90.132`
  - `8.163.90.235`
  - `8.163.87.42`
  - `8.163.69.176`
  - `8.163.71.73`

Sidecar 访问规则:

- 必须经 slave hop 访问。
- 不要直接从 client 改连 sidecar。

## 3. 凭据与敏感信息

凭据文件是本地权威来源, 不要把内容抄进 repo 文档:

- `leikwan-v3.txt`
- `cantons.txt`
- `shanghais.txt`

使用原则:

- 优先走 WSL / SSH。
- 只在本地读取这些文件。
- 不要把密码、token、完整凭据重新写回 repo。

## 4. 当前线上状态

### 4.1 Panel

repo 路径:

- `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`

当前已上线:

- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `artifacts/versions.json`
- `artifacts/wstunnel/leikwan-bond_linux_amd64`

当前 panel artifact:

- 版本: `0.1.13`
- SHA256: `40652f9dd5cde8423fd6b8ac6c902f55e53beb61ea0efd676e855613f4b3cf72`

### 4.2 Client

关键服务:

- `leikwan-client-agent.service`
- `leikwan-client-23.service`
- `leikwan-client-24.service`
- `leikwan-client-25.service`
- `leikwan-client-26.service`

`forward 26` 关键文件:

- `/usr/local/bin/leikwan-client-start-26.sh`
- `/usr/local/bin/leikwan-bond`
- `/usr/local/bin/leikwan-client-agent.py`

`forward 26` 当前运行特征:

- local listener `0.0.0.0:11024` 存在
- 5 条 plain TCP ingress local legs 会起在:
  - `20962`
  - `20963`
  - `20964`
  - `20965`
  - `20966`

### 4.3 Sidecar

关键服务:

- `leikwan-bond-sidecar-4.service`
- `leikwan-mptcp-sidecar-4.service`

当前绑定:

- bond: `0.0.0.0:9000`
- mptcp: `0.0.0.0:14969`

## 5. 已经修好的东西

不要重复回退这些:

1. `plain_tcp_mux` slave ingress
2. slave control-plane 调度修复
3. admin dashboard 的 `Republish All Forwards` / `Diagnostics`
4. sidecar point `bond` / `mptcp` 分离监听端口
5. `bond` runtime 里的 `join_timeout_ms` 保留逻辑
6. client-agent 的自动重启规则:
   - `Connection reset by peer (os error 104)`
   - `Broken pipe (os error 32)`
7. bullseye 构建链路

## 6. 当前未解决的问题

### 6.1 Forward 26: Bond 11024

当前不是入口层问题。

已经确认:

- slave `7001` mux 正常
- sidecar `9000` 正常
- client 当前会真实建立多条 leg
- sidecar `:9000` 上能看到多个广州 slave 的 ESTAB 连接

但 reverse 单线程测试仍然明显偏低:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -t 10
```

最新代表结果:

- sender: `500 Mbits/sec`
- receiver: `132 Mbits/sec`

最新强证据:

- `journalctl -u leikwan-client-26.service` 可见:
  - `bond tcp stream 3 local read failed: Connection reset by peer (os error 104)`
  - 后续也出现过 `stream 43`、`stream 46` 同类报错

这说明:

- 问题已经缩到 bond transport 的 stream lifecycle / control-result channel
- 不是“完全没进 bulk”
- 也不是 glibc 构建不兼容了

### 6.2 Forward 25: MPTCP 11023

仍未修复。

最强嫌疑:

- `client-agent/leikwan_mptcp_client.py`
- endpoint convergence
- stale endpoint / wrong dev cleanup
- relay EOF / half-close behavior

## 7. 这轮排障里已经确认过的事实

### 7.1 遗留污染进程

曾发现一个 unmanaged 进程:

```text
/usr/local/bin/leikwan-bond client --listen 127.0.0.1:12024 ... --forward_id 926
```

问题:

- 不在 `/var/lib/leikwan-client-agent/applied.json` 里
- 复用了 `20962` 这条本地 leg

处理:

- 已手工杀掉

结论:

- 它是真问题
- 但不是剩余单线程吞吐问题的唯一根因

### 7.2 ACK 时机

曾修过一个 deeper transport 问题:

- 以前 inbound TCP data 只要排进 local writer channel 就 ACK
- 现在改成写 socket 成功后再推进 ACK

结论:

- 这个修复是合理的
- 但单线程 reverse 仍然没恢复
- 所以它不是全部根因

### 7.3 日志可见性

已经把:

- `wstunnel-main/leikwan-bond/src/session.rs`
- `wstunnel-main/leikwan-bond/src/transport.rs`

从 `tracing` 路径切到当前真正会进 `journalctl` 的 `log/env_logger` 路径。

所以现在新增日志应该优先继续沿这个通道加。

## 8. 构建与发布基线

### 8.1 不要再用本地 WSL 直接产 Linux ELF

原因:

- 本地 WSL 之前产出的 ELF 依赖 `GLIBC_2.38`
- client / sidecar 不兼容

### 8.2 正确基线

使用 panel 主机 `10.0.10.196` 上的 `Debian 11 (bullseye)` 构建。

固定做法:

1. 先把本地完整 `wstunnel-main/leikwan-bond` crate 同步到 panel
2. panel 上使用固定工具链:
   - `rustup 1.93.0`
3. 在 panel 上执行:

```bash
export HOME=/root
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3/wstunnel-main
/root/.cargo/bin/cargo test -p leikwan-bond
/root/.cargo/bin/cargo build --release -p leikwan-bond
cp target/release/leikwan-bond /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_linux_amd64
```

### 8.3 当前兼容产物

当前线上兼容 SHA:

- `40652f9dd5cde8423fd6b8ac6c902f55e53beb61ea0efd676e855613f4b3cf72`

版本号:

- `0.1.13`

### 8.4 发布时注意

不要热替换正在运行的 `/usr/local/bin/leikwan-bond`。

正确顺序:

1. 上传到 `/root/leikwan-bond.new`
2. 停服务
3. `mv /root/leikwan-bond.new /usr/local/bin/leikwan-bond`
4. `chmod 755 /usr/local/bin/leikwan-bond`
5. 启服务

否则容易遇到:

- `Text file busy`

## 9. 当前推荐的验证命令

### 9.1 Client

检查服务与监听:

```bash
systemctl is-active leikwan-client-agent.service
systemctl is-active leikwan-client-26.service
ss -ltnp | grep ':11024'
ps -ef | grep '[l]eikwan-bond client'
```

看关键日志:

```bash
journalctl -u leikwan-client-26.service --no-pager -n 200
```

反向单线程压测:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -t 10
```

### 9.2 Sidecar

通过 slave hop:

```bash
systemctl is-active leikwan-bond-sidecar-4.service
ss -tnp | egrep ':9000'
journalctl -u leikwan-bond-sidecar-4.service --no-pager -n 200
```

### 9.3 Slave

至少检查跳 sidecar 的那台:

```bash
systemctl is-active leikwan-slave-agent
systemctl is-active leikwan-tcp-mux-7001
ss -ltn | grep :7001
```

## 10. 禁止事项

不要做:

- 不要把拓扑改成 `client -> sidecar`
- 不要把 `plain_tcp_mux` 回退回 `7000 / wss_path_mux`
- 不要删除 admin dashboard 恢复项
- 不要把凭据抄进 repo 文档
- 不要再用本地 WSL glibc 直接发 Linux artifact
- 不要在服务运行中直接覆盖 `/usr/local/bin/leikwan-bond`

## 11. 现有备份

主备份目录:

- panel: `/root/codex-backups/20260417-2308-bond-multi-health/panel`
- client: `/root/codex-backups/20260417-2308-bond-multi-health/client`
- sidecar: `/root/codex-backups/20260417-2308-bond-multi-health/sidecar`

额外源码备份:

- `/root/codex-backups/20260417-2345-bullseye-build-src`
- `/root/codex-backups/20260417-2355-bullseye-full-crate`

## 12. 下一步建议

下一刀不要再先猜参数。

优先做:

1. 围绕 `stream_id` 增加更细日志:
   - `StreamOpen`
   - `StreamOpenAck`
   - `Ack`
   - `Fin`
   - `Reset`
   - `LocalReadFailed`
   - `LocalWriteFailed`

2. 重新 bullseye 编译并发布

3. 只跑这一条:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M -t 10
```

4. 对照 `stream 3` 或新的 stream id 在 client / sidecar 日志中的完整生命周期

当前最值得验证的问题不是“有没有多腿”, 而是:

- 到底是哪一个 stream 被 reset
- 是数据流被 reset, 还是 iperf 控制/结果流被 reset
- reset 是 client 本地触发, 还是 sidecar / 对端先触发

## 13. 交接结论

一句话版本:

- 入口层已经通了, bullseye 构建链路已经通了, 自动重启规则已经上线了。
- 当前剩余主问题是 `forward 26` 在 reverse 单线程下仍然只到 `132 Mbit/s` 左右, 并伴随 bond transport stream reset。
- 下一个人应该直接围绕 `stream reset` 做更细 lifecycle 日志, 不要再从 ingress 层重查。

## 14. 2026-04-18 后续进展补充

这一节记录 2026-04-18 本次接手后的新增结论。和前文不一致时, 以后续补充为准。

### 14.1 `forward 26` 的多腿聚合已经显著恢复

本次新增确认:

- `forward 26` 不是“没有进 bulk”, 也不是“没有多腿”
- 在打开 `RUST_LOG=debug` 后, 已经直接看到大流 `stream 3` / `stream 8` 进入:
  - `Sticky -> Warmup`
  - `Warmup -> Bulk`
- 并且 chunk 已经真实分发到 5 条 leg, 不是单腿伪聚合

关键根因缩小为:

- 乱序场景下, `bond` transport 对 pending window 的重传过于激进
- ACK 仍在前进时也会被误判为超时, 导致大面积重复重传和 cooldown
- 这会把有效吞吐压低到 `~166-176 Mbit/s`

### 14.2 已上线的 `leikwan-bond` 变更

本次对 `wstunnel-main/leikwan-bond/src/session.rs` 做了两轮修复, 并按 bullseye 基线重新构建、发布到 client + sidecar。

本次新增回归测试:

- `retransmit_candidates_wait_for_ack_stall`
- `retransmit_candidates_cap_to_oldest_gap_chunks`
- `late_inbound_data_is_ignored_after_writer_shutdown`
- `connection_reset_after_writer_shutdown_is_benign_close`

本次已验证通过:

```bash
cargo test -p leikwan-bond
```

bullseye 构建产物当前线上 SHA:

- `79030feb0e17e3994bf8232315e275f5aebb7e5e67923b52b7e56c8259410b37`

注意:

- 本地还编出过一版更新的候选 SHA:
  - `748ef536e5545e612bb3f39af1d1788ab172d20ca1c472272965f2d1f749508b`
- 这版已经在 panel bullseye 上编译通过, 但因为远端分发临时文件路径问题, 当时没有真正切到 client / sidecar 现网
- 所以如果下一位要继续沿 `stream 2` / half-close 收尾推进, 需要重新把这版或后续新版本明确发布到现网, 不要误以为已经生效

### 14.3 `forward 26` 最新实测结果

#### `-b 400M`

代表结果:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -t 10
```

本次接手后已经多次跑到:

- `360 Mbits/sec`
- `378 Mbits/sec`
- `390+ Mbits/sec` 的 8 秒窗口

代表性稳定结果:

- sender: `400 Mbits/sec`
- receiver: `370-378 Mbits/sec`

#### `-b 600M`

关键结果:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 600M -t 10
```

本次 fresh verification 已经直接跑到:

- sender: `600 Mbits/sec`
- receiver: `399 Mbits/sec`

随后又跑到:

- sender: `600 Mbits/sec`
- receiver: `405 Mbits/sec`

这说明:

- “永远上不去 400M”已经不成立
- 当前 `11024` 在 `-b 600M` 条件下已经能实测超过 `400 Mbit/s`

### 14.4 为什么会 jitter, 为什么 400M 左右会抖

本次新增确认:

- sidecar 直连 target `193.227.109.82:5201` 时:
  - `iperf3 -R -b 600M -t 10`
  - receiver 可稳定到 `603 Mbits/sec`
- 所以瓶颈不在 sidecar -> target

同时确认:

- client 直连 target 时控制流本身仍会异常
- `11024` 经过 bond 时 CPU 没有打满:
  - `leikwan-bond` 进程压测时大约 `40%-50% CPU`
- 所以当前 jitter 不是“client CPU 跑满”

当前更可信的解释是:

- 数据腿聚合已经基本恢复
- 剩余波动主要在 client 侧 transport 调度 / 控制流 / 结果流收尾
- 也就是 `bond tcp stream 2` 这类控制/结果流的 half-close 生命周期还没有完全收干净

### 14.5 `forward 26` 自动重启为什么以前没触发, 现在为什么恢复了

本次新增确认:

- `leikwan-client-26.service` 的 unit 本身一直是:
  - `Restart=always`
- 但 `bond tcp stream N local read failed` 只会报单 stream 错误
- `leikwan-bond` 主进程本身不退出时, systemd 不会触发重启

所以以前“没有自动重启”的真根因是两层:

1. `forward 26` 只报 stream 级别错误, 不触发主进程退出
2. 兜底的 `leikwan-client-agent.service` 当时被手动 stop 掉了, 且 bond 日志匹配规则过窄

本次已修复:

- `client-agent/client_agent.py`
- `service_status_matches_bond_restart_rule(...)`

修复点:

- 从只能匹配 `"warn bond tcp stream"` 改成匹配真实现网 `journalctl` 里会出现的:
  - `bond tcp stream`
  - `Connection reset by peer (os error 104)`
  - `Broken pipe (os error 32)`

本次现网已验证:

- `leikwan-client-agent.service` 重新拉起后, 在 `forward 26` 再次出现同类报错时:
  - agent 确实执行了:
    - `restarted leikwan-client-26 due to runtime health check: bond status matched reset warning rule`
- `leikwan-client-26.service` 被真实 stop/start 了一次

### 14.6 `client-agent-update` 缺失问题已修复

之前 client 上长期有:

```text
update helper missing: /usr/local/bin/leikwan-client-agent-update
```

本次新增确认:

- 不是只有“缺文件”这么简单
- 真根因包含三层:
  1. client 机上正式 helper 缺失, 只剩 `.bak`
  2. panel 没暴露 `/artifacts/client-agent-update.sh`
  3. 自更新时 installer / helper 存在热覆盖自己导致的:
     - `curl: (23) Failure writing output to destination`

本次已修改:

- `install/client-agent-update.sh`
- `install/install-client-agent.sh`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Bootstrap.php`
- `artifacts/versions.json`

本次新增测试:

- `client-agent/tests/test_client_agent_installer_helper.py`
- `master/tests/client_agent_update_artifact_regression_test.php`

本次现网已验证:

- 手工执行 panel 上新的:
  - `/artifacts/install-client-agent.sh`
- client 端成功返回:
  - `installed leikwan-client-agent`
  - `EXIT:0`
- 当前 `agent_update_state.json` 已恢复为:
  - `status: success`

### 14.7 当前现网状态总结

截至本次交接结束:

- `forward 26`:
  - 现网 `leikwan-bond` SHA 仍是:
    - `79030feb0e17e3994bf8232315e275f5aebb7e5e67923b52b7e56c8259410b37`
  - `client-agent` 自动重启链路已恢复
  - `11024` 在 `-b 600M` 下已经实测超过 `400 Mbit/s`
- `client-agent-update`:
  - 现网 helper 已补上
  - 手工自更新链路已跑通

### 14.8 下一步建议

当前最值得继续做的事, 已经不是再调 `warmup_trigger` / `bulk_promote` 这些参数。

下一位建议直接做:

1. 围绕 `bond tcp stream 2` 这类控制/结果流继续补 half-close 生命周期日志和收尾逻辑
2. 重新把本地已编过但未真正部署的候选版本重新发布到 client + sidecar
   - 当前最接近这条线的是:
     - `748ef536e5545e612bb3f39af1d1788ab172d20ca1c472272965f2d1f749508b`
3. 继续用这两条命令做 fresh verification:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -t 10
iperf3 -c 127.0.0.1 -p 11024 -R -b 600M -t 10
```

当前判断:

- 参数层面的收益已经接近天花板
- 后续要继续追更高稳定吞吐, 主要靠把 control/result stream 的 close path 修干净

### 14.11 `forward 26` 新增根因确认: sidecar 之前会在 backlog 未排空时过早发 `Fin`

本次围绕 `forward 26` 单独继续排障, 已经把 `bond` transport 的 close-path lifecycle 日志真正打到现网可见级别。

新增本地测试:

- `stream_lifecycle_snapshot_reports_close_relevant_fields`
- `close_lifecycle_ack_logging_only_when_stream_is_closing`
- `arm_transport_stream_close_deadline_returns_true_once`
- `local_eof_waits_for_buffered_data_before_sending_fin`

新增中间日志版 SHA:

- `05ecff1b0042d644a35ff5bbc1c4277232666fdb29cd6dc95a1b0fafc37a1102`

通过这版日志, 已经直接确认一个真实根因:

- sidecar 在 `stream 2` / `stream 4` 上, 一旦读到 `local_eof`, 以前就会立刻 `send_fin`
- 但那一刻:
  - `backlog` 仍然很大
  - `pending_chunks` 仍然很多
  - `pending_bytes` 仍卡在 `16777216`
- 并且 `send_fin` 之后 `outbound_next` 还会继续往前增长

这说明旧逻辑里:

- `Fin seq` 被锁在了一个过早的 offset
- 但 sidecar 还在继续把更后面的数据发给 client
- client / sidecar 对这个 stream 的收尾 offset 就会错位

这不是猜测, 是现网日志直接看到的。

### 14.12 已上线修复: `local_eof` 后必须等 backlog / pending 全排空再发 `Fin`

本次已修改:

- `wstunnel-main/leikwan-bond/src/session.rs`

修复点:

- 原先 `flush_tcp_stream_backlogs(...)` 里只要 `state.local_eof == true` 就可能发 `send_fin`
- 现在改成必须同时满足:
  - `state.local_backlog.is_empty()`
  - `state.pending_chunks.is_empty()`
- 然后才允许发送 `Fin`

本次 bullseye fresh build + deploy 后现网 SHA:

- `bc07def04367dffeb53f385eb7e53f24d07420247acbfa9917203681d2780b4c`

本次 bullseye fresh verification:

```bash
cargo test -p leikwan-bond
```

结果:

- `16 passed`
- `0 failed`

### 14.13 修复后的现网结果: “过早 `Fin`” 已压住, 但 `forward 26` 仍未完全恢复

本次 fresh verification:

#### `-b 400M`

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -t 10
```

结果:

- sender: `400 Mbits/sec`
- receiver: `335 Mbits/sec`

#### `-b 600M`

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 600M -t 10
```

结果:

- sender: `600 Mbits/sec`
- receiver: `354 Mbits/sec`

修复后的关键现象变化:

- sidecar 在 `stream 2` 上:
  - 仍然会先出现 `local_eof`
  - 但之后不再立刻 `send_fin`
  - 而是继续看到大量 `recv_ack`, 说明 backlog drain 确实还在继续
- 这说明“backlog 未排空就过早发 `Fin`”这一层问题已经被压住了

### 14.14 新的剩余主线: client 侧 `local_read_failed` 仍然先发生, 且当时还没有 remote `Fin`

修复后仍然能直接看到:

- client:
  - `bond tcp stream 2 local read failed: Connection reset by peer (os error 104)`
  - 下一次压测里也出现过 `stream 4` 同类报错

而在这些时刻, client 日志里的 state 关键点是:

- `fin_offset=None`
- `outbound_next=37`
- `inbound_next` / `inbound_acked` 已经很大

这说明当前更接近的剩余问题已经不是“sidecar 过早发 `Fin`”了, 而是:

- client 本地 peer / control-result stream 在 remote `Fin` 之前就先 reset / 断开
- client 因此先发送 `local_read_failed -> Reset`
- sidecar 随后会看到大量:
  - `recv_reset stream=4 ... reason=local_read_failed`

当前新的最强判断:

- `forward 26` 的 close-path 里, 第一层 bug 已经修掉
- 剩余主因更像是 client 侧 control/result stream 的本地 peer 生命周期, 或 iperf 反向场景里的 client-local half-close / reset 语义
- 下一位不应该再从“sidecar 过早 `Fin`”这一层重新查起

### 14.9 `748ef...` 候选版已真正发布到现网, 并完成 fresh verification

本次继续交接已实际执行:

1. 在 panel `10.0.10.196` 的 bullseye 环境重新执行:

```bash
export HOME=/root
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3/wstunnel-main
/root/.cargo/bin/cargo test -p leikwan-bond
/root/.cargo/bin/cargo build --release -p leikwan-bond
cp target/release/leikwan-bond /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_linux_amd64
```

2. fresh 构建结果再次落到:

- SHA256: `748ef536e5545e612bb3f39af1d1788ab172d20ca1c472272965f2d1f749508b`

3. 已按安全顺序真正切到 client + sidecar 现网:

- 先备份当前 live `79030feb0e17e3994bf8232315e275f5aebb7e5e67923b52b7e56c8259410b37`
- client 备份:
  - `/root/codex-backups/20260418-ops-handoff-final/client/leikwan-bond.pre-748ef`
- sidecar 备份:
  - `/root/codex-backups/20260418-ops-handoff-final/sidecar/leikwan-bond.pre-748ef`
- 然后按:
  - 上传 `/root/leikwan-bond.new`
  - `systemctl stop ...`
  - `mv /root/leikwan-bond.new /usr/local/bin/leikwan-bond`
  - `chmod 755 /usr/local/bin/leikwan-bond`
  - `systemctl start ...`

4. 发布后实锤:

- panel artifact SHA:
  - `748ef536e5545e612bb3f39af1d1788ab172d20ca1c472272965f2d1f749508b`
- client `/usr/local/bin/leikwan-bond` SHA:
  - `748ef536e5545e612bb3f39af1d1788ab172d20ca1c472272965f2d1f749508b`
- sidecar `/usr/local/bin/leikwan-bond` SHA:
  - `748ef536e5545e612bb3f39af1d1788ab172d20ca1c472272965f2d1f749508b`
- `leikwan-client-26.service`: `active`
- `leikwan-bond-sidecar-4.service`: `active`
- `leikwan-mptcp-sidecar-4.service`: `active`
- slave `8.163.90.132` 上:
  - `leikwan-slave-agent`: `active`
  - `leikwan-tcp-mux-7001`: `active`

5. fresh verification 结果:

#### `-b 400M`

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -t 10
```

本次 fresh 结果:

- sender: `400 Mbits/sec`
- receiver: `306 Mbits/sec`

#### `-b 600M`

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 600M -t 10
```

本次 fresh 结果:

- sender: `600 Mbits/sec`
- receiver: `371 Mbits/sec`

6. 本次发布后的现网观测:

- sidecar 仍能看到 `bond tcp stream 2` 进入:
  - `Sticky -> Warmup`
  - `Warmup -> Bulk`
- 说明多腿聚合/升档路径仍在工作
- 但 fresh `400M` / `600M` 结果都还没回到这份手册前文里提过的最佳窗口
- 本次发布后的 `leikwan-client-26.service` 日志采样窗口内, 没有再直接截到新的:
  - `bond tcp stream N local read failed`
  - `bond tcp stream N local write failed`

### 14.10 新发现: `leikwan-client-agent.service` 仍有常驻性问题

这不是本次 `748ef...` bond runtime 发布本身的失败, 但它是 fresh verification 里额外暴露出的独立问题。

当前现象:

- `systemctl start leikwan-client-agent.service` 后, 服务会短暂进入 `active`
- 随后在日志里执行到:
  - `applying forward 23 mode=bond ...`
  - `applying forward 24 mode=mptcp ...`
- 然后 unit 变回:
  - `inactive (dead)`

本次直接观察到的一个样本:

- `2026-04-18 10:37:56` 左右, agent 启动后执行到 `applying forward 24 mode=mptcp ...`
- 随后 systemd 记录:
  - `Stopping leikwan-client-agent.service`
  - `Deactivated successfully`

当前判断:

- `forward 26` 的 bond runtime 二进制已经切到了 `748ef...`
- 但 `client-agent` 本身并没有稳定常驻
- 所以下一位如果要继续依赖:
  - 自动重启
  - 自动自更新
  - 自动应用 forward 变更
- 需要单独排查为什么 agent 在处理 `forward 24` / mptcp 路径时退出
### 14.15 已上线修复: client 侧 non-benign stream failure 会主动打掉 persistent transport session

本次继续围绕用户反馈的:

- “有时要手动 `systemctl restart leikwan-client-26` 才恢复连接”

继续排查后, 新确认一个更贴近现象的机制问题:

- `run_client_tcp_listener(...)` 会长期复用一个 persistent TCP transport session
- 旧逻辑里即使某个 stream 已经出现:
  - `local_read_failed`
  - `local_write_failed`
- 整个 client transport session 仍可能继续存活
- 下一次新连接就有机会继续复用这个已经“脏掉”的旧 session

本次新增本地测试:

- `client_stream_failure_aborts_persistent_transport`
- `aggregator_stream_failure_does_not_abort_transport`
- `benign_close_does_not_abort_persistent_transport`

本次已修改:

- `wstunnel-main/leikwan-bond/src/session.rs`

新策略:

- 只在 `SessionRole::Client`
- 并且 stream failure 不是 benign close-path 场景时
- 遇到:
  - `local_read_failed`
  - `local_write_failed`
- 就直接让当前 persistent transport session 返回错误退出

这样:

- 当前坏掉的 transport session 会被主动打掉
- listener 在下一次新连接进入时会自动重建新的 transport session
- 不再依赖手工 `systemctl restart leikwan-client-26`

本次 bullseye fresh build + deploy 后现网 SHA:

- `23fcd3268d273d86686156626371d95eaae61a47447822c8c2bb6a89af7f2ea0`

本次 bullseye fresh verification:

```bash
cargo test -p leikwan-bond
```

结果:

- `19 passed`
- `0 failed`

### 14.16 针对“必须手工重启才能恢复”的 fresh verification

本次直接做了不重启 service 的连续验证。

第一轮:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 600M -t 5
```

结果:

- sender: `600 Mbits/sec`
- receiver: `332 Mbits/sec`

随后该轮 data stream failure 发生时, client 日志直接出现:

- `bond client TCP transport 019d9e98-... failed: client transport stream 2 failed with local_read_failed; forcing transport reconnect`

紧接着就出现:

- `bond client TCP transport session established`

并且新的 session id 变成了:

- `019d9e99-...`

sidecar 同时也能看到新的 transport session:

- `bond TCP transport 019d9e99-... started with 5 leg(s) toward 193.227.109.82:5201`

这说明:

- 坏掉的 persistent transport session 已经被自动销毁
- 新 session 已经自动建立
- 这一层恢复已经不再依赖手动 restart service

随后不重启 service, 只等待短暂间隔后再次执行:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 600M -t 5
```

结果:

- sender: `600 Mbits/sec`
- receiver: `368 Mbits/sec`

注意:

- 如果在上一次 iperf 刚结束后“立刻”再次执行, 可能会先看到:
  - `iperf3: error - the server is busy running a test`
- 这是 iperf 自己的 test-busy 窗口, 不是 `leikwan-client-26` 没恢复
- 等几秒后再跑, 在当前这版 `23fcd...` 上已经可以无需手动 restart 直接重新连上

## 15. 当前 Bond 聚合之道 / Panel 对接 / Slave 配合

这一节不是新增排障结论, 而是把当前已经跑通的 `forward 26` 生产链路记成“可复用知识”。

### 15.1 当前 `bond` 聚合的真实数据路径

当前 `forward 26` 的 TCP 聚合链路是:

```text
local app
  -> 127.0.0.1:11024 (`leikwan-bond client`)
  -> 5 条本地 leg (`127.0.0.1:20962-20966`)
  -> 5 个 `leikwan-ingress client`
  -> 5 台 slave 的 `plain_tcp_mux :7001`
  -> slave 按 route-key / auth-token 转发到 sidecar
  -> sidecar `leikwan-bond server :9000`
  -> target `193.227.109.82:5201`
```

当前 5 条实际生产腿:

- `20962 -> 8.163.90.132:7001`
- `20963 -> 8.163.90.235:7001`
- `20964 -> 8.163.87.42:7001`
- `20965 -> 8.163.69.176:7001`
- `20966 -> 8.163.71.73:7001`

当前确认:

- 真正的聚合发生在 `leikwan-bond client/server` 的 stream/chunk 调度层
- slave 不是 MPTCP 端点, 也不是“第二层聚合器”
- slave 只是每条 leg 的 ingress / relay
- sidecar 才是 5 条腿重新汇聚到 target 的那一端

### 15.2 当前聚合表现应该如何理解

当前现网 5 腿下:

- 第 1 秒可以瞬时冲到 `480M-600M+`
- 随后通常会回落到 `330M-410M`
- 现在已经确认:
  - 不是 sidecar 算力不够
  - 不是 5 腿没建立
  - 不是 `bond` 完全没进 `Warmup/Bulk`

当前更接近的理解是:

- 5 条腿都会参与
- 但 steady-state 的总可用容量目前大约就在 `300M-400M` 这一档
- 继续在 `bond` 参数层硬调, 收益已经不大
- 真要稳定 `500M+`, 更像是要:
  - 换更强的腿
  - 或增加腿数
  - 或混用更强地域/运营商的 slave

### 15.3 Panel 如何把 `bond` 描述下发给 client

当前最关键的 panel 入口是:

- `master/src/Support/ProvisioningService.php`
  - `buildClientConfig(...)`

`forward 26` 这类 `client_mode = bond` 且 `bond_ingress_mode = plain_tcp_mux` 的配置, panel 会下发三类关键信息:

1. sidecar 直达目标

- 由 sidecar point 解析出:
  - `bond_remote_host`
  - `bond_remote_port`
- 对当前 point `4`, 生产值就是:
  - host = `138.252.162.120`
  - port = `9000`

2. client -> slave ingress 描述

- 对每个 endpoint 下发:
  - slave host
  - slave port
  - `ingress_protocol = plain_tcp_mux`
  - `backend_protocol = tcp_relay`
  - `ingress_auth_token`
  - `route_key`

3. `bond` 自身运行参数

- `bonding.session_token`
- `bonding.udp_session_token`
- `bonding.remote_target`
- `bonding.ingress_mode`
- 以及一组调度参数:
  - `join_timeout_ms`
  - `interactive_chunk_bytes`
  - `latency_guard_bytes`
  - `warmup_trigger_bytes`
  - `warmup_slice_bytes`
  - `warmup_leg_delay_ms`
  - `bulk_promote_bytes`
  - `leg_cooldown_ms`

当前对接细则:

- `bond` 模式下, panel 必须选 sidecar point
- 当前真正生效的 sidecar 监听口是 `bond_listen_port`, 不是 `mptcp_listen_port`
- `plain_tcp_mux` 的 slave ingress 端口必须继续限制在 `7000-9000`
- 不要把 topology 改成 `client -> sidecar`

### 15.4 Client 如何把 panel 配置落成运行进程

当前 client 侧最关键文件:

- `client-agent/install_forward_runtime.py`
- `client-agent/client_agent.py`

`install_forward_runtime.py` 在 `bond + plain_tcp_mux` 下会做这些事:

1. 为每个 endpoint 启一个 `leikwan-ingress client`

形态类似:

```bash
/usr/local/bin/leikwan-ingress client \
  --listen 127.0.0.1:20962 \
  --remote 8.163.90.132:7001 \
  --route-key <route_key> \
  --auth-token <ingress_auth_token>
```

2. 再起一个总的 `leikwan-bond client`

形态类似:

```bash
/usr/local/bin/leikwan-bond client \
  --protocol tcp \
  --listen 0.0.0.0:11024 \
  --legs 'tcp://127.0.0.1:20962?priority=10,...,tcp://127.0.0.1:20966?priority=10' \
  --session-token <bond_session_token> \
  ...
```

3. 对 `forward 26` 而言, 当前只关注 TCP runtime

原因:

- `plain_tcp_mux` 下当前 client runtime 会 `skip_udp_runtime`
- 所以现网这条生产聚合路径本质上还是:
  - `bond TCP only`
- 当前 UDP 转发尚未支持, 不要把 `11024` 的 TCP 压测表现外推成“UDP 也已打通”

### 15.5 Slave 在这条链路里到底负责什么

当前 slave 侧最关键文件:

- `slave-agent/slave_agent.py`

当 panel 下发:

- `ingress_protocol = plain_tcp_mux`

时, slave-agent 会:

1. 渲染 `plain_tcp_mux` 监听 unit
2. 在共享端口 `7001` 接收来自 client 的 leg
3. 用:
  - `route_key`
  - `ingress_auth_token`
- 验证这条 leg 属于哪条 route
4. 再把这条 leg 转发到对应的 sidecar target

当前要点:

- slave 是“每条腿的 ingress / relay”, 不是最终业务端点
- 一条入站 leg 对应一条到 sidecar 的出站 TCP 连接
- 这就是为什么 sidecar 上会看到来自 5 台广州 slave 的 5 条 `ESTAB`

### 15.6 现在继续做 `bond` 研发时的正确边界

应该继续保持:

- `client -> slave -> sidecar`
- slave ingress 端口在 `7000-9000`
- sidecar 通过 slave hop 访问

不要回退:

- 不要把 `plain_tcp_mux` 再改回 `wss_path_mux`
- 不要为了省事改成 `client -> sidecar`
- 不要把当前 `bond` steady-state 不到 `500M` 误判成“5 腿没建立”

如果下一位继续追 `500M+`, 当前最值钱的方向是:

1. 验证更强 slave 组合的 steady-state 总容量
2. 让 panel 支持更可控的 slave 组合 / 分组实验
3. 明确区分:
   - `bond` 调度问题
   - 当前这 5 条腿的真实链路容量上限

## 16. 2026-04-18 后续补充: 发放链修复与 noon 偶发中断结论

### 16.1 当前 panel 发放面状态

截至本次补充:

- panel 已经对外发放最新 `leikwan-bond` Linux artifact
- 当前 panel artifact SHA:
  - `d9eedc2a3df4680a59465994288b00869e896b77ee004691044e656d015013e0`

panel 已补齐的关键发放路径:

- `/artifacts/install-client-agent.sh`
  - 现在会直接下载 `leikwan-bond_linux_amd64`
- `/artifacts/client-agent-update.sh`
  - 现在会先停掉活动的 `leikwan-client-*.service`
- `/artifacts/install-bond-sidecar.sh`
  - 现在会下载 `sidecar-agent-update.sh`
- `/artifacts/sidecar-agent-update.sh`
  - 已经新增并注册到 panel 路由

当前 `versions.json` 关键值:

- `client_agent_installer = 2026.04.18.2`
- `bond_sidecar_installer = 2026.04.18.2`
- `sidecar_agent = 2026.04.18.1`

### 16.2 新装 / 旧装结论

新装:

- `slave`
  - 可以直接参与当前 `plain_tcp_mux` 链路
- `sidecar`
  - 可以直接拿到当前的 `leikwan-bond` 和 sidecar update helper
- `client`
  - 现在也会直接拿到当前 `leikwan-bond`

旧装:

- `slave`
  - 原本就有完整自动更新链
- `client`
  - 现在自动更新链已经能真正把 `bond` bin 更新下来
- `sidecar`
  - 现在有完整代码链路
  - 但极旧主机第一次仍可能需要人工 bootstrap 一次新 `sidecar_agent.py` 和 `/usr/local/bin/leikwan-sidecar-update`

### 16.3 noon 偶发中断的根因

本次单独回看 `2026-04-18 12:00-12:10 CST` 的 client 日志后, 看到的不是 service restart, 也不是 panel update 动作。

client 在这段时间的核心症状是:

- stream `8-15` 一类本地连接
- 在 `StreamOpenAck` 到来之前就已经 `local_eof`
- 随后 runtime 持续:
  - `send_stream_open`
  - `resend_stream_open`
- 一直卡在 `open_ack_pending`

这说明当时有一类“本地很快连上又很快关闭”的连接, 在 client 侧被留成了 zombie stream。

sidecar 同一时间窗 (`04:00-04:10 UTC`) 没有对应的合法 stream-open 生命周期日志, 只有:

- `invalid bond frame magic`
- `timed out waiting for hello frame`

而且来源是外部 IP:

- `3.134.216.108`

所以 noon 那次偶发中断的主因不是 sidecar 自更新, 而是:

- client 对 `local_eof before open-ack` 的 stream 清理不干净
- 导致一批未成功打开的 stream 在 transport 里无限重发 `StreamOpen`

### 16.4 noon 根因的修复

本次已在:

- `wstunnel-main/leikwan-bond/src/session.rs`

增加新的行为:

- 对 `SessionRole::Client`
- 如果某个 stream:
  - 还没 `opened`
  - 已经 `local_eof`
  - `local_backlog` 为空
  - `pending_chunks` 为空
  - 且等待超过 `connect_timeout`
- 就直接放弃这个 unopened stream
- 不再无限 `resend_stream_open`

新增测试:

- `unopened_local_eof_stream_times_out_after_connect_timeout`
- `unopened_local_eof_stream_does_not_timeout_while_data_is_pending`

本次已按 bullseye fresh build + deploy 到 panel / client / sidecar:

- SHA `d9eedc2a3df4680a59465994288b00869e896b77ee004691044e656d015013e0`

### 16.5 另一个独立问题: sidecar 自更新误触发循环

本次还额外修掉了一个容易和 noon 中断混淆的独立问题:

- `master/src/Controllers/SidecarAgentController.php`

之前这里会错误地用:

- `sidecar_points.agent_version`

去生成 sidecar 的 bond auto-update token。

但这个字段其实是 sidecar agent 版本, 不是 bond bin 的 SHA。

结果:

- sidecar 会被误判成一直需要更新
- 出现:
  - `auto-sidecar-bond:sha256=...`
  - `auto-release:agent=2026.04.18.1`
- 两类 token 交替触发的 update loop

这会导致:

- `leikwan-bond-sidecar-4-agent.service` 周期性 stop/start
- 并可能顺带打断 sidecar runtime

现在 panel 已修复:

- 不再自动从 `point.agent_version` 伪造 bond update token
- 显式 `agent_update_request_token` 仍保留
- `desired_bond_sha256` 仍正常下发, 交给 sidecar 本地比对

### 16.6 当前状态总结

截至这次补充结束:

- panel 发放链已经补齐
- 新装 client/slave/sidecar 都能接到当前 `bond` 生产链
- 旧 client/slave 会自动追新
- 当前 sidecar host 已完成一次 bootstrap, 后续可走新 update 链
- noon 那批 `open_ack_pending` zombie stream 已经有代码级修复
- sidecar 误触发 update loop 也已从 panel 侧掐掉

仍未解决:

- `forward 26` steady-state 吞吐仍未稳定到 `500 Mbit/s+`
