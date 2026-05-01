# 2026-04-19 Forward 26 UDP UoT Live Rollout Report And Maintenance Log

日期: 2026-04-19

适用范围:

- `forward 26`
- mode: `bond`
- local listen: `11024`
- topology: `client -> slave -> sidecar`
- rollout line: `UDP UoT sidepath`

这份文档是给下一位 AI / 工程师的现场报告。

目标不是重复所有聊天过程，而是清楚回答这几件事:

- 这次到底做了什么
- 真正的根因链是什么
- 哪些改动已经同步到 panel / 现场主机
- 当前 live 实际状态是什么
- 还剩什么问题
- 如果继续做，应该先做什么，不该做什么

## 1. 一句话结论

这次最大的发现不是“UDP UoT 方案性能差”，而是:

- 现场最初根本没有真正跑上新方案
- client 还在跑旧的 native `leikwan-bond --protocol udp`
- sidecar agent 被 panel `heartbeat` 的 500 卡死，拿不到新 runtime
- slaves 也没有 `.uot` shadow route

把这三层补齐以后，新的 UDP UoT sidepath 已经真实跑起来了。

当前现场最准确的描述是:

- UDP sidepath 数据面已经工作，按 sidecar stats 差分看，实测大约 `439 Mbps`
- 但 `iperf3 -u` 仍会报错，因为它的 TCP 控制连接仍然会命中旧的 `leikwan-client-26.service` 控制流 reset 问题
- 所以剩余的主要问题已经从“UDP 数据面不通”变成了“TCP 控制流仍不稳”

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

## 3. 根因链

这次已经确认过的根因链如下。

### 3.1 旧 client runtime generator 仍在现场

client 最初现场状态:

- `/usr/local/bin/install_forward_runtime.py` 不是当前版本
- `leikwan-client-26.service` 仍然在一个 unit 里同时挂:
  - `leikwan-bond --protocol tcp`
  - `leikwan-bond --protocol udp`
- `leikwan-client-26-udp-uot.service` 根本不存在

这意味着:

- 当前 workspace 里的 UDP UoT sidepath 实现虽然已经写好了
- 但现场 client 还没有真正切到那个新运行形状

### 3.2 sidecar heartbeat 被 panel 500 卡死

sidecar agent 现场反复报:

- `http 500 https://v3.leikwanhost.com/api/sidecar/heartbeat`
- `Call to undefined method Leikwan\Master\Controllers\SidecarAgentController::sidecarBondListenPort()`

这个 500 的直接根因是:

- `master/src/Controllers/SidecarAgentController.php`
  在 controller 里直接调用:
  - `sidecarBondListenPort()`
  - `sidecarMptcpListenPort()`
- 但这两个 helper 只存在于:
  - `master/src/Support/ProvisioningService.php`

结果:

- sidecar agent 拿不到新的 management payload
- `leikwan-bond-sidecar-4-udp-uot.service` 不会被自动生成
- `8999` 不会起来

### 3.3 slave 侧没有 `.uot` shadow route

在新方案真正部署前，slave 现场状态是:

- `routes-7001.json` 没有 `.uot` route
- `wstunnel-svc-*-udp-uot.service` 不存在
- sidecar `8999` 也没有来自 slaves 的 TCP leg

所以最初那条链路实际上还停留在:

- client old UDP bond
- slave no `.uot`
- sidecar no `8999`

### 3.4 后续新的主要故障点

把 sidepath 手工拉起后，新的主要故障点变成:

- `iperf3 -u` 的 TCP 控制连接仍然会失败
- `leikwan-client-26.service` 仍然会出现:
  - `bond tcp stream ... local read failed: Connection reset by peer (os error 104)`

这说明:

- 新的 UDP UoT payload path 已经不是主故障
- 旧的 TCP control-stream reset 现在变成影响 `iperf3 -u` 结果展示的主要问题

## 4. 这次本地实现了什么

这次本地已经完成并本地回归通过的实现包括:

### 4.1 client

- `client-agent/install_forward_runtime.py`
  - 支持 forward 26 的 TCP primary + UDP auxiliary unit split
- `client-agent/client_agent.py`
  - 支持多 unit 管理和清理
- `client-agent/leikwan_uot_bond.py`
  - 新的 client-side striped UDP runtime

### 4.2 slave

- `slave-agent/slave_agent.py`
  - 支持 `.uot` shadow route
  - 支持 `.uot` shadow backend 指向 sidecar `8999`
  - 支持 republish / destroy / reconcile 生命周期

### 4.3 sidecar

- `sidecar-agent/sidecar_agent.py`
  - 支持 `-udp-uot` runtime unit 管理
- `sidecar-agent/leikwan_udp_uot_merge.py`
  - sidecar UDP merge runtime
  - 已修正 token-scoped isolation

