<?php
declare(strict_types=1);

/**
 * Static homepage preview. No database, no session, no .env.
 * Open http://localhost:8080/home-preview.php
 */

require_once dirname(__DIR__) . '/src/Support/LocaleResolver.php';
require_once dirname(__DIR__) . '/src/Support/HomePageContent.php';

$locale = \Leikwan\Master\Support\LocaleResolver::normalize((string) ($_GET['lang'] ?? 'zh-Hans'))
    ?? 'zh-Hans';
$body = \Leikwan\Master\Support\HomePageContent::render('LeiKwan Bridge', '/login', $locale);
$lang = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LeiKwan Bridge — preview</title>
  <link rel="preload" href="/assets/fonts/cormorant-garamond-600.ttf" as="font" type="font/ttf" crossorigin>
  <link rel="preload" href="/assets/fonts/inter-var.ttf" as="font" type="font/ttf" crossorigin>
  <link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
  <link rel="stylesheet" href="/assets/bridge-home.css?v=20260916d">
</head>
<body class="pg-public bridge-home-page">
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
        <a class="button" href="/home-preview.php?lang=zh-Hans">简体</a>
        <a class="button" href="/home-preview.php?lang=zh-TW">繁體</a>
        <a class="button" href="/home-preview.php?lang=en">EN</a>
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
