# 全方向 External-UoT 测试矩阵（只读盘点）

日期：2026-09-20。Panel 数据快照：12:46:26 UTC / 20:46:26 UTC+8；随后只读检查 sidecar 服务与授权方法。

## 结论与边界

- **能力配置：12 个启用且 UoT target/token 完整的 Ultra 组合，去重为 11 个 ingress→destination 方向。** 组合57是54的Tokyo pilot，不增加方向。
- **client3 / service30 当前显式授权7个方向，缺4个方向：Shanghai→Hong Kong/Singapore/South Korea、Beijing→Moscow。不得漏测，也不得直接改共享plan9补权限。**
- **主机健康：6个配置sidecar端点均已直接SSH确认 `leikwan-external-uot.service` active/running、TCP7443 LISTEN。** FRA/MOS共享host首次SSH失败，一次重试成功；SG后续热加载能力检查SSH失败，不抹掉先前健康证据，也不能称其连接稳定。
- **真实流量：本盘点未发任何数据面测试流量；11方向的Mac+Linux/UoT端到端验收均未完成。** 之前HK/SG TCP-only压测不是UoT验收，也不能代替其他方向。
- Ultra拓扑始终为 `client -> leased Ultra provider relay -> configured external UoT sidecar -> UDP target`。管理面直接SSH sidecar；不引入slave/CDN，不使用新专用sidecar端口替代现有7443路径。

## 全11方向矩阵

候选数列为“启用绑定数 / 满足来源与传输能力筛选的候选数 / 快照时material未过期数”。中间一项要求line/source启用、source_present、destination相符，并具有verified native UDP或verified native SOCKS5；**不等同于已成功租约/探测/载荷传输**。所有列出的有效候选来源为 `source-home-171`；具体DB行及过期时间保存在私有JSON。

| 组合 | ingress → destination | 配置sidecar endpoint | 候选数 | 候选line_id（能力筛选后） | client3可用service | client3现有forward / 主要缺口 |
|---|---|---|---|---|---|---|
|46|shanghai → hong-kong|70.36.96.183:7443|2 / 1 / 0|818|无|无forward；缺授权，material为空|
|47|shanghai → singapore|77.93.90.45:7443|2 / 1 / 0|886|无|无forward；缺授权，material为空|
|48|shanghai → south-korea|103.246.219.39:7443|12 / 5 / 0|451,32,660,686,887|无|无forward；缺授权，material过期/为空|
|49|beijing → frankfurt|157.228.138.23:7443|14 / 7 / 0|693,455,9,493,598,629,380|11、30|client3无forward；material均过期|
|50|beijing → moscow|157.228.138.23:7443|16 / 8 / 0|692,456,10,344,599,600,646,372|无|无forward；缺授权，material过期/为空|
|51|guangzhou → hong-kong|70.36.96.183:7443|18 / 8 / 8|447,17,197,642,682,758,904,696|11、30|158启用；180为native；198测试forward已禁用|
|52|guangzhou → singapore|77.93.90.45:7443|8 / 2 / 2|464,399|11、30|199测试forward已禁用|
|53|fuzhou → taiwan|103.246.219.39:7443|2 / 1 / 0|41|11、30|160禁用；material已过期|
|54|shanghai → tokyo|185.220.238.143:7443|16 / 7 / 7|453,37,325,574,581,653,689|11、30|159/171启用；176禁用；不可覆盖生产target|
|55|shanghai → los-angeles|208.122.17.50:7443|18 / 9 / 9|694,384,431,454,525,585,643,700,709|11、30|client3无forward；单forward元数据最多发布8候选|
|56|hong-kong → china-mainland|70.36.96.183:7443|1 / 1 / 0|7|30|174禁用；material已过期；detected_only|

补充组合57 `ultra-sha-tyo-uot-pilot`：同Shanghai→Tokyo，16/7/7，client3 forward170启用，但当前service11/30的可选组合列表都不含57。它是既存状态，不应据此断言新建/修改授权通过；不计第12方向、不擅动170。

**方向核验注意：** TW与KR共享sidecar，Moscow与Frankfurt共享sidecar，Mainland与HK共享sidecar。这些是配置映射，不是对物理机房或UDP出口地理位置的证明。需使用对应目的地的已授权UDP target，并回读实际选择的ingress/destination/candidate；其余组合的`detected_then_any`回退不能冒充指定入口验收。组合56为`detected_only`。

