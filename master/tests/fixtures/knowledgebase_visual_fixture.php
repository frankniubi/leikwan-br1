<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    http_response_code(500);
    exit('Could not resolve the master directory.');
}

$manifest = require $root . '/resources/knowledgebase/topics.php';
$localeLabels = [
    'en' => 'English',
    'zh-Hans' => '简体中文',
    'zh-TW' => '繁體中文',
];
$sections = '';
$diagramTotal = 0;
$articleTotal = 0;

foreach ($localeLabels as $locale => $localeLabel) {
    $articles = '';
    $localeTotal = 0;
    foreach ($manifest as $topicKey => $topic) {
        $article = $topic['articles'][$locale] ?? null;
        if (!is_array($article)) {
            continue;
        }
        $bodyPath = $root . '/resources/knowledgebase/' . (string) ($article['body'] ?? '');
        $body = is_file($bodyPath) ? (string) file_get_contents($bodyPath) : '';
        $count = substr_count($body, '<pre class="mermaid">');
        if ($count !== ($topicKey === 'glossary' ? 0 : 1)) {
            http_response_code(500);
            exit('Topic ' . $topicKey . ' must hold exactly one diagram per locale, found ' . $count . ' in ' . $locale . '.');
        }
        $articleTotal++;
        $diagramTotal += $count;
        $localeTotal += $count;
        $articles .= '<article class="kb-article" data-topic="' . htmlspecialchars((string) $topicKey, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="kb-article-meta"><span class="kb-pill">' . htmlspecialchars($localeLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>' . htmlspecialchars((string) $topicKey, ENT_QUOTES, 'UTF-8') . '</span></div>'
            . '<h2>' . htmlspecialchars((string) ($article['title'] ?? $topicKey), ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p class="kb-article-lead">' . htmlspecialchars((string) ($article['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="kb-article-body">' . $body . '</div>'
            . '</article>';
    }
    $sections .= '<section class="locale-section" id="locale-' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . '">'
        . '<header><span>Locale</span><h2>' . htmlspecialchars($localeLabel, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>' . $localeTotal . ' diagrams from the versioned guide resources.</p></header>'
        . $articles . '</section>';
}

$sections .= '<section class="locale-section" id="invalid-diagram">'
    . '<header><span>Failure fixture</span><h2>Invalid diagram isolation</h2>'
    . '<p>This final diagram is intentionally invalid. Its source and error notice must remain visible without affecting the valid diagrams above.</p></header>'
    . '<article class="kb-article"><h2>Deliberate parser failure</h2><div class="kb-article-body">'
    . '<pre class="mermaid" data-error-label="Diagram could not be rendered. The source is shown below.">flowchart TD'
    . "\naccTitle: Deliberately invalid diagram\naccDescr: Test that one parsing failure remains isolated\nA[Start] --&gt;</pre>"
    . '<p>The other diagrams must remain rendered when this one fails.</p></div></article></section>';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Knowledgebase visual fixture</title>
  <link rel="stylesheet" href="../../public/assets/knowledgebase-docs.css?v=20260914a">
  <style>
    :root { color-scheme: light; --ink: #15221c; --muted: #69776f; --line: #e1e8e2; --paper: #eef2ef; --card: #fff; --accent: #2f6b52; }
    * { box-sizing: border-box; }
    html { overflow-x: hidden; }
    body { margin: 0; background: var(--paper); color: var(--ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif; overflow-wrap: anywhere; }
    a { color: var(--accent); }
    .fixture-shell { width: min(1080px, calc(100% - 32px)); margin: 0 auto; padding: 36px 0 72px; }
    .fixture-header { display: grid; gap: 14px; margin-bottom: 28px; }
    .fixture-header h1, .fixture-header p { margin: 0; }
    .fixture-header nav { display: flex; flex-wrap: wrap; gap: 10px; }
    .fixture-header nav a { border: 1px solid var(--line); border-radius: 6px; background: var(--card); padding: 7px 11px; text-decoration: none; font-size: 14px; }
    .locale-section { display: grid; gap: 20px; margin-top: 42px; }
    .locale-section > header { border-bottom: 1px solid var(--line); padding-bottom: 14px; }
    .locale-section > header span { color: var(--muted); font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .locale-section > header h2, .locale-section > header p { margin: 5px 0 0; }
    .locale-section > header p { color: var(--muted); }
    .kb-article { padding: 32px 34px 36px; }
    @media (max-width: 620px) {
      .fixture-shell { width: min(100% - 20px, 1080px); padding-top: 20px; }
      .kb-article { padding: 22px 16px 26px; }
    }
  </style>
</head>
<body>
  <main class="fixture-shell kb-docs">
    <header class="fixture-header">
      <span>Test-only fixture</span>
      <h1>Multilingual knowledgebase diagrams</h1>
      <p><strong><?= $articleTotal ?> articles</strong> and <strong><?= $diagramTotal ?> Mermaid diagrams</strong> plus one intentional failure. This page uses only versioned resource files and the shared documentation stylesheet.</p>
      <nav aria-label="Language sections">
        <a href="#locale-en">English</a>
        <a href="#locale-zh-Hans">简体中文</a>
        <a href="#locale-zh-TW">繁體中文</a>
        <a href="#invalid-diagram">Failure fixture</a>
      </nav>
    </header>
    <?= $sections ?>
  </main>
  <script src="../../public/assets/vendor/mermaid/mermaid.min.js?v=11.12.0"></script>
  <script src="../../public/assets/knowledgebase-mermaid.js?v=20260729"></script>
</body>
</html>
