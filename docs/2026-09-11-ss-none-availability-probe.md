# SS-none 24h 可用率探测

`tools/ultra-client/ss-none-availability-probe.py` 是一个无第三方依赖的长驻探测器，直接验证本机 `127.0.0.1:10001` 的 SS `method=none` wire path：

- TCP：发送 address-first request 到 `1.1.1.1:80`，验证 HTTP 响应；
- UDP：发送 SOCKS5 UDP envelope 到 `1.1.1.1:53`，验证 DNS transaction ID/response bit；
- 默认每 30 秒一次，维护滚动 24 小时窗口；
- 只有状态转换和每 5 分钟汇总写日志，掉线马上输出 `ALERT`，不输出密码；
- `status.json` 原子替换，`events.jsonl` 只保存时间、TCP/UDP 成功和错误码，不保存凭据。

`method=none` 的 Shadowsocks wire format 不使用密码做加密/认证；探测配置仍保存用户指定的密码，用于确保目标配置完整，但不会写入日志、状态或 wire payload。

## 状态位置

- Linux service：`leikwan-ss-none-probe.service`
  - 配置：`/etc/leikwan-ss-none-probe/config.json`（`0600`）
  - 状态：`/var/lib/leikwan-ss-none-probe/status.json`
  - 事件：`/var/lib/leikwan-ss-none-probe/events.jsonl`
  - 查看：`journalctl -u leikwan-ss-none-probe -f`
- macOS LaunchAgent：`com.leikwan.ss-none-probe`
  - 配置/状态/事件：`~/Library/Application Support/LeiKwan/SSNoneProbe/`
  - 日志：`~/Library/Logs/LeiKwan/SSNoneProbe.{out,err}.log`

探测服务本身不会替换或重启 Ultra 客户端；如果本地 10001 没有监听，状态会明确为 `down`，错误码通常是 `tcp_connection_refused+udp_io_error`，避免把“客户端未启动”伪报成线路在线。