## Sidecar identity、授权绑定及健康证据

统一服务名 `leikwan-external-uot.service`；配置文件 `/etc/leikwan-external-uot.json`；现有listener TCP7443。identity在本报告用`external-uot@host:7443`，不虚构不存在的sidecar UUID。

| host | 映射方向区域 | 健康只读结果 | 磁盘配置中与client3相关绑定 |
|---|---|---|---|
|70.36.96.183|HK、Mainland|active/running + LISTEN|158/160/174/180等既存ID允许`*`；198限定70.36.96.183:60051|
|77.93.90.45|Singapore|active/running + LISTEN；后续SSH不稳定|同一批既存ID允许`*`；199限定77.93.90.45:60051|
|103.246.219.39|South Korea、Taiwan|active/running + LISTEN|包含160等既存ID、targets=`*`；没有自动接受任意新ID|
|157.228.138.23|Frankfurt、Moscow|首次SSH失败，一次重试active/running + LISTEN|既存ID包括183等；panel注释中combo50=no-forwards|
|208.122.17.50|Los Angeles|active/running + LISTEN|既存ID包括182/187/194；client3目前无该方向forward|
|185.220.238.143|Tokyo|active/running + LISTEN|159/170/171/176等；targets=[]；panel matched_point=5、name=tokyo-nrt1|

空targets和`*`在现有Go `allowsTarget`中均为允许规范化目标；但**仍必须存在forward ID并通过token/HMAC校验**。本次仅检查token字段存在，不读取/输出其明文，不验证token一致性。磁盘配置也不等同于进程内已加载配置；需真实HELLO/ACK与载荷证据回读。

组合198/199的测试HTTP60051目标已由主线程清理，不能因残存sidecar绑定就称可测UDP target仍存在。现有其他forward多为生产业务，不能拿原target随意做20Mbps压测。后续须建立/选择已授权专用UDP echo/带序号目标，但保留真实sidecar7443路径。

## Pool与默认目标映射

实时panel：`EXTERNAL_UOT_DEFAULT_ENABLED=true`；fallback target=`185.220.238.143:7443`；`EXTERNAL_UOT_POOL_JSON`为空。实际依据是每个组合的`external_uot_sidecar_target`，不是已启用的多sidecar pool。

`EXTERNAL_UOT_DEFAULT_TARGETS_JSON`包含HK、Singapore、South Korea、Frankfurt、Moscow、Taiwan、Los Angeles、Tokyo、Mainland九个destination映射，与上表一致。zone存在本身不证明组合方向存在；本矩阵来自active组合及绑定，不把pool/默认键数当方向数。

## 临时绑定是否支持无重启生效

**当前源码不支持配置热加载。不能假设写JSON、systemctl reload或SIGHUP会新增forward。**

- `tools/uot-sidecar/main.go`仅启动时`loadConfig`一次，信号处理只注册SIGINT/SIGTERM；未实现SIGHUP、文件watch、reload API。不要发送SIGHUP试探，可能结束进程。
- 现网HK/KR/FRA/LA/Tokyo `/proc/<pid>/exe --help`均只列config/check-config/version/print-token等，无reload参数；对应systemd `CanReload=no`。SG本轮help/CanReload检查SSH失败，未验证；源代码结论不能冒充SG二进制验证。
- HMAC是对**预先存在的forward binding**中secret校验forward_id+target，不是全局动态注册机制。所有已读配置使用按forward ID表项；新ID没有匹配binding仍会拒绝。
- 现有ID配合wildcard/empty targets，可在token匹配且政策许可时使用新target，无需新增目标白名单；但不能侵占生产forward或把换组合后的token假设为原token。198/199当前是固定target，不具有该便利。
- 无中断加入新ID的方案目前缺失。必须先补并验证安全热加载（不可变binding快照、保留存量session、拒绝无效配置、可回滚），或与业务协调明确维护窗口；本盘点不部署、不重启。**不能另开sidecar端口绕开限制后声称真实现网7443已验收。**

## client3/service30最窄临时授权建议（未执行）

