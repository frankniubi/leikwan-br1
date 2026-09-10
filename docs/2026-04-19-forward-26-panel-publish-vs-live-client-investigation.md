# 2026-04-19 Forward 26 Panel Publish Vs Live Client Investigation

日期: 2026-04-19

适用范围:

- `forward 26`
- client `1` / live VM `172.21.255.189`
- sidecar point `4` / `138.252.162.120`
- panel `10.0.10.196`
- 关注命令:
  - `iperf3 -c 127.0.0.1 -p 11024 -R -u -b 500M -t 10`

这份文档只记录这次补充调查里实际确认到的事实。
目标是把“panel publish 逻辑是否有锅”和“为什么这条 UDP reverse iperf3 还是不能当作有效测速”拆开讲清楚。

## 1. 一句话结论

结论分两层:

- `panel` 现在确实还没有把 `UDP UoT sidepath` 作为 `forward 26` 的一等管理配置完整下发
- 但当前这条 `iperf3 -R -u` 失败的直接原因，仍然是 `leikwan-client-26.service` 里的 `TCP control/result stream reset`

也就是说:

- panel 管理面确实还有缺口
- 但这次 fresh repro 里，失败点仍然先发生在 TCP 控制流
- 这次失败窗口里，UDP UoT runtime 根本没有真正进入新的活跃会话

## 2. Panel 侧实际查到的内容

### 2.1 `forwards.id = 26`

现场数据库里 `forward 26` 的关键字段是:

- `listen_port = 11024`
- `target_host = 193.227.109.82`
- `target_port = 5201`
- `client_mode = bond`
- `transport_mode = tcp+udp`
- `bond_ingress_mode = plain_tcp_mux`
- `bond_sidecar_point_id = 4`
- `bond_remote_host = 138.252.162.120`
- `bond_remote_port = 9000`
- `path_prefix = a43547b6350f72cd`
- `endpoint_count = 5`
- `public_port = 7001`
- `node_group_key = canton-ali`

这说明 panel 数据库里 `forward 26` 本身仍然是“bond 主路径”定义，不是“原生就带 UDP UoT sidepath 字段”的 forward 数据形状。

### 2.2 `sidecar_points.id = 4`

现场数据库里 sidecar point `4` 的关键字段是:

- `sidecar_host = 138.252.162.120`
- `listen_port = 9000`
- `bond_listen_port = 9000`
- `mptcp_listen_port = 14969`
- `is_active = 1`
- `last_status = ok`
- `last_heartbeat_at = 2026-04-19 08:09:23`

这说明 panel 侧对 sidecar 主 bond 入口的认知是正常的:

- bond 走 `9000`
- mptcp 走 `14969`

### 2.3 `forward 26` 当前发布的 5 个 slave endpoint

panel 数据库里 `service_endpoints` 对应的 live 集合是:

- `184 -> 8.163.90.132:7001`
- `185 -> 8.163.90.235:7001`
- `186 -> 8.163.87.42:7001`
- `187 -> 8.163.69.176:7001`
- `188 -> 8.163.71.73:7001`

这和当前现场使用的 5 台 Guangzhou slave 是一致的。

### 2.4 `assignedForwardConfigsForClient(1)` 真正生成了什么

这次直接在 panel 上调用了:

- `ProvisioningService::assignedForwardConfigsForClient(1)`

对 `forward 26` 返回的关键内容是:

- `listen_port = 11024`
- `target = 193.227.109.82:5201`
- `bond_sidecar_host = 138.252.162.120`
- `bond_sidecar_port = 9000`
- `bond_remote_host = 138.252.162.120`
- `bond_remote_port = 9000`
- `mode = bond`
- `transport = tcp+udp`
- `path_prefix = a43547b6350f72cd`
- `bonding.route_key = a43547b6350f72cd`
- `bonding.sidecar_port = 9000`
- `bonding.udp_session_token` 已正常生成
- `endpoints` 正常包含上述 5 台 Guangzhou slave

但这份 panel 生成的 forward 配置里，没有任何显式的:

- `udp_uot_sidepath`
- `.uot` shadow route 配置
- `8999` sidecar UDP merge 入口
- “把 TCP 主 unit 和 UDP aux unit 拆开”的 forward 级指示

所以如果问题问的是:

- “panel 有没有把 UDP UoT sidepath 作为完整 forward publish 出去”

答案是:

- 还没有

## 3. Client 侧实际运行形状

### 3.1 live client agent 本地配置

现场 live client 的:

- `/etc/leikwan-client-agent/config.json`

里明确存在:

- `udp_uot_sidepath.enabled_forward_ids = [26]`
- `udp_uot_sidepath.shadow_route_suffix = ".uot"`
- `udp_uot_sidepath.sidecar_port = 8999`

这说明:

- live client 当前能跑 `forward 26` 的 UDP UoT aux runtime
- 不是因为 panel 当前 forward payload 已经自带了 sidepath 元数据
- 而是因为这台 client 的本地 agent config 里手工/额外保留了这块配置

### 3.2 live runtime 现状

现场实际确认到:

- `leikwan-client-26.service` 为 `active`
- `leikwan-client-26-udp-uot.service` 为 `active`
- `11024/tcp` 由 `leikwan-bond` 监听
- `11024/udp` 由 `python3` 的 `leikwan-uot-bond.py` 监听
- `/etc/leikwan/uot-bond-forward-26.json` 存在

也就是说:

- 现场 split runtime 仍然在
- TCP 主路径和 UDP aux 路径都已经起来

### 3.3 client agent 当前处于失配状态

现场同时确认到:

