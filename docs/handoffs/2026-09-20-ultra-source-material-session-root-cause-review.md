# Source material / shared-session 根因审查（只读）

日期：2026-09-20。范围：当前仓库source-agent→Panel材料/租约链，已有600秒请求记录及Panel安全元数据、访问日志。未触发startacc、login、review或任何生产变更；未访问slave/CDN。source主机当前SSH定位/代码hash不由本报告认定。

## 结论

**存在必须验证的共享provider登录session/有副作用取材假设，但现有证据不能认定它造成400次EOF，也不能认定“广港/广新互踢”。**

可证：每条线的取材调用外部`line-client startacc`，同一agent实例复用同一个配置session路径；单进程串行，但未实现跨进程共享session锁。复核流程也会再次startacc，且不会把复核新取得的材料回传到materials接口。部分失败触发login后只重取失败线路，先前成功材料仍上传。反面证据：Panel材料缓存按source+line分开，客户端grant按client+device分开；本次窗口没有source171 review执行记录。

不可证：外部line-client具体调用哪个provider API、startacc是否切换账号当前线路/吊销既有token、重新login是否撤销旧line材料、两个source是否同一个provider账号、source171实际是否有重复进程/其他工具共用session。该外部程序源代码不在本repo，版本字符串也不是现网代码hash。

## 代码链与证据

1. `source-agent/ultra_source_agent.py:861–904`：`acquire_line`执行`binary startacc --config <line_id>.toml [--session <session_path>] --save <output>`；不是单纯读取材料缓存。不同line共用实例的`self.session_path`（1032–1033、1117、1236、1407）。如果未配置路径，则省略参数、由外部程序默认session行为决定，仍不能声称per-line隔离。
2. `run_cycle:1105–1147`：最多8条串行采集，临时文件按`line-{id}.json.tmp`命名，无跨进程锁；第二个实例/手工工具若共享路径，可竞争同一个session及同line临时文件。systemd只管理自己启动的那个实例，不阻止手工并行进程。没有证据证明现场发生过该竞争。
3. `reauthenticate:1326–1383`：调用login，成功后把login HOME下session文件原子替换到配置session路径；原子replace避免半写文件，但不是账号级锁，也不证明provider旧授权仍有效。
4. `run_cycle:1127–1134`：若部分line取材失败，login后仅重试失败line，保留此前成功材料。现有单测`test_agent_reauthenticates_and_retries_partial_line_acquisition_failure`（tests:624–674）明确断言调用顺序`[1,2,2]`。如果login会使line1旧token无效，该批次会混用前后session epoch；这是条件风险，不是已证provider行为。
5. `process_reviews/run_review:1151–1260`：常规materials上传后串行复核；复核再次`acquire_line`使用同session，结果仅发`/source/review`，没有上传复核获得的`user/credential/relays`到`/source/materials`。若startacc轮换/撤销材料，Panel仍持有旧材料到TTL；本次窗口无review执行，故不能把它当400次EOF的现成解释。
6. `normalize_acquisition:806–858`：过期时间是本地`now + material_lifetime_seconds`；`probe.ok=True`也是直接赋值，没有在该函数实测数据面。generation覆盖line_id、user、credential、relays，但有效TTL不来自provider可验证承诺。取材成功并不等于旧新材料同时可用。
7. Panel `UltraMaterialService.materialKey:792–795`：键为`ultra.material.v2.source.<hash(source_id)>.line.<line_id>`；不是仅source/账号的单个槽。旧fallback也含line ID，且只用于primary source。
8. `acceptSourceUpload:141–187`仅写对应source+line key，拒绝比现有acquired_at更旧的上传；未见刷新某line时删除其他line材料或撤销所有forward租约的代码。
9. `grantCacheKey:860–863`为client_id+device_hash，值再按forward_id分组；正常不同device不共用grant缓存。空device落到`none`，同device并发整图写入可能last-write-wins，但这是缓存缺失/重签风险，不是已证跨线路provider凭据覆盖。
10. `LeigodTokenBroker:33–140`只从已上传材料签发/缓存scope，没有直接调用provider login/startacc。客户端Mac+Linux并发领取租约本身不等于分别执行provider登录；源代理统一获取材料是两者的共同依赖。