已证：client3 active、owner_user_id=3；service30 active、plan_id=9、到期2026-10-01；service11 active、plan_id=7、到期2026-09-24。调用现网只读`availableForwardCombinationsForService(30)`返回49/51/52/53/54/55/56，与数据库绑定一致；service11少56。当前plan9仅关联service30，但仍按共享模板保护，**不要修改plan9及其绑定**。

源码授权链：`availableForwardCombinationsForService`读取service.plan_id→`service_plan_forward_combinations`，`visibleForwardCombinationForService`再次校验；未发现service/client级组合override字段/表或此链上的override分支。`allow_native_outbound_unrestricted=true`不是普通组合授权旁路。client3 `allow_paid_lines=0`也不要临时改；本次候选metadata SQL并未按该字段过滤，因此表中存在paid候选不能自动视为权限拒绝，须后续租约回读确认。

可回滚最小方案，交主线程选择执行时点：
1. 备份service30、plan9全字段及相关授权绑定，登记现有client3 forward/配置revision；确认没有正在压测和用户连接变更。
2. 克隆plan9为**独立、隐藏、一次性测试plan**：唯一name/product_code，`is_tenant_visible=0`；保留bandwidth/traffic/feature_flags/billing字段，新增46/47/48/50，合计46–56十一方向；不加入57重复pilot。原plan9不变。
3. 在事务中用CAS只修改`services.id=30 AND user_id=3 AND plan_id=9`的plan_id指向新plan；affected_rows必须为1。不要改其他service/client、全局套餐或用户余额，不走可能扣款的`purchaseService`流程。
4. 调用只读可选组合方法确认service30=11方向；service11和其他service保持原结果。注意service30自身运行中的native180/catalog可能收到配置变化，需在协调窗口执行，不宣称零影响。
5. 每方向使用独立测试forward/端口与明确target；先解决现有sidecar新ID热加载限制、冷material刷新和token校验，不能仅绕过数据库授权。
6. 预置超时回滚：只禁用本次测试forward，CAS把service30从测试plan还原plan9，核对权限重回7方向及已有forward未变；无引用后停用一次性plan并保留审计。条件不匹配即停止，不能覆盖他人的并发调整。

如不能接受service30自己的catalog变更，替代是独立临时service+隐藏测试plan、同owner3且仅测试forward绑定client3；需先审核现有服务创建/计费路径，不能伪造WHMCS service ID或无意产生账单。本次未执行任一方案。

## 后续验收门槛（全方向，不能缩成HK/SG）

用户追加验收要求：修复后，对上表全部11方向逐一执行；每方向Mac和Linux同时运行，目标每平台每路20Mbps、持续600秒。并行方向数先受client/service实际限速约束，不以超额压测导致的丢包判定单线路失败。

每个方向都保留一行状态，分别报：配置能力、权限、material/候选、sidecar真实HELLO与UDP载荷、Mac和Linux结果。冷material的7方向应先通过受支持的获取流程刷新，刷新失败列为BLOCKED，不把旧候选静态存在当成功。

每方向至少拆分 raw UDP基线 / tunnel UDP / reliable datagram / TCP控制，不混淆TCP字节完整性与UDP丢包；记录20Mbps目标负载、实际发送/接收、持续时间、序号缺口/重复/乱序、queue drops、重连和candidate/sidecar身份。保留控制故障后的自动恢复结果；未跑的平台/方向明确未验收。此处只是测试范围与前置清单，未启动测试。

执行前必须确认本地listen端口已ready、实际租约方向匹配、HELLO/ACK成功及已协商传输模式，然后才启动600秒计时。setup失败的整轮标INVALID，保留原始结果后重做；不能把目标未启动、本地connection refused或直连sidecar成功计入Ultra路径通过。结束后回读临时forward禁用、target/listener消失、原生产forward和sidecar仍健康。

## 证据与源码

私有详细inventory：`tmp/uot-all-directions-20260920/`，不提交凭据文件。
- `panel-inventory.json`、`schema.json`、`authorization-readback.json`、`direction-matrix.json`。
- 六个`<host>-sidecar.json`及`fra-retry.json`：配置脱敏、服务状态、listener；`reload-capability/*.json`：现网安全help/CanReload。
- `master/src/Support/ExternalUoTDefaults.php:23–60`：默认目标映射。
- `master/src/Support/ProvisioningService.php:1410–1431,1528–1564,1871–1883`：套餐/服务组合权限；3795–3819：client所属用户及group可用性；4335–4474：候选筛选、material有效期、UoT grant及最多8候选。
- `tools/uot-sidecar/main.go:28–92`：CLI、一次load、信号；`protocol.go:240–286`：目标白名单与预配置binding HMAC。