- `leikwan-client-agent.service` 当前是 `inactive (dead)`
- 它最近几次启动都会在 `self-update / apply` 过程中很快退出

现场日志里的关键现象是:

- agent 会开始 `requested client self-update`
- 会开始 `applying forward ...`
- 但服务很快就被停掉，没有进入长期稳定轮询状态

panel 数据库里 client `1` 仍记录:

- `agent_version = 2026.04.09.1`
- `last_sync_at = 2026-04-18 20:57:47`

这和现场 live VM 上已经手工推进到更新版本的事实不一致。

所以当前还存在一个管理面问题:

- panel 看到的 client 状态
- 和现场真正运行的 client runtime

并没有完全重新收敛到同一个真实状态。

## 4. Fresh repro: 这条 `iperf3 -R -u` 这次到底卡在哪里

### 4.1 实际执行

这次在 client 上 fresh 执行:

```bash
timeout 20s iperf3 -c 127.0.0.1 -p 11024 -R -u -b 500M -t 10
```

时间窗口:

- `2026-04-19 09:30:16 UTC`

### 4.2 执行前后 UDP UoT stats

执行前 `/run/leikwan/uot-bond-forward-26.stats.json`:

```json
{"datagrams_dropped_gap": 0, "datagrams_in": 10, "datagrams_out": 0, "live_legs": 5, "reorder_buffer_bytes": 0, "sessions_active": 0}
```

执行后仍然是:

```json
{"datagrams_dropped_gap": 0, "datagrams_in": 10, "datagrams_out": 0, "live_legs": 5, "reorder_buffer_bytes": 0, "sessions_active": 0}
```

这个结果非常关键。

它说明这次失败窗口里:

- `live_legs` 仍然是 `5`
- 但 `sessions_active` 没有增长
- `datagrams_out` 没有增长

也就是说:

- 这次失败的 `iperf3 -R -u` 根本没有真正进入新的 UDP UoT 活跃数据会话

### 4.3 `iperf3` 表现

这次命令最终表现为:

- 打印出 UDP 测试表头
- 之后卡住
- 被 `timeout` 杀掉
- `IPERF_RC = 124`

### 4.4 同窗口 client journal

同一时间窗口里，`leikwan-client-26.service` 看到的关键错误仍然是 TCP 控制流问题:

- `bond tcp stream ... local write failed: Broken pipe (os error 32)`
- `bond tcp stream ... local read failed: Connection reset by peer (os error 104)`
- `bond client TCP transport ... failed: client transport stream ... failed with local_read_failed; forcing transport reconnect`

同时还伴随:

- `bond tcp transport closed before accepting ...; reconnecting persistent session`

这说明当前 fresh repro 的直接失败链条仍然是:

1. `iperf3` 先走本地 `11024/tcp` 控制连接
2. control/result stream 在 `leikwan-client-26.service` 里发生 `Broken pipe / Connection reset by peer`
3. bond TCP transport 被强制重连
4. 整个 `iperf3 -R -u` 没能把 reverse UDP 数据会话真正拉活

## 5. 这次调查后，对“panel publish 有没有锅”的更准确说法

更准确的表述应该拆成两句。

### 5.1 panel publish / 管理面的问题

是的，panel 现在还存在管理面缺口:

- `assignedForwardConfigsForClient(1)` 生成的 `forward 26` 仍然只有 bond 主路径配置
- 它没有把 `UDP UoT sidepath` 作为 forward 级正式管理配置一起下发
- 现场目前仍依赖 client 本地 `agent config` 里的 `udp_uot_sidepath` 才能保留 split runtime

所以如果要问:

- “panel publish 逻辑是不是还不完整”

答案是:

- 是，不完整

### 5.2 但这不是这次命令失败的直接根因

这次 fresh repro 已经说明:

- 这条失败的 `iperf3 -R -u`
- 在当前窗口里甚至没有真正进入新的 UDP UoT 活跃会话

所以它当下失败的直接原因不是:

- panel 把 `.uot` route 发布错了
- panel 把 5 台 slave 发错了
- panel 把 sidecar 指错了

当前更直接的失败点仍然是:

- `leikwan-client-26.service` 里的 TCP control/result stream reset

也就是:

- panel 管理面有缺口
- 但这条命令当前失败，先死在 TCP 控制流

这两件事是并存的，不是同一层。

## 6. 当前最稳妥的归因

截至这次调查，最稳妥的归因是:

1. `panel` 还没有把 `forward 26` 的 UDP UoT sidepath 做成完整 authoritative publish
2. live client 靠本地保留的 `udp_uot_sidepath` 配置把 split runtime 维持住了
3. `client agent` 目前又不处于稳定持续同步状态，所以 panel 与 live runtime 的状态继续有漂移风险
4. 当前这条 `iperf3 -R -u -b 500M` 在 fresh repro 里仍然主要死在 `TCP control/result stream`
5. 这次失败窗口里 `UDP UoT runtime stats` 没有出现新会话增长，所以不能把这次失败直接解释成“UDP UoT 数据面吞吐差”

## 7. 给下一位看的最短交接句

如果下一位只看一句话:

- panel 端确实还没有把 `UDP UoT sidepath` 作为正式 forward publish 完整接上
- 但这次 client 上 fresh 失败的 `iperf3 -R -u` 仍然先死在 `leikwan-client-26.service` 的 TCP control-stream reset，而且这次失败窗口里 UoT stats 没有出现新的 active session

## 8. 本次未做的事

这次没有继续修改任何现场配置，也没有继续动 panel / client / slave / sidecar 代码。

只做了:

- panel 数据库只读检查
- panel `ProvisioningService` 只读调用
- client 本地配置只读检查
- client fresh repro
- client journal 只读取证