### 4.4 install / artifacts / docs

- `install/install-client-agent.sh`
- `install/install-slave.sh`
- `install/install-bond-sidecar.sh`
- `artifacts/versions.json`
- `master/src/Bootstrap.php`
- `master/src/Controllers/ArtifactController.php`
- `docs/BOND_OPERATIONS.md`
- `config-examples/forward-26-udp-uot-sidepath.json`

## 5. 这次实际同步到 panel 的东西

这次已经同步到 panel host `10.0.10.196` 的关键文件:

- `master/src/Controllers/SidecarAgentController.php`
- `master/src/Bootstrap.php`
- `master/src/Controllers/ArtifactController.php`
- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `client-agent/leikwan_uot_bond.py`
- `slave-agent/slave_agent.py`
- `sidecar-agent/sidecar_agent.py`
- `sidecar-agent/leikwan_udp_uot_merge.py`
- `install/install-client-agent.sh`
- `install/install-slave.sh`
- `install/install-bond-sidecar.sh`
- `artifacts/versions.json`

并且已经做过:

- panel PHP 语法检查
- PHP-FPM reload

## 6. panel 供料链现在的真实状态

现在 panel 已经能供 fresh install / self-update 拉到新版本。

### 6.1 新增 artifact 路由

已经确认 panel 现在提供:

- `/artifacts/leikwan-uot-bond.py`
- `/artifacts/leikwan-udp-uot-merge.py`

### 6.2 manifest / installer

已经确认:

- `/artifacts/client-manifest.json` 包含:
  - `uot_bond_runtime`
- `/artifacts/sidecar-manifest.json` 包含:
  - `udp_uot_merge_runtime`
- `/artifacts/install-client-agent.sh` 会拉:
  - `leikwan-uot-bond.py`
- `/artifacts/install-bond-sidecar.sh` 会拉:
  - `leikwan-udp-uot-merge.py`
- `/artifacts/install-slave.sh` 已更新到:
  - `2026.04.19.1`

### 6.3 版本号

当前供料链里已经使用:

- `client_agent = 2026.04.19.1`
- `slave_agent = 2026.04.19.1`
- `sidecar_agent = 2026.04.19.1`
- `client_agent_installer = 2026.04.19.1`
- `slave_installer = 2026.04.19.1`
- `bond_sidecar_installer = 2026.04.19.1`

## 7. 现场主机升级状态

### 7.1 client

已确认:

- `/usr/local/bin/leikwan-client-agent.py` 已是 `2026.04.19.1`
- `/usr/local/bin/install_forward_runtime.py` 已包含:
  - `udp_uot_sidepath_settings`
  - `render_udp_uot_aux_unit`
  - `leikwan-uot-bond.py`
- `/usr/local/bin/leikwan-uot-bond.py` 已在位

手工执行过:

- `ClientAgent.apply_forward(forward 26)`

结果:

- `leikwan-client-26.service` 变成 TCP-only
- `leikwan-client-26-udp-uot.service` 被创建并启动
- `/etc/leikwan/uot-bond-forward-26.json` 被写出

### 7.2 sidecar

已确认:

- `/usr/local/bin/leikwan-sidecar-agent.py` 已是 `2026.04.19.1`
- `/usr/local/bin/leikwan-udp-uot-merge.py` 已在位

由于 sidecar agent 自动管理链路不稳定，这次做了手工触发:

- 先修 panel `heartbeat` 500
- 再直接在 sidecar 上手工运行当前 `sidecar_agent.py` 的 `apply_management(...)`

结果:

- `leikwan-bond-sidecar-4-udp-uot.service` 被创建
- `8999` 已开始监听
- `/var/lib/leikwan-sidecar-4/udp-uot-runtime.json` 已写出

### 7.3 5 台 Guangzhou slaves

已确认 5 台都已换上:

- `/usr/local/bin/leikwan-slave-agent.py` = `2026.04.19.1`

并且都已经手工:

- 在 `/etc/leikwan-slave/config.json` 中加入 `udp_uot_sidepath`
- 对 `forward 26` 执行一次本地 `publish_task(...)`

结果:

- `routes-7001.json` 中出现 `.uot` route
- `wstunnel-svc-*-udp-uot.service` 被创建
- `.uot` backend target 指向:
  - `138.252.162.120:8999`

## 8. 当前 live 运行形状

### 8.1 client

当前 client 真实运行形状已经变成:

- `leikwan-client-26.service`
  - 只跑 TCP bond
- `leikwan-client-26-udp-uot.service`
  - 只跑 UDP UoT sidepath

关键点:

- `11024/tcp` 由 `leikwan-bond` 持有
- `11024/udp` 由 `leikwan-uot-bond.py` 持有

### 8.2 sidecar

