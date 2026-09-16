# Ultra single-reader 修复部署与真实验证（forward-170）

- 日期：2026-09-16（Asia/Taipei）
- 分支：fix/ultra-probe-single-reader（HEAD 6d189471，docs 5541aeb6；master 未改动）
- 目标：修复 probeEcho/runtime 双读者竞态（keepalive_timeout 假死），并在 forward-170 用真实流量完成 canary + 大包 UDP 测试

## 1. 修复内容

- tools/ultra-client/ha_udp.go：单一 reader goroutine 独占 socket 读取，probe 经 channel 分发（readerOwned/probeWaiter/dispatchProbeEcho）
- 单元测试覆盖：probe 等待、竞态防护、错误传播
- 分支全量测试：ok leikwan.local/ultra-client 162.194s（0 失败）

## 2. 部署（backup-first，单次计划重启）

| 项目 | 值 |
|---|---|
| 主机 | 172.21.255.189（instance-20241020-0131，x86_64） |
| 服务 | ultra-forward-170.service |
| 旧版本 | 2026.09.16.1.e0e4a71f（/usr/local/bin/ultra-client，PID 4089893） |
| 新版本 | 2026.09.16.2-reader.6d189471（/usr/local/libexec/leikwan/，sha256 9455177caf38c5f0f20e9d6f...） |
| 部署方式 | drop-in 90-reader-fix.conf 覆盖 ExecStart → daemon-reload → restart |
| 新 PID | 4168421（active，NRestarts=0，全程未再重启） |
| 备份 | /root/ultra-reader-fix-20260916-2（二进制+unit+config+pre.sha256+rollback.sh 0700） |
| 回滚 | bash /root/ultra-reader-fix-20260916-2/rollback.sh（删 drop-in + daemon-reload + restart） |
| 配置 | /etc/ultra/forward-170.json 哈希前后一致 |

## 3. Canary（runtime 本地 SOCKS 127.0.0.1:10001）

- ha-canary.py 单发：TCP 402B/1.114s ok；UDP 71B/0.051s ok
- 尺寸阶梯（30pps × 20s，direct 基线 185.220.238.143:60000）：

| 内层包长 | 隧道丢包 | 直连丢包 |
|---|---|---|
| 256-1200B（7 档） | 0.00% | 7.7-14% |
| 1400B | 100%（路径上限） | 12.3% |
| 1472-12000B | 100% | 100%（端点 MTU，无意义档） |

- 973B 边界消失：0% 丢包（旧版 972/973 EMSGSIZE 崩塌不复现）
- TCP 心跳全程 20/20，无 send_errors

## 4. 大包毒化 + 免重启恢复（决定性）

- 毒化输入：1400/1472/1500/2000/4096/8192/12000B × 600 包 = 4200 超限包
- 毒化后不重启，256B @ 10pps × 60s：sent=600 recv=600，loss=0.00%，rtt p50=47.98ms p95=49.37ms（无尾延迟）
- TCP 心跳 60/60；PID 未变；status.json 无 keepalive_timeout/probe race 标签
- 对比：旧二进制同场景 keepalive_timeout 假死，256B 全丢直至重启

## 5. 边界与未验证项

- 1400B 隧道 100% 丢包为本路径 provider 上限（直连同样高丢包），与读端竞态无关；生产安全上限仍按 ≤1200B
- 本验证仅覆盖 forward-170 单主机；其他 forwards/线路未移植未验证
- 长时（>1h）稳定性未测；无重负载并发 UDP 流场景

## 6. 结论

single-reader 修复在真实 forward-170 流量下验证通过：小包 0 丢失、973B 边界消失、大包毒化后免重启自愈、PID/服务全程稳定。分支已具备合并 master 的证据基础。
