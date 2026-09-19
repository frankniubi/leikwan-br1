# 多 External UoT sidecar（无需新 client build）

## 行为与限制

- 面板继续向客户端发单个 `sidecar_target`，token、帧格式不变。
- 每个原始 target 对应一个节点池；同一客户端、设备、forward 通过 rendezvous hash 稳定选择健康节点。节点退出时只重新分配受影响的分配。
- sidecar 上独立 Python agent 每轮拉配置、执行本机 UOT2 → UDP echo → UOT2 验证、提交独立 Bearer 鉴权心跳，轮次间隔 10 秒。面板默认心跳过期时间 45 秒。健康检查不是公网 relay 路径验收。
- 现有 Go sidecar 已主动发 UOT2 KEEPALIVE，旧客户端回应；默认保活 10 秒。本实现不增加客户端协议字段。
- 切换发生于客户端下次成功拉取配置/续租，不是现有 TCP carrier 的瞬时热迁移。UDP 源端口及服务端会话状态不能跨节点继承，可能需要应用重连。
- 全池失效返回 503，不偷偷走 native UDP 或重新选择已知失效节点。既有客户端可能继续持有旧租约。
- `reliable_datagram_v1` 只分配给显式 `reliable: true` 的节点；不要在未升级的 sidecar 上开启此标记。
- 节点配置变化引起服务重启，会断开其现有会话。建议先停分配并等会话排空，再更新绑定。
- 当前提供配置/API 管理，尚无专用后台节点池编辑页面。

Ultra 路径始终是 `client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`，不使用 LeiKwan slave/CDN。

## 面板配置（默认不开启）

面板 `.env`，JSON 必须单行；为每台节点生成不同的至少 32 字符随机 secret：

```dotenv
EXTERNAL_UOT_POOL_JSON='[{"id":"la1","target":"la1.example.net:7443","secret":"REPLACE_WITH_RANDOM_SECRET_AT_LEAST_32_CHARS","origins":["original.example.net:7443"]},{"id":"la2","target":"la2.example.net:7443","secret":"REPLACE_WITH_ANOTHER_RANDOM_SECRET_AT_LEAST_32_CHARS","origins":["original.example.net:7443"]}]'
EXTERNAL_UOT_POOL_STATE_DIR=/var/lib/leikwan/uot-pool
EXTERNAL_UOT_POOL_TTL_SECONDS=45
```

`origins` 必须精确匹配现有组合的 `external_uot_sidecar_target`，不要修改组合 token。池里每台节点会获得该 origin 下全部组合的 forward/token 配置。不同区域可配置不同 origins。节点置 `enabled:false` 后不再接受新分配，但其配置/心跳 API 仍可用。

状态目录必须在 Web 根目录之外，只允许 PHP 服务用户读写；多个面板 worker 共享同一目录，多机面板必须使用支持文件锁的共享存储或改为共享数据库状态。secret 不提交 Git，不显示给租户，不放 URL。

## Sidecar 安装

先用现有 `install-external-uot.sh` 安装 Go sidecar 和 `leikwan-external-uot.service`。将 `tools/uot-sidecar/pool-agent.py` 复制到 `/usr/local/lib/leikwan/uot-pool-agent.py`。创建权限 `0600` 的 `/etc/leikwan-uot-pool-agent.json`：

```json
{
  "panel_url": "https://panel.example.net",
  "node_id": "la1",
  "secret": "MATCH_THE_PANEL_NODE_SECRET",
  "binary_path": "/usr/local/bin/leikwan-external-uot-go",
  "config_path": "/etc/leikwan-external-uot.json",
  "unit": "leikwan-external-uot.service"
}
```

面板必须启用有效 HTTPS；agent 禁止重定向，避免 Bearer 泄漏。agent 对配置先 `--check-config`，保留 `.pool-backup`，再重启并进行本机协议回环；失败尝试恢复旧配置。首装无旧配置时失败会保持不健康，不会获得流量。

```ini
# /etc/systemd/system/leikwan-uot-pool-agent.service
[Unit]
Description=LeiKwan external UoT pool heartbeat
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
UMask=0077
ExecStart=/usr/bin/python3 /usr/local/lib/leikwan/uot-pool-agent.py --settings /etc/leikwan-uot-pool-agent.json
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```sh
chmod 600 /etc/leikwan-uot-pool-agent.json
python3 /usr/local/lib/leikwan/uot-pool-agent.py --settings /etc/leikwan-uot-pool-agent.json --once
systemctl daemon-reload
systemctl enable --now leikwan-uot-pool-agent.service
```

无绑定的节点不会上报健康。应先配置一个测试组合完成部署验收，再把生产 origins 纳入节点池。不能先启用生产池而让所有节点尚未启动，否则续租会 503。

## API

- `GET /api/external-uot/pool/{id}/config`：Bearer secret，仅返回本节点授权 origins 的 sidecar 配置和 `config_hash`。
- `POST /api/external-uot/pool/{id}/heartbeat`：同样鉴权，body 为 `{"healthy":true,"config_hash":"..."}`。旧配置摘要返回 409；时间采用面板接收时间。
- config_hash 是配置同步证明，不是独立可信远程度量。已被控制的 sidecar 能伪报健康。现有旧 `/external-uot-config` 的权限行为未在本改动中变更。

## 验收及回滚

1. 备份面板文件/.env 和两台 sidecar 配置，先用测试 origin 验证。
2. 验证匿名/错误 token 为 401，错 config_hash 为 409。
3. 检查两节点同样接受测试 forward；真实旧客户端经 Ultra provider relay 访问 UDP echo。
4. 停一台 agent，45 秒后再拉取/续租，确认落到另一台；记录实际客户端更新时间及 UDP 恢复时间。
5. 停两台 agent，确认返回 503 而不是 native UDP 回退；验证恢复、长时间空闲保活和公网 UDP 数据面。
6. 回滚：清空 `EXTERNAL_UOT_POOL_JSON`，停止 pool agent，恢复 sidecar `.pool-backup` 并重启。客户端下一次刷新回到组合原 target；该 target 必须仍可用。

本地测试不等同于以上生产多节点/三平台验收。未修改或发布 client build。
