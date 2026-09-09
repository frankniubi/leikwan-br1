# 三端控制面高可用与自愈设计

## 目标

在不改变现有 API 下发逻辑、请求字段、lease 协议和服务端响应结构的前提下，为 Windows、macOS、Linux 增加 Cloudflare 主站 / Aliyun 备用站的本地控制面故障切换、后台重试、连接保留和恢复能力。

## 固定 endpoint

- Primary: https://v3.leikwanhost.com
- Secondary: https://api-leikwanbridge.nbwly.com

Linux 安装器生成的配置必须把 Cloudflare 写入 master_url，把 Aliyun 写入 panel_mirror_urls。Windows/macOS 的本地配置仍保留现有 master_url 字段，并由客户端内部维护备用 endpoint，不要求服务端新增字段。

## API 兼容约束

禁止修改：

- /api/client/configs/pull
- /api/client/ultra/lease
- /api/client/heartbeat
- 登录、client 选择、diagnostics、update 请求的路径和 JSON 字段
- forward、Ultra policy、lease 响应结构

允许修改：

- 客户端 endpoint 选择、超时、重试和熔断
- 客户端本地配置新增兼容字段
- Linux 安装器默认配置
- 客户端本地日志、pull state、watchdog 状态

## 统一 endpoint 策略

客户端维护当前 endpoint、主/备用 endpoint、失败次数、熔断截止时间和最后成功时间。

- 仅网络错误、超时、连接断开、HTTP 502/503/504/530 触发切换。
- 401/403/409 和其他明确业务响应不触发 endpoint 切换。
- GET 与幂等请求允许有限指数退避重试。
- POST lease/heartbeat 只有在没有收到 HTTP 响应时才允许切换重试，不能重复提交已收到响应的业务请求。
- 备用 endpoint 成功后保持使用，后台探测主站恢复；主站连续成功后回切。
- 控制面切换不得停止已有 forward/runtime；恢复后增量 reconcile。

## Windows/macOS

在 Core 层新增可测试的 endpoint supervisor/transport abstraction，由 MasterApiClient 统一使用。Desktop polling、heartbeat、Ultra lease 和启动连接共享 endpoint 状态。

- 启动连接失败自动后台重试，不要求重新输入 token。
- polling/heartbeat/lease 失败时不清理已有 runtime。
- 连接恢复后执行一次完整 pull，再按 revision 增量 reconcile。
- UI 只显示非阻塞的 reconnecting/failover 状态。
- 原有 Windows/macOS API 调用者和构造方式保持兼容。

## Linux

- 安装器默认写入 master_url=Cloudflare 与 panel_mirror_urls=[Aliyun]。
- pull_client_configs、Ultra lease、heartbeat、diagnostics、update helper 和 check --live 统一使用当前可用 endpoint。
- 保留最后成功 pull 的 endpoint 和已有 forward runtime。
- 增加 agent heartbeat/watchdog 文件或 systemd watchdog 机制，检测进程存活但主循环停滞。
- systemd 仍保持 Restart=always，不替换现有服务拓扑。
- 主站恢复后自动回切，不停止现有 forward。

## 故障和恢复

- 双站均不可用：保留已有运行时，继续本地健康检查和后台重试；不主动 Stop All。
- 冷启动无可用控制面：显示/记录 reconnecting，等待恢复，不删除缓存 runtime。
- lease 临近过期但控制面不可用：保留旧 lease 到失效；失效后仅影响对应 forward，不影响其他 forward。
- 任何协议校验、权限或业务拒绝按原有错误路径处理，不伪装为 CDN 故障。

## 测试与验收

必须覆盖：

1. Cloudflare 网络失败切换 Aliyun。
2. Aliyun 失败回切 Cloudflare。
3. 502/503/504/530 切换；401/403/409 不切换。
4. lease 无响应时允许切换，有响应后不重复提交。
5. 双站不可用时已有 runtime 保持。
6. Windows/macOS 启动失败后台恢复。
7. Linux 安装器默认 endpoint 配置。
8. Linux check --live、heartbeat、lease 使用当前 endpoint。
9. Linux watchdog 能识别主循环卡死。
10. 既有 API 请求路径、字段和服务端测试全部保持通过。

