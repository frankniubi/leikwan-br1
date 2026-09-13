# Ultra 线路隔离自动复审（源主机复核 + 设备追责）— Handoff

Date: 2026-09-13
Repos: `leikwan-br1`（panel + source-agent）
部署：面板 `173.208.183.93`（PHP-FPM **8.3**，站点 `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`）、源主机 `10.10.10.171`（`full-9929-vm`）

## 为什么需要它

信誉是靠**客户端上报**建立的，而客户端的本地网络坏了就会把好线路报成死线。隔离一条线路容易，但它
没有出口：隔离到期只会变成 probation，`backoff_level` 保留，下一次被上报就再次隔离。

源主机是唯一能从**供应商侧**看同一线路的观察点，所以现在由它来反驳这些上报。

## 机制

面板 `UltraCandidateReviewService`：

| 常量 | 值 | 含义 |
| --- | --- | --- |
| `REVIEW_SECONDS` | 600 | 源侧必须保持干净的时间窗 |
| `MAX_OPEN_PER_SOURCE` | 2 | 每源每周期最多下发数（实际由代理申报的容量决定） |
| `STALE_RUNNING_SECONDS` | 1800 | 领单后多久没回报视为失联 |
| `MAX_ATTEMPTS` | 5 | 重试上限，超过即结案为 abandoned |
| `REENQUEUE_COOLDOWN_SECONDS` | 21600 | 结案后同一候选重新入队的冷却 |
| `DEMOTION_STRIKES` | 2 | 达到即降级该设备 |

流程：`GET /api/ultra/source/jobs?reviews_capacity=N` → `requestForSource()`（先 `expireStaleRunning()`
做全局回收，再 `enqueueQuarantined()` 入队、`claimPending()` 领单）→ 代理跑探针 → `POST /api/ultra/source/review`
→ `recordResult()`：

- **passed**：`liftQuarantine()` 移除隔离、清了 `quarantine_until`、`backoff_level` 回退一档、
  `last_reason=review_passed`；同时给每一个上报过该候选的设备记一次 strike（`last_rebuttal_at`）。
- **failed**：上报是对的，相关设备各**扣回**一次 strike 并解除降级（报告可以挣回信誉）。
- **abandoned**（5 次都没回报）：只结案，**不碰信誉、不碰任何设备**——没有任何探针反驳过这条线路。

被降级（strikes ≥ 2）的设备不再计入 `observerCount()` 的独立观察者要求，因此它单独无法再隔离一条线路。
管理台「Ultra 信誉」页新增两张表：被反驳的设备（client / device / strikes / 降级时间 / 最近反驳时间 / 最近出现）
与源复审历史（ID / 状态 / 源 / 线路 / 尝试次数 / 请求时间 / 完成时间 / 结果）。

## 171 上的落地

| 文件 | 说明 |
| --- | --- |
| `/usr/local/bin/ultra-source-agent.py` | `AGENT_VERSION=2026.09.13.1`，容量握手 + 探针调度 |
| `/opt/ultra-source/runtime/ultra_line_review.py` | 探针（0755），md5 `a9cc8b87b1bebf8a370bda023c9981ec` |
| `/opt/ultra-source/runtime/native_udp.py` | 探针依赖的信封协议客户端（0644） |
| `/etc/ultra-source-agent.json` | 新增 `review_script` / `review_interval_seconds=20` / `review_big_bytes=1400` / **`review_require_big_packet=true`** / `max_reviews_per_cycle=1` |

服务单元是 `ProtectSystem=strict` + `ReadWritePaths=/var/lib/ultra-source-agent /opt/leigod`：探针只读
`/opt/ultra-source/runtime`，中间状态写在 `state_dir`，无需改单元。

**投递路径**（面板到不了 171，本机也直连不了 171）：

```
pscp -> root@180.153.83.218:2125（密码见 leigod-forward/credentials.txt）
plink 180.153.83.218 -> sshpass scp/ssh root@10.10.10.171（同密码）
面板侧另有 WireGuard 隧道 cn，10.10.10.0/24 直达，但面板 /root/.ssh 为空、无凭据
```

