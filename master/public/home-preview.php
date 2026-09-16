<?php
declare(strict_types=1);

/**
 * Static homepage preview. No database, no session, no .env.
 * Open http://localhost:8080/home-preview.php
 */

require_once dirname(__DIR__) . '/src/Support/LocaleResolver.php';
require_once dirname(__DIR__) . '/src/Support/HomePageContent.php';

$body = \Leikwan\Master\Support\HomePageContent::render('LeiKwan Bridge', '/login', null);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>會聚 · LeiKwan Bridge | 利群主機 | LeiKwan Host</title>
  <meta name="description" content="會聚 LeiKwan Bridge：连接东亚多地节点的双向网络转发平台。利群主機提供云主机、网络基础设施与全球连接服务。 / A bilingual network bridge from LeiKwan Host across East Asia.">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://www.leikwanhost.com/">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="利群主機 | LeiKwan Host">
  <meta property="og:title" content="會聚 · LeiKwan Bridge">
  <meta property="og:description" content="利群主機云主机与全球网络连接 / LeiKwan Host cloud infrastructure and global connectivity">
  <meta property="og:url" content="https://www.leikwanhost.com/">
  <meta name="twitter:card" content="summary">
  <script type="application/ld+json">
  {"@context":"https://schema.org","@type":"WebSite","name":"會聚 · LeiKwan Bridge","url":"https://www.leikwanhost.com/","inLanguage":["zh-Hant","en"],"publisher":{"@type":"Organization","name":"利群主機 | LeiKwan Host","url":"https://www.leikwanhost.com"}}
  </script>
  <link rel="preload" href="/assets/fonts/inter-var.ttf" as="font" type="font/ttf" crossorigin>
  <link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
  <link rel="stylesheet" href="/assets/bridge-home.css?v=20260917b">
</head>
<body class="pg-public bridge-home-page <?= htmlspecialchars($GLOBALS['_home_style'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <div class="public-shell">
    <header class="site-topbar bridge-home-topbar">
      <a class="site-brand" href="/home-preview.php">
        <span class="site-mark"><img src="/assets/brand-mark.png" alt="LeiKwan Bridge logo"></span>
        <span class="site-copy">
          <strong lang="zh-Hant">利群會聚</strong>
          <span lang="en">LeiKwan Bridge</span>
        </span>
      </a>
      <div class="site-actions">
        <a class="button" href="/login">登录 · Sign In</a>
      </div>
    </header>
    <main class="landing-stack bridge-home-main">
      <div class="pg-public-body bridge-home-body">
        <?= $body ?>
      </div>
    </main>
  </div>
</body>
</html>
