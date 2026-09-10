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

function assertPatchRuntimeBehavior(string $patchPath): void
{
    $harness = <<<'JS'
'use strict';

const fs = require('fs');
const vm = require('vm');
const patch = fs.readFileSync(process.argv[2], 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function same(actual, expected, message) {
  const actualJson = JSON.stringify(actual);
  const expectedJson = JSON.stringify(expected);
  assert(actualJson === expectedJson, `${message}: expected ${expectedJson}, got ${actualJson}`);
}

function withoutLang(url) {
  return Array.from(url.searchParams.entries()).filter(([name]) => name !== 'lang');
}

async function execute(options = {}) {
  const href = options.href || 'https://example.test/account';
  const parsed = new URL(href);
  const storage = new Map(Object.entries(options.storage || {}));
  const storageWrites = [];
  const cookieWrites = [];
  const replaceCalls = [];
  const assignCalls = [];
  let cookieHeader = options.cookie || '';

  const switcher = {
    value: '',
    change: null,
    addEventListener(type, callback) {
      if (type === 'change') this.change = callback;
    }
  };

  const document = {
    readyState: 'complete',
    documentElement: { lang: '' },
    body: {},
    getElementById(id) {
      return id === 'locale-switcher' ? switcher : null;
    },
    createTreeWalker() {
      throw new Error('tree walker should not run when dictionary fetch is unavailable');
    }
  };

  Object.defineProperty(document, 'cookie', {
    get() {
      return cookieHeader;
    },
    set(value) {
      cookieWrites.push(value);
      const pair = value.split(';', 1)[0];
      const separator = pair.indexOf('=');
      const name = separator === -1 ? pair : pair.slice(0, separator);
      const existing = cookieHeader
        .split(';')
        .map((part) => part.trim())
        .filter((part) => part !== '' && part.split('=', 1)[0].trim() !== name);
      cookieHeader = [pair, ...existing].join('; ');
    }
  });

  const location = {
    href,
    pathname: parsed.pathname,
    search: parsed.search,
    replace(url) {
      replaceCalls.push({ url: String(url), stack: new Error().stack || '' });
    },
    assign(url) {
      assignCalls.push({ url: String(url), stack: new Error().stack || '' });
    }
  };

  const window = {
    LEIKWAN_I18N: Object.assign({
      defaultLocale: 'en',
      supported: ['en', 'zh-TW', 'zh-Hans'],
      basePath: '/i18n'
    }, options.config || {}),
    location
  };

  const context = {
    window,
    document,
    localStorage: {
      getItem(key) {
        return storage.has(key) ? storage.get(key) : null;
      },
      setItem(key, value) {
        storageWrites.push([key, String(value)]);
        storage.set(key, String(value));
      }
    },
    URL,
    URLSearchParams,
    NodeFilter: { SHOW_TEXT: 4, FILTER_REJECT: 2, FILTER_ACCEPT: 1 },
    MutationObserver: class {
      observe() {}
    },
    fetch: async () => ({ ok: false }),
    console
  };

  vm.runInNewContext(patch, context, { filename: process.argv[2] });
  await new Promise((resolve) => setImmediate(resolve));

  return {
    document,
    switcher,
    storage,
    storageWrites,
    cookieWrites,
    replaceCalls,
    assignCalls
  };
}

function assertCookieWrite(write, name, encodedLocale) {
  assert(write.startsWith(`${name}=${encodedLocale};`), `cookie must use ${name} and encode its locale`);
  assert(write.includes('; Path=/'), 'cookie must use Path=/');
  assert(write.includes('; Max-Age=31536000'), 'cookie must use one-year Max-Age');
  assert(write.includes('; SameSite=Lax'), 'cookie must use SameSite=Lax');
}

(async () => {
  const fallback = await execute({
    href: 'https://example.test/account',
    config: { defaultLocale: 'zh-Hans' }
  });
  same(fallback.document.documentElement.lang, 'en', 'missing query, cookie, and storage must fall back to English');

  const query = await execute({
    href: 'https://example.test/account?lang=zh-Hans',
    cookie: 'leikwan_locale=zh-TW',
    storage: { leikwan_locale: 'en' }
  });
  same(query.document.documentElement.lang, 'zh-Hans', 'query locale must win');
  same(query.storage.get('leikwan_locale'), 'zh-Hans', 'query locale must persist to localStorage');
  same(query.cookieWrites.length, 1, 'query locale must persist one cookie');
  assertCookieWrite(query.cookieWrites[0], 'leikwan_locale', 'zh-Hans');
  same(query.replaceCalls.length, 0, 'query locale must not replace location');
  same(query.assignCalls.length, 0, 'query locale must not assign location');

  const duplicateQuery = await execute({
    href: 'https://example.test/account?lang=en&lang=zh-Hans'
  });
  same(duplicateQuery.document.documentElement.lang, 'zh-Hans', 'last duplicate query locale must win');
  same(duplicateQuery.storage.get('leikwan_locale'), 'zh-Hans', 'last duplicate query locale must persist to localStorage');
  same(duplicateQuery.cookieWrites.length, 1, 'last duplicate query locale must persist one cookie');
  assertCookieWrite(duplicateQuery.cookieWrites[0], 'leikwan_locale', 'zh-Hans');

  const invalidQuery = await execute({
    href: 'https://example.test/account?lang=unsupported',
    cookie: 'leikwan_locale=zh-TW',
    storage: { leikwan_locale: 'zh-Hans' }
  });
  same(invalidQuery.document.documentElement.lang, 'en', 'invalid query must resolve to English');
  same(invalidQuery.storage.get('leikwan_locale'), 'en', 'resolved query fallback must persist to localStorage');
  assertCookieWrite(invalidQuery.cookieWrites[0], 'leikwan_locale', 'en');

  const cookie = await execute({
    href: 'https://example.test/account',
    cookie: 'other=a=b=c; custom_locale=zh%2DTW',
    storage: { leikwan_locale: 'zh-Hans' },
    config: { cookieName: 'custom_locale' }
  });
  same(cookie.document.documentElement.lang, 'zh-TW', 'decoded configured cookie must win over localStorage');
  same(cookie.storage.get('leikwan_locale'), 'zh-TW', 'cookie locale must persist to localStorage');
  same(cookie.cookieWrites.length, 0, 'reading a valid cookie must not rewrite it');

  const firstEquals = await execute({
    href: 'https://example.test/account',
    cookie: 'leikwan_locale=zh%2DTW=trailing'
  });
  same(firstEquals.document.documentElement.lang, 'en', 'cookie parsing must retain text after the first equals');

  const malformedCookie = await execute({
    href: 'https://example.test/account',
    cookie: 'leikwan_locale=%E0%A4%A',
    storage: { leikwan_locale: 'zh-Hans' }
  });
  same(malformedCookie.document.documentElement.lang, 'zh-Hans', 'malformed cookie decoding must not abort storage fallback');
  assertCookieWrite(malformedCookie.cookieWrites[0], 'leikwan_locale', 'zh-Hans');

  const invalidCookieMigration = await execute({
    href: 'https://example.test/knowledgebase?thread=setup',
    cookie: 'leikwan_locale=unsupported',
    storage: { leikwan_locale: 'zh-Hans' }
  });
  same(invalidCookieMigration.replaceCalls.length, 1, 'unsupported cookie must not block legacy localStorage migration');
  same(new URL(invalidCookieMigration.replaceCalls[0].url).searchParams.get('lang'), 'zh-Hans', 'legacy migration must use supported storage locale');

  for (const pathname of ['/knowledgebase/', '/knowledgebase/setup']) {
    const guarded = await execute({
      href: `https://example.test${pathname}?thread=setup`,
      storage: { leikwan_locale: 'zh-Hans' }
    });
    same(guarded.replaceCalls.length, 0, `legacy migration must require exact /knowledgebase pathname (${pathname})`);
    same(guarded.document.documentElement.lang, 'zh-Hans', `non-exact knowledgebase path must still patch (${pathname})`);
  }

  const legacyHref = 'https://example.test/knowledgebase?thread=setup&foo=1&foo=2#section';
  const legacy = await execute({
    href: legacyHref,
    storage: { leikwan_locale: 'zh-TW' }
  });
  same(legacy.replaceCalls.length, 1, 'legacy knowledgebase locale must perform exactly one replace');
  same(legacy.assignCalls.length, 0, 'legacy migration must not assign location');
  assertCookieWrite(legacy.cookieWrites[0], 'leikwan_locale', 'zh-TW');
  const legacyUrl = new URL(legacy.replaceCalls[0].url);
  same(legacyUrl.searchParams.getAll('lang'), ['zh-TW'], 'legacy migration must set one lang parameter');
  same(withoutLang(legacyUrl), withoutLang(new URL(legacyHref)), 'legacy migration must preserve every non-lang parameter');
  same(legacyUrl.hash, '#section', 'legacy migration must preserve the fragment');
  assert(!legacy.replaceCalls[0].stack.includes('applyPatch'), 'legacy migration navigation must happen outside applyPatch');
  same(legacy.document.documentElement.lang, '', 'patching must not continue after legacy migration navigation');

  const loopGuard = await execute({
    href: 'https://example.test/knowledgebase?thread=setup&lang=zh-TW',
    storage: { leikwan_locale: 'zh-TW' }
  });
  same(loopGuard.replaceCalls.length, 0, 'synchronized knowledgebase URL must not redirect again');

  const selectionHref = 'https://example.test/knowledgebase?thread=setup&lang=en&foo=1&lang=zh-Hans#install';
  const selection = await execute({ href: selectionHref });
  assert(typeof selection.switcher.change === 'function', 'locale switcher change handler must be installed');
  selection.switcher.change.call({ value: 'zh-TW' });
  same(selection.storage.get('leikwan_locale'), 'zh-TW', 'explicit selection must persist to localStorage');
  same(selection.cookieWrites.length, 2, 'bootstrap query and explicit selection must each persist a cookie');
  assertCookieWrite(selection.cookieWrites[1], 'leikwan_locale', 'zh-TW');
  same(selection.assignCalls.length, 1, 'explicit selection must perform exactly one assign');
  same(selection.replaceCalls.length, 0, 'explicit selection must not replace location');
  const selectionUrl = new URL(selection.assignCalls[0].url);
  same(selectionUrl.searchParams.getAll('lang'), ['zh-TW'], 'explicit selection must replace all lang values');
  same(withoutLang(selectionUrl), withoutLang(new URL(selectionHref)), 'explicit selection must preserve every non-lang parameter');
  same(selectionUrl.hash, '#install', 'explicit selection must preserve the fragment');
  assert(!selection.assignCalls[0].stack.includes('applyPatch'), 'explicit selection navigation must not run during applyPatch');
})().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
JS;

    $harnessPath = tempnam(sys_get_temp_dir(), 'leikwan-i18n-');
    if ($harnessPath === false || file_put_contents($harnessPath, $harness) === false) {
        fail('unable to create patch runtime harness');
    }

    try {
        $output = [];
        $exitCode = 0;
        exec('node ' . escapeshellarg($harnessPath) . ' ' . escapeshellarg($patchPath) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            fail('patch runtime regression failed:' . PHP_EOL . implode(PHP_EOL, $output));
        }
    } finally {
        @unlink($harnessPath);
    }
}

$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$install = file_get_contents(__DIR__ . '/../public/install.php');
$patchPath = __DIR__ . '/../public/i18n/patch.js';
$patch = file_get_contents($patchPath);
$zhTwPath = __DIR__ . '/../public/i18n/zh-TW.json';
$zhHansPath = __DIR__ . '/../public/i18n/zh-Hans.json';

foreach ([
    "supported: ['en', 'zh-TW', 'zh-Hans']",
    "cookieName: 'leikwan_locale'",
    '<option value="zh-Hans">Simplified Chinese</option>',
    '<option value="zh-TW">Traditional Chinese</option>',
] as $snippet) {
    if (!str_contains((string) $base . (string) $install, $snippet)) {
        fail('missing locale snippet: ' . $snippet);
    }
}

foreach ([
    'config.cookieName || "leikwan_locale"',
    'params.has("lang")',
    'SameSite=Lax',
    'Max-Age=31536000',
    'writeLocaleCookie(resolved)',
    'writeLocaleCookie(fromStorage)',
    'window.location.replace(currentUrl.toString())',
    'nextUrl.searchParams.set("lang", locale)',
    'window.location.assign(nextUrl.toString())',
] as $snippet) {
    if (!str_contains((string) $patch, $snippet)) {
        fail('missing patch locale snippet: ' . $snippet);
    }
}

$cookiePosition = strpos((string) $patch, 'const fromCookie = readLocaleCookie();');
$storagePosition = strpos((string) $patch, 'const fromStorage = localStorage.getItem(storageKey);');
if ($cookiePosition === false || $storagePosition === false || $cookiePosition >= $storagePosition) {
    fail('cookie locale must be checked before localStorage');
}

$applyPatchStart = strpos((string) $patch, 'async function applyPatch()');
$bootstrapStart = strpos((string) $patch, 'function bootstrap()');
if ($applyPatchStart === false || $bootstrapStart === false || $applyPatchStart >= $bootstrapStart) {
    fail('unable to locate applyPatch bootstrap boundary');
}
$applyPatchBody = substr((string) $patch, $applyPatchStart, $bootstrapStart - $applyPatchStart);
foreach (['window.location.', 'setLocale(', 'migrateLegacyKnowledgebaseLocale('] as $navigationCall) {
    if (str_contains($applyPatchBody, $navigationCall)) {
        fail('applyPatch must not navigate or call navigation helpers: ' . $navigationCall);
    }
}

assertPatchRuntimeBehavior($patchPath);

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
    'User Guides',
    'Start here',
    'Learn as needed',
    'Pinned',
    'Updated',
    'This topic is not available in your selected language. Showing English.',
    'Diagram could not be rendered. The source is shown below.',
    'No published guides yet.',
    'Knowledgebase Topics',
    'Create Topic',
    'Edit Topic',
    'Edit Language Version',
    'Topic Key',
    'Language Versions',
    'Missing',
    'Draft',
    'Published',
    'Pinned',
    'Order',
    'Summary',
    'Title',
    'Preview',
    'Save Topic',
    'Save Language Version',
    'Delete Topic',
    'Delete Language Version',
    'Delete this topic and all three language versions?',
    'Delete this language version?',
    'Preview — not published',
    'No active package yet. Choose a package first so you have a service to configure.',
    'Choose a package',
    'Open Packages and activate the plan that fits this device or location.',
    'Read the five-minute overview',
    'Your package is active. Create your first forward to choose where traffic should go.',
    'Create your first forward',
    'Use the guided form and start with the recommended defaults.',
    'Open the first-forward checklist',
    'Your forward is ready. Install the client on this device to start using it.',
    'Install the client',
    'Open Clients to download the app or copy the Linux installation command.',
    'Open client downloads',
    'Read the installation guide',
    'Getting Started - Setup Complete',
    'Your package, forward, and client are ready.',
    'Connect and verify',
    'Open Forwards to manage traffic, then use the verification guide to confirm the complete path.',
    'Open forwards',
    'Read the connection check',
    'Choose a connection mode',
    'Set up Bond and Sidecar',
    'Troubleshoot a connection',
    'Read the glossary',
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
$knowledgebaseAdminTextKeys = [
    'Knowledgebase Topics',
    'Create Topic',
    'Edit Topic',
    'Edit Language Version',
    'Topic Key',
    'Language Versions',
    'Missing',
    'Draft',
    'Published',
    'Pinned',
    'Order',
    'Summary',
    'Title',
    'Preview',
    'Save Topic',
    'Save Language Version',
    'Delete Topic',
    'Delete Language Version',
    'Delete this topic and all three language versions?',
    'Delete this language version?',
    'Preview — not published',
];

assertLocalizedEntries($zhTw, 'zh-TW', $frontendHardeningTextKeys, 'text');
assertLocalizedEntries($zhHans, 'zh-Hans', $frontendHardeningTextKeys, 'text');
assertLocalizedEntries($zhTw, 'zh-TW', $knowledgebaseAdminTextKeys, 'text');
assertLocalizedEntries($zhHans, 'zh-Hans', $knowledgebaseAdminTextKeys, 'text');
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
