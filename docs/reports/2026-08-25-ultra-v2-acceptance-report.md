# Ultra v2 验收报告

日期：2026-08-25

分支：`codex/ultra-policy-v2`

## 已实现

- Linux/Windows Client 删除 `cf_mode` runtime 支持；收到该模式只拒绝并清理，不降级到其他模式。
- Ultra 仍是 Panel 集成控制面下的 direct client -> provider -> fixed target；不介入 slave、sidecar、独立 gateway 或公共 SOCKS5。
- Panel grant 使用 `leikwan-ultra-policy-v2`，lease 只允许一次 nonce、绑定 device/session、forward、generation、target digest、transport 和有效期。
- 旧 `/api/user/forwards/{id}/client-config` 对 Ultra 返回 410；Ultra 只能通过 device session 的 policy/lease 路径取运行授权。
- provider IP/临时账号只在运行时内存和直接连接中出现；不写入配置、state、status、命令行、heartbeat 或管理输出。
- Panel rate、quota、service status、expiry 和 generation 是硬边界；Linux/Windows 都有本地 rate/quota limiter，heartbeat 用量按序列去重并可返回不含线路数据的 stop action。
- 停止/过期/revoke 会关闭 listener、provider socket、子进程和 IPC；Linux 使用 root-only Unix socket，Windows 使用拒绝远程客户端的 named pipe 和 per-forward Job Object。
- Windows 安装脚本会校验并携带 `ultra-client.exe`；Linux/Windows helper 均有 hash manifest 和 source-build map。

## 本地验证

- Go Linux runtime：`go test ./...` 通过。
- Go Windows cross-compile：`GOOS=windows GOARCH=amd64 go test ./... -c` 通过。
- Windows Core：133 tests passed。
- Windows App：28 tests passed。
- Ultra/Python focused tests：25 passed。
- Panel Ultra/artifact/PHP contract tests：全部通过。
- `git diff --check`、PHP syntax、PowerShell parser 全部通过。
- Linux helper：`f3d00004dd854ec994f5fd8084ba9e2dfa78961557cdfdfb333f2e3c5e27de0a`，7,106,826 bytes；ELF PIE、stripped、无 Go BuildID。
- Windows helper：`cd7a5b4debc63c254060bef469e05720ba229535c05a1f4c3f67aed2e00f9494`，6,603,776 bytes；PE `MZ`、stripped、无 Go BuildID。

## 现场部署与 canary

- 已在 live Panel 做 backup-first 部署；`20260825_add_ultra_policy_leases.sql` 已应用，PHP-FPM 83 reload/restart 后 public `/api/health` 与 artifact manifest 均返回 200。
- 创建了带独立 marker 的临时 Ultra service/forward，source job 真实返回 required lines，至少一条 provider material 进入 ready；sh36 的新 client-agent 与 Linux helper 通过 Panel config pull 启动 `ultra-forward`，listener 只绑定 loopback。
- raw TLS provider canary 返回成功；重连前启动的 targeted tcpdump 捕获 36 packets、4 个 SYN、1 个 Ultra peer。对同一 listener 发送 SOCKS5 greeting 没有 SOCKS 响应。
- 现场发现并修复了 Panel grant broker 丢失 `quota_remaining_bytes`、以及缓存 grant 在 quota 归零时仍复用的问题；修复后 quota=0 的 config 不再下发 grant，client runtime/unit/listener/process 全部停止。
- 将临时 service `expire_at` 置过去后，client runtime/unit/listener/process 全部停止；恢复后调用 live `revokeGeneration`，跨 PHP-FPM 新请求仍拒绝同 generation lease，client 再次全部停止。
- 回滚完成：临时 service/forward、109 lease、6 usage、3 个 client-3 grant cache、1 个 canary material key、测试 device sessions 均清理；Panel marker/forward/lease/usage 为 0。sh36 最终 agent active，但 Ultra unit、config、status、control socket、listener、process 均为 0。
- 现场 global `ultra.material` key 数仍包含既有 Panel 历史 key；canary 涉及 key 已清除，client 端未留下 provider material、line info 或 Ultra runtime 文件。
- Windows setup 已重新构建并以 `CN=LeiKwan Bridge Controlled Release` 受控自签名证书签署；RFC3161 timestamp 存在，`signtool verify /pa` 与 `Get-AuthenticodeSignature` 均为 `Valid`，manifest hash/size 已更新。该证书不是公信 CA，公开分发前必须替换为正式代码签名证书。

## 当前未完成

- 全量 Python suite 仍有 2 个既有、与本改动无关的 UoT baseline failures：旧 Python UoT 路径断言和缺失 `forward_id` fixture；Ultra、artifact mirror 和新增 runtime 测试均通过。

## 发布闸门

1. 正式发布前用公信 CA 代码签名证书替换受控自签名证书，并重新签 setup、manifest 和发布包。
2. 修复两项既有 UoT baseline failures 后再把全量 Python suite 纳入绿色门槛。
3. 保持 provider material 只在 Panel 加密存储和 client runtime 内存/直接连接中存在；失败时只停用 v2 issuance，不恢复 Client 侧持久化 provider material。
