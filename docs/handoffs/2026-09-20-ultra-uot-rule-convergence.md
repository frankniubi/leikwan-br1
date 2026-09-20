# Ultra UDP/UoT 配置核实与 TCP 隔离

日期：2026-09-20。用户新增证据：相同规则从TCP+UDP改为TCP后恢复。工作重点因此转到UDP配置下发和跨协议故障影响；不能把Panel材料TTL等同于provider凭据真实失效。

## 全量规则与sidecar

- 14:06 UTC实时Panel查询：40条Ultra规则，其中17条enabled；所有规则对应组合均启用external UoT，target/token完整。
- Linux测试机5个运行实例的磁盘配置均`external_uot.enabled=true`。
- Desktop运行配置通过stdin交付，不能因找不到配置文件就当作没有运行。
- 六台权威sidecar均运行相同版本`2026.09.19.1.f18d01fb`，本机UDP echo各3/3通过。
- 全规则实时HELLO准入最初37/40通过，东京197/200、LA196返回`forward is not allowed`；200为enabled。已补齐三条授权绑定，东京、LA各一次受控重启，其余四台未重启。
- 修复后40/40实时HELLO准入通过，覆盖17/17 enabled。此为sidecar端准入验证，不是client→provider relay全方向载荷验收。
- 东京重启前7条carrier需要重连；0.305秒是服务重启耗时，不是客户端完整恢复时间。备份、CAS、自动回滚和各主机证据见`tmp/sidecar-health-20260920-current/REPORT.md`。

## 确定的配置下发缺陷

`ClientAgentController::resolveUltraLease`取得含`external_uot`的broker grant，却直接返回`UltraLeaseService`生成的不含该字段的v2 lease。Desktop只从lease解析sidecar配置，因此Panel勾选UoT不能保证Desktop真正启用。

已补`attachExternalUotToLease`：只携带服务器授权的当前grant，绑定forward ID、policy generation、target digest；不信任客户端提供的sidecar字段。Native SOCKS5独立协议不注入不支持的sidecar合同。

新回归覆盖正常传递、字段完整、禁用/缺失grant、不泄露到native SOCKS5，以及forward/generation/target不匹配拒绝；同时校验真实endpoint调用该处理边界。

## UoT carrier和普通TCP状态隔离

- UoT拨号/失败/恢复使用独立`uot`健康域。
- sidecar失败不隔离普通TCP或原生UDP的候选。
- UoT HELLO成功不再清普通TCP业务失败记录、不覆盖LatestTCP、不上报普通TCP全局成功信誉。
- 保留此前TCP fallback的撤销、取消、业务隔离及到期恢复约束。
- 确定性反例修前失败、修后通过；另覆盖UoT选兄弟候选、HELLO拒绝、到期恢复和协议间计数隔离。
- 新增脱敏`udp_transport`运行状态枚举，由helper实际bootstrap决定；Panel保存枚举，旧客户端缺字段标unknown，不猜为UoT。

控制面`directForwardCandidateTest`仍发现一个TCP+UDP fallback依赖sidecar HELLO的分支，正在独立回归修复；不能把已完成carrier隔离说成所有控制面路径均已隔离。

## 已部署与回读（.6阶段）

Panel备份：`/root/codex-backups/uot-lease-split-1789914530`。

- Controller SHA-256：`b11090f0531bc4a4ae8240580fcf4b6a2227b02044463f9b99224634ba0394e9`
- Status service SHA-256：`0aec051929d36f90f5d0a902150ad2d73483b56da7b8328fc2a52492e6e5839b`
- 两文件hash条件检查、备份、lint、原子替换和回读通过。

测试Mac备份：`/Users/leikwan/backups/mac-uot-split-20260920-143311`。

- helper：`2026.09.20.6.52d81766`
- Mac签名后SHA-256：`9d0192c213338b40eb7d29b84442ce168d49b77f6dfa7526ab50bec7b4b709b5`
- Mac原生回归退出0，签名深度验证通过。
- 14:34 UTC：158/159/170/171四个运行实例全部`udp_transport=external_uot`；Panel session333同步回读了相同模式、版本和hash。
- 170出现`udp_runtime_session`，证明已实际完成UoT HELLO；不把它当成业务载荷完整性或长期稳定性的证明。

## 功能探针与限制

首次小流量canary停在SG管理SSH握手失败，未启用198/199、未开始正式流量；HK临时target已停止，sidecar仍active。证据`canary-setup-failed-ssh.json`。SSH失败不是UDP数据面失败。

旧600秒TCP记录仍是有效请求记录，但旧Mac/Linux UDP实现未被统一，不能把它解释为同一UDP路径的严格平台对照。

尚未完成：所有用户设备运行时逐台回读、全11方向Mac/Linux 600秒20Mbps载荷验收、可靠datagram/受控故障恢复验收。未发布公共客户端更新、未提交或push。

## 本地证据

`tmp/uot-rule-audit-20260920/`：Panel全规则清单、Linux配置、PHP部署回执、Mac部署回执、实际模式回读、合并Go race、Mac原生测试、功能探针脚本与结果。所有token/密码仍仅保留在原本地敏感文件或受保护远端备份，不进入报告。
