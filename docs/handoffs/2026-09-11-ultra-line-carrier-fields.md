# Ultra 线路「运营商」字段适配 + 171 列表推送收尾 — Handoff

Date: 2026-09-11
Repos: `leikwan-br1` (panel + source agent), `leigod-forward` (171 refresh_lines)
Companion: `leigod-forward/docs/handoffs/2026-09-11-leigod-linelist-panel-push.md`

## 背景

171 的 30 分钟全量刷线（2026-09-11）让面板线路池出现大量**单线**（电信/联通/移动）。
面板此前没有承载运营商的字段：

- `ultra_lines` 无 `isp`/`provider`/`in_line_type` 列；
- `UltraInventoryService::normalizeInventoryLine()` 与 `source-agent::build_inventory()` 两层都是白名单，
  未列出的字段在 API 边界即被丢弃（`master/src` 全库 0 处 `isp` 引用）；
- `route_class` 枚举只有 `single_telecom`，无 unicom/mobile。

## 三层改动

1. **源代理 → 面板载荷**（`source-agent/ultra_source_agent.py`）
   `build_inventory()` 新增 `isp` / `provider` / `in_line_type`，取值优先 index（30 分钟新），
   其次 audit manifest；TOML 元数据兜底。新增测试
   `test_inventory_carries_carrier_metadata_without_ports_or_credentials`（仍断言载荷无端口/凭据）。
2. **面板入库**（`master/src/Support/UltraInventoryService.php` + 迁移）
   - `ISP_CLASSES`/`ISP_ALIASES` + `normalizeIsp()`：电信/联通/移动/通用 → telecom/unicom/mobile/generic，
     未知一律 `unknown`（英文码同样接受）；
   - `normalizeInventoryLine()` 输出 `isp`/`provider`(≤64)/`in_line_type`；
   - upsert 列 + `ON DUPLICATE KEY UPDATE` 同步；
   - `ROUTE_CLASSES` 增补 `single_unicom`/`single_mobile`；
   - `listLinesForAdmin()` 支持 `isp` 过滤。
   迁移：`master/migrations/20260911_add_ultra_line_carrier_fields.sql`（含 `schema.sql` 同步与
   `idx_ultra_lines_carrier` 索引），幂等，重复执行只报“已存在”。
3. **后台展示**（`master/src/Controllers/AdminController.php`）
   Ultra 线路表新增 Carrier 列（Telecom/Unicom/Mobile/Generic + provider + in_type），
   并新增 Carrier 过滤下拉。

## 171 侧收尾（Plan A 剩余项）

`refresh_lines.py` v3.1（md5 `e9932167d39f103b487d44936a50eb01`，已部署）：

- **写 index**：源代理 `inventory_path` 指向 `/opt/ultra-source/lines/index.json`，每轮覆盖；
- **采集 TOML 自动补齐**：为 index 中缺 TOML 的线路生成
  `/opt/ultra-source/lines/<id>.toml`（不覆盖已有；hardware_id 沿用 `mac_auto_<sha256(lid:gid:aid)[:32]>`），
  实测 64 条新线一次补齐；生成后 index 的 `config_sha256` 立即改为该 TOML 的 sha256；
- **清场**：`--prune-toml-dir /opt/ultra-source/lines` 已加入 cron（隔离到
  `/opt/ultra-source/lines-stale/<UTC>/` + manifest，可回滚）。下线判定为
  “静态 pair 连续 code==0 缺席”：无 TOML 行 2 次、有 TOML 行 4 次（`--max-misses`/`--max-misses-toml`），
  legacy pair 与未扫 pair 永不作为证据；
- **carrier 透传**：index 行写入 `isp`/`provider`/`in_line_type`（sweep 优先，TSV 兜底）。

## 验证证据

- 本地测试：`python -m unittest tests.test_refresh_lines_index` → **20 tests OK**；
  `python -m unittest source-agent.tests.test_ultra_source_agent` → **24 tests OK**；
  面板 8 个 Ultra 相关 PHP 测试全部 `ok`（contract / admin page / schema / native socks5 / multi-source /
  saveforwardcombination / direction class / material state）。
- 171 实跑（17:31Z）：`fresh=146 swept_static=170 failures=0 index total=183 aging=1(653) dropped=0`，
  `acquisition tomls created=64`，`index↔toml hash mismatch=0`（183/183）；
