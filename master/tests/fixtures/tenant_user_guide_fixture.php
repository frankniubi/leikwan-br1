<?php
declare(strict_types=1);

$allowedViews = [
    'overview',
    'forward-form',
    'client-install',
    'forward-result',
    'diagnostics',
    'target-endpoint',
];
$requestedView = (string) ($_GET['view'] ?? 'overview');
$view = in_array($requestedView, $allowedViews, true) ? $requestedView : 'overview';

function guideEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function guideNav(string $active): string
{
    $items = [
        'overview' => ['Overview', '/master/tests/fixtures/tenant_user_guide_fixture.php?view=overview'],
        'forward-form' => ['Forwards', '/master/tests/fixtures/tenant_user_guide_fixture.php?view=forward-form'],
        'client-install' => ['Clients', '/master/tests/fixtures/tenant_user_guide_fixture.php?view=client-install'],
        'forward-result' => ['Forward result', '/master/tests/fixtures/tenant_user_guide_fixture.php?view=forward-result'],
        'diagnostics' => ['Diagnostics', '/master/tests/fixtures/tenant_user_guide_fixture.php?view=diagnostics'],
        'target-endpoint' => ['Target Endpoints', '/master/tests/fixtures/tenant_user_guide_fixture.php?view=target-endpoint'],
    ];

    $html = '';
    foreach ($items as $key => [$label, $href]) {
        $class = $key === $active ? ' class="active"' : '';
        $html .= '<a' . $class . ' href="' . guideEscape($href) . '">' . guideEscape($label) . '</a>';
    }
    return $html;
}

function guideField(string $label, string $value = '', string $type = 'text'): string
{
    return '<div class="field"><label>' . guideEscape($label) . '</label><input type="' . guideEscape($type) . '" value="' . guideEscape($value) . '" readonly></div>';
}

function guideCard(string $title, string $body): string
{
    return '<section class="card"><h3>' . guideEscape($title) . '</h3>' . $body . '</section>';
}

