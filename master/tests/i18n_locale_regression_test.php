<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * @return array{locale?: string, text?: array<string, string>, attributes?: array<string, string>}
 */
function loadLocale(string $path): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        fail('unable to read locale file: ' . $path);
    }

    try {
        /** @var array{locale?: string, text?: array<string, string>, attributes?: array<string, string>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $data;
    } catch (JsonException $exception) {
        fail('invalid locale json for ' . $path . ': ' . $exception->getMessage());
    }
}

/**
 * @param array{locale?: string, text?: array<string, string>, attributes?: array<string, string>} $dictionary
 */
function assertTextKeys(array $dictionary, string $locale, array $keys): void
{
    foreach ($keys as $key) {
        $value = trim((string) ($dictionary['text'][$key] ?? ''));
        if ($value === '') {
            fail('missing text translation for [' . $locale . ']: ' . $key);
        }
    }
}

/**
 * @param array{locale?: string, text?: array<string, string>, attributes?: array<string, string>} $dictionary
 */
function assertAttributeKeys(array $dictionary, string $locale, array $keys): void
{
    foreach ($keys as $key) {
        $value = trim((string) ($dictionary['attributes'][$key] ?? ''));
        if ($value === '') {
            fail('missing attribute translation for [' . $locale . ']: ' . $key);
        }
    }
}

/**
 * @param array{locale?: string, text?: array<string, string>, attributes?: array<string, string>} $dictionary
 * @param array<string> $keys
 */
function assertNoAsciiQuestionMarks(array $dictionary, string $locale, array $keys): void
{
    foreach ($keys as $key) {
        $value = (string) ($dictionary['text'][$key] ?? '');
        if ($value !== '' && str_contains($value, '?')) {
            fail('corrupted translation for [' . $locale . ']: ' . $key . ' => ' . $value);
        }
    }
}

/**
 * @param array{locale?: string, text?: array<string, string>, attributes?: array<string, string>} $dictionary
 * @param array<string> $keys
 */
function assertLocalizedEntries(array $dictionary, string $locale, array $keys, string $dictionaryKey): void
{
    /** @var array<string, string> $entries */
    $entries = $dictionary[$dictionaryKey] ?? [];
    foreach ($keys as $key) {
        $value = trim((string) ($entries[$key] ?? ''));
        if ($value === '') {
            fail('missing ' . $dictionaryKey . ' translation for [' . $locale . ']: ' . $key);
        }
        if (str_contains($value, '?')) {
            fail('corrupted ' . $dictionaryKey . ' translation for [' . $locale . ']: ' . $key . ' => ' . $value);
        }
        if ($value === $key) {
            fail('untranslated ' . $dictionaryKey . ' entry for [' . $locale . ']: ' . $key);
        }
    }
}

/**
 * @param array<string> $left
 * @param array<string> $right
 */
function assertSameKeys(array $left, array $right, string $label): void
{
    sort($left);
    sort($right);

    if ($left !== $right) {
        fail('locale key mismatch for ' . $label);
    }
}

function normalizeLocaleKey(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function containsCjk(string $value): bool
{
    return preg_match('/[\x{4E00}-\x{9FFF}]/u', $value) === 1;
}

function shouldInspectCandidate(string $value): bool
{
    if ($value === '' || containsCjk($value)) {
        return false;
    }

    if (preg_match('/[A-Za-z]/', $value) !== 1) {
        return false;
    }

    if (strlen($value) > 160) {
        return false;
    }

    foreach (['$', '\' .', '{', '}', ';', 'function ', 'window.LEIKWAN', '.button', '@media', 'textarea', 'document.'] as $needle) {
        if (str_contains($value, $needle)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, string> $dictionary
 * @param array<string, array<string>> $allowlist
 */
function assertCoverageFromFiles(array $dictionary, array $files, array $allowlist, string $dictionaryKey): void
{
    $missing = [];

    foreach ($files as $path) {
        $content = file_get_contents($path);
        if ($content === false) {
            fail('unable to read scan file: ' . $path);
        }

        if ($dictionaryKey === 'text') {
            if (!preg_match_all('/<([A-Za-z0-9:-]+)[^>]*>([^<>]+)<\/\1>/u', $content, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $tag = strtolower((string) ($match[1] ?? ''));
                if (in_array($tag, ['code', 'pre', 'script', 'style', 'textarea'], true)) {
                    continue;
                }

                $candidate = normalizeLocaleKey((string) ($match[2] ?? ''));
                if (!shouldInspectCandidate($candidate)) {
                    continue;
                }

                $allowed = $allowlist[$path] ?? [];
                if (in_array($candidate, $allowed, true)) {
                    continue;
                }

                if (!array_key_exists($candidate, $dictionary)) {
                    $missing[$path][$candidate] = true;
                }
            }
            continue;
        }

        if (!preg_match_all('/(?:placeholder|title|aria-label)="([^"]+)"/u', $content, $matches)) {
            continue;
        }

        foreach ($matches[1] as $candidate) {
            $candidate = normalizeLocaleKey((string) $candidate);
            if (!shouldInspectCandidate($candidate)) {
                continue;
            }

            $allowed = $allowlist[$path] ?? [];
            if (in_array($candidate, $allowed, true)) {
                continue;
            }

            if (!array_key_exists($candidate, $dictionary)) {
                $missing[$path][$candidate] = true;
            }
        }
    }

    if ($missing === []) {
        return;
    }

    $lines = [];
    foreach ($missing as $path => $values) {
        foreach (array_keys($values) as $value) {
            $lines[] = $path . ': ' . $value;
        }
    }
    sort($lines);

    fail('uncovered ' . $dictionaryKey . ' locale strings:' . PHP_EOL . implode(PHP_EOL, $lines));
}

$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$install = file_get_contents(__DIR__ . '/../public/install.php');
$zhTwPath = __DIR__ . '/../public/i18n/zh-TW.json';
$zhHansPath = __DIR__ . '/../public/i18n/zh-Hans.json';

foreach ([
    "supported: ['en', 'zh-TW', 'zh-Hans']",
    '<option value="zh-Hans">Simplified Chinese</option>',
    '<option value="zh-TW">Traditional Chinese</option>',
] as $snippet) {
    if (!str_contains((string) $base . (string) $install, $snippet)) {
        fail('missing locale snippet: ' . $snippet);
    }
}

if (!file_exists($zhTwPath)) {
    fail('missing zh-TW locale file');
}

if (!file_exists($zhHansPath)) {
    fail('missing zh-Hans locale file');
}

$zhTw = loadLocale($zhTwPath);
$zhHans = loadLocale($zhHansPath);

if (($zhTw['locale'] ?? null) !== 'zh-TW') {
    fail('unexpected zh-TW locale id');
}

if (($zhHans['locale'] ?? null) !== 'zh-Hans') {
    fail('unexpected zh-Hans locale id');
}

$requiredTextKeys = [
    'Language',
    'Traditional Chinese',
    'Simplified Chinese',
    'Sign In',
    'Admin Console',
    'Create Account',
    'Open Docs',
    'Lightweight IXP control plane.',
    'Short homepage by design',
    'Use this page as the front door. Real work continues inside the tenant or admin console.',
    'Plans, tenants, nodes',
    'Operator Sign In',
    'Tenant Panel',
    'Account, plans, forwards, client, sidecar.',
    'Forgot Password',
    'Welcome back',
    'Use your panel account to continue.',
    'Before You Sign In',
    'Keep it simple',
    'Use your panel email and password.',
    'Buy station binding happens after email verification.',
    'If needed, reset the password from the link below.',
    'Back Home',
    'Start with one account',
    'Create the panel account first. Verification happens by email.',
    'Display Name',
    'Confirm Password',
    'After registration',
    'Open the verification email.',
    'Sign in with the same panel account.',
    'Bind billing later from Account if needed.',
    'Create your workspace account',
    'Create a new panel account',
    'What Happens Next',
    'Submit the form and wait for the verification email.',
    'Open the email and click the verification link.',
    'After verification, the system will try to bind the same email in Buy station automatically.',
    'Open registration is enabled, but email verification is still required.',
    'This site currently requires an invite code.',
    'Account Login',
    'Account Signup',
    'Registration',
    'Clear All Failed Tasks',
    'Allowed Forward Combinations',
];

$requiredAttributeKeys = [
    'you@example.com',
    'Your password',
    'Your name',
    'At least 8 characters',
    'Repeat the password',
    'How should we address you?',
    'Use at least 8 characters',
    'Enter the same password again',
    'Enter your panel password',
    'LKH-XXXXXX',
    '6-digit code',
];

$frontendHardeningTextKeys = [
    'Knowledgebase / Docs',
    'Client Setup',
    'Client Profiles',
    'Transport Endpoints',
    'Transport Endpoint List',
    'Allow Paid Lines',
    'Selected Carriers',
    'Runtime Platform Scope',
    'Target IP',
    'This forward uses the purchased server IP automatically. Update the service sync if the IP is stale.',
    'No target transport endpoint yet. Create one, install it on your C-side machine, then select it when you create a bond or MPTCP forward.',
    'No visible package is available right now. Contact the administrator if you expected to self-purchase.',
    'Optional comma-separated carrier codes for Linux profile runtime selection.',
    'Order Now',
    'Packages',
    'Visible Packages',
    'Current Services',
    'Current Cycle Estimate',
    'Save Transport Endpoint',
    'Transport Endpoint',
    'Transport Listen Port',
    'Forward Route',
    'Forward Routes',
    'Client Device Limit',
    'Max Client Profiles',
    'Tenant Visibility',
    'Visible to tenants',
    'Transport Health',
    'Admin-pinned rented slaves',
    'Allow runtime selection of paid lines for this profile.',
    'Manage Transport Endpoints',
    'Advanced target transport setup and backend reachability details.',
    'Open the panel that controls services, forwards, client profiles, transport endpoints, and route policy.',
    'Blend exchange fabric and private-line paths by tenant plan, node group, region, and target transport policy.',
    'One busy session should not crawl because one route is full. LeiKwan Bridge bonds IXP, IPLC, slave nodes, client profiles, and transport endpoints into one policy-visible delivery plane.',
    'Install flow, route modes, diagnostics, transport endpoint rollout, and recovery notes live in the panel docs.',
];

$frontendHardeningAttributeKeys = [
    'One feature per line for the tenant storefront card.',
    'ct,cu',
];

assertTextKeys($zhTw, 'zh-TW', $requiredTextKeys);
assertTextKeys($zhHans, 'zh-Hans', $requiredTextKeys);
assertAttributeKeys($zhTw, 'zh-TW', $requiredAttributeKeys);
assertAttributeKeys($zhHans, 'zh-Hans', $requiredAttributeKeys);
assertNoAsciiQuestionMarks($zhTw, 'zh-TW', $requiredTextKeys);
assertNoAsciiQuestionMarks($zhHans, 'zh-Hans', $requiredTextKeys);
assertLocalizedEntries($zhTw, 'zh-TW', $frontendHardeningTextKeys, 'text');
assertLocalizedEntries($zhHans, 'zh-Hans', $frontendHardeningTextKeys, 'text');
assertLocalizedEntries($zhTw, 'zh-TW', $frontendHardeningAttributeKeys, 'attributes');
assertLocalizedEntries($zhHans, 'zh-Hans', $frontendHardeningAttributeKeys, 'attributes');

assertSameKeys(array_keys($zhTw['text'] ?? []), array_keys($zhHans['text'] ?? []), 'text');
assertSameKeys(array_keys($zhTw['attributes'] ?? []), array_keys($zhHans['attributes'] ?? []), 'attributes');

$coverageFiles = [
    realpath(__DIR__ . '/../src/Controllers/BaseController.php'),
    realpath(__DIR__ . '/../src/Controllers/AuthController.php'),
    realpath(__DIR__ . '/../src/Controllers/AdminController.php'),
    realpath(__DIR__ . '/../src/Controllers/TenantController.php'),
    realpath(__DIR__ . '/../src/Controllers/HealthController.php'),
    realpath(__DIR__ . '/../src/Controllers/NodeController.php'),
    realpath(__DIR__ . '/../public/install.php'),
];

/** @var array<string, array<string>> $textAllowlist */
$textAllowlist = [
    (string) realpath(__DIR__ . '/../src/Controllers/BaseController.php') => [
        "format('F Y'))) . '",
    ],
    (string) realpath(__DIR__ . '/../src/Controllers/AuthController.php') => [
        "knowledgebaseStyles() .",
        "getMessage()) . '",
    ],
    (string) realpath(__DIR__ . '/../public/install.php') => [
        "details(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '",
        "getMessage()) . '",
    ],
];

/** @var array<string, array<string>> $attributeAllowlist */
$attributeAllowlist = [
    (string) realpath(__DIR__ . '/../src/Controllers/BaseController.php') => [
        'bond',
        'en',
        'forward_chain',
        'mptcp',
        'race',
        'single',
        'slave_group',
        'zh-Hans',
        'zh-TW',
    ],
    (string) realpath(__DIR__ . '/../public/install.php') => [
        'en',
        'false',
        'mysql',
        'true',
        'zh-Hans',
        'zh-TW',
    ],
];

assertCoverageFromFiles($zhTw['text'] ?? [], $coverageFiles, $textAllowlist, 'text');
assertCoverageFromFiles($zhTw['attributes'] ?? [], $coverageFiles, $attributeAllowlist, 'attributes');
assertCoverageFromFiles($zhHans['text'] ?? [], $coverageFiles, $textAllowlist, 'text');
assertCoverageFromFiles($zhHans['attributes'] ?? [], $coverageFiles, $attributeAllowlist, 'attributes');

echo "ok\n";
