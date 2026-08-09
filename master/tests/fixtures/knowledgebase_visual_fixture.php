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
        if ($count === 0) {
            continue;
        }
        $diagramTotal += $count;
        $localeTotal += $count;
        $articles .= '<article class="kb-article" data-topic="' . htmlspecialchars((string) $topicKey, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="kb-article-meta"><span class="kb-pill">' . htmlspecialchars($localeLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>' . htmlspecialchars((string) $topicKey, ENT_QUOTES, 'UTF-8') . '</span></div>'
            . '<h2>' . htmlspecialchars((string) ($article['title'] ?? $topicKey), ENT_QUOTES, 'UTF-8') . '</h2>'
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

if ($diagramTotal !== 21) {
    http_response_code(500);
    exit('Expected 21 valid diagrams, found ' . $diagramTotal . '.');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Knowledgebase visual fixture</title>
  <style>
    :root { color-scheme: light; --ink: #18211c; --muted: #5d6861; --line: #d9dfdb; --paper: #f3f5f2; --card: #fff; --accent: #165c3d; --danger: #b42318; }
    * { box-sizing: border-box; }
    html { overflow-x: hidden; }
    body { margin: 0; background: var(--paper); color: var(--ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif; overflow-wrap: anywhere; }
    a { color: var(--accent); }
    .fixture-shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; padding: 36px 0 72px; }
    .fixture-header { display: grid; gap: 14px; margin-bottom: 28px; }
    .fixture-header h1, .fixture-header p { margin: 0; }
    .fixture-header nav { display: flex; flex-wrap: wrap; gap: 10px; }
    .fixture-header nav a { border: 1px solid var(--line); border-radius: 999px; background: var(--card); padding: 8px 12px; text-decoration: none; }
    .locale-section { display: grid; gap: 20px; margin-top: 42px; }
    .locale-section > header { border-bottom: 1px solid var(--line); padding-bottom: 14px; }
    .locale-section > header span { color: var(--muted); font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .locale-section > header h2, .locale-section > header p { margin: 5px 0 0; }
    .locale-section > header p { color: var(--muted); }
    .kb-article { max-width: 920px; border: 1px solid var(--line); border-radius: 18px; background: var(--card); padding: 28px; box-shadow: 0 10px 30px rgba(24, 33, 28, .04); }
    .kb-article > h2 { margin: 0 0 16px; }
    .kb-article-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; color: var(--muted); font-size: 12px; }
    .kb-pill { border: 1px solid #b9d2c3; border-radius: 999px; background: #edf7f1; color: var(--accent); padding: 4px 8px; font-weight: 700; }
    .kb-article-body { display: grid; gap: 14px; min-width: 0; line-height: 1.7; }
    .kb-article-body h2, .kb-article-body h3, .kb-article-body p, .kb-article-body ul, .kb-article-body ol { margin: 0; }
    .kb-mermaid-figure { max-width: 100%; margin: 8px 0; overflow: hidden; }
    .kb-mermaid-figure svg { display: block; width: 100%; max-width: 100%; height: auto; }
    .kb-mermaid-error { border-left: 4px solid var(--danger); margin: 0; padding: 10px 12px; color: var(--danger); font-weight: 700; }
    .kb-mermaid-failed { max-width: 100%; margin: 0; overflow-x: auto; white-space: pre; }
    @media (max-width: 520px) {
      .fixture-shell { width: min(100% - 20px, 1180px); padding-top: 20px; }
      .kb-article { padding: 18px 14px; border-radius: 12px; }
      .kb-mermaid-figure svg { min-width: 0; }
    }
  </style>
</head>
<body>
  <main class="fixture-shell">
    <header class="fixture-header">
      <span>Test-only fixture</span>
      <h1>Multilingual knowledgebase diagrams</h1>
      <p><strong>21 valid Mermaid diagrams</strong> plus one intentional failure. This page uses only versioned resource files and local browser assets.</p>
      <nav aria-label="Language sections">
        <a href="#locale-en">English</a>
        <a href="#locale-zh-Hans">简体中文</a>
        <a href="#locale-zh-TW">繁體中文</a>
        <a href="#invalid-diagram">Failure fixture</a>
      </nav>
    </header>
    <?= $sections ?>
  </main>
  <script src="/public/assets/vendor/mermaid/mermaid.min.js?v=11.12.0"></script>
  <script src="/public/assets/knowledgebase-mermaid.js?v=20260729"></script>
</body>
</html>
