<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Verifies Android Keystore key-attestation records without trusting fields
 * asserted by the Android process. Test callbacks are deliberately constructor
 * only; production uses the certificate chain and DER parser below.
 */
final class AndroidDeviceAttestationVerifier implements DeviceAttestationVerifier
{
    private const ATTESTATION_EXTENSION_OID = '1.3.6.1.4.1.11129.2.1.17';
    private const MAX_CERTIFICATE_BYTES = 65536;
    private const MAX_CHAIN_LENGTH = 5;

    /** @var array<int, string> */
    private array $expectedSigningCertificateDigests;
    /** @var array<int, string> */
    private array $trustedRootCertificateDigests;
    /** @var callable|null */
    private mixed $attestationRecordDecoder;
    /** @var callable|null */
    private mixed $certificateChainVerifier;
    /** @var callable|null */
    private mixed $bindingSignatureVerifier;

    /**
     * @param array<int, string> $expectedSigningCertificateDigests
     * @param array<int, string> $trustedRootCertificateDigests
     * @param callable|null $attestationRecordDecoder fn(array<string,mixed>, string): array<string,mixed>|null
     * @param callable|null $certificateChainVerifier fn(array<int,string>, array<int,string>): array{leaf_der:string,attestation_public_key:mixed}|null
     * @param callable|null $bindingSignatureVerifier fn(string, string, mixed): bool
     */
    public function __construct(
        private string $expectedPackageName,
        array $expectedSigningCertificateDigests,
        array $trustedRootCertificateDigests,
        ?callable $attestationRecordDecoder = null,
        ?callable $certificateChainVerifier = null,
        ?callable $bindingSignatureVerifier = null,
    ) {
        $this->expectedPackageName = trim($this->expectedPackageName);
        $this->expectedSigningCertificateDigests = $this->normalizeDigests($expectedSigningCertificateDigests);
        $this->trustedRootCertificateDigests = $this->normalizeDigests($trustedRootCertificateDigests);
        $this->attestationRecordDecoder = $attestationRecordDecoder;
        $this->certificateChainVerifier = $certificateChainVerifier;
        $this->bindingSignatureVerifier = $bindingSignatureVerifier;
    }

    public function verify(array $proof, string $nonce, int $clientId, string $deviceId): AttestationVerificationResult
    {
        if ($clientId <= 0 || $nonce === '' || $this->expectedPackageName === ''
            || $this->expectedSigningCertificateDigests === [] || $this->trustedRootCertificateDigests === []) {
            return AttestationVerificationResult::rejected('attestation_configuration_missing');
        }

        $identitySpki = $this->decodeBase64Field($proof['identity_public_key'] ?? null, 16, self::MAX_CERTIFICATE_BYTES);
        if ($identitySpki === null) {
            return AttestationVerificationResult::rejected('identity_public_key_invalid');
        }

        $expectedDeviceId = 'android-client-' . $clientId . '-' . substr(hash('sha256', $identitySpki), 0, 32);
        if (!hash_equals($expectedDeviceId, trim($deviceId))) {
            return AttestationVerificationResult::rejected('device_id_mismatch');
        }

        $certificateChain = $proof['certificate_chain'] ?? null;
        if (!is_array($certificateChain) || $certificateChain === [] || count($certificateChain) > self::MAX_CHAIN_LENGTH) {
            return AttestationVerificationResult::rejected('certificate_chain_invalid');
        }
        $certificateChain = array_values(array_filter($certificateChain, static fn(mixed $item): bool => is_string($item) && $item !== ''));
        if ($certificateChain === []) {
            return AttestationVerificationResult::rejected('certificate_chain_invalid');
        }

        $chain = $this->verifyCertificateChain($certificateChain);
        if (!is_array($chain)) {
            return AttestationVerificationResult::rejected('certificate_chain_invalid');
        }
        if (isset($chain['error'])) {
            return AttestationVerificationResult::rejected((string) $chain['error']);
        }
        $leafDer = isset($chain['leaf_der']) && is_string($chain['leaf_der']) ? $chain['leaf_der'] : null;
        $attestationPublicKey = $chain['attestation_public_key'] ?? null;
        if ($leafDer === null || $attestationPublicKey === null) {
            return AttestationVerificationResult::rejected('certificate_chain_invalid');
        }

        $record = $this->decodeAttestationRecord($proof, $leafDer);
        if (!is_array($record)) {
            return AttestationVerificationResult::rejected('attestation_record_invalid');
        }
        if (!hash_equals($nonce, (string) ($record['challenge'] ?? ''))) {
            return AttestationVerificationResult::rejected('challenge_mismatch');
        }
        if (!$this->isHardwareSecurityLevel($record['attestation_security_level'] ?? null)
            || !$this->isHardwareSecurityLevel($record['keymaster_security_level'] ?? null)) {
            return AttestationVerificationResult::rejected('hardware_security_required');
        }
        if (($record['device_locked'] ?? false) !== true) {
            return AttestationVerificationResult::rejected('boot_not_locked');
        }
        if (($record['verified_boot_state'] ?? null) !== 'verified') {
            return AttestationVerificationResult::rejected('verified_boot_not_green');
        }

        $packages = $record['packages'] ?? [];
        if (!is_array($packages) || !in_array($this->expectedPackageName, $packages, true)) {
            return AttestationVerificationResult::rejected('package_name_mismatch');
        }
        $digests = $record['signature_digests'] ?? [];
        if (!is_array($digests) || array_intersect($this->expectedSigningCertificateDigests, $this->normalizeDigests($digests)) === []) {
            return AttestationVerificationResult::rejected('signing_certificate_mismatch');
        }

        $bindingSignature = $this->decodeBase64Field($proof['binding_signature'] ?? null, 16, self::MAX_CERTIFICATE_BYTES);
        if ($bindingSignature === null) {
            return AttestationVerificationResult::rejected('identity_binding_invalid');
        }
        $binding = implode('|', [$nonce, (string) $clientId, $deviceId, hash('sha256', $identitySpki)]);
        if (!$this->verifyBindingSignature($binding, $bindingSignature, $attestationPublicKey)) {
            return AttestationVerificationResult::rejected('identity_binding_invalid');
        }

        return AttestationVerificationResult::trusted(hash('sha256', $identitySpki), hash('sha256', $leafDer));
    }