$demoNotice = '<div class="demo-notice"><strong>DEMO DATA</strong><span>本页面只用于文档截图，所有值都是演示值。</span></div>';
$body = match ($view) {
    'forward-form' => '
        <div class="hero"><div><span class="eyebrow">Services / Forwards</span><h2>Create Forward</h2><p class="muted">Choose a published route, enter the destination, and save the first forwarding rule.</p></div></div>
        <section class="card"><form><div class="form-grid">'
            . '<div class="field"><label>Forward Route</label><select><option>Demo Route / Recommended</option></select></div>'
            . guideField('Target Host', 'demo.example.invalid')
            . guideField('Target Port', '8443', 'number')
            . guideField('Local Listen Port', '')
            . '<div class="field"><label>Transport</label><select><option>TCP</option><option>TCP + UDP</option></select></div>'
            . '</div><div class="actions"><button class="button" type="button">Create Forward</button><button class="button ghost" type="button">Cancel</button></div></form></section>',
    'client-install' => '
        <div class="hero"><div><span class="eyebrow">Clients / Install</span><h2>Install Client</h2><p class="muted">Choose the device you will use, then return here to confirm the latest sync.</p></div></div>
        <nav class="tabs"><a class="active" href="#install">Install</a><a href="#clients">Clients</a></nav>
        <div class="grid three">'
            . guideCard('Windows App', '<p class="muted">Download the app, open it, and use the panel account shown in your own session.</p><div class="actions"><button class="button" type="button">Download Windows App</button></div>')
            . guideCard('Android App', '<p class="muted">Install the supported package, approve the VPN request, and select a service offered by the app.</p><div class="actions"><button class="button" type="button">Download Android App</button></div>')
            . guideCard('Linux Install', '<p class="muted">Copy the one-time installer from this card. Keep the command private and run it only on the host that will run the client-agent.</p><div class="actions"><button class="button" type="button">Copy Install Command</button></div>')
            . '</div><section class="card"><h3>Client status</h3><div class="status-line"><span class="badge success">Connected</span><strong>Last sync: just now</strong></div></section>',
    'forward-result' => '
        <div class="hero"><div><span class="eyebrow">Services / Forwards</span><h2>Forward result</h2><p class="muted">Use the local listen port shown here in the application that should use the route.</p></div></div>
        <section class="card"><div class="result-grid"><div><span class="muted">Local Listen</span><strong>127.0.0.1:123</strong></div><div><span class="muted">Target</span><strong>demo.example.invalid:8443</strong></div><div><span class="muted">Transport</span><strong>TCP</strong></div><div><span class="muted">Endpoint Status</span><span class="badge success">Running</span></div></div><div class="actions"><button class="button" type="button">Edit</button><button class="button ghost" type="button">Run Diagnostics</button><button class="button ghost" type="button">View Diagnostics</button></div></section>',
    'diagnostics' => '
        <div class="hero"><div><span class="eyebrow">Clients / Diagnostics</span><h2>Client diagnostics</h2><p class="muted">Run a fresh check before opening the details view.</p></div></div>
        <section class="card"><div class="actions"><button class="button" type="button">Run Diagnostics</button><button class="button ghost" type="button">View Diagnostics</button></div><div class="diagnostic-dialog"><div class="dialog-title"><strong>View Diagnostics</strong><span class="badge success">Healthy</span></div><dl><div><dt>Client sync</dt><dd>Recent</dd></div><div><dt>Forward state</dt><dd>Running</dd></div><div><dt>Path check</dt><dd>Passed</dd></div></dl><p class="muted">Diagnostic details hide internal node addresses.</p></div></section>',
    'target-endpoint' => '
        <div class="hero"><div><span class="eyebrow">Advanced / Target Endpoints</span><h2>Create Transport Endpoint</h2><p class="muted">Only use this page when an administrator or plan requires a tenant-owned target transport endpoint.</p></div></div>
        <section class="card"><form><div class="form-grid">'
            . guideField('Name', 'Demo endpoint')
            . guideField('Sidecar Host', 'target.example.invalid')
            . guideField('Transport Listen Port', '7800', 'number')
            . guideField('Alternate Listen Port', '7801', 'number')
            . guideField('CF Listen Port', '7844', 'number')
            . guideField('Bind Host', '0.0.0.0')
            . '</div><div class="actions"><button class="button" type="button">Save Transport Endpoint</button></div></form></section>',
    default => '
        <div class="hero"><div><span class="eyebrow">Tenant / Getting Started</span><h2>Overview</h2><p class="muted">Check your active package, create one forward, then install a client.</p></div></div>
        <div class="grid three"><section class="card"><div class="muted">Active packages</div><div class="stats">1</div></section><section class="card"><div class="muted">Forwards</div><div class="stats">0</div></section><section class="card"><div class="muted">Clients</div><div class="stats">0</div></section></div>
        <section class="card onboarding"><h3>Get connected in 3 steps</h3><ol><li><strong>Buy a package</strong><span class="badge success">Done</span></li><li><strong>Create a forward</strong><span class="badge info">Current</span></li><li><strong>Install client</strong><span class="badge">Next</span></li></ol><div class="actions"><button class="button" type="button">Create a forward</button><button class="button ghost" type="button">Open setup guide</button></div></section>',
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tenant user guide fixture</title>
  <link rel="stylesheet" href="/master/public/assets/leikwan-ui.css?v=20260806">
  <link rel="stylesheet" href="/master/public/assets/signal-chamber.css?v=20260806">
  <link rel="stylesheet" href="/master/public/assets/bridge-modern.css?v=20260806">
  <style>
    :root { --fixture-line: #dfe5e1; --fixture-ink: #18211c; --fixture-muted: #5d6861; --fixture-bg: #f3f5f2; }
    body { margin: 0; background: var(--fixture-bg); color: var(--fixture-ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
    .fixture-layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); min-height: 100vh; }
    .fixture-sidebar { min-width: 0; padding: 24px 16px; background: #18211c; color: #fff; }
    .fixture-brand { display: block; margin: 0 8px 24px; font-weight: 800; letter-spacing: .04em; }
    .fixture-sidebar nav { min-width: 0; display: grid; gap: 6px; }
    .fixture-sidebar a { color: #c9d6cd; border-radius: 8px; padding: 10px 12px; text-decoration: none; }
    .fixture-sidebar a:hover, .fixture-sidebar a.active { background: #2c4c3b; color: #fff; }
    .fixture-main { min-width: 0; overflow: hidden; padding: 30px clamp(18px, 4vw, 56px) 60px; }
    .fixture-content { width: min(1120px, 100%); margin: 0 auto; }
    .demo-notice { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; margin-bottom: 18px; padding: 10px 14px; border: 1px solid #ddc47a; border-radius: 10px; background: #fff8df; color: #624b05; }
    .demo-notice strong { font-size: 12px; letter-spacing: .12em; }
    .hero { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; margin-bottom: 20px; }
    .hero h2, .hero p { margin: 0; }
    .eyebrow { display: inline-block; margin-bottom: 8px; color: #286044; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .muted { color: var(--fixture-muted); }
    .grid { display: grid; gap: 18px; }
    .grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .card { min-width: 0; border: 1px solid var(--fixture-line); border-radius: 12px; background: #fff; padding: 20px; box-shadow: 0 8px 24px rgba(24, 33, 28, .05); }
    .card h3 { margin: 0 0 12px; }
    .stats { margin-top: 6px; font-size: 30px; font-weight: 800; }
    .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .field { min-width: 0; display: grid; gap: 7px; }
    .field label { font-size: 13px; font-weight: 700; }
    .field input, .field select { width: 100%; min-width: 0; border: 1px solid #cbd5d0; border-radius: 8px; padding: 10px 12px; background: #fff; color: var(--fixture-ink); font: inherit; }
    .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .button { border: 0; border-radius: 8px; padding: 10px 14px; background: #165c3d; color: #fff; font: inherit; font-weight: 700; }
    .button.ghost { border: 1px solid #b8c6bd; background: #fff; color: #165c3d; }
    .badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 9px; background: #edf0ed; color: #536057; font-size: 12px; font-weight: 700; }
    .badge.success { background: #e2f3e8; color: #17623b; }
    .badge.info { background: #e4eef9; color: #1d4f7a; }
    .onboarding ol { display: grid; gap: 12px; margin: 0; padding-left: 24px; }
    .onboarding li { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .tabs { display: flex; gap: 18px; margin: 0 0 18px; border-bottom: 1px solid var(--fixture-line); }
    .tabs a { padding: 10px 2px; color: var(--fixture-muted); text-decoration: none; }
    .tabs a.active { border-bottom: 2px solid #165c3d; color: #165c3d; font-weight: 700; }
    .status-line, .dialog-title, .result-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .result-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); align-items: start; }
    .result-grid > div { display: grid; gap: 6px; min-width: 0; }
    .result-grid strong { overflow-wrap: anywhere; }
    .diagnostic-dialog { margin-top: 18px; border: 1px solid #b8c6bd; border-radius: 10px; padding: 16px; background: #f6faf7; }
    .diagnostic-dialog dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 16px 0 0; }
    .diagnostic-dialog dl div { display: grid; gap: 5px; }
    .diagnostic-dialog dt { color: var(--fixture-muted); font-size: 12px; }
    .diagnostic-dialog dd { margin: 0; font-weight: 700; }
    @media (max-width: 820px) { .fixture-layout { grid-template-columns: 1fr; } .fixture-sidebar { padding: 12px; } .fixture-sidebar nav { display: flex; overflow-x: auto; } .fixture-sidebar a { flex: 0 0 auto; } .fixture-brand { margin: 0 0 8px; } .grid.three, .result-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 520px) { .fixture-main { padding: 18px 12px 36px; } .form-grid, .grid.three, .result-grid, .diagnostic-dialog dl { grid-template-columns: 1fr; } .card { padding: 16px; } }
  </style>
</head>
<body>
  <div class="fixture-layout">
    <aside class="fixture-sidebar"><span class="fixture-brand">LeiKwan Bridge</span><nav aria-label="Tenant navigation"><?= guideNav($view) ?></nav></aside>
    <main class="fixture-main" data-guide-view="<?= guideEscape($view) ?>"><div class="fixture-content"><?= $demoNotice ?><?= $body ?></div></main>
  </div>
</body>
</html>
