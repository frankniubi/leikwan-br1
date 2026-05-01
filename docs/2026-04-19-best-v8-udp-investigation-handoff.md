# 2026-04-19 best-v8 UDP Investigation Handoff

日期: 2026-04-19

适用范围:

- `forward 26`
- mode: `bond`
- local listen: `11024`
- topology: `client -> slave -> sidecar`
- 当前目标: 在保住当前管理面修复的前提下，继续追 reverse UDP 问题

这份文档写给下一位 AI / 工程师。

目的:

- 说明这轮到底做了什么
- 当前 live 状态到底是什么
- `best-v8` 备份在哪里
- 还剩什么问题
- 下一步最值得做什么
- 哪些坑不要再踩

## 1. 一句话结论

本轮已经把管理面修好并重新收敛:

- panel 现在可以把 `udp_uot_sidepath` 作为 authoritative config 往 client / slave / sidecar 同步
- client 不会再因为 `iperf3 -R -u` 产生的 reset warning 被 health check 直接重启 `leikwan-client-26`
- client `1`、sidecar `4`、Guangzhou slaves `4/5/6/7/8` 都已经重新回到 `2026.04.19.1`

但 UDP 问题还没结束。

当前最强的新线索是:

- client UoT runtime 认为 `live_legs = 5`
- sidecar UoT runtime 却只看到 `live_legs = 1` 或 `2`
- 只有 `8.163.90.132` 会稳定保持到 sidecar `8999` 的 backend leg

所以当前最值得继续追的点，不再是 panel sync，而是:

- 为什么 5 台 slave 的 `.uot` backend fan-in 到 sidecar `8999` 只稳定剩 1 台左右

## 2. 这轮完成了什么

### 2.1 panel 管理面修复

已修改并同步:

- `master/src/Controllers/ClientAgentController.php`
- `master/src/Controllers/NodeController.php`
- `master/src/Controllers/SidecarAgentController.php`

修复点:

- `ClientAgentController::authoritativeAgentConfig()` 现在保留并下发 `udp_uot_sidepath`
- `NodeController::authoritativeAgentConfig()` 现在保留并下发 `udp_uot_sidepath`
- `SidecarAgentController` 的 `config_patch` 现在也会带 `udp_uot_sidepath`

结果:

- panel 不会再在管理同步时把 sidepath 配置 silently 丢掉

### 2.2 agent 本地 config patch 修复

已修改并本地回归:

- `client-agent/client_agent.py`
- `slave-agent/slave_agent.py`
- `sidecar-agent/sidecar_agent.py`

修复点:

- client / slave / sidecar 都接受 `udp_uot_sidepath` 的 add / update / remove
- sidecar 以前不会通过 `config_patch` 写入 `udp_uot_sidepath`，现在会了

结果:

- 就算通过 panel management 下发，sidepath 也能真正落盘，不再只靠手工本地修改保活

### 2.3 client health check 修复

已修改并回归:

- `client-agent/client_agent.py`
- `client-agent/tests/test_client_agent_bond_endpoint_health.py`

修复点:

- 以前 `ensure_runtime_health()` 看到:
  - `Connection reset by peer (os error 104)`
  - `Broken pipe (os error 32)`
  这种 bond stream warning，就会直接重启 `leikwan-client-26`
- 现在不再因为这种日志文本 alone 重启 bond forward
- 只在真正 probe 失败 / runtime mismatch 时才重启

结果:

- `iperf3 -R -u` 不会再把 `leikwan-client-26` 杀掉

### 2.4 client stale explicit update token 修复

已修改并回归:

- `client-agent/client_agent.py`
- `client-agent/tests/test_client_agent_mptcp_health_grace.py`

修复点:

- slave / sidecar 原本已经有“如果 explicit update token 是 stale，但本地其实已经是目标版本，就直接标记 success”的逻辑
- client 没有这段，导致手工更新后的 client 仍会在下次 sync 时重新进入 updater loop
- 现在 client 也补齐了这段逻辑

结果:

- stale explicit update token 不会再把 client 卡进无意义更新循环

### 2.5 fleet 重新收敛

这轮结束时，panel 数据库确认:

- client `1` = `2026.04.19.1`
- sidecar point `4` = `2026.04.19.1`
- Guangzhou slave nodes `4/5/6/7/8` = `2026.04.19.1`

client `1` 当前还同步回来了 fresh `last_sync_at`。

### 2.6 admin panel 按钮修复

已补回 admin client actions 里的:

- `Republish`
- `Run Diagnostics`

相关:

- `master/src/Controllers/AdminController.php`
- `master/src/Bootstrap.php`
- `master/tests/admin_client_actions_regression_test.php`

并且 live panel 已做最小片段补丁，不是整文件覆盖。

## 3. best-v8 备份

### 3.1 本地

- archive:
  - `artifacts/backups/best-v8/leikwan-br1-best-v8-20260419-201256.zip`
- SHA256:
  - `01455E6528EE6AA58420C096F81F69BDC713F31175C22D77682B99D5B9142F02`
- notes:
  - `artifacts/backups/best-v8/README.md`
- manifest:
  - `artifacts/backups/best-v8/manifest.json`

### 3.2 远端

- panel:
  - `/root/codex-backups/best-v8/panel`
- client:
  - `/root/codex-backups/best-v8/client`
- slaves:
  - `/root/codex-backups/best-v8/slaves/<slave-ip>`
- sidecar:
  - `/root/codex-backups/best-v8/sidecar`

## 4. 当前 live 状态

### 4.1 client

当前 client:

- `leikwan-client-agent.service` = active
- `leikwan-client-26.service` = active
- `leikwan-client-26-udp-uot.service` = active

并且:

- `11024/tcp` 由 `leikwan-bond` 持有
- `11024/udp` 由 `leikwan-uot-bond.py` 持有

当前 client config 仍包含:

- `udp_uot_sidepath.enabled_forward_ids = [26]`
- `shadow_route_suffix = ".uot"`
- `sidecar_port = 8999`

### 4.2 sidecar

当前 sidecar:

- `leikwan-bond-sidecar-4.service` = active
- `leikwan-bond-sidecar-4-udp-uot.service` = active
- `leikwan-bond-sidecar-4-agent.service` = active

### 4.3 Guangzhou slaves

5 台 Guangzhou slave 当前都重新回到:

- `2026.04.19.1`
- `leikwan-slave-agent.service` active
- `.uot` route 仍存在
- `.uot` backend service 仍存在

## 5. 这轮最重要的新证据

### 5.1 direct reverse UDP 到 target 其实有 UDP 回包

在 client 上对目标直接做:

```bash
iperf3 -c 193.227.109.82 -p 5201 -R -u -b 10M -t 3
```

同时抓包后确认:

- `193.227.109.82:5201 -> 172.21.255.189:<ephemeral-port>` 的 UDP 包确实有回到 client VM

也就是说:

- reverse UDP 数据报本身不是完全没回来

但 `iperf3` 仍然报:

- `unable to read from stream socket`

这说明:

- direct reverse UDP 的主要表象问题仍然和 `iperf3` 的 TCP control path 强相关

### 5.2 target 本机上的 reverse UDP 是正常的

在 target host 本机上:

```bash
iperf3 -c 127.0.0.1 -p 5201 -R -u -b 10M -t 3
```

是可以正常结束的。

这说明:

- `iperf3 -s` 进程本身不是完全坏的

### 5.3 tunnel path 的 strongest clue

当前最强线索是这个 mismatch:

- client `/run/leikwan/uot-bond-forward-26.stats.json`
  - `live_legs = 5`
- sidecar `/var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json`
  - `live_legs = 1` 或 `2`

进一步检查后确认:

- 5 台 slave 的 `.uot` route 都还在
- 5 台 slave 的 `wstunnel-svc-*-udp-uot.service` 都还是 active
- 但 sidecar 当前只稳定看到 `8.163.90.132 -> 138.252.162.120:8999` 这条 ESTAB backend leg

所以现在最像根因的是:

- `.uot` backend 服务虽然都起着
- 但 4 台 slave 没有把到 sidecar `8999` 的 backend TCP leg 稳定保持住

## 6. 当前最可能的根因方向

当前我认为最值得优先追的不是 panel，也不是 client health check，而是:

### 根因方向 A: slave `.uot` backend fan-in 不稳定

表现:

