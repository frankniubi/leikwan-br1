# Ultra single-reader 修复与 forward-170 验证（2026-09-16）

## 代码与部署

- 分支 fix/ultra-probe-single-reader；修复提交 6d189471。master 保持 55c0cbcb，未合并或推送。
- 独立 worktree leikwan-br1-canary 从修复提交构建，未包含 external_uot WIP；其他未提交改动保留。
- Windows 包测试 go test . -count=1 -timeout 600s 通过（162.194s）；不代表 Linux race 检测通过。
- forward-170 / 172.21.255.189 于 19:11:57 CST 计划重启一次，PID 4089893 -> 4168421。
- 版本 2026.09.16.2-reader.6d189471；二进制 /usr/local/libexec/leikwan/ultra-client-2026.09.16.2-reader.6d189471。
- SHA256：9455177caf38c5f0f20e9d6fd495277a154e964621088c8360c3940f1f3e002b。
- drop-in：/etc/systemd/system/ultra-forward-170.service.d/90-reader-fix.conf。
- 共享 /usr/local/bin/ultra-client 未替换，其他 forwards 未推广。
- 备份及回滚：/root/ultra-reader-fix-20260916-2/rollback.sh；回滚会删除本次 drop-in 并重启目标服务。
- 部署前后 forward-170.json 哈希一致，不延伸为后续配置永不改变。

## 实测

单发 canary：TCP 402B/1.114s、UDP DNS 71B/0.051s，均成功。
尺寸阶梯每档 20 秒、30 pps、600 包，每档是独立 soak，不是同一个 association。

| payload | 隧道接收/发送 | 直连丢包 |
| --- | --- | --- |
| 256/512/768/972/973/1000/1200 bytes | 每档 600/600 | 7.67%–14% |
| 1400 bytes | 0/600 | 12.33% |
| 1472/1500/2000/4096/8192/12000 bytes | 每档 0/600 | 每档 100% |

隧道 TCP 每档 20/20 心跳，无断连。部分小包档 p95 达 2.1–3.9 秒，不能称为整体低延迟验收通过；1200-byte 档 p95=50.28ms。
1400-byte 失败原因未隔离：本地拒绝、探测目标语义、relay 限制和 MTU 仍需区分。上述丢包不能单独证明 provider 上限或具体 MTU。
972/973 在本路径通过，不代表修复了另一项目 WireGuard MTU 1000 的边界。

## 大包后恢复

- 1400–12000-byte 档共发送 4200 包，未收到 echo。
- 恢复脚本未重启服务；11:27:15Z 开始记录，先等待 45 秒，再预热与测试。
- 11:28:00Z 起 60 秒、256 bytes、10 pps：600/600，0% 丢包，p50=47.98ms、p95=49.37ms。TCP 心跳 60/60。
- 前后 PID=4168421、NRestarts=0、启动时间=19:11:57 CST 相同，attestation 匹配上述版本及完整 SHA256。
- state 前后 ready；oversize drops 4063 -> 4063；keepalive ACK 4973 -> 5890；HA failures 33 -> 34。
- 仍有 io_error/reset/closed 等事件，不应声称无故障。已保存恢复窗口 journal 中 keepalive_timeout 计数为 0，不延伸为整个部署期间零故障。

证明此轮大包之后无服务重启的小包窗口可正常通信；未测定即时恢复时间、是否更换 association，未做同候选旧版 A/B。原旧版报告也有一次恢复成功，不能声称旧版总是必须重启。

## 证据及结论

本地 tmp/udp-size-diagnosis-linux/*.summary.json 与 tmp/udp-recovery-linux/ 保存结果、journal、status、unit 前后快照和时间。
完成隔离分支修复、包测试、forward-170 定向部署、canary、大包测试和小包恢复验证。1400 bytes 及以上仍不通，大包可用性未通过；不能据此推广全线路或声称所有 UDP 故障已解决。
