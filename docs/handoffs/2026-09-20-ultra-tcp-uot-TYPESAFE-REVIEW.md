# TypeSafe 风格复核：Ultra TCP / UoT 完整交接

复核日期：2026-09-21

## 结论

这份交接文档的**主要结论是可靠的**：它没有把失败的长测包装成修复完成，也没有把 line17、TTL、sidecar 本机健康或 `headers_eof` 单独升级成已证实根因。

但有三处必须降级措辞：

1. “40/40 HELLO、17 条启用规则”只能证明控制面准入/sidecar 观测，不是 provider relay 经过的业务 E2E。
2. “所有 Ultra rules 都是 UoT”没有被证明；文档实际证明的是 17 条启用规则有 UoT 配置，且 native SOCKS5 是独立合同。
3. “active fallback 修复通过”只适用于本地机制/回归测试；`.7` 未部署，因此不能作为线上修复。

## 复核方法

按 TypeSafe 的窄问题原则，将声明拆成四类判断：

- **事实支持**：文档中的数字、版本、状态是否有对应证据入口。
- **因果支持**：证据是否足以证明“X 导致 Y”，而不只是时间相关。
- **范围支持**：本机、sidecar、provider relay、目标业务是否被明确区分。
- **行动资格**：是否足以允许继续部署、扩大压测或宣布修复。

TypeSafe API 本轮没有实际调用：本机没有 `TYPESAFE_API_KEY`，也没有安装 `typesafe_sdk`。因此下面是按 TypeSafe 的 Choice/Noul/Score 结构做的可审计复核，不冒充远端模型输出。

## 判断表

| 声明 | 判断 | 置信度 | 原因 |
|---|---|---:|---|
| 原始广港/广新交替掉线问题尚未闭环 | 支持 | 高 | 600 秒 TCP-only 仍有 400 次 `headers_eof`，且最新版本未完成同等业务验收。 |
| line17 是全部 EOF 的根因 | 不支持 | 高 | line17、line447 都有失败；line17 后续还有 958 次成功请求；缺逐流 RST/关闭发起方证据。 |
| `headers_eof` 等于 provider 没有返回字节 | 不支持 | 高 | 旧计数在本地 Write 后才累加，`downstream=0` 可能是本地写失败。 |
| Panel TTL/材料过期导致 TCP 不可用 | 不支持 | 高 | 用户已有旧材料仍可用的反例；文档也承认未完成 provider 撤销行为验证。 |
| 六台 sidecar 正常 | 部分支持 | 高 | 本机 UDP echo 和规则 HELLO 通过；没有 provider relay 到目标的全链路证明。 |
| 40/40 HELLO 代表 40 条业务方向都可用 | 不支持 | 高 | HELLO 是准入/健康信号，不是目标请求响应或吞吐验收。 |
| 所有 Ultra rules 都实际走 UoT | 不支持 | 高 | 只明确写出 17 条启用规则有 UoT 配置；native SOCKS5 被定义为独立合同，设备实际数据面未全量核实。 |
| lease `external_uot` 传递缺陷已修复 | 支持但范围有限 | 中高 | Panel 字段和 Mac 实例回读有证据；不能外推到所有客户端版本。 |
| `.5/.6` 的 TCP fallback 修改已证明线上修复 | 不支持 | 高 | `.7` 未部署；长测失败发生在这些版本验收前/之外；没有新的同条件业务对照。 |
| UDP/UoT 污染普通 TCP 是关键假设 | 可检验假设 | 中 | TCP 模式可用是线索，但没有 TCP-only vs TCP+UDP+UoT 的同材料、同目标、短 A/B。 |
| 交接建议停止盲跑、先做本地机制门槛 | 支持 | 高 | 当前证据不足以区分 client、relay、target 或 provider；继续 10 分钟压测不会增加归因能力。 |

## 必须保留的措辞

- `FAIL`：历史 600 秒 TCP-only 业务验收失败。
- `NOT RUN / SETUP FAILED`：最新 canary 未进入业务流量。
- `LOCAL PASS ONLY`：本地单测、race、sidecar echo/HELLO。
- `NOT PROVEN`：line17 根因、TTL 根因、provider 拒绝、全方向 E2E、所有设备均已启用 UoT。

## 建议修订

把交接中的：

> “全规则配置盘点……均有 UoT”

改成：

> “盘点到 40 条规则，其中 17 条当前启用；这 17 条有 UoT 配置。其余规则、native SOCKS5 合同及各设备实际 UDP 数据面不据此推断。”

把：

> “六台 sidecar 正常”

改成：

> “六台 sidecar 通过本机 echo 与规则 HELLO 检查；provider-relay→target 的业务 E2E 未验证。”

把：

> “fallback 修复通过”

改成：

> “fallback 本地回归通过；线上部署和业务验收未完成。”

## 最终门槛

在没有以下证据前，不应宣布修复：

1. 同一候选、同一材料、同一目标的短 A/B：TCP-only、TCP+UDP+UoT、UoT-only。
2. 每条流同时记录 client close/error、relay/provider 关闭、target request/sent。
3. 至少一轮新 helper 的实际部署回读；`.7` 不能用本地 hash 代替。
4. 明确区分普通 TCP、raw UDP、tunnel UDP、reliable datagram、external-UoT。