- sidecar `8999` 上只稳定有 1 条 slave backend leg
- 理论上应该有 5 条

这会直接影响:

- reverse UDP 的多腿并发
- sidecar merge runtime 的有效输入面

### 根因方向 B: reverse `iperf3` control path 仍然不健康

表现:

- direct reverse TCP 也差
- direct reverse UDP 也会因 control socket 报错
- tunnel reverse UDP 可能在 control flow 还没稳定前就结束

但这一层当前已经不是:

- “会把 `leikwan-client-26` 杀掉”的管理面问题

那一层已经修了。

## 7. 下一步最值得做什么

建议按下面顺序继续。

### 7.1 先只追 slave -> sidecar `8999` fan-in

目标:

- 搞清楚为什么只有 `8.163.90.132` 会稳定保持 backend leg 到 `8999`

建议动作:

1. 在 5 台 Guangzhou slave 上分别看:
   - `wstunnel-svc-*-udp-uot.service` journal
   - backend 到 `138.252.162.120:8999` 的连接是否反复断开
2. 在 sidecar 上同时看:
   - `leikwan-bond-sidecar-4-udp-uot.service` journal
   - `ss -tnp | grep 8999`
3. 对比 5 台 slave 的 `.uot` service unit / backend port:
   - 是否全部都是同一个 target
   - 是否只有某些 slave 经常 reset / early EOF

### 7.2 再做一次低速 reverse UDP 联合抓证

建议用低速:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -u -b 10M -t 3
```

同时采:

- client:
  - `journalctl -u leikwan-client-26-udp-uot.service`
  - `/run/leikwan/uot-bond-forward-26.stats.json`
- sidecar:
  - `journalctl -u leikwan-bond-sidecar-4-udp-uot.service`
  - `/var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json`
- one or two suspect slaves:
  - `.uot` service journal

### 7.3 最后才回到 `iperf3` control-stream 语义

如果确认 `8999` 这层 fan-in 恢复成 5 条了，但 reverse UDP 仍然坏，
再继续深追:

- `iperf3` control/result stream 为什么仍然不完整

## 8. Instructions To AI

下面是给下一位 AI 的直接执行说明。

### 8.1 先做什么

1. 先把当前状态当成 `best-v8` baseline，不要乱回退
2. 先确认:
   - client `1` / sidecar `4` / slave `4/5/6/7/8` 仍然都是 `2026.04.19.1`
3. 先看 sidecar `8999`:
   - 当前到底有几条 ESTAB slave backend leg
4. 再逐台看 5 个 Guangzhou slave 的 `.uot` backend 服务

### 8.2 不要再做什么

- 不要再从 panel sync 丢配置开始查
  - 这一层已经修好并验过
- 不要再把 `iperf3 -R -u` 导致 `leikwan-client-26` 被 agent 重启当作当前问题
  - 这层也已经修掉
- 不要先改 UoT merge / striping 算法
  - 先确认 5 条 slave backend leg 有没有都真正接到 sidecar `8999`
- 不要先把问题归咎于 “server 根本没发 UDP”
  - direct reverse UDP 抓包已经证明 target 有回包到 client VM

### 8.3 建议的工作顺序

1. sidecar `8999` live fan-in 数量
2. 每台 slave 的 `.uot` backend 连接稳定性
3. 低速 reverse UDP 联合抓证
4. 确认是不是只有 `8999` fan-in 问题
5. 再决定是否深入 `iperf3` control path

### 8.4 如果要连 sidecar

遵守当前环境规则:

- 优先通过 WSL
- sidecar 通过 slave hop 进入
- 不要改成 client 直连 sidecar

### 8.5 如果要动服务

优先只动:

- 一个 slave 的 `.uot` service
- 或 sidecar 的 `udp-uot` service

不要一次把整条链同时重启。

## 9. 当前状态的最短总结

如果下一位只看三句话:

1. `best-v8` 已保存，panel sync / client health check / admin republish button 修复都在里面
2. 当前最强 UDP clue 是 client UoT `live_legs = 5`，但 sidecar UoT 只看到 `live_legs = 1~2`
3. 下一步别再查 panel，直接查为什么 5 台 Guangzhou slave 的 `.uot` backend 只有 1 台稳定接到 sidecar `8999`
