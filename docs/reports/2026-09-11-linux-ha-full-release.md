# Linux HA 2026.09.11.2 全量发布记录

## 发布结果

- 发布通道：正式 Linux amd64 / arm64，不再仅 forward-170 灰度。
- 本地 `master` 制品提交：`7bb86beddd520828fdc2c3e35538c5fdad32af0f`。
- runtime 编译源码：`8ed729059d1c1fa1fbb3185ef897519af0b81d2f`；`--version` 为 `2026.09.11.2.8ed72905`。
- GitHub `master` 发布提交：`17fde9297ee6c1046283c1d0174bdc6056128551`。本地历史与远端 sanitized 历史不同，按相同发布文件复制到远端基线后提交，未 force-push。八个部署文件已逐一确认本地提交、远端提交、服务器一致。
- 生产发布服务器：`173.208.183.93`，核验 hostname `leikwan`、UID 0、实际站点目录 `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`。
- 03:11:33（2026-09-11，UTC+8）发布完成；先原子替换制品，最后切换 manifest。
- `.env` 未修改；迁移前后 applied=92、pending=[]，本轮未改 PHP/schema，未运行无关迁移或重启面板服务。

## 已发布的修复

1. UDP 单 reader 消费预热和业务保活响应；ACK 不泄露到业务层；连续无有效回应约 15 秒后关闭单关联，下一报文选择备用候选。
2. TCP 在发送业务前按有界总预算尝试候选；兼容 Lease 刷新不误拒绝；已建立连接不透明迁移或重放。
3. 保留当前 master 的 candidate identity/feedback、候选隔离与 native 支持，兼容本轮按协议隔离和刷新后故障处理。
4. Ultra 冷启动 90 秒宽限；至少三次、跨度 10 秒监听缺失才进入重启流程，避免一次探测失败中断全部连接。
5. updater 不再下载前停止所有 forwards/Agent；manifest 与 Agent 内容/版本预检失败则保留现有服务。
6. 安装器先验证临时文件 SHA256，再原子替换目标；失败不删除正在使用的目标文件。
7. 更新锁跨 token 生效；Agent 更新冷却不再被 token 轮换绕过。
8. ARM64 单文件安装器重新打包，嵌入新 Agent、runtime、updater 与对应 manifest。

## 测试和下载验收

- Windows Go 全套测试 PASS（25.047 秒），`go vet` PASS。
- Linux `go test -race -count=1 ./...` PASS（22.970 秒）。
- Ultra Python 回归 48 项、installer helper 23 项、实际 updater 内嵌预检代码测试 1 项（六种有效/无效发布组合）、ARM64 installer 5 项通过。
- 两次 ARM64 installer 构建哈希一致；这是构建验收，不是 ARM64 真机运行验收。
- Cloudflare 主入口 `https://v3.leikwanhost.com` 与备用入口 `https://api-leikwanbridge.nbwly.com`：六项发布制品的实际下载长度、SHA256 与新 manifest 全部匹配。
- 生产登录页和备用入口 health 均 HTTP 200。

| 组件 | SHA256 |
|---|---|
| Agent | `876bae8d197c8d90b7a3893a0259927b708e9ec31e85af496d3271035071853b` |
| installer | `980eee89a89c215f5b3ca08d710061a749e73bc9e5b622edfeb6c19503eafdad` |
| updater | `825a6522029a4ec2ef1031b53b39d28404709a9e0c36f7b3cf7226713977f904` |
| runtime amd64 | `e546aac8296cc94e041f0ae1c1563f9e0c39210b9931047d5596e47131de4a5b` |
| runtime arm64 | `4e6da7a3356dae60b2f8b3c0dbc2986287af249a33bf31ceaa9dd42a0331d7e9` |
| ARM64 单文件 installer | `f49e43baacf34594bcc0347978c6f2a025355df76b2e9f5612a5ed28ccc2db14` |

## 172.21.255.189 实际更新验收

- 03:13:28 移除 Agent 更新暂停 drop-in、重启 Agent；03:13:33 Agent 自动检测并请求 `2026.09.11.2`，不是只验证文件上传。
- 安装完成，更新状态 `success`、`installed_version=2026.09.11.2`，Agent/updater/共享 runtime 三个磁盘哈希匹配正式制品。
- 自动下载安装期间 forward-170 PID `1781553` 保持不变。
- 随后无活跃 TCP 会话时移走 forward-170 灰度 ExecStart 覆盖，进行一次计划 runtime 升级重启；新 PID `1796781`，`/proc/1796781/exe` 哈希匹配正式 runtime。
- 新版现场 TCP HTTP 返回 402 bytes，UDP DNS 返回 71 bytes，均通过原有拓扑。
- Agent PID `1796046`，不再受原先 24 小时暂停控制；原截止时间已不适用。
- 升级前一次 TCP canary 出现上游 reset/空响应，UDP 成功；新版启动后两项通过。没有据此断言所有公网目标永不失败。

## 备份、发布异常和剩余范围

- 面板 root-only 备份：`/root/codex-backups/linux-ha-20260911-2`。应用 tar 和数据库 gzip 均验证通过，含 `before.json`、`release-plan.json`、`env.sha256`、迁移状态与部署提交。
- 客户端备份：`/root/ultra-ha-20260911-2`；移走的暂停配置和灰度 ExecStart 保留在该目录。
- Git 发布曾因新 sparse worktree 未完整初始化 index 而意外提交缺失路径（`552bd2fb`）；立即以追加提交 `17fde929` 恢复。已验证相对旧远端 `421e71e8` 无任何删除、任务外文件不变，未对生产部署该缺失树。
- “全量发布”指正式制品对全部 Linux 客户端可用，不等于所有离线客户端已经安装，也不为立即加载二进制而强制重启所有客户长连接。
- `sh36.ctyun.mcbar.cc` 只读抽查发现既有 Agent 启动失败：本地 config JSON 在首字符解析失败，旧版本且反复重启，无法正常自动更新。本轮未猜测身份配置或覆盖该主机配置；需要单独恢复正确配置后更新。
- Windows/macOS/Android 应用包未在本轮重新发布；ARM64 真机、长期业务和现有 TCP 无缝迁移不在已验收结论内。