- **startacc 冒烟**：新线 993 用自动生成的 TOML 取流成功（`code 0 成功`，含 line_user/line_token，speed_ip 43.230.8.164）；
- 载荷：`payload_lines 183`，993 `isp=通用 provider=层峰`、655 `isp=移动 provider=腾讯云`；
  面板已接受该指纹（`inventory-state.json` 在 index 写入后更新）。

## 部署状态

| 位置 | 状态 |
|---|---|
| 171 `refresh_lines.py` v3.1 + crontab `--prune-toml-dir` | 已部署并实跑 |
| 171 `/usr/local/bin/ultra-source-agent.py`（carrier 载荷） | 已部署并重启，服务 active |
| 面板（迁移 + 2 个 PHP 文件 + php-fpm reload） | **待部署**，脚本 `scripts/deploy-ultra-carrier-fields-20260911.sh` 已就绪 |

面板部署步骤（在面板主机执行）：

```bash
# 1) 传三个 base64 载荷到 /tmp（文件名固定，见脚本头部注释）
# 2) SITE=/www/wwwroot/v3.leikwanhost.com/leikwan-v3 bash scripts/deploy-ultra-carrier-fields-20260911.sh
# 3) 校验：/admin/ultra?view=lines 出现 Carrier 列与筛选；新线 isp 非空
```

回滚：脚本在 `/root/leigod-forward-backups/ultra-carrier-<UTC>/` 留了三个原文件；
SQL 只增列加索引，不回滚也不影响旧代码（旧代码不读这些列）。

## 注意

- 面板未部署前，源代理仍会推送 `isp`/`provider`，旧面板会忽略（无副作用），但列中不会有数据。
- 新线在面板仍是 `is_enabled=0`：面板按 audit 字段重算该位，需真实 arm 结果写入
  `/opt/ultra-source/line-audit.json` 才会置 1（本轮未自动标记）。
- relay IP 不随列表刷新，仍由 material（`startacc`）链路负责；本轮补齐 TOML 后该链路对新线已可用。
---

## 追加：`isp` 语义修正（2026-09-11 18:00Z）

上线后核对发现：linelist 响应里的 `isp` **描述的返回该线路的池子，不是线路固有属性**。实测
`451`（上海-韩国-BGP-华为云）在 `isp=电信` 与 `isp=移动` 查询下返回 `通用`，在 `isp=联通` 查询下返回空 ——
把查询池当成线路运营商，会导致 42 条 BGP 线被误标 telecom。

`refresh_lines.py` v3.4 采用两级判定：

1. **标题标签优先**：标题里唯一出现 `电信/联通/移动` 的（如 `上海-韩国-电信`）直接取该运营商；
2. 否则要求**多池一致**：本轮所有非空 `isp` 值唯一时才采用，出现分歧则置空（面板显示 unknown）。

同步影响：

- `config_sha256` 不受影响（仍取 TOML 哈希，稳定）；
- 面板 `ultra_lines.isp` 会在下一轮上传被覆盖修正；`unknown` 是诚实结果，不代表解析失败；
- 真正的单线识别建议同时看 `source_title`（厂商自带标签）与 `route_class`（audit 产出，含 single_telecom；
  新增的 single_unicom/single_mobile 需 audit 写入后才有值）。
### 最终口径（v3.6）

`isp` 只认**标题自带的运营商标签**（`电信/联通/移动`，如 `上海-韩国-电信`），其余一律留空 →
面板显示 unknown。依据：

- 池标签不可靠（451 实测三种查询返回 `通用`/空/`通用`）；
- 标题标签是厂商自己的命名，60 条单线（电信/联通/移动各 24/23/20 条实测）全部命中；
- 未复扫的 kept 行：标题有标签才保留，历史遗留的 pool 标签清空，中性的 `通用(generic)` 保留。

结果（面板 `ultra_lines`, source-home-171，18:37Z 实测）：**telecom 20 / unicom 20 / mobile 20 / generic 13 / unknown 110**，
共 183 行全部 present；60 条标题自带运营商的单线全部命中，其余诚实标 unknown。

`--carrier-from-pool` 可显式打开旧的“多池一致”规则（默认关闭）。
---

