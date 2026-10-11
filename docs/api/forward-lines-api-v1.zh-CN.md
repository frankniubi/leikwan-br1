# 专线 API v1（转发规则管理）

本文档描述租户通过 API 管理自己服务下“专线转发规则”（forward）的接口。所有 ID、域名均为示例值。
实现位置：`master/src/Controllers/ForwardLineApiController.php`、`master/src/Support/ForwardLineApiContract.php`。
英文原版与 Native Outbound 细节见 [user-forward-lines-v1.md](user-forward-lines-v1.md)、[native-outbound.md](native-outbound.md)。

> 定位：LeiKwan 只做数据包转发。API 只管理“转发规则”这一资源；节点、线路组合、入口端口、SNI、
> 隧道凭据等运行时材料不是租户资源，不会出现在 API 中。实际连接由 LeiKwan 客户端拉取配置后建立，
> `listen_port` 是客户端设备上的本地监听端口。

---

## 1. 认证

```http
Authorization: Bearer <user-api-token>
```

- 只接受 `Authorization: Bearer`，不接受 query string 里的 token。
- 账号必须是 `role=tenant` 且 `status=active`，否则统一 `401 user_auth_failed`。
- 目前 token 由运营方发放（租户面板暂无查看/轮换入口）。请勿在日志中打印 token。

## 2. 通用约定

| 项 | 规则 |
| --- | --- |
| Base URL | `https://<panel-host>` |
| 编码 | 请求、响应均为 JSON（UTF-8） |
| 写请求 | 必须 `Content-Type: application/json`，否则 `415`；请求体上限 16 KiB，否则 `413` |
| 类型 | 严格类型：端口、ID 必须是 JSON 整数，不能是字符串 |
| 未知字段 | 任何未定义的顶层或嵌套字段 → `422 unknown_fields` |
| 缓存 | 成功响应带 `Cache-Control: no-store`、`X-Content-Type-Options: nosniff` |
| 版本控制 | 单资源响应带 `ETag: "forward-{id}-r{revision}"`；PATCH/DELETE 必须 `If-Match` 精确匹配（不支持 `W/`、`*`） |
| 幂等 | 创建必须带 `Idempotency-Key`（8–64 位 `[A-Za-z0-9._:-]`） |

## 3. 路由一览

| 方法 | 路径 | 必需头 | 结果 |
| --- | --- | --- | --- |
| `GET` | `/api/v1/user/services` | Bearer | 你名下的服务及其 `service_id`（即 `services.id`，不是客户端 ID，也不是 WHMCS 服务编号） |
| `GET` | `/api/v1/user/services/{service_id}/forward-options` | Bearer | 可选线路、传输方式、配额、权限 |
| `GET` | `/api/v1/user/services/{service_id}/forwards` | Bearer | 该服务下所有生效中的转发 |
| `POST` | `/api/v1/user/services/{service_id}/forwards` | Bearer、Content-Type、Idempotency-Key | 创建（`201`）或幂等重放（`200`） |
| `GET` | `/api/v1/user/forwards/{forward_id}` | Bearer | 单条转发 + ETag |
| `PATCH` | `/api/v1/user/forwards/{forward_id}` | Bearer、Content-Type、If-Match | 修改，返回新 ETag |
| `DELETE` | `/api/v1/user/forwards/{forward_id}` | Bearer、If-Match | 软删除，`204` 空响应 |

路径 ID 必须是正整数；格式不对、不属于你、已删除，一律返回 `404`（`service_not_found` / `forward_not_found`），不区分原因。

## 4. 查询可选项

```http
GET /api/v1/user/services/42/forward-options
Authorization: Bearer <token>
```

```json
{
  "ok": true,
  "transports": ["tcp", "udp", "tcp+udp"],
  "forward_limit": 64,
  "active_forward_count": 2,
  "capabilities": {
    "allow_manage_forwards": true,
    "allow_delete_forwards": true
  },
  "routes": [
    {
      "id": 14,
      "label": "沪日专线",
      "description": "上海 → 东京",
      "badge": "stable",
      "transports": {
        "tcp":     {"available": true,  "healthy_node_count": 2, "unavailable_reason": null},
        "udp":     {"available": false, "healthy_node_count": 0, "unavailable_reason": "no_healthy_nodes"},
        "tcp+udp": {"available": true,  "healthy_node_count": 2, "unavailable_reason": null}
      }
    }
  ]
}
```

- 只返回套餐明确授权、且至少含一个启用组合的线路；套餐未映射线路时返回空列表（fail-closed）。
- `forward_limit`：该服务允许同时生效的转发条数。
- `unavailable_reason` 取值：`route_unavailable`、`no_healthy_nodes`、`transport_unavailable`、`not_configured`。
- 注意：非 LeiGod 专属线路的 `healthy_node_count` 恒为 `0`、`available` 恒为 `true`，不代表实时健康度。

## 5. 转发资源（Forward）

列表、详情、创建、修改都返回同一结构：