## 实测验证（2026-09-13）

复核 #3（线路 574）全自动跑完：面板入队 → 代理领单（`started_at=03:19:45`）→ 600 秒探针 →
`completed_at=03:30:13`：

```json
{"passed":true,"detail":{"armed_ms":4938,"big_failed":0,"big_ok":30,"dns_failed":0,"dns_ok":30,
 "max_echo_bytes":1410,"min_rtt_ms":0.076,"relay":"106.75.250.117:5438","rounds":30,"udp_failed":0,"udp_ok":30},
 "observers":6}
```

结果：线路 574 `tcp+udp` → **healthy、`backoff_level` 1→0、`quarantine_until` NULL**；
**6 台设备各 +1 strike**（client 3/20/25，设备名如 `macos-client-fcb4a…`、`windows-client-3-2…`），
管理台可见。复核 #1（574）与 #4（453）同样通过。

## 本次修掉的三个缺陷

1. **`mb_substr` 致命错误**：面板用 PHP 8.3（php-cgi-83）服务请求，**没有编译 mbstring**；CLI 是 8.2，
   有 mbstring。所以「CLI 测试全绿」什么都证明不了。只要有待入队候选，`/api/ultra/source/jobs` 就 500
   （`Call to undefined function …mb_substr()`）。这也解释了 2026-09-12 19:43–21:01 的间歇 500：隔离到期
   后自行消失，隔离一恢复就复发。现全部走 `function_exists` 守卫，与 `UltraInventoryService`、
   `UltraClientStatusService` 既有写法一致；管理台表格里那唯一一处未守卫的调用也一并加上。
2. **队列永久泄漏**：`expireStaleRunning()` 原来只在 `attempts < 5` 时重排队，超过就把行永远留在
   `running`——既不再下发，又因「有未结复核」而被 `enqueueQuarantined()` 跳过，于是仍在隔离的候选**再无出路**。
   线上实例：复核 #2（线路 325）`attempts=5`，从 22:38 一直卡到修复后 03:07:38 才被结案。现在超预算的
   **结案为 failed 并记 `{abandoned:true}`**，且不牵连任何信誉/设备；结案后有 6 小时冷却再入队。
3. **容量握手 + 签名**：面板过去一次下发 2 条，而代理每周期只跑 1 条，第二条必然空转到过期（浪费 30 分钟
   和一次尝试）。现在代理在 jobs 请求里申报 `reviews_capacity`，面板按此下发；不申报的老代理**一条都不发**
   （发了也不会回报）。另外该参数**不能进签名**：面板签名覆盖 method/path/source_id/timestamp/nonce/body hash，
   把 query 一起签会导致每次轮询 401，代理现在只签裸路径，两侧都有测试钉死。

## 运维速查

```bash
# 面板：看隔离候选、复核队列、设备追责
php /tmp/review-status.php          # 只读快照（脚本见本仓 .tmp/review-status.php）

# 面板：复核服务单测
php master/tests/ultra_candidate_review_queue_test.php

# 171：手工对某条线路跑探针（先停服务，避免与调度抢 session）
systemctl stop ultra-source-agent
python3 /tmp/probe171.py 325 90     # line_id 秒数
systemctl start ultra-source-agent
```

备份：
- 面板 `/root/leikwan-br1-backups/<UTC stamp>/`（`UltraCandidateReviewService.php`、`UltraSourceController.php`）
- 171 `/root/leikwan-source-agent-backups/<UTC stamp>/`
- 诊断补丁 `master/public/index.php`（`*.bak-diag`，仅把异常 message/file/line 加进 error_log，**可保留**）

## 遗留

- 面板 hash 审计仍有 2 个缺失产物：`gost_linux_amd64`、`leigod_linux_amd64`（与本次无关）。
- 面板全量测试 29 个既有失败（leigod 混合 schema、provisioning sidecar、knowledgebase、i18n），与本次无关。
- 线路 453 的候选带着 `last_reason=manual_review_e2e`（本次端到端验证手工置入的隔离），复审通过后会自行清掉。