## 追加：腾讯低优先派发 + arm 标记（2026-09-11 18:47Z）

### 1. 腾讯入口低优先派发（已上线面板）

- 新列 `ultra_lines.dispatch_class`（`normal` / `low`）+ `idx_ultra_lines_dispatch`；
- 判定规则集中在 `UltraInventoryService::dispatchClass()`：`provider` 或 `source_title` 含 `腾讯`
  （`LOW_PRIORITY_PROVIDER_MARKERS`，后续可加标记）→ `low`；
- 派发排序在 `ProvisioningService` 的 client 候选查询里：
  `source priority → dispatch_class(low 最后) → pricing_class → membership sort_order`，
  即只要有非腾讯候选，客户端就不会先拿到腾讯入口；
- 后台 Ultra 线路表 Carrier 列显示 `Low priority` 徽标，并新增 Dispatch 筛选；
- 生产实测：源 171 上 `low=31 / normal=158`（另一源共 `low=45`，`tencent_marked=45` 一一对应），
  27 条在跑的 ultra forward 排序全部满足 `low_last=yes`
  （例：fwd 159 `453,37,574,581,689,325(low),653(low)`；fwd 182 `431,384,454,700,709,694(low),525(low),585(low),643(low)`）。

回滚：`/root/leigod-forward-backups/ultra-dispatch-20260911T184705Z`（3 个 PHP 原文件）。

### 2. arm 实测线路线路标记（已生效）

`/opt/ultra-source/line-audit.json` 增加 **16 条** clean-relay 国际线（`native_udp/verified` +
`audit_at=2026-09-11T10:28:00Z` + 逐条 `audit_hash`），备份 `line-audit.json.bak-20260911T184356Z`；
面板实测这 16 条全部 `access_class=native_udp / native_udp_state=verified / is_enabled=1`。
ARM-ONLY（944/945/962）按约定**未标记**。

注意：其中 6 条（880、946、951、994、996、1002）当前 30 分钟 sweep 已不再返回（intl_pairs 119 对全查无果），
因此没有 `/opt/ultra-source/lines/<id>.toml` 采集配置 —— 它们会显示为 `Material not required`（未进任何组合时无影响），
一旦 sweep 重新返回即可自动补齐 TOML。其余 10 条 TOML 已就绪、可正常采材料。
---

## 收尾（2026-09-11 19:42Z）

按“线路名里已经带了这些”的结论做了收敛：

- **保留**：`isp` 仅由线路名派生（标题含唯一 `电信/联通/移动` → 该运营商；含 `BGP` → `generic`），
  实测面板 `generic=99 / telecom=20 / unicom=20 / mobile=20 / unknown=30`（189 行）；
- **保留**：腾讯低优先派发（`dispatch_class=low` 32 条），排序验证通过；
- **回滚**：`direction_class` 的 zone 自动判定（`deriveDirection` + `MAINLAND_ZONE_CODES`）已撤，
  面板 `UltraInventoryService` 恢复为“carrier + dispatch”版本；带该逻辑的版本留档在
  `/root/leigod-forward-backups/ultra-direction-20260911T193144Z/UltraInventoryService.with-direction.php`。
  `direction_class` 回到纯管理员字段（列与后台筛选保留）。
- 171 侧：`refresh_lines.py` v3.9、index 189 条（通用 99 / 空 30 / 电信 20 / 联通 20 / 移动 20）、
  TOML 189 条、prune 单轮 0 误伤；audit 声明的 6 条线路已 pin 进 index。
### 海外加速并入线上分组（2026-09-11 20:25Z）

按运营要求：`unknown` 里凡是 `海外加速-*` 的并入现有 `generic`（通用/BGP 多线）分组，不新开分组：

- `refresh_lines.title_carrier()`：无单一运营商 token 且标题含 `BGP` **或** `海外加速` → `通用`；
- pinned（audit 声明）行同样按自己的线路名取组，不再固定留空；
- 6 条 pinned 线路补了名字（取自 `/opt/leigod/intl_core.tsv`：880 海外加速-韩国、946 圣彼得堡-中国、
  951 孟买-中国、994 海外加速-台湾-韩国、996 海外加速-台湾-新加坡、1002 海外加速-日本-韩国）。

