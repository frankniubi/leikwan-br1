# DESDWAN 拆分架构落地：tcp_accel_sidepath 自动化（2026-08-15）

## 1. 目的

执行 `2026-08-15-desdwan-single-slave-completion-handoff.md` 的推荐方案：UoT/first-wins 只承载 WireGuard/UDP 与低延迟小包，bulk TCP 走 `tcp_accel` split-TCP 路径。此前 tcp_accel 只能按 pilot 文档手工三步激活（sidecar `server.env`、slave `bridge-<name>.json`、client `client-<name>.json`）；本轮把该分工接入 Panel→agent 自动化，**默认完全关闭**（无 env 时行为零变化）。

## 2. 设计

- 面板侧新增 `tcp_accel_sidepath` agent-config 块，与既有 `udp_uot_sidepath` 同模式（config-gated，权威生成）：
  - 门控：panel env `TCP_ACCEL_SIDECAR_SERVER`（`host:port`）。缺省或非法 → 不下发任何块。`TCP_ACCEL_TOKEN` 可选，作为 lane token 透传。
  - 资格：与 UoT sidepath 相同（client_mode=bond、ingress=plain_tcp_mux、transport 含 udp），另要求 transport 含 tcp（拆分才有意义）。line membership 复用 `authoritativeForwardLeigodLines`。
  - 确定性端口：slave bridge listener 端口 = `7000 + (line_id % 2000)`（落在 bridge helper 强制的 7000-9000 窗口内）；slave 侧 SOCKS 拨号地址 = `127.0.0.1:20000+line_id`（与 slave agent 既有 line listener 约定一致，若 line state 中有实际 socks_port 则优先用实际值）。
- 客户端（`install_forward_runtime.py`）：当 forward 同时命中 UoT sidepath 与 tcp_accel sidepath 且无 speed_limit 时，**不再渲染 bond WSS 主服务**：主 unit 变为 `leikwan-client-<fid>-tcp-accel.service`（`tcp_accel.py client-config`，配置写入 `/etc/leigod/tcp-accel/client-forward-<fid>.json`，listen=`0.0.0.0:<listen_port>` TCP，remotes=slave bridge 端口），UDP 仍由 `leikwan-client-<fid>-udp-uot` aux unit 承载。有 speed_limit 时回退旧路径（tcp_accel 无限速能力）。
- Slave（`slave_agent.py`）：渲染**节点级合并**的单个 `leigwan-tcp-accel-bridge.service` + `/etc/leigod/tcp-accel/bridge.json`（remote=sidecar server，listeners=全部 forward 的 line 并集、per-line 去重——端口按 line 确定性分配，若按 forward 各起 unit 会争抢同一端口 EADDRINUSE）。挂在 `ensure_udp_uot_shadow_service` 的既有 per-forward reconcile 上触发；`apply_config_patch` 中 `tcp_accel_sidepath` 变化时 reap（面板不再列任何条目即整 unit 下线）；runtime binary 缺失/无可用 listener 时同样 reap。SOCKS 拨号地址优先取 line 实际 socks_port，否则回退 `127.0.0.1:20000+line_id`。可配置键：`tcp_accel_bridge_bin`（默认 `/opt/leigod/tcp-accel/leigod_tcp_accel_bridge.py`）、`tcp_accel_config_dir`（默认 `/etc/leigod/tcp-accel`）。
- Sidecar：tcp-accel server 沿用 pilot 的 `leigod-tcp-accel-server.service`（token file），无需新 wiring。

## 3. 变更文件

- `master/src/Support/ProvisioningService.php`：`tcpAccelSidepathConfigForAssignedClientForwards` / `tcpAccelSidepathConfigForNode` + 私有 helpers。
- `master/src/Controllers/ClientAgentController.php`、`NodeController.php`：下发 `tcp_accel_sidepath` 并加入 managed keys。
- `client-agent/install_forward_runtime.py` + `artifacts/install-forward-runtime.py`（byte-identical）：`tcp_accel_sidepath_settings`、`render_tcp_accel_client_unit`、bond 分支拆分接入。
- `slave-agent/slave_agent.py` + `artifacts/slave-agent.py`（byte-identical）：bridge render/reap + config patch 键。
- 新测试：`master/tests/tcp_accel_sidepath_config_test.php`、`client-agent/tests/test_tcp_accel_sidepath_render.py`、`slave-agent/tests/test_slave_tcp_accel_bridge_render.py`。

## 4. 验证（全部通过）

```text
php master/tests/tcp_accel_sidepath_config_test.php            ok
php master/tests/uot_control_plane_authority_regression_test.php ok
php master/tests/agent_udp_uot_sidepath_management_test.php    ok
php master/tests/tcp_accel_artifact_distribution_test.php      ok
php master/tests/artifact_versions_manifest_consistency_test.php ok
python -m unittest client-agent.tests.test_tcp_accel_sidepath_render
  client-agent.tests.test_uot_scheduler_profile_migration     6+4 passed
python -m unittest slave-agent.tests.test_slave_tcp_accel_bridge_render
  slave-agent.tests.test_tcp_accel_bridge
  slave-agent.tests.test_slave_udp_uot_shadow_routes          5+7+12 passed
git diff --check                                               clean
```

## 5. 未做 / 激活步骤

- **未发布 production、未 commit、未 live 验证**（与上游 handoff 相同边界）。
- 激活清单：
  1. sidecar：确认 `leigod-tcp-accel-server.service` active（pilot 已装）。
  2. panel 主机 env：`TCP_ACCEL_SIDECAR_SERVER=<sidecar_ip>:29030`、可选 `TCP_ACCEL_TOKEN`。
  3. 发布 slave-agent / client installer artifact（三件套同步 + versions.json bump，注意 nginx 静态 shadow）。
  4. 触发 republish + agent 拉取后观察 `leikwan-tcp-accel-bridge.service`（slave）与 `leikwan-client-<fid>-tcp-accel.service`（client）。
- 已知限制：tcp_accel 拆分路径不覆盖 speed_limit（回退 bond TCP）；上传方向加速未优化（pilot 记录 ~4.7 Mbps，下载/反向是 win condition）；bridge listener 端口按 line 全局确定（`7000+line_id%2000`），同节点跨 forward 共线即共享同一 listener（由合并 bridge 承载，不会端口冲突）。

## 6. 相关

- `docs/handoffs/2026-08-15-desdwan-single-slave-completion-handoff.md`（问题定义与推荐）
- `docs/handoffs/tcp-accel-br1-pilot-20260810.md`（288 Mbps 反向证据、激活拓扑）
- `docs/handoffs/2026-08-15-desdwan-single-slave-root-cause.md`（为何纯 UoT 不可外推 200M）
