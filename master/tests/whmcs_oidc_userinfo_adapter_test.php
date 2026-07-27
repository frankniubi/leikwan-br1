<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertContains(string $needle, string $source, string $message): void
{
    if (!str_contains($source, $needle)) {
        fail($message . ': missing ' . $needle);
    }
}

function assertOrdered(string $first, string $second, string $source, string $message): void
{
    $firstOffset = strpos($source, $first);
    $secondOffset = strpos($source, $second);
    if ($firstOffset === false || $secondOffset === false || $firstOffset >= $secondOffset) {
        fail($message);
    }
}

$path = __DIR__ . '/../integrations/whmcs/oidc-userinfo-v3.php';
if (!is_file($path)) {
    fail('WHMCS OIDC userinfo adapter is missing');
}
$source = (string) file_get_contents($path);

foreach ([
    "header('Cache-Control: no-store')",
    "\$_SERVER['HTTP_AUTHORIZATION']",
    "CURLOPT_FOLLOWLOCATION => false",
    "CURLOPT_SSL_VERIFYPEER => true",
    "CURLOPT_SSL_VERIFYHOST => 2",
    "WHMCS\\Config\\Setting::getValue('SystemURL')",
    "Capsule::table('tblclients')->where('uuid', \$subject)",
    "Capsule::table('tblusers_clients')",
    "->where('owner', 1)",
    "->isEmailAddressVerified()",
    "->emailVerified()",
    "\$userinfo['email_verified'] = true",
] as $needle) {
    assertContains($needle, $source, 'adapter security contract');
}

assertOrdered("->isEmailAddressVerified()", "\$userinfo['email_verified'] = true", $source, 'client verification must precede claim elevation');
assertOrdered("->emailVerified()", "\$userinfo['email_verified'] = true", $source, 'owner verification must precede claim elevation');
if (preg_match('/(?:secret|password|access_token)\s*=\s*[\'\"][^\'\"]+/i', $source) === 1) {
    fail('adapter must not contain credential literals');
}

echo "ok\n";
