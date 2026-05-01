# Master Backend

这是 Leikwan 转发平台的 Master 控制面后端。

## 包含内容

1. 纯 PHP 路由与控制器。
2. MySQL schema。
3. WHMCS webhook 接口。
4. 节点注册、心跳、任务、流量上报接口。
5. 用户服务与 client config 接口。
6. 安装脚本和 slave-agent 的 artifact 输出接口。

## 初始化

1. 复制 `.env.example` 为 `.env`。
2. 填写数据库和 WHMCS 参数。
3. 用 [schema.sql](C:\Users\frankniubi\Downloads\leikwan-v3\master\migrations\schema.sql) 初始化 MySQL。
4. 将 `public/` 配置为 Nginx 站点根目录。
5. 运行 [create_user.php](C:\Users\frankniubi\Downloads\leikwan-v3\master\scripts\create_user.php) 创建第一个管理员。

## 关键环境变量

- `MASTER_ADMIN_TOKEN`
- `WHMCS_WEBHOOK_TOKEN`
- `NODE_ENROLLMENT_TOKEN`
- `WHMCS_BASE_URL`
- `WHMCS_API_IDENTIFIER`
- `WHMCS_API_SECRET`

## 同步方式

推荐同时启用：

1. WHMCS webhook 推送到 `/api/whmcs/*`
2. 定时执行 [whmcs_sync.php](C:\Users\frankniubi\Downloads\leikwan-v3\master\scripts\whmcs_sync.php) 做兜底对账

## Web 入口

- `/login`: 登录页
- `/admin`: 管理员控制台
- `/tenant`: 租户控制台
- `/install.php`: 幂等安装/更新页面

## Artifact And Update Endpoints

- `/artifacts/slave-manifest.json`
- `/artifacts/client-manifest.json`
- `/artifacts/install-slave.sh`
- `/artifacts/install-client.sh`
- `/artifacts/install-client-agent.sh`
- `/artifacts/client-agent.py`
- `/artifacts/install-forward-runtime.py`
- `/artifacts/remote-install-slave.sh`
- `/artifacts/remote-install-client.sh`

## i18n Patch Files

- `/i18n/patch.js`
- `/i18n/en.json`
- `/i18n/zh-TW.json`

UI text can now be translated through external language patch files instead of editing PHP templates directly.

## SSL Task Worker

Panel-side Let's Encrypt issuance is now queued into `ssl_certificate_tasks` and should be processed by a backend worker instead of the browser request.

Manual run:

```bash
php /var/www/master/scripts/process_ssl_certificate_tasks.php --limit 5
```

Recommended systemd units:

- `deploy/systemd/leikwan-ssl-task-worker.service`
- `deploy/systemd/leikwan-ssl-task-worker.timer`

Recommended ingress mode:

- Keep slave ingress on a shared TLS port such as `443`
- Let each forward use its own WebSocket path prefix
- Use `race / failover` plus multiple endpoints when you want client-side redundancy for new connections
