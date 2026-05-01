# API Overview

## WHMCS

`POST /api/whmcs/provision`

请求头：

- `X-WHMCS-Webhook-Token: <WHMCS_WEBHOOK_TOKEN>`

示例 body：

```json
{
  "whmcs_service_id": "10001",
  "user_email": "user@example.com",
  "user_name": "Demo User",
  "product_code": "wss-forward-basic",
  "service_name": "HK Basic",
  "expire_at": "2026-04-30 00:00:00",
  "bandwidth_limit_mbps": 100,
  "traffic_limit_gb": 1000,
  "forwards": [
    {
      "listen_port": 24443,
      "public_port": 24443,
      "target_host": "127.0.0.1",
      "target_port": 443,
      "bond_remote_host": "198.51.100.10",
      "bond_remote_port": 9000,
      "transport_mode": "tcp",
      "client_mode": "bond",
      "sni_host": "edge.example.com",
      "cdn_host": "edge.example.com",
      "node_group": "default",
      "filter_profile": "off"
    }
  ]
}
```

Forward payloads no longer accept a user-specified `endpoint_count`. The panel assigns endpoint count automatically from the selected route scope, connection mode, and currently usable nodes.

For tenant-managed bond forwards, the target-side `leikwan-bond server` can use that service's `client_token` as its `--token-secret`. `BOND_TOKEN_SECRET` remains a panel-side fallback when a service token is not available.

`POST /api/whmcs/renew`

```json
{
  "whmcs_service_id": "10001",
  "expire_at": "2026-05-30 00:00:00"
}
```

`POST /api/whmcs/suspend`

```json
{
  "whmcs_service_id": "10001",
  "reason": "billing"
}
```

`POST /api/whmcs/terminate`

```json
{
  "whmcs_service_id": "10001"
}
```

## User

用户接口使用：

- `Authorization: Bearer <users.api_token>`

接口：

- `GET /api/user/services`
- `GET /api/user/services/{id}`
- `POST /api/user/services/{id}/forwards`
- `PUT /api/user/forwards/{id}`
- `DELETE /api/user/forwards/{id}`
- `GET /api/user/forwards/{id}/client-config`
- `POST /api/user/services/{id}/regenerate-client-token`

## Node

节点注册：

- `POST /api/node/register`
- Header: `X-Enrollment-Token: <NODE_ENROLLMENT_TOKEN>`

已注册节点：

- `POST /api/node/heartbeat`
- `GET /api/node/tasks/pull`
- `POST /api/node/tasks/{id}/ack`
- `POST /api/node/tasks/{id}/result`
- `POST /api/node/report-usage`

节点接口使用：

- `Authorization: Bearer <nodes.api_token>`

## Client Config

用户或 client token 都可以获取配置：

- `GET /api/user/forwards/{id}/client-config`

client token 方式：

`GET /api/user/forwards/{id}/client-config?token=<services.client_token>`
## Artifact Manifests

- `GET /artifacts/slave-manifest.json`
- `GET /artifacts/client-manifest.json`

Each manifest contains current version, artifact URLs and SHA-256 hashes.
