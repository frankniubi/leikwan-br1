# client 3 广港 / 广新双平台并发对照测试

## 判定

**四路 UDP PASS；整体非全通过：macOS 广港 TCP 控制出现 18 次 headers_eof。**
本次未复现 HK/SG 同时开启造成一方 UDP 掉线，不能据此排除原报障客户端及其目标环境的问题。

## 测试范围

- 时间：2026-09-20 17:14:12–17:24:12（UTC+8），测量600秒，排空至约17:24:24。
- client 3 / service 30：新建 HK #198（60151）、SG #199（60152）。
- Mac、Linux各同时跑两条线路。每路 UDP 下行20,000,000 bit/s，1200字节，每路1,250,000包；四路共80Mbps、5,000,000包。
- 每路并行TCP控制负载目标1Mbps、1 worker，校验HTTP正文哈希；不是TCP 20Mbps压力验收。
- 保持线上raw UoT，未强制reliable_datagram_v1；未做直连Internet UDP基线。
- UDP路径：client -> leased Ultra provider relay -> 对应线上external-UoT sidecar:7443 -> 授权测试目标:60051。
- 目标与sidecar同机，因此不包含第三方目标的最后一跳。
- TCP路径：client -> leased Ultra provider relay -> 测试HTTP目标，不经过UoT封装。

## 结果

| 平台 | 线路 | UDP 收到/目标发送 | UDP Mbps（测量窗内） | TCP 成功/失败 | Runtime PID |
|---|---|---:|---:|---:|---|
| macOS | HK | 1,250,000/1,250,000 | 19.99731 | 520/18 | 77874 |
| macOS | SG | 1,250,000/1,250,000 | 19.99386 | 505/0 | 77879 |
| Linux | HK | 1,250,000/1,250,000 | 19.99450 | 511/0 | 2167245 |
| Linux | SG | 1,250,000/1,250,000 | 19.99054 | 480/0 | 2167293 |

四路UDP均零丢失、零重复、零无效包。收到包数包含排空期，吞吐仅计600秒测量窗内。目标进程四份独立发送回执均匹配run ID，sent_ok=1,250,000、send_errors=0。

## 异常：Mac 广港 TCP

- 18次失败均发生于HTTP响应头阶段，unexpected EOF，正文收到0字节，耗时约1.02–1.04秒。
- 时间：17:18:55.586–17:19:38.614（UTC+8）。
- 同期HK/SG UDP继续接收且最终完整，四个运行时PID未变。
- 最近采样HK TCP candidate hash前缀40ac894c610f，HA记录有eof/reset/no_response；最近采样不能精确归属每次请求，不据此断言provider根因。
- 这是TCP请求路径异常，不是已经证明的UoT会话互踢。具体断开点尚未定位。
- 证据：mac-hk-tcp-failures.json、mac-result-hk.json、mac-metrics.jsonl。

## Runtime与监控

- Mac实际运行1.0.45，Darwin arm64；Linux x86_64、agent 2026.09.19.1。
- 两平台运行时指纹：2026.09.19.1.f18d01fb。
- Mac runtime SHA256：a02191fbd33910b6e611e13f77f1891ddcefc0319903450aa28cd896f667066f。
- Linux runtime SHA256：a391e5b9388b86d53bef9e8cfc44e248bfba9db1a14b903e5863dd37b39acd6e。
- 两台sidecar SHA256：c6d11563b8c1b8a28f2d8b3a9422d4215ccc67755a0d0d32e059eba40dfd5317。
- 四个runtime采样queue_drop/queue_timeout/queue_blocked均0。Linux socket_receive_drop=0；Mac不支持，为null。
- HK sidecar PID1045490、SG PID619199，测量期未变，自动重启0。
- 已观察关闭会话未见queue_drops/oversize_drops/reattaches非零。每台已捕获一条完整业务关闭统计udp_in=1,250,005（含5个完成回执）、udp_out=60，发生于测量结束后的清理期。
- sidecar已采样窗口内RcvbufErrors/SndbufErrors/InErrors增量均0。HK监控从测量开始约10秒后开始，SG因SSH/SFTP延迟从约157秒后开始，不能声称计数覆盖完整600秒。
- CPU/RSS逐路统计在verdict.json；CPU为ps平台特定采样值，不作为严格跨平台CPU基准。

## 变更与清理

- 创建前panel快照：/root/codex-backups/hk-sg-client3-20260920/before.json。
- 新forward通过生产ProvisioningService创建，同事务先停用，sidecar准备完毕后启用。
- sidecar不支持热加载：分别等待连续5次无已建立carrier，备份、校验新配置、设置180秒独立回滚，再重启一次载入绑定。重启在正式测量前，原有绑定字段未改。
- sidecar配置备份：两台/root/hk-sg-client3-20260920/sidecar-config.before.json。
- 独立目标hksg-c3-target设置2400秒寿命、256MiB内存限制及随机授权token。
- 测试后#198/#199均已停用；两平台60151/60152最终均无监听；两个目标服务停止，60051无监听；生产sidecar保持active。
- 测试forward记录及仅允许本机60051目标的sidecar绑定保留供复测，没有为删除绑定再次重启生产sidecar。私有授权值不写入报告。
- 常规setForwardEnabled(false)触发buildClientConfig()->forward_not_found并回滚。随后严格核对ID/client/service/port/backend/native标志，事务中只停用198/199并递增revision，最终回读确认。该既有停用API异常未在本次任务中改代码。
- Linux首次清理检查超时后，agent稍后完成撤销；最终再次检查两端测试监听均已消失。
- 最终比对client3所有原有转发enabled/client/port/target/combination/native字段，与测试前完全相同。

## 验证与限制

- Harness从master 52d81766的tools/uot-acceptance/main.go导出，仅增加plain固定目标模式与独立tcp-bps参数，不改产品二进制。
- 本机3秒自检6250/6250包、TCP3/0；四路10秒预检各20833/20833包。
- 原始requested_bps_per_protocol为旧字段20M，实际TCP已由tcp-bps覆盖为1M。以run-plan.json及verdict.json中的actual参数为准。
- 没有单线路600秒基线、第三方目标末跳验证、TCP20Mbps验收、可靠模式验收；不宣称任意网络下零丢包。

## 证据

目录：tmp/hk-sg-client3-20260920/。
- verdict.json、sidecar-verdict.json、combined-results.json：判定与统计。
- mac/linux-result-hk/sg.json：逐路结果和完整tcp_events。
- mac/linux-metrics.jsonl：客户端监控。
- hk/sg-sidecar-final.json：sidecar监控、journal和目标发送回执。
- panel-final-readback.json、mac/linux-cleanup.txt：最终状态。
- created-private.json、test-token.secret只保留本地，不提交、不输出。
