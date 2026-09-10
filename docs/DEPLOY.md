# Deployment Notes

## AI reference

For the current AI-oriented end-to-end architecture, release history, and working logic for both normal mode and CF mode, read:

- `docs/2026-04-27-forwarding-runtime-ai-tech-book.md`

## Master

Recommended path now:

1. Deploy the code.
2. Open `/install.php`.
3. Fill DB + app settings once.
4. Run install/update from the web installer.
5. Sign in at `/login`.

1. 准备一台 LNMP 服务器。
2. 部署 [master](C:\Users\frankniubi\Downloads\leikwan-v3\master) 到站点目录。
3. 复制 `.env.example` 到 `.env`。
4. 初始化 [schema.sql](C:\Users\frankniubi\Downloads\leikwan-v3\master\migrations\schema.sql)。
5. Nginx 站点根目录指向 `master/public`。
6. 开启 [deploy/systemd/leikwan-whmcs-sync.timer](C:\Users\frankniubi\Downloads\leikwan-v3\deploy\systemd\leikwan-whmcs-sync.timer)。
7. 运行：

```bash
php /var/www/master/scripts/create_user.php --email admin@example.com --password ChangeMe123! --role admin --name "Platform Admin"
```

Background workers to enable on the panel host:

- `deploy/systemd/leikwan-whmcs-sync.timer`
- `deploy/systemd/leikwan-ssl-task-worker.timer`

The SSL task worker processes queued panel-side certificate issue and renew jobs:

```bash
php /var/www/master/scripts/process_ssl_certificate_tasks.php --limit 5
```

8. 打开 `/login`。

如果你已经部署过旧版 schema，请先执行：

```sql
SOURCE /var/www/master/migrations/20260330_add_web_auth.sql;
```

## Slave

一键安装：

```bash
curl -fsSL https://panel.example.com/artifacts/install-slave.sh | bash -s -- \
  --master https://panel.example.com \
  --token NODE_ENROLLMENT_TOKEN \
  --group default \
  --region hk
```

Before first slave install, prepare the panel-hosted `wstunnel` archives:

```bash
cd /var/www/leikwan
bash install/fetch-wstunnel-artifacts.sh
```

If your slave/client machines include Debian 11, Ubuntu 20, or other older glibc hosts,
do not rely on Linux amd64 binaries that were built on a newer distro. Rebuild the panel
artifacts in a bullseye container instead:

```bash
cd /var/www/leikwan
bash install/build-runtime-artifacts.sh
```

This places the expected files under `artifacts/wstunnel/`, and slave nodes will then download them from:

- `https://panel.example.com/artifacts/wstunnel/wstunnel_10.5.1_linux_amd64.tar.gz`
- `https://panel.example.com/artifacts/wstunnel/wstunnel_10.5.1_linux_arm64.tar.gz`

## Client

### Linux arm64 single-file installer

The published ARM64 installer is a self-contained static ELF. It embeds the
client-agent shell installer, Python helpers, Ultra runtime, wstunnel, bond,
ingress, and UoT native helpers; only the control-plane API is contacted during
installation. The configured `--artifact-base` is retained in
`/etc/leikwan-client-agent/config.json` for later hash-verified updates.

```bash
curl -fsSL https://v3.leikwanhost.com/artifacts/install-client-agent-linux-arm64 \
  -o install-client-agent-linux-arm64
chmod 0755 install-client-agent-linux-arm64
sudo ./install-client-agent-linux-arm64 \
  --master https://v3.leikwanhost.com \
  --client-id CLIENT_ID \
  --agent-token AGENT_TOKEN
```

The binary is Linux `aarch64/arm64` only. Verify before execution with:

```bash
./install-client-agent-linux-arm64 --self-test
```

单点安装：

```bash
curl -fsSL https://panel.example.com/artifacts/install-client.sh | bash -s -- \
  --master https://panel.example.com \
  --forward-id 1 \
  --token SERVICES_CLIENT_TOKEN
```

高级多节点模式：

```bash
curl -fsSL https://panel.example.com/artifacts/client.sh -o client.sh
chmod +x client.sh
```

## 安全建议

1. 把 `MASTER_ADMIN_TOKEN`、`WHMCS_WEBHOOK_TOKEN`、`NODE_ENROLLMENT_TOKEN` 放在只读配置中。
2. 对 Master API 开启 Nginx TLS。
3. 限制节点注册接口只对管理网络开放，或给注册 token 设置短时轮换。
4. 将 WHMCS webhook 和同步任务同时启用，避免单点漂移。
## Remote Install

Slave over SSH:

```bash
bash /var/www/master/install/remote-install-slave.sh \
  --host 1.2.3.4 \
  --master https://panel.example.com \
  --token NODE_ENROLLMENT_TOKEN \
  --group default \
  --region hk
```

Notes:

1. Slave install now creates the default TLS base path under `/etc/wstunnel/ssl` automatically.
2. Slave public IPv4 detection now prefers local route/interface inspection and then falls back to several echo endpoints, including ones that are usually more reachable from mainland China. If all checks still fail, pass `--public-ip` explicitly during install.
3. If you do not want each slave to run `certbot`, install the slave with `--tls-mode self-signed` first, then open `Credentials` in the panel and create an `SSL Certificate Library` item. You can now issue a fresh panel-side Let's Encrypt certificate through Cloudflare DNS from the `Panel Let's Encrypt via CF DNS` source type, point at explicit panel cert/key filesystem paths, or upload an existing PEM certificate/key pair. Panel-side certbot issuance now uses the app-owned writable directories under `master/runtime/certbot/` by default. You can override them with `PANEL_CERTBOT_BASE_DIR`, `PANEL_CERTBOT_CONFIG_DIR`, `PANEL_CERTBOT_WORK_DIR`, and `PANEL_CERTBOT_LOGS_DIR` in `.env`. After that, assign the library item from the node management page and the slave will pull the PEM bundle on its next poll.
   Browser saves now only queue the SSL job. Enable `deploy/systemd/leikwan-ssl-task-worker.timer` or run `php /var/www/master/scripts/process_ssl_certificate_tasks.php --limit 5` from cron/systemd so the backend worker can execute queued certbot tasks and write logs back into the panel.
5. Shared ingress is now the recommended default. Set the slave group's preferred public port to `443`, keep the edge port strategy on `shared preferred port, multi-path routing`, and let the panel generate a unique WebSocket path for each forward. This lets one slave TLS listener carry many services on the same port.
6. Client agents now perform periodic local and remote ready probes. In `race / failover` mode, new connections can fail over to another slave endpoint when one endpoint is unhealthy. Existing TCP sessions still cannot be migrated mid-stream, so seamless hot-swap for an already established TCP connection is not possible.
7. True single-stream bonding now works best as a target-side sidecar. New tenant-managed bond forwards use that service's `client_token` as the default sidecar secret, so the tenant can install `leikwan-bond server` on their own C-side machine directly from the tenant panel. `BOND_TOKEN_SECRET` in `master/.env` is still accepted as a fallback when a service token is not available, and `BOND_AGGREGATOR_HOST` / `BOND_AGGREGATOR_PORT` remain only as a legacy remote-target fallback for older forwards:

```bash
cd /var/www/master/wstunnel-main
cargo build --release -p leikwan-bond
./target/release/leikwan-bond server \
  --listen 0.0.0.0:9000 \
  --token-secret "$BOND_TOKEN_SECRET"
```

Clients in `bond` mode now support `tcp`, `udp`, and `tcp+udp` over the sidecar path, and they require `/usr/local/bin/leikwan-bond` on the client host.
4. Browser visits to the slave port now return a neutral landing page or normal 404 page. Health checks remain under the internal `/_leikwan/*` paths.
5. To probe a published slave listener:

```bash
curl -sk https://<host>:<public_port>/_leikwan/healthz
curl -sk https://<host>:<public_port>/_leikwan/readyz/<path_prefix>
```

The first command only checks that the TLS listener is alive. The second checks that the specific path route exists and that the local backend socket is reachable.

Client over SSH:

```bash
bash /var/www/master/install/remote-install-client.sh \
  --host 1.2.3.4 \
  --master https://panel.example.com \
  --forward-id 1 \
  --token SERVICES_CLIENT_TOKEN
```

Windows client manual install in elevated PowerShell:

```powershell
$tmp = "$env:TEMP\install-client.ps1"
Invoke-WebRequest -UseBasicParsing "https://panel.example.com/artifacts/install-client.ps1" -OutFile $tmp
powershell -ExecutionPolicy Bypass -File $tmp `
  -MasterUrl "https://panel.example.com" `
  -ForwardId 1 `
  -Token "SERVICES_CLIENT_TOKEN"
```

The panel-hosted client manifest now includes Linux/Windows amd64 `wstunnel` packages plus Linux/Windows amd64 `leikwan-bond` packages, so client installers no longer need to pull `wstunnel` from GitHub directly. Linux amd64 artifacts should be rebuilt through `install/build-runtime-artifacts.sh` when you need compatibility with Debian 11 / Ubuntu 20 class hosts.

## Bond plain TCP ingress prototype

- bond forwards using `bond_ingress_mode=plain_tcp_mux` should expose the slave ingress on a dedicated public port such as `7001`
- `client -> slave` uses a transparent shared-port TCP mux driven by `leikwan-ingress`
- health checks for this mode use the same TCP ingress path with a `PING` command, not `/_leikwan/readyz/...`
- `slave -> sidecar` remains one raw TCP connection per accepted bond leg

## Manual Pull Updates

On a slave host:

```bash
/usr/local/bin/leikwan-slave-update check
/usr/local/bin/leikwan-slave-update
```

On a client host:

```bash
/usr/local/bin/leikwan-client-update --forward-id 1 --check
/usr/local/bin/leikwan-client-update --forward-id 1
```

Client auto-sync is also enabled automatically via:

```bash
systemctl status leikwan-client-sync-<forward_id>.timer
```

Tenant-owned client agent install:

```bash
curl -fsSL https://panel.example.com/artifacts/install-client-agent.sh | bash -s -- \
  --master https://panel.example.com \
  --client-id 123 \
  --agent-token CLIENT_AGENT_TOKEN
```

## MPTCP Tuning Reference

Current tested baseline for the sidecar MPTCP path:

- WireGuard MTU `1382`
- TCP congestion control `bbr`
- client MPTCP endpoints using `subflow`
- sidecar MPTCP endpoints using `signal`
- sidecar runtime with `net.mptcp.allow_join_initial_addr_port=0`

For controlled lab tuning, the client agent now also supports:

```json
{
  "disable_auto_updates": true
}
```

Use that only on test clients when you want repeated throughput measurements without self-update noise.
