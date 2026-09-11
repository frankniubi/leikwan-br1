# Ultra 高可用与快速切换修复文档

日期：2026-09-11。范围：`tools/ultra-client`，首台验收 `172.21.255.189` 的 forward-170 / 10001。

## 1. 事实与边界

- 01:15 左右无服务重启证据；01:15:25 是最后一次流量上报时间，不是精确断线时间。累计 TCP 占比高，但不能据此证明当时某条连接的具体关闭原因。
- 选线日志不等于重启。旧实现丢弃 TCP 复制错误；UDP 预热 socket 无 reader，现场响应堆满 213504 bytes 并持续丢包。
- 新 UDP DNS 探测成功不能证明已有业务链路健康，更不能代表 TCP 目标可达。
- 不重放已发送的 TCP 业务数据，不宣称透明迁移 TCP。UDP 切换可能改变出口 NAT 映射，应用是否接受由业务协议决定。
- 不因 generation 变化强制迁移健康关联；不延长真实授权过期，不绕过撤销/配额限制。

## 2. 方案取舍

不采用故障即 systemctl restart（破坏全部会话），也不采用无条件并行向多候选发送业务（重复请求）。采用监听保持、连接粒度失败恢复、有限候选集、有限时间预算。

### TCP

1. 原子安装最多四个已授权候选，保持现有 API/Lease 格式，不依赖未部署的 candidate 数据库迁移。
2. 新连接按选中候选优先顺序重试；总预算沿用 connect timeout，各候选公平分配剩余时间。业务读取在握手成功之后开始。
3. 短期隔离失败候选，按协议区分 TCP/UDP；刷新相同路径不清空隔离。所有候选失败时停止本次连接而非忙循环。
4. 已建立连接正常 EOF 使用 half-close，保留反向数据直到其 EOF；真正错误和 runtime 取消关闭两端。日志记录方向、分类、持续时间、generation 与 selection_ref，不含凭据/业务载荷。

### UDP

1. 每个 runtime DatagramSession 使用唯一 reader；预热阶段也消费响应，交接时不启动第二 reader。
2. 只在合法 keepalive 响应或合法下行数据时刷新接收活性；每 5 秒发送，连续约 15 秒无有效响应后判定失联。已有协议没有 nonce，不擅自改上游格式；响应由 connected socket 绑定来源，不能把本地 Write 成功当 ACK。
3. 失联关闭单个关联并隔离其 UDP 候选，后续客户端报文创建新关联；不重放此前已发送数据。预热 worker 自行重建备用关联。
4. 有界响应队列，有界 pending 队列，有界 setup 并发；过载丢弃并计数，不阻塞所有客户端。
5. 不单凭目标应用不回包判死：保活 ACK 可维持健康；ACK 也只证明 Relay 存活，不等于最终目标业务可用。

### 状态与恢复

- 原子安装 Lease 后更新实际生效 generation；新增持久计数/最近故障记录，不让下一次成功探测擦掉历史故障。
- 保留 ready 的兼容字段，增加观测字段区分 probe、active association 和 keepalive；不改旧客户端 API。
- 暂时的下发失败保留最后有效 runtime；真实过期/撤销继续执行已有取消逻辑。

## 3. 执行任务与验收

- [x] 从当前 master `f41c1464` 建立独立 worktree；保留主目录全部未提交改动。
- [x] 恢复构建依赖：master 缺失 `opengfw/`，使用既有 `origin/master` 同路径的已提交内容，不替换过滤器为 stub。
- [x] 基线 `go test ./...` PASS。
- [x] TCP：先编写候选故障、总预算、取消、隔离、刷新/撤销、half-close 回归测试，观察 RED，实现后 GREEN。
- [x] UDP：真实 loopback 验证预热消费响应、无 ACK 超时、有效 ACK 保活、失效队列关闭与运行时关联恢复。
- [x] 状态：generation 更新及故障历史不被探测覆盖。
- [x] Linux `go test -race ./...`，Windows 测试与 Linux amd64/arm64 构建。
- [x] 检查 diff，提交精确代码和文档，产物由提交代码构建，记录 SHA256。
- [x] 首台生产备份（二进制、unit、配置）目录权限 0700；仅替换目标服务二进制，保留其他服务和配置。
- [x] 无活跃 TCP 会话时执行计划升级，验证 UID/hostname/PID/监听/Lease、真实转发及保活不再堆积；另发现旧 Agent 冷启动误判引发一次额外重启，已补修。
- [x] 准备回滚脚本及备份，未为测试回滚而中断生产。记录版本、测试、部署范围和仍需真实业务验证的边界。

## 4. 快速切换指标

