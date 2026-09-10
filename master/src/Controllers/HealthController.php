<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;

final class HealthController extends BaseController
{
    public function ping(Request $request, array $params): Response
    {
        return $this->json([
            'status' => 'ok',
            'service' => (string) $this->config->get('APP_NAME', '利群會聚 LeiKwan Bridge'),
            'time' => gmdate('c'),
        ]);
    }

    public function landing(Request $request, array $params): Response
    {
        $appName = (string) $this->config->get('APP_NAME', '利群會聚 LeiKwan Bridge');
        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$appName}</title>
  <link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
  <link rel="stylesheet" href="/assets/signal-chamber.css?v=20260725">
</head>
<body class="pg-public">
  <main class="landing-stack"><div class="card pg-form-section">
    <h1>{$appName}</h1>
    <p>利群會聚 LeiKwan Bridge 的 Master 控制面已经就绪。当前仓库提供了 WHMCS、节点编排、流量上报、client 配置和安装脚本接口。</p>
    <p>健康检查：<code>/api/health</code></p>
    <p>管理员登录：<code>/login</code></p>
    <p>节点安装脚本：<code>/artifacts/install-slave.sh</code></p>
  </div></main>
</body>
</html>
HTML;

        return Response::html(200, $html);
    }
}