    /**
     * @param array<int, string> $certificateChain
     * @return array{leaf_der?:string,attestation_public_key?:mixed,error?:string}|null
     */
    private function verifyCertificateChain(array $certificateChain): ?array
    {
        if (is_callable($this->certificateChainVerifier)) {
            $verified = ($this->certificateChainVerifier)($certificateChain, $this->trustedRootCertificateDigests);
            return is_array($verified) ? $verified : null;
        }
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_x509_verify') || !function_exists('openssl_pkey_get_public')) {
            return ['error' => 'openssl_unavailable'];
        }

        $certificates = [];
        foreach ($certificateChain as $encoded) {
            $der = $this->certificateDer($encoded);
            if ($der === null || strlen($der) > self::MAX_CERTIFICATE_BYTES) {
                return null;
            }
            $pem = $this->pemFromDer($der);
            $certificate = @openssl_x509_read($pem);
            if ($certificate === false) {
                return null;
            }
            $parsed = @openssl_x509_parse($certificate);
            if (!is_array($parsed) || !$this->isCurrentlyValid($parsed)) {
                return null;
            }
            $certificates[] = [
                'der' => $der,
                'certificate' => $certificate,
                'public_key' => @openssl_pkey_get_public($certificate),
            ];
        }
        if ($certificates === [] || $certificates[0]['public_key'] === false) {
            return null;
        }

        $last = count($certificates) - 1;
        $rootHash = hash('sha256', $certificates[$last]['der']);
        if (!in_array($rootHash, $this->trustedRootCertificateDigests, true)) {
            return null;
        }
        for ($index = 0; $index < $last; $index++) {
            $issuerKey = $certificates[$index + 1]['public_key'];
            if ($issuerKey === false || @openssl_x509_verify($certificates[$index]['certificate'], $issuerKey) !== 1) {
                return null;
            }
        }
        $rootKey = $certificates[$last]['public_key'];
        if ($rootKey === false || @openssl_x509_verify($certificates[$last]['certificate'], $rootKey) !== 1) {
            return null;
        }