当前 sidecar 真实运行形状:

- `leikwan-bond-sidecar-4.service`
  - 监听 `9000`
- `leikwan-bond-sidecar-4-udp-uot.service`
  - 监听 `8999`

### 8.3 slaves

当前 slaves 真实运行形状:

- base route:
  - `a43547b6350f72cd`
  - target sidecar `9000`
- shadow route:
  - `a43547b6350f72cd.uot`
  - target sidecar `8999`

## 9. 现场实测结果

### 9.1 UDP sidepath 已经真实在跑

现在已经确认不是“只有 unit 文件存在”，而是 3 段链路都活了:

- client 上 `leikwan-client-26-udp-uot.service` 有 5 条到 slaves `7001` 的 TCP legs
- sidecar `8999` 上能看到 5 条来自 Guangzhou slaves 的 TCP 连接
- sidecar `udp-uot-runtime-stats.json` 在 UDP 测试时会持续增长

### 9.2 过去“UDP 只有 90 Mbps”的结论已经过时

在新的 UoT sidepath 实际部署后，按 sidecar stats 差分计算:

- 一次 reverse UDP 测试窗口内
- `bytes_out` 增量约 `1,766,391,812`
- 实际折算速率约 `439 Mbps`

这说明:

- 新 UDP payload path 的真实性能远高于之前“90 Mbps”那次旧路径结果
- 之前那个结论主要是因为新方案根本还没落到现场

### 9.3 当前仍然存在的表象问题

虽然 UDP payload path 已经在工作，但 `iperf3 -u` 仍然会报:

- `unable to read from stream socket: Resource temporarily unavailable`

当前证据显示，这个报错主要来自:

- `iperf3` 自己的 TCP control connection
- 它仍然经 `leikwan-client-26.service` 的 TCP path
- 而 TCP control-stream 仍会命中老问题:
  - `bond tcp stream ... local read failed: Connection reset by peer (os error 104)`

所以当前不能再把:

- `iperf3 -u` 最终报错

简单等同于:

- UDP payload path 没流量

更准确的描述是:

- UDP payload 已经在跑，且速率很高
- 但 `iperf3 -u` 的控制流仍会被旧的 TCP reset 问题污染

## 10. 这次维护动作日志

下面按“发生了什么 -> 为什么做 -> 结果”记录。

### 10.1 先做现场只读排查

做了:

- 读取 client `systemctl status leikwan-client-26`
- 读取 `journalctl`
- 看 live `ss`

发现:

- client 仍是旧的 `leikwan-bond --protocol udp`
- 没有 `leikwan-client-26-udp-uot.service`

意义:

- 第一时间排除了“新实现已经上线但表现差”
- 确认问题首先是“未部署”

### 10.2 发现 sidecar agent heartbeat 500

做了:

- 查看 sidecar agent journal

发现:

- `/api/sidecar/heartbeat` 500
- `undefined method SidecarAgentController::sidecarBondListenPort`

意义:

- 确认 sidecar 自动管理链路被 panel bug 阻断

### 10.3 修 panel controller

做了:

- 在 `SidecarAgentController.php` 中补上:
  - `sidecarBondListenPort()`
  - `sidecarMptcpListenPort()`
- 同步到 panel
- reload PHP-FPM

结果:

- sidecar `heartbeat` 路由本身恢复可用
- 从 `500` 变成可正常返回认证错误 / 正常逻辑

### 10.4 修 panel 供料链

做了:

- 新增 artifact 路由:
  - `leikwan-uot-bond.py`
  - `leikwan-udp-uot-merge.py`
- installer 增加下载
- `versions.json` 增加新 runtime 并提升 agent / installer 版本

结果:

- fresh install / self-update 供料链具备拉新文件能力

### 10.5 手工拉起 sidecar UDP runtime

为什么要手工做:

- sidecar agent 当时仍有上游可达性 / 自更新混杂问题
- 不适合继续等自动链路稳定后再验证功能

做了:

- 手工同步:
  - `leikwan-sidecar-agent.py`
  - `leikwan-udp-uot-merge.py`
- 手工补 `/etc/leikwan-sidecar/config.json`
- 手工调用当前 `sidecar_agent.py` 的 `apply_management(...)`

结果:

- `leikwan-bond-sidecar-4-udp-uot.service` 起了
- `8999` 开始监听

### 10.6 手工拉起 client UDP runtime

为什么要手工做:

- `leikwan-client-agent.service` 会在自更新 / apply 过程中自己退出
- 直接等待它完整收敛不够快

做了:

- 同步:
  - `leikwan-client-agent.py`
  - `install_forward_runtime.py`
  - `leikwan-uot-bond.py`
- 补 client config 的 `udp_uot_sidepath`
- 手工调用 `ClientAgent.apply_forward(forward 26)`