面板最终分布（189 行）：**generic=118 / telecom=20 / mobile=20 / unicom=20 / unknown=11**。
剩 11 条名字里确实没有分组标记（9 北京-德国、347 TCP-游戏下载、445 上海-韩国【UDPOVERTCP】、
660 上海-韩国-本土-优云、691 line-691、709 上海-美国【家庭IP】、904 广州-香港【Poe2】、
913 全区服备份链路【勿取消】、965 永恒之塔2-台湾、946 圣彼得堡-中国、951 孟买-中国），
需要时用后台 `admin_label`/Carrier 归属人工指定。
### 人工确认的三条散线（2026-09-11 20:35Z）

运营确认路线后按现有 `admin_label` 约定（`<ORIG> -> <DEST> / L<id>`）标注，并写入 notes：

| line | 名称 | admin_label | notes |
|---|---|---|---|
| 9 | 北京-德国 | `PEK -> FRA / L9`（原有，未改） | PEK→FRA · operator-confirmed 2026-09-11 |
| 445 | 上海-韩国【UDPOVERTCP】 | `SHA -> ICN / L445`（原有，未改） | SHA→SEO / UDP-over-TCP · operator-confirmed |
| 709 | 上海-美国【家庭IP】 | `SHA -> LAX / L709 · 家宽 Residential IP` | SHA→LAX · 家宽IP (residential) · operator-confirmed |

`admin_label` / `notes` 是管理员字段，inventory upsert 不会覆盖，所以标注是持久的。
注意：709 的 label 被我短暂覆盖过一次（先写成纯 `家宽 Residential IP`），随后按约定格式重建；
该源上共 41 条线路带 label。

`isp` 分组维持不变：generic=118 / telecom=20 / mobile=20 / unicom=20 / unknown=11（其中 8 条尚待人工确认）。
---

## Native UDP + 大包丢包实测（2026-09-12 06:0xZ，171 上跑）

工具：`research-lines-udp-20260817/components/udp_bigpkt_probe.py`（已装到 171 `/opt/leigod-native/scripts/`）。
流程：每条线 `startacc` 取材料 → 用 `native_udp.NativeSession` arm（envelope 协议）→ 通过 relay 把不同大小的
UDP 载荷打到回显目标（relay keep-echo `2.0.0.0:0`，relay 会原样回显）→ 统计丢包。
探针 socket `SO_RCVBUF=4 MiB`（171 的 `net.core.rmem_max=128 MiB`，所以 4 MiB 真生效，不是被内核截断）。

结论：

- **native UDP**：11 条里 **10 条 arm 成功**（含面板标成 `relay_unreachable/udp=failed` 的 904、913 —— 那两条
  audit 已过期，实测 arm ok、512B~2000B 全通）；只有 **691** `startacc` 失败（无材料，面板也是
  `dynamic_ingress/unverified/disabled`，一致）。
- **大包丢包**：与 4 MiB 缓冲无关（丢包是按尺寸**确定性**发生的，不是缓冲溢出），是路径 MTU：
  - 安全线：445 / 904 / 951 / 9 / 709 / 913 / 965（≤2000B 全通，个别 1440B 偶发 1/12 抖动）
  - **有墙**：660 在 **1456B 起 100% 丢**（1456 envelope 后正好 1500 字节线缆长度，仍丢 → 有效 MTU 约 1450）
  - **取决于 relay**：946（一条 relay 1400B 过后即 100% 丢，另一条 relay 直接不回包）、
    347（一条 relay 1472B 起全丢，另一条 relay 1470B 仍 0%）
  - 材料里的 relay 每次 `startacc` 都会轮换（660/946/347 各拿到 3 个不同 relay），所以按线路描述能力会漂移，
    **应当按 relay 维度评估**。

建议：

1. 受影响线路（660 等）在客户端把 MTU 兜底到 ≤1400（envelope+IP/UDP 后 ≤1444）；
2. 用同款探针按 relay 扫一遍，把「大包必丢」的 relay 从候选里剔除（946 的 154.83.196.112 已实测全丢）；
3. 904 / 913 值得重新 audit（实测可用），重新写入 `line-audit.json` 后即可恢复 `is_enabled=1`。

原始数据：`docs/reports/2026-09-12-udp-bigpkt-probe.tsv`。