```json
{
  "id": 123,
  "service_id": 42,
  "route": {"id": 14, "label": "沪日专线"},
  "listen_port": 11024,
  "transport": "tcp",
  "native_outbound": false,
  "native_direction": null,
  "target": {
    "source": "manual",
    "host": "203.0.113.10",
    "port": 443,
    "service_id": null
  },
  "ingress": {"host": null, "port": null},
  "state": "active",
  "endpoint_count": 1,
  "active_endpoint_count": 1,
  "revision": 3,
  "created_at": "2026-10-07 03:00:00",
  "updated_at": "2026-10-07 03:05:00"
}
```

| 字段 | 说明 |
| --- | --- |
| `listen_port` | 客户端设备上的本地监听端口 |
| `transport` | `tcp` / `udp` / `tcp+udp` |
| `target.source` | `manual`（手填 host+port）或 `service_ip`（指向你名下另一个服务的 IP） |
| `native_outbound` / `native_direction` | 只读；见第 9 节 |
| `ingress` | 保留字段，当前恒为 `null`，**不要依赖**；连接信息由客户端配置流程下发 |
| `revision` | 每次成功修改 +1；用于拼 ETag |

## 6. 创建

```http
POST /api/v1/user/services/42/forwards
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: create-fwd-20261007-01
```

```json
{
  "route_id": 14,
  "listen_port": null,
  "transport": "tcp",
  "target": {"source": "manual", "host": "203.0.113.10", "port": 443}
}
```

字段规则：

| 字段 | 必填 | 规则 |
| --- | --- | --- |
| `route_id` | 是 | 正整数，必须是 forward-options 返回的线路 |
| `listen_port` | 是（键必须存在） | 1–65535 整数，或 `null` 表示自动分配 |
| `transport` | 是 | `tcp` / `udp` / `tcp+udp` |
| `target` | 非 native 时必填 | 见下 |
| `native_outbound` | 否 | 默认 `false` |

`target`：

- `manual`：`{"source":"manual","host":"...","port":N}`。`host` 为 ASCII IPv4/IPv6/域名（≤253 字符），不允许 scheme、userinfo、路径、空白、控制字符、IPv6 zone。
  目标由平台节点代为连接，因此**保留地址段会被拒绝**（`422`，`fields: {"target.host": "reserved_range"}`）：
  环回 `127.0.0.0/8`、`::1`，链路本地 `169.254.0.0/16`（含云元数据 `169.254.169.254`），`0.0.0.0`，以及 `localhost` / `*.localhost`。
  私有网段（`10/8`、`172.16/12`、`192.168/16`）仍可用于你自己的后端。
- `service_ip`：`{"source":"service_ip","service_id":43,"port":8443}`。`service_id` 必须是你名下、**已分配公网 IP** 的服务，不接受 `host`。
  校验失败时 `fields` 为：`{"target.service_id": "not_found"}`（服务不存在）、`"not_allowed"`（不属于你）、`"no_public_ip"`（该服务没有公网 IP，例如专线服务本身）。

PowerShell 示例：

```powershell
$body = @'
{"route_id":14,"listen_port":null,"transport":"tcp+udp",
 "target":{"source":"manual","host":"203.0.113.10","port":443}}
'@
Invoke-RestMethod -Method Post `
  -Uri "https://panel.example.test/api/v1/user/services/42/forwards" `
  -Headers @{ Authorization = "Bearer $TOKEN"; 'Idempotency-Key' = 'create-fwd-20261007-01' } `
  -ContentType 'application/json' -Body $body
```

curl 示例：

```bash
curl -sS -X POST "https://panel.example.test/api/v1/user/services/42/forwards" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: create-fwd-20261007-01" \
  -H "Content-Type: application/json" \
  -d '{"route_id":14,"listen_port":null,"transport":"tcp","target":{"source":"manual","host":"203.0.113.10","port":443}}'