结果:

- `leikwan-client-26.service` 变成 TCP-only
- `leikwan-client-26-udp-uot.service` 启动
- `uot-bond-forward-26.json` 出现

### 10.7 手工给 5 台 slaves 补 `.uot`

为什么要手工做:

- 在自动链路仍不稳时，先确认 `.uot` 路由和 backend 能被真实创建

做了:

- 5 台 Guangzhou slaves 都同步 `slave_agent.py`
- 都补了 `udp_uot_sidepath` config
- 都基于本地 existing metadata 手工 `publish_task(forward 26)`

结果:

- 5 台都有 `.uot` route
- 5 台都有 `wstunnel-svc-*-udp-uot.service`

### 10.8 暂停会造成抖动的管理 agent

做了:

- 停止 5 台 Guangzhou 的 `leikwan-slave-agent.service`
- 停止 sidecar 的 `leikwan-bond-sidecar-4-agent.service`

原因:

- 这些 agent 还会周期性重发布 / 自更新 / 覆盖现场
- 会把刚拉起来的 `.uot` sidepath 反复 stop/start

结果:

- `.uot` backend 不再持续被管理链抖掉

## 11. 当前真实状态

### 已完成

- panel 已同步
- panel artifact / manifest / installer 已同步
- client 已是新 agent 版本
- sidecar 已是新 agent 版本
- 5 台 Guangzhou slaves 已是新 agent 版本
- client UDP aux unit 已起来
- sidecar UDP merge unit 已起来
- 5 台 slaves 的 `.uot` shadow route 已起来
- `best-v7` 本地 + 远端备份已完成

### 仍未完成

- client / slave / sidecar 的管理 agent 尚未在“新方案稳定前提下”重新全部打开并验证
- `iperf3 -u` 的 TCP control-stream 仍然会报错
- 当前还没有一份“管理 agent 全开且 sidepath 仍稳定”的最终验收

## 12. 现在最值得继续做的事

继续排障时，建议按这个顺序。

### 12.1 先恢复管理链路，但一次只放开一层

顺序建议:

1. 先开 sidecar agent
2. 再只开一台 slave agent
3. 看 `.uot` shadow 是否被误删 / 重写
4. 稳了再开剩余 4 台 slaves
5. 最后再开 client agent

原因:

- 当前最容易复发的不是数据面，而是管理面把现场拉回旧形状

### 12.2 再追 `iperf3 -u` 的控制流 reset

现在线上真正还在拖结果展示的，是:

- `leikwan-client-26.service`
- 里的 TCP control-stream reset

下一步更值得看的点:

1. `iperf3 -u` 的 TCP control socket 在 sidecar 目标进程里为什么会被 peer reset
2. 是不是 `193.227.109.82:5201` 这个目标应用自己在控制流收尾阶段主动 reset
3. 为什么这个 reset 会把 `leikwan-bond` 整体 transport reconnect 打开
4. 这能不能只影响单 stream，而不影响整次 control path

### 12.3 不要再做的事

- 不要再把“`iperf3 -u` 最终报错”直接等同于“UDP payload 没跑”
- 不要再把重点放回 panel schema 或 UDP route key 设计
- 不要先改新的 UoT merge/striping 算法

当前最主要的问题已经不是:

- 新 UDP sidepath 本身不通

而是:

- 管理链路持久化
- 旧 TCP control-stream reset

## 13. best-v7 备份

本地:

- `artifacts/backups/best-v7/leikwan-br1-best-v7-20260419-164146.zip`
- `artifacts/backups/best-v7/README.md`
- `artifacts/backups/best-v7/manifest.json`

本地 SHA256:

- `6B444DCF1CCC1F67DE3C6C59CB1797954627E672E55980824737E6CA60802A05`

远端:

- panel:
  - `/root/codex-backups/best-v7/panel`
- client:
  - `/root/codex-backups/best-v7/client`
- slaves:
  - `/root/codex-backups/best-v7/slaves/<slave-ip>`
- sidecar:
  - `/root/codex-backups/best-v7/sidecar`

## 14. 一句话交接结论

这次已经把 `forward 26` 的 UDP UoT sidepath 真正推到了现场，并确认它在 payload 层已经跑起来，速率约 `439 Mbps`。

当前剩余主要问题不再是 UDP sidepath 本身，而是:

- 管理 agent 重新开启后是否会把 `.uot` 路径打坏
- 以及 `leikwan-client-26.service` 里那个老的 TCP control-stream reset，会继续让 `iperf3 -u` 报错

所以如果下一位继续做，优先顺序应当是:

1. 先逐层恢复管理 agent 并确认 sidepath 不回退
2. 再只追 TCP control-stream reset

不要再从 panel schema、shadow route 设计、或 UDP stripe 算法开始。