## 600秒时间线核对

窗口：2026-09-20 **12:27:28–12:37:28 UTC**。下表是客户端失败请求开始时间，不是内核EOF精确发生时间；EOF约晚1秒。

| 客户端/方向 | 失败开始时间区间UTC | 数量 |
|---|---|---:|
|Mac HK|12:27:47.092–12:27:48.701|8|
|Linux HK|12:27:47.801–12:27:50.176|11|
|Mac HK|12:28:51.509–12:28:55.586|21|
|Mac HK|12:29:49.844–12:29:50.742|8|
|Linux SG|12:27:28.672–12:27:50.520|156|
|Mac SG|12:36:35.791–12:36:38.103|18|
|Linux SG|12:36:36.178–12:37:00.075|178|

Panel accesslog该窗口内materials POST均HTTP200：**12:27:42、12:28:54、12:30:12、12:31:10、12:32:31、12:37:27 UTC**。读取的v3日志时间范围完整覆盖本窗口。

- 第一簇HK失败在12:27:42 upload后约5秒；MacHK第二簇与12:28:54 upload重叠。
- MacHK第三簇12:29:49附近无materials upload；末段SG双平台失败12:36:35–12:37:00期间也无upload（前一次12:32:31、后一次12:37:27）。
- 部分相近时间不构成因果；上传发生在取材之后，也不能用“没有upload”排除尚在进行或失败的startacc/login。
- 标准accesslog不含source_id、line_ids、acquisition start/end、login事件；HTTP200不能直接当provider取材成功数。保留peer于私有证据，未将其臆断为账号身份。
- `ultra_candidate_reviews`查source-home-171在12:00–13:00UTC启动或重叠执行的记录，结果**0条**。未发现本次失败窗口review创建/执行可以对应。
- 13:08:02UTC读取`ultra_sources`：171 agent_version=`2026.09.13.1`，last_material_upload_at=13:07:42；第二source版本2026.08.24.3，last_material_upload_at仍2026-08-30。版本与当前源码声明一致不等于字节级部署证明。
- `ultra_lines.last_material_acquired_at/expires_at`与app_settings均只保留最新投影，已被后续刷新覆盖；当前值不能重建12:27–12:37每次line材料更新。未解析provider凭据或猜测历史世代。

## 不依赖SSH的诊断通道现状

当前协议只有：`GET /api/ultra/source/jobs`、`POST /materials`、`POST /inventory`、`POST /review`（Bootstrap:379–382）。jobs仅返回line_ids/reviews/upload_ticket；agent只处理这两类任务及catalog刷新，无只读日志/文件hash诊断分支、无更新任务。inventory中的agent_version是字符串，inventory_fingerprint只哈希线路inventory，不含agent二进制/脚本hash（agent:742–760）。

因此**不是“只能SSH”，而是当前source协议缺少这类取证能力**：
1. 缺独立、无startacc/login副作用的diagnostic任务类型与签名回执。
2. 缺每次acquire/login的结构化epoch、line_id、开始/结束/返回码/session-epoch审计。
3. 材料接口会规范化并丢弃未知字段，缺历史审计存储；现有latest字段不能事后补回。
4. 旧agent无自动更新分支，仅给jobs加字段不会执行。需要受控更新agent（可使用已有主机管理/发布渠道，非必须SSH）及Panel兼容接收。

