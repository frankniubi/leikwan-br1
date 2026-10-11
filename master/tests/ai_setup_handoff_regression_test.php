<?php
declare(strict_types=1);

function failAiHandoff(string $message): never
{
    fwrite(STDERR, 'AI setup handoff: ' . $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../../docs/user/AI-SETUP-HANDOFF.zh-CN.md';
$handoff = is_file($path) ? file_get_contents($path) : false;
if (!is_string($handoff) || trim($handoff) === '') {
    failAiHandoff('handoff document is missing');
}

foreach ([
    '/tenant/getting-started',
    '/tenant/clients',
    '/tenant/forwards',
    '下载安装包',
    '复制命令',
    '最大在线',
    '127.0.0.1',
    'Target Host',
    'Target Port',
    '不要保存',
    '不要回显',
    '停止',
    '密码',
    'Bearer token',
] as $required) {
    if (!str_contains($handoff, $required)) {
        failAiHandoff('missing required handoff rule: ' . $required);
    }
}

foreach (['/tenant/clients?view=install', 'Android App', 'Download Android App', 'Run Diagnostics', 'View Diagnostics'] as $forbidden) {
    if (str_contains($handoff, $forbidden)) {
        failAiHandoff('handoff contains retired Client guidance: ' . $forbidden);
    }
}

foreach (['198.18.', '10.', '172.16.', '172.17.', '192.168.'] as $privatePrefix) {
    if (str_contains($handoff, $privatePrefix)) {
        failAiHandoff('handoff contains a private network example: ' . $privatePrefix);
    }
}

preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $handoff, $ipMatches);
foreach ($ipMatches[0] ?? [] as $ip) {
    if ($ip !== '127.0.0.1'
        && !str_starts_with($ip, '192.0.2.')
        && !str_starts_with($ip, '198.51.100.')
        && !str_starts_with($ip, '203.0.113.')) {
        failAiHandoff('handoff contains an unapproved IPv4 literal: ' . $ip);
    }
}

if (preg_match('/Bearer\s+[A-Za-z0-9._~-]{12,}/i', $handoff) === 1) {
    failAiHandoff('handoff contains a literal bearer value');
}
if (preg_match('/(?:password|token|secret|private\s+key)\s*[:=]\s*[^<\s][^\r\n]*/i', strip_tags($handoff)) === 1) {
    failAiHandoff('handoff appears to assign a secret value');
}
if (preg_match('/(?:run|execute|paste)\s+[^\r\n]*(?:token|secret|password)\s*[:=]/i', $handoff) === 1) {
    failAiHandoff('handoff contains an executable secret assignment');
}

echo "ai_setup_handoff_regression_test: OK\n";
