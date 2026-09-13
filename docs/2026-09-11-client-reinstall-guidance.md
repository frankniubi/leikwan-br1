# 旧客户端重装建议

## 适用范围

旧版本如果显示 `up-to-date`，但本机 runtime 未实际替换，建议不要在原目录上继续叠加修复。先保留配置和日志，再卸载旧客户端并安装最新发布包。

## Windows

1. 退出 LeiKwan Bridge 和托盘进程。
2. 在系统“应用和功能”中卸载旧版本。
3. 删除旧安装目录中残留的 `bin`、`runtime` 和 `updates`，不要删除用户配置备份。
4. 安装 `0.1.20.27` 或更高版本。
5. 启动后确认主程序版本、`bin\\ultra-client.exe` hash、运行进程路径和实际监听端口。

## macOS

1. 停止连接并退出应用。
2. 删除旧的 `LeiKwan Bridge.app` 及旧更新缓存目录。
3. 保留 Keychain、登录配置和必要日志。
4. 安装线上已发布的对应架构 DMG；`1.0.27` 正式包上线后，所有旧版统一切换到该版本。不要把本地 fallback 包当作正式发布包。
5. 启动后确认 `.app/Contents/Resources/bin` 下的 runtime hash 与 manifest 一致。

## Linux

1. 停止目标 client-agent/forward 服务。
2. 备份 `/etc/leikwan`、agent 配置、systemd unit 和 state 目录。
3. 运行 Linux runtime inventory，确认 agent、Ultra、bond、UoT、MPTCP 的版本和 hash。
4. 无法确认安装来源或 hash 时，删除旧 runtime 后重新安装最新 Linux 包。
5. 启动后验证 PID、监听端口、lease、TCP canary 和 UDP 业务 canary。

## Android

卸载旧 APK 前导出必要配置；重新安装最新 APK，并重新确认 VPN 权限、设备会话、lease 刷新和真实 UDP 流量。

## 判定标准

只有同时满足以下条件才可认为更新完成：

- 主程序版本正确；
- 所有实际使用的 runtime hash 正确；
- 运行中的 PID 来自当前安装目录；
- lease 有效且 generation 正确；
- TCP 和 UDP 真实业务 canary 均通过。