安全最小协议扩展建议（未实现）：独立`diagnostics`任务，明确type/nonce/expiry/allowlisted log-source/bounded bytes；执行只读、不得调用acquire_line/reauthenticate/run_review；回报agent脚本sha256、外部line-client文件sha256、安全配置字段和脱敏事件。额外字段不含user、token、credential、raw argv、原始session内容；byte/time预算严格限制。之后让**正常已发生**的startacc/login自然产生安全事件，不能为取证额外触发它们。已有`/api/client/debug/log`使用客户端身份，不能冒充source通道。无新agent时，保留具体诊断能力缺口，不创建review来变相执行远端命令。

## 可重现实验与最小修法建议（未执行/未编辑）

### A. 纯本地确定性实验：先验证设计风险，不触及provider

用fake line-client脚本模拟provider：维护session_epoch，startacc(A)返回带epoch材料；第二条B第一次失败，login把epoch+1，重试B成功。断言现有run_cycle上传A旧epoch+B新epoch，顺序[1,2,2]。这能确定“跨login保留旧材料”的程序行为，不能证明真实provider一定撤销旧材料。

用两个fake agent进程共享session_path/state_dir，并让fake startacc在读session和写结果间等待barrier：记录最大重叠数，验证同line临时文件unlink/覆盖及login与acquire交叠是否出现。单实例串行对照应无重叠。

对review：fake startacc每次旋转line generation；常规采集上传generation A，再run_review生成B，检查materials接口未收到B。这验证材料回传缺口，不等同于现网B必使A失效。

### B. 真provider因果实验：仅隔离账号/维护窗口，先不运行

固定同一已授权HK/SG provider候选、目标及**材料快照**，连续记请求rid与上行/远端读结果：
1. 无login/startacc对照；保留raw TCP目标可达对照。
2. 只取A，然后验证A；取B后继续用**原A材料**验证A，同时验证B；记录动作开始/结束、材料匿名fingerprint变化。
3. 反向B→A重复；再做同line A→A，区分跨line切换与同line token轮换。
4. 在单独实验组只login不startacc，判断旧A/B是否同时失效。
5. 不同账号或已确认独立provider session作为对照，不能仅复制session文件冒充隔离。

只有当跨动作前后旧材料失效/新材料恢复可重复且目标/网络控制稳定，才可认定provider会话互斥/凭据撤销。不能把一次EOF或一秒延迟作为因果标准。

### C. 最小修法，分别说明能解决什么

- **跨进程锁+唯一临时文件**：按canonical session/account身份建立协作锁，覆盖startacc/login/linelist与session更新的临界区；唯一PID/nonce临时文件避免互删。它解决并发竞争，**不能**解决provider即使串行startacc仍只允许一条线的策略。外部不遵守同锁的工具仍需统一管理。
- **review不额外startacc**：优先使用源端受保护、未过期、generation/advertised relay匹配的既存材料快照；无精确快照则blocked，不为复核偷偷旋转provider状态。复核获得另一材料后简单覆盖Panel不等于修复，可能使正在使用旧材料的连接更频繁变更。
- **session epoch边界**：成功login后不要混合旧epoch与新epoch材料上传；重新建立一致快照或明确标记旧epoch不可验证。若真实provider证实跨line互斥，应采用真正独立的provider授权会话/账号，不能仅加锁或盲目重取全线。
- **遥测先行**：每次正常采集记录动作ID、line_id、session_epoch、start/end、返回码、材料fingerprint、真实probe状态；现有无实测的probe.ok不能作为健康结论。Panel保留有限历史以和失败rid关联。

上述均为建议，未修改源码、未部署、未访问provider API。不要将它们列为已完成修复。

## 本地证据

`tmp/ultra-source-session-review-20260920/`：
- `panel-safe-timeline.json`：sources、空review查询结果、当前material安全投影。
- `panel-access-window.json`：窗口内jobs/materials/inventory端点、HTTP状态与epoch，未记录请求body/token。
- `failure-clusters.json`：400次失败的聚类时间与数量。

另见 `tmp/mac-tcp-path-20260920/failure-correlation-report-FINAL.md`：400失败rid均无目标request/sent，但该事实本身不定位provider登录/线路切换责任。