        return [
            'leaf_der' => $certificates[0]['der'],
            'attestation_public_key' => $certificates[0]['public_key'],
        ];
    }

    /**
     * @param array<string, mixed> $proof
     * @return array<string, mixed>|null
     */
    private function decodeAttestationRecord(array $proof, string $leafDer): ?array
    {
        if (is_callable($this->attestationRecordDecoder)) {
            $decoded = ($this->attestationRecordDecoder)($proof, $leafDer);
            return is_array($decoded) ? $decoded : null;
        }

        $extension = $this->extractExtensionValue($leafDer, self::ATTESTATION_EXTENSION_OID);
        return $extension === null ? null : $this->parseKeyDescription($extension);
    }

    private function verifyBindingSignature(string $message, string $signature, mixed $publicKey): bool
    {
        if (is_callable($this->bindingSignatureVerifier)) {
            return ($this->bindingSignatureVerifier)($message, $signature, $publicKey) === true;
        }
        if (!function_exists('openssl_verify')) {
            return false;
        }
        return @openssl_verify($message, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function isHardwareSecurityLevel(mixed $value): bool
    {
        return in_array($value, ['trusted_environment', 'strongbox'], true);
    }

    /**
     * @param array<int, string> $digests
     * @return array<int, string>
     */
    private function normalizeDigests(array $digests): array
    {
        $normalized = [];
        foreach ($digests as $digest) {
            $digest = strtolower(trim($digest));
            if (preg_match('/^[a-f0-9]{64}$/', $digest) === 1) {
                $normalized[] = $digest;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function decodeBase64Field(mixed $value, int $minimumLength, int $maximumLength): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) < $minimumLength || strlen($decoded) > $maximumLength) {
            return null;
        }
        return $decoded;
    }

    private function certificateDer(string $value): ?string
    {
        if (str_contains($value, '-----BEGIN CERTIFICATE-----')) {
            $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\\s+/', '', $value);
            return is_string($body) ? base64_decode($body, true) ?: null : null;
        }
        return base64_decode($value, true) ?: null;
    }

    private function pemFromDer(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    /** @param array<string, mixed> $parsed */
    private function isCurrentlyValid(array $parsed): bool
    {
        $now = time();
        return isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])
            && (int) $parsed['validFrom_time_t'] <= $now
            && (int) $parsed['validTo_time_t'] >= $now;
    }

    private function extractExtensionValue(string $certificateDer, string $oid): ?string
    {
        $offset = 0;
        $certificate = $this->parseDerNode($certificateDer, $offset);
        if (!is_array($certificate) || $offset !== strlen($certificateDer) || !$this->isUniversal($certificate, 16)) {
            return null;
        }
        $tbs = $certificate['children'][0] ?? null;
        if (!is_array($tbs) || !$this->isUniversal($tbs, 16)) {
            return null;
        }
        foreach ($tbs['children'] as $candidate) {
            if (!is_array($candidate) || ($candidate['class'] ?? -1) !== 2 || ($candidate['tag'] ?? -1) !== 3) {
                continue;
            }
            $extensions = $candidate['children'][0] ?? null;
            if (!is_array($extensions) || !$this->isUniversal($extensions, 16)) {
                return null;
            }
            foreach ($extensions['children'] as $extension) {
                if (!is_array($extension) || !$this->isUniversal($extension, 16)) {
                    continue;
                }
                $oidNode = $extension['children'][0] ?? null;
                if (!is_array($oidNode) || !$this->isUniversal($oidNode, 6) || $this->decodeOid($oidNode['value']) !== $oid) {
                    continue;
                }
                foreach (array_slice($extension['children'], 1) as $field) {
                    if (is_array($field) && $this->isUniversal($field, 4)) {
                        return $field['value'];
                    }
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function parseKeyDescription(string $recordDer): ?array
    {
        $offset = 0;
        $root = $this->parseDerNode($recordDer, $offset);
        if (!is_array($root) || $offset !== strlen($recordDer) || !$this->isUniversal($root, 16) || count($root['children']) < 8) {
            return null;
        }
        $children = $root['children'];
        $challenge = $children[4] ?? null;
        $software = $children[6] ?? null;
        $tee = $children[7] ?? null;
        if (!is_array($challenge) || !is_array($software) || !is_array($tee)
            || !$this->isUniversal($challenge, 4) || !$this->isUniversal($software, 16) || !$this->isUniversal($tee, 16)) {
            return null;
        }

        $rootOfTrust = $this->contextChild($tee, 704);
        $rootOfTrust = is_array($rootOfTrust) ? ($rootOfTrust['children'][0] ?? null) : null;
        if (!is_array($rootOfTrust) || !$this->isUniversal($rootOfTrust, 16) || count($rootOfTrust['children']) < 3) {
            return null;
        }
        $deviceLocked = $rootOfTrust['children'][1] ?? null;
        $bootState = $rootOfTrust['children'][2] ?? null;
        if (!is_array($deviceLocked) || !is_array($bootState) || !$this->isUniversal($deviceLocked, 1) || !$this->isUniversal($bootState, 10)) {
            return null;
        }

        $applicationId = $this->contextChild($software, 709) ?? $this->contextChild($tee, 709);
        $applicationId = is_array($applicationId) ? ($applicationId['children'][0] ?? null) : null;
        if (!is_array($applicationId) || !$this->isUniversal($applicationId, 4)) {
            return null;
        }
        $application = $this->parseAttestationApplicationId($applicationId['value']);
        if ($application === null) {
            return null;
        }

        return [
            'attestation_security_level' => $this->securityLevel($children[1] ?? null),
            'keymaster_security_level' => $this->securityLevel($children[3] ?? null),
            'challenge' => $challenge['value'],
            'packages' => $application['packages'],
            'signature_digests' => $application['signature_digests'],
            'verified_boot_state' => $this->verifiedBootState($bootState),
            'device_locked' => $deviceLocked['value'] !== '' && ord($deviceLocked['value'][0]) !== 0,
        ];
    }

    /** @return array{packages: array<int,string>, signature_digests: array<int,string>}|null */
    private function parseAttestationApplicationId(string $der): ?array
    {
        $offset = 0;
        $root = $this->parseDerNode($der, $offset);
        if (!is_array($root) || $offset !== strlen($der) || !$this->isUniversal($root, 16) || count($root['children']) < 2) {
            return null;
        }
        $packagesSet = $root['children'][0];
        $digestsSet = $root['children'][1];
        if (!$this->isUniversal($packagesSet, 17) || !$this->isUniversal($digestsSet, 17)) {
            return null;
        }
        $packages = [];
        foreach ($packagesSet['children'] as $packageInfo) {
            $name = is_array($packageInfo) ? ($packageInfo['children'][0] ?? null) : null;
            if (is_array($name) && $this->isUniversal($name, 4)) {
                $packages[] = $name['value'];
            }
        }
        $digests = [];
        foreach ($digestsSet['children'] as $digest) {
            if (is_array($digest) && $this->isUniversal($digest, 4)) {
                $digests[] = bin2hex($digest['value']);
            }
        }
        return $packages === [] || $digests === [] ? null : [
            'packages' => array_values(array_unique($packages)),
            'signature_digests' => $this->normalizeDigests($digests),
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseDerNode(string $data, int &$offset, int $depth = 0): ?array
    {
        if ($depth > 16 || $offset >= strlen($data)) {
            return null;
        }
        $identifier = ord($data[$offset++]);
        $class = $identifier >> 6;
        $constructed = ($identifier & 0x20) !== 0;
        $tag = $identifier & 0x1f;
        if ($tag === 0x1f) {
            $tag = 0;
            $count = 0;
            do {
                if ($offset >= strlen($data) || $count++ >= 5) {
                    return null;
                }
                $octet = ord($data[$offset++]);
                $tag = ($tag << 7) | ($octet & 0x7f);
            } while (($octet & 0x80) !== 0);
        }
        if ($offset >= strlen($data)) {
            return null;
        }
        $firstLength = ord($data[$offset++]);
        if (($firstLength & 0x80) === 0) {
            $length = $firstLength;
        } else {
            $lengthBytes = $firstLength & 0x7f;
            if ($lengthBytes === 0 || $lengthBytes > 4 || $offset + $lengthBytes > strlen($data)) {
                return null;
            }
            $length = 0;
            for ($index = 0; $index < $lengthBytes; $index++) {
                $length = ($length << 8) | ord($data[$offset++]);
            }
        }
        if ($length < 0 || $offset + $length > strlen($data)) {
            return null;
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        $children = [];
        if ($constructed) {
            $childOffset = 0;
            while ($childOffset < strlen($value)) {
                $child = $this->parseDerNode($value, $childOffset, $depth + 1);
                if (!is_array($child)) {
                    return null;
                }
                $children[] = $child;
            }
        }
        return [
            'class' => $class,
            'tag' => $tag,
            'constructed' => $constructed,
            'value' => $value,
            'children' => $children,
        ];
    }

    /** @param array<string, mixed> $node */
    private function isUniversal(array $node, int $tag): bool
    {
        return ($node['class'] ?? -1) === 0 && ($node['tag'] ?? -1) === $tag;
    }

    /** @param array<string, mixed> $authorizationList */
    private function contextChild(array $authorizationList, int $tag): ?array
    {
        foreach ($authorizationList['children'] as $child) {
            if (is_array($child) && ($child['class'] ?? -1) === 2 && ($child['tag'] ?? -1) === $tag) {
                return $child;
            }
        }
        return null;
    }

    private function decodeOid(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $first = ord($value[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $current = 0;
        for ($index = 1; $index < strlen($value); $index++) {
            $octet = ord($value[$index]);
            $current = ($current << 7) | ($octet & 0x7f);
            if (($octet & 0x80) === 0) {
                $parts[] = $current;
                $current = 0;
            }
        }
        return $current === 0 ? implode('.', $parts) : null;
    }

    /** @param array<string, mixed>|null $node */
    private function securityLevel(?array $node): ?string
    {
        if ($node === null || !$this->isUniversal($node, 10) || $node['value'] === '') {
            return null;
        }
        return match (ord($node['value'][0])) {
            1 => 'trusted_environment',
            2 => 'strongbox',
            0 => 'software',
            default => null,
        };
    }

    /** @param array<string, mixed> $node */
    private function verifiedBootState(array $node): ?string
    {
        if ($node['value'] === '') {
            return null;
        }
        return match (ord($node['value'][0])) {
            0 => 'verified',
            1 => 'self_signed',
            2 => 'unverified',
            3 => 'failed',
            default => null,
        };
    }
}