- TCP 黑洞候选：新连接总建立尝试不超过配置总预算（调度容差另计）；已隔离候选不再次拖慢紧接着的新连接。
- UDP：连续无有效响应约 15 秒发现，随后按有限握手预算恢复；不能承诺公网每次恢复均在此上限内。
- 健康 generation 刷新：监听 PID 不变、已建立流不被主动关闭。
- 预热保活：持续 60 秒不产生累计接收队列增长；单次漏包不重启服务。

## 5. Agent 高可用补修

### 冷启动与故障判定

- 实际升级时发现旧 Agent 在 runtime 尚待首份 Lease/探测完成时，通过连接本地端口判定故障，14 秒即重启。
- 新实现对 Ultra 冷启动给予 90 秒宽限，不延长授权期限。
- 使用本地 TCP/UDP 监听存在性检查，不通过业务连接触发上游请求；单次检查失败不重启。
- 连续至少三次失败、跨度至少 10 秒，才进入既有 30 秒重启冷却。监听恢复立即清除失败计数。
- 真实服务停止、明确停用、真实授权撤销/到期仍按原逻辑处理。

### 发布源不一致：已止血，尚未根治

02:47 后观察到 Agent 自更新循环：面板先要求哈希 `54d146...`，又要求版本 `2026.09.11.1`，但更新后的本地文件仍为 `2026.09.10.2` / `54d146...`。仅成功上传新文件不能算发布成功。

新 Agent 支持 root 本地环境变量 `LEIKWAN_UPDATE_HOLD_UNTIL`：有效期最多 24 小时，暂停显式/自动更新调度，但继续接收业务配置、续租、健康检查；不把未安装的更新标记成功。无效或过期值恢复正常更新。此机制是灰度发布暂停，不是发布源一致性的最终修复。

目标主机暂停截止：**2026-09-12 02:53:27 +08:00**。到期前需要完成第 9 节发布源收敛，否则旧更新循环可能再次出现。

## 6. 实现、测试与构建结果

### 代码来源

- 独立分支：`codex/ultra-ha-20260911`，目录 `.worktrees/ultra-ha-20260911`。未覆盖主工作区未提交改动，未强推或强行合并。
- runtime 源码提交：`56b7fad4`；版本 `2026.09.11.1-ha.56b7fad4`。
- Agent 源码提交：`540c0b31`；版本 `2026.09.11.1`。包含此前现场 candidate-feedback 增补，未覆盖并行修改。
- `opengfw/` 从已有 `origin/master` 恢复，tree `d638d2d7bce109fe51874def955104d2d2834bd3`；没有使用空实现替换过滤器。

### 验证结果

- Windows `go test -count=1 ./...` PASS（22.696 秒）；`go vet ./...` PASS。
- Linux `go test -race -count=1 ./...` PASS（最终 runtime 提交：22.942 秒）。使用已有 Go module cache 避免下载代理超时。
- 先验证失败再修复：握手期间兼容 generation 刷新误拒绝、UDP 首包丢失、刷新后旧关联失联无法隔离当前同一路径。
- 真实 loopback 集成测试覆盖：同一监听和客户端 UDP tuple，健康 generation 刷新不迁移，主路径停止 ACK/响应后约 15 秒切至备用；runtime 无重启。
- 单测覆盖：候选重试、总超时、取消、协议隔离、刷新保留隔离、撤销、TCP half-close、UDP ACK 消费、无 ACK 超时、非法帧不得续命、首包重试和独立故障状态。
- Python：HA/startup 5 项、Ultra status 6 项、Ultra client 17 项通过；此前 bond health 50 项、MPTCP grace 6 项通过；`py_compile` 通过。
- 修复测试 fixture 中原有 data race（`echoOuterTarget` 改为 atomic.Bool）；未削弱生产逻辑。
- 不是全仓库、所有平台、真实游戏的完整验收。

### 可复核产物

构建：`garble v0.17.0 -literals -tiny`、`CGO_ENABLED=0`、PIE、trimpath、stripped；现场确认 amd64 ELF DYN、GNU_RELRO 与非可执行 GNU_STACK。ARM64 仅编译，未上硬件验收。

| 产物 | SHA256 |
|---|---|
| Linux amd64 runtime | `3d274b56d1ddfea8d3b9a79c8c500b30414d5e437736597e27849783d61c2da5` |
| Linux arm64 runtime | `c3881a4193095f93d16ceef0b95b2b0ab54d5266855ff3d4f74510c67ca6ba10` |
| Agent（LF，提交 540c0b31） | `995d53ec3ad71fb9439752492aba1350068b39f6508861c2346a4d15a20b923d` |