所有操作仅SELECT/读取配置/SSH状态与`--help`；未建forward、未改配置、未启流量、未部署。

## 补充：复用禁用owned forward的无重启可行性（实时只读比对）

仅审计client3禁用记录160/174/176；没有触碰启用生产forward或其他client：

| forward | 当前service | 当前组合 | client | enabled |
|---|---:|---:|---:|---:|
|160|11|53|3|0|
|174|30|56|3|0|
|176|30|54|3|0|

Panel组合46–56的token与各目标host配置中对应ID的static token / HMAC secret，在本地仅用SHA-256摘要比较；摘要只在进程内参与比较，最终文件仅保存匹配布尔值，不保存token或摘要。**组合token并非全部同值。** 本次选定binding都有static token；已存在的160/174/176 binding均没有HMAC secret，不能靠动态HMAC改变授权结果。

| 组合 / 方向 | 目标host存在160/174/176 binding | static token match：160 / 174 / 176 | 当前集合内可复用候选 |
|---|---|---|---|
|46 Shanghai→HK|是 / 是 / 否|false / false / false|无|
|47 Shanghai→SG|是 / 是 / 否|false / false / false|无|
|48 Shanghai→KR|是 / 是 / 否|false / false / false|无|
|49 Beijing→FRA|是 / 是 / 否|false / false / false|无|
|50 Beijing→Moscow|是 / 是 / 否|false / false / false|无|
|51 Guangzhou→HK|是 / 是 / 否|false / false / false|无|
|52 Guangzhou→SG|是 / 是 / 否|false / false / false|无|
|53 Fuzhou→Taiwan|是 / 是 / 否|true / false / false|160，仅在原53授权下|
|54 Shanghai→Tokyo|否 / 否 / 是|false / false / true|176，仅在原54授权下|
|55 Shanghai→LA|是 / 是 / 否|false / false / false|无|
|56 HK→Mainland|是 / 是 / 否|false / true / false|174，仅在原56授权下|

FRA明确存在160与174，但两者均不匹配49/50的组合token。SG首次比对SSH失败，一次重试成功，47/52不匹配已确认，不再是UNKNOWN。

**判断：在160/174/176这个候选集合内，仅53、54、56具有“同ID + token匹配 + wildcard/empty target”的磁盘配置条件。** 其余8方向不能只改panel组合/target就声称授权生效；wildcard仅放开target，不放开token或forward ID。HK/SG另有198/199固定target绑定，但其token不在本次比较范围，不能从本表推断198/199可用或不可用。

`forwards`表没有UoT token/secret override列；已查授权构建代码直接取`forward_combinations.external_uot_token`，token broker继续原样传递。因此不存在已证实的“只给174覆盖成新组合token、sidecar仍无改动”的现成旁路。更不能改组合共享token来迁就单个测试ID，这会影响其他forward。

对三个条件匹配的ID，未来执行仍需：读取并备份全行/revision，验证client3+enabled0+原组合，先确保该ID没有残留活跃会话，使用受支持更新路径且仅改测试target/必要端口，准备超时恢复；结束时以本次写入的revision/组合/target作为CAS条件还原原值与enabled0。160属于service11，不能把service30的临时授权当成160自动获得新权限；不改service_id规避检查。此处未执行任何更新、HELLO探测或流量。

进程内binding仍未做数据面回读。配置mtime与按Linux `/proc/stat btime`推算启动时间相差不足1秒，受btime整数秒取整影响，**不能据此认定配置在进程启动后才修改或未加载**；详细JSON将其标为时间分辨率内UNKNOWN。需要后续对选定真实路径的HELLO/ACK及载荷确认，不能把磁盘hash匹配等同于运行时验收。

补充证据：`tmp/uot-all-directions-20260920/disabled-forward-binding-audit.json`；采集脚本`audit_disabled_bindings.py`，SG只读重试`retry_sg_binding_audit.py`。所有11方向都保留，不缩小验收范围。
