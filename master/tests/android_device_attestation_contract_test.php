<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === false) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\AndroidDeviceAttestationVerifier;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        fail($message);
    }
}

function bindingMessage(string $nonce, int $clientId, string $deviceId, string $identitySpki): string
{
    return implode('|', [$nonce, (string) $clientId, $deviceId, hash('sha256', $identitySpki)]);
}

function verifierFor(array $record, string $expectedSigningDigest, string $rootDigest): AndroidDeviceAttestationVerifier
{
    return new AndroidDeviceAttestationVerifier(
        'com.leikwan.bridge',
        [$expectedSigningDigest],
        [$rootDigest],
        static fn(array $proof, string $leafDer): array => $record,
        static fn(array $certificateChain, array $trustedRoots): array => [
            'leaf_der' => 'fixture-leaf-certificate',
            'attestation_public_key' => 'fixture-attestation-public-key',
        ],
        static fn(string $message, string $signature, mixed $publicKey): bool =>
            $publicKey === 'fixture-attestation-public-key'
            && hash_equals(hash('sha256', $message, true), $signature),
    );
}

$clientId = 17;
$nonce = 'android-attestation-nonce-fixture';
$identitySpki = 'fixture-stable-identity-spki';
$deviceId = 'android-client-' . $clientId . '-' . substr(hash('sha256', $identitySpki), 0, 32);
$rootDigest = hash('sha256', 'fixture-attestation-root');
$expectedSigningDigest = hash('sha256', 'fixture-signing-certificate');
$bindingSignature = hash('sha256', bindingMessage($nonce, $clientId, $deviceId, $identitySpki), true);

$validRecord = [
    'attestation_security_level' => 'trusted_environment',
    'keymaster_security_level' => 'trusted_environment',
    'challenge' => $nonce,
    'packages' => ['com.leikwan.bridge'],
    'signature_digests' => [$expectedSigningDigest],
    'verified_boot_state' => 'verified',
    'device_locked' => true,
];
$validProof = [
    'certificate_chain' => ['fixture-leaf-certificate'],
    'identity_public_key' => base64_encode($identitySpki),
    'binding_signature' => base64_encode($bindingSignature),
];

$verifier = verifierFor($validRecord, $expectedSigningDigest, $rootDigest);
$valid = $verifier->verify($validProof, $nonce, $clientId, $deviceId);
assertTrue($valid->trusted, 'a valid hardware-backed proof should be trusted');
assertSame('trusted', $valid->reason, 'valid proof reason');
assertSame(hash('sha256', $identitySpki), $valid->identityKeyHash, 'identity key hash should be persisted, not the key');

$unlocked = $validRecord;
$unlocked['device_locked'] = false;
assertSame(
    'boot_not_locked',
    verifierFor($unlocked, $expectedSigningDigest, $rootDigest)->verify($validProof, $nonce, $clientId, $deviceId)->reason,
    'unlocked boot must fail closed'
);

$software = $validRecord;
$software['attestation_security_level'] = 'software';
assertSame(
    'hardware_security_required',
    verifierFor($software, $expectedSigningDigest, $rootDigest)->verify($validProof, $nonce, $clientId, $deviceId)->reason,
    'software-only attestation must fail closed'
);

$wrongPackage = $validRecord;
$wrongPackage['packages'] = ['com.example.other'];
assertSame(
    'package_name_mismatch',
    verifierFor($wrongPackage, $expectedSigningDigest, $rootDigest)->verify($validProof, $nonce, $clientId, $deviceId)->reason,
    'package binding must match the released Android package'
);

$wrongSigningCertificate = $validRecord;
$wrongSigningCertificate['signature_digests'] = [str_repeat('f', 64)];
assertSame(
    'signing_certificate_mismatch',
    verifierFor($wrongSigningCertificate, $expectedSigningDigest, $rootDigest)->verify($validProof, $nonce, $clientId, $deviceId)->reason,
    'package certificate digest must match the release signing certificate'
);

assertSame(
    'challenge_mismatch',
    $verifier->verify($validProof, 'different-nonce', $clientId, $deviceId)->reason,
    'attestation extension challenge must match the single-use server nonce'
);

$badSignature = $validProof;
$badSignature['binding_signature'] = base64_encode(random_bytes(64));
assertSame(
    'identity_binding_invalid',
    $verifier->verify($badSignature, $nonce, $clientId, $deviceId)->reason,
    'attestation key must sign the stable identity binding'
);

assertSame(
    'device_id_mismatch',
    $verifier->verify($validProof, $nonce, $clientId, $deviceId . '-tampered')->reason,
    'device ID must be derived from the submitted stable public key'
);

$nativeVerifier = new AndroidDeviceAttestationVerifier(
    'com.leikwan.bridge',
    [$expectedSigningDigest],
    [$rootDigest],
);
assertTrue(
    !$nativeVerifier->verify($validProof, $nonce, $clientId, $deviceId)->trusted,
    'missing native cryptographic support must never trust a proof'
);

echo "ok\n";