## 7. 生产执行记录（UTC+8）

- 主机核验：`instance-20241020-0131`，UID 0，SSH 最终目标 `172.21.255.189`。
- root-only 备份目录：`/root/ultra-ha-20260911-1`，权限 0700，包含原二进制、原 unit、配置、Agent 和执行记录。
- runtime 使用版本化路径 `/usr/local/libexec/leikwan/ultra-client-2026.09.11.1`；仅 `ultra-forward-170.service` 添加 `90-ha-canary.conf` 覆盖 ExecStart。
- 共享 `/usr/local/bin/ultra-client` 未替换，其他 forwards 的二进制未推广；Agent 则为这台主机的共享管理进程，新健康检查分支仅作用于 Ultra。
- 02:41:00 开始计划升级；02:41:14 旧 Agent 因冷启动误判再次重启 runtime；02:41:48 监听就绪。不能把该维护窗口说成完全无中断。
- runtime 此后 PID `1781553`；跨 Agent 更新及观察窗口保持不变。`NRestarts=0` 本身不代表没有手工重启，本记录以 PID 与 journal 为准。
- 02:48:01—02:49:16：ACK 123 → 138；runtime UDP socket Recv-Q 均 0、drops 均 0，状态 ready。
- 02:53:27 部署带更新暂停的 Agent，PID `1789709`；runtime PID 不变。
- 02:53:33 端到端 canary：TCP HTTP 返回 402 bytes / 0.120 秒；UDP DNS 返回 71 bytes / 0.048 秒，均通过原拓扑。
- 02:53:54—02:55:24 最终观察：Agent 与 runtime PID 均不变，无新的 self-update/restart 日志；Agent 与配置哈希匹配。再次 TCP 402 bytes / 0.129 秒、UDP 71 bytes / 0.050 秒通过。
- 配置前后 SHA256 未改变：`7e570db7a2a2c5ad394c5a6430c3411a33131232470ae6212f9abd5be4e574ad`。
- 实际上游提前发送 FIN 的 canary 曾得到空响应；普通请求正常。只能确认本地 half-close 回归测试通过，不能声称上游支持相同语义。

## 8. 验证与回滚操作

在目标主机执行：

```bash
systemctl show ultra-forward-170 leikwan-client-agent -p MainPID -p ActiveState -p ExecMainStartTimestamp
/usr/local/libexec/leikwan/ultra-client-2026.09.11.1 --version
sha256sum /usr/local/libexec/leikwan/ultra-client-2026.09.11.1 /usr/local/bin/leikwan-client-agent.py
python3 /root/ultra-ha-20260911-1/ha-canary.py --port 10001
journalctl -u ultra-forward-170 --since '-10 min' --no-pager
```

runtime 回滚会中断 forward-170，安排维护窗口后执行：

```bash
bash /root/ultra-ha-20260911-1/rollback.sh
```

Agent 回滚（不主动重启转发 runtime）：

```bash
bash /root/ultra-ha-20260911-1/rollback-agent.sh
```

回滚脚本有目标/哈希前置检查；仅验证语法，未在生产演练。回滚到旧 Agent 会恢复旧健康判定和更新行为，不能解决发布源问题。

## 9. 全量发布门槛与剩余工作

- [x] 目标 forward-170 的 runtime 修复已部署，TCP/UDP 真实 canary 通过。
- [x] 目标 Agent 冷启动与误判修复已部署；更新循环以有期限的本地暂停止血。
- [ ] 对照面板、Cloudflare 主源、Aliyun 备用源的实际下载内容，统一版本、哈希和文件；最后原子更新 manifest，不先下发新版本元数据。
- [ ] updater 在停止 Agent 前校验下载文件及目标版本；不一致应拒绝安装并保留现有服务，持续失败应退避，不能轮换 token 绕过重试冷却。
- [ ] 完成上一项后移走 Agent `90-ha-canary.conf` 并重启 Agent；验证真实更新检测、安装及多轮拉取不再循环。不得把暂停永久化。
- [ ] 全量发布前对齐主分支、代码及制品源，移除 runtime 灰度 ExecStart 覆盖，让正常 updater 接管；先小批后扩大。
- [ ] ARM64 实机、长期空闲后业务恢复、真实应用故障切换和多客户端压力验收。

当前交付为**定向灰度修复，不是全网发布完成**。现有 TCP 不会透明迁移；UDP ACK 只证明 Relay 可达；原有 association packet-count 上限仍在；native/桌面路径未部署本轮 runtime reader 改造。01:15 单条历史连接的准确关闭原因仍缺少当时证据，本轮新增诊断用于后续定位。