```

成功：`201`，带 `Location: /api/v1/user/forwards/{id}` 与 `ETag`，body 为 `{"ok":true,"forward":{...}}`。幂等重放返回 `200`，带 `Content-Location`（而非 `Location`）与 `ETag`。

服务端校验顺序（决定你先看到哪个错误）：
认证 → 服务归属 → 服务 active → 功能权限 → Content-Type → 体积 → 字段校验 → Idempotency-Key → 幂等重放 → 配额 → 线路/节点可用性。

## 7. 列表与详情

```http
GET /api/v1/user/services/42/forwards
GET /api/v1/user/forwards/123
```

列表返回 `{"ok":true,"forwards":[...]}`，只含生效中的转发，**不带 ETag**；需要修改/删除前，请先 GET 详情拿 ETag。

## 8. 修改（PATCH）

```http
PATCH /api/v1/user/forwards/123
Authorization: Bearer <token>
Content-Type: application/json
If-Match: "forward-123-r3"
```

```json
{
  "transport": "tcp+udp",
  "target": {"source": "manual", "host": "backend.example.test", "port": 8443}
}
```

- 可改字段：`route_id`、`listen_port`、`transport`、`target`（可部分）、`native_outbound`（仅关闭）。至少一个字段。
- 成功返回新资源和新 ETag（如 `"forward-123-r4"`）。
- **结构性修改**（改 `route_id`、`target.source`、`target.service_id`、实际改变 `transport`、或 `listen_port: null` 重新分配）：会重新编排节点，校验失败时原子回滚，线路不可用返回 `409 route_unavailable`。
- **非结构性修改**（只改 target host/port 或指定非零 listen_port）：走轻量更新路径，但仍会重建该转发的端点，**请勿高频调用**。
- 切换 `manual` ↔ `service_ip` 必须在同一个 patch 中带齐 `source` 与 `service_id`/`host`。

## 9. Native Outbound（设备侧出站）

承载在客户端设备上运行，而不是平台入口。创建时：

```json
{"route_id": 14, "listen_port": null, "transport": "tcp+udp", "native_outbound": true}
```

- 不传 `target`，服务端自动指定。
- 条件：线路为 Ultra 组合、`transport=tcp+udp`、套餐开启 `allow_native_outbound`、线路带方向、同服务下该方向未被占用。
- 关闭：`PATCH {"native_outbound": false, "target": {"source":"manual","host":"...","port":N}}`。
- 对已有转发开启 native 不是 v1 PATCH 操作，请新建或用面板表单。

## 10. 删除

```http
DELETE /api/v1/user/forwards/123
Authorization: Bearer <token>
If-Match: "forward-123-r4"
```

- 成功 `204` 空响应，为软删除（墓碑记录）；ETag 过期不会删除任何东西。
- 已删除记录计入“历史保留上限”（默认每服务 512 条），达到上限后创建返回 `409 forward_storage_quota_exceeded`，需要联系运营归档。请勿循环创建/删除。

## 11. 重试与并发

- 读请求可随意重试。
- 创建超时：用**同一个** `Idempotency-Key` 和**相同的** JSON 重试，服务端返回原资源（`200`），不会重复创建。
- 同一个 key 发不同内容 → `409 idempotency_conflict`；原资源已删除 → `409 idempotency_resource_inactive`。
- PATCH/DELETE 收到 `412 forward_version_conflict`：重新 GET 详情，确认后用新 ETag 再操作，不要原样重放。
- 并发创建在服务行上加锁复核配额，不会超出 `forward_limit`。

## 12. 错误

统一格式：

```json
{
  "ok": false,
  "error": {
    "code": "validation_failed",
    "message": "The forward request is invalid.",
    "fields": {"target.port": "out_of_range"}
  }
}
```

`unknown_fields` 的 `fields` 是数组（如 `["target.foo"]`），`validation_failed` 的 `fields` 是对象，每个 `validation_failed` 都带 `fields`。

| HTTP | code | 含义 |
| --- | --- | --- |
| 401 | `user_auth_failed` | token 无效 / 非 tenant / 账号停用 |
| 403 | `feature_denied_manage_forwards`、`feature_denied_delete_forwards` | 套餐/账号不允许该操作 |
| 404 | `service_not_found`、`forward_not_found` | 不存在、不属于你或已删除 |
| 403 | `native_outbound_not_allowed` | 套餐未开启 Native Outbound |
| 409 | `service_inactive` | 服务非 active，或已过到期时间（`expire_at`） |
| 409 | `native_direction_conflict` | 同服务下该方向已有生效的 native 转发 |
| 409 | `forward_quota_exceeded` | 生效转发数已达 `forward_limit` |
| 409 | `forward_storage_quota_exceeded` | 历史保留记录达上限 |
| 409 | `idempotency_conflict`、`idempotency_resource_inactive` | 见第 11 节 |
| 409 | `route_unavailable` | 线路当前无可用节点 |
| 412 | `forward_version_conflict` | If-Match 不匹配 |
| 413 | `request_too_large` | 请求体 > 16 KiB |
| 415 | `unsupported_media_type` | 非 `application/json` |
| 422 | `validation_failed`、`unknown_fields` | 字段非法 / 多余字段 |
| 422 | `idempotency_key_invalid` | key 格式不对 |
| 422 | `forward_route_not_allowed`、`forward_route_has_no_enabled_combination` | 线路不在套餐内 / 无启用组合 |
| 422 | `native_outbound_requires_ultra_tcp_udp` | 线路非 Ultra 组合，或 transport 不是 `tcp+udp` |
| 422 | `native_outbound_direction_missing` | 线路未配置方向 |
| 428 | `idempotency_key_required`、`precondition_required` | 缺 Idempotency-Key / If-Match |
| 500 | `operation_failed` | 内部错误（细节只记录在服务端） |

## 13. 旧版接口（Deprecated）

`/api/user/services/{id}/forwards`、`/api/user/forwards/{id}`（PUT/DELETE）、`/api/user/forwards/{id}/client-config`、
`/api/user/services/{id}/regenerate-client-token` 仍可用但已废弃（响应带 `Deprecation: true`），错误格式为
`{"error":code,"details":{...}}`，无 ETag/幂等保护。新集成请只用 v1。
