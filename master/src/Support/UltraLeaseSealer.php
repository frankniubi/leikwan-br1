<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use RuntimeException;

/**
 * Seals the provider material of an Ultra lease to one device's public key.
 *
 * A lease used to carry the LeiGod relay endpoint and credential in clear, so
 * anybody holding a client agent_token could curl /api/client/ultra/lease and
 * walk away with a working line. With sealing the device presents a P-256
 * public key (X-Leikwan-Lease-Key) and only the holder of the matching private
 * key can open the material. The format is specified in
 * docs/specs/2026-10-02-ultra-sealed-lease-v1.md; every client implements the
 * open side against the vectors in that document.
 *
 *   Z    = ECDH(ephemeral_priv, device_pub)            32-byte x coordinate
 *   key  = HKDF-SHA256(ikm=Z, salt=epk || device_pub, info=INFO, L=32)
 *   aad  = INFO || "|" || lease_id
 *   ct   = AES-256-GCM(key, iv, plaintext, aad) || tag(16)
 */
final class UltraLeaseSealer
{
    public const HEADER = 'X-Leikwan-Lease-Key';
    public const VERSION = 1;
    public const ALGORITHM = 'ECDH-P256+HKDF-SHA256+A256GCM';
    public const INFO = 'leikwan-ultra-lease-v1';

    /** Lease fields that only ever travel inside the sealed envelope. */
    public const SEALED_FIELDS = ['endpoint', 'credential', 'external_uot'];

    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Decode and validate a header value: base64url (padding optional) of the
     * 65-byte uncompressed SEC1 point. Returns null for anything else,
     * including a point that is not on the curve.
     */
    public static function parsePublicKey(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return null;
        }
        $raw = self::base64UrlDecode($value);
        if ($raw === null || strlen($raw) !== 65 || $raw[0] !== "\x04") {
            return null;
        }
        return self::publicKeyResource($raw) === null ? null : $raw;
    }

    /** Stable short identifier of a device key, echoed back as "kid". */
    public static function keyId(string $rawPublicKey): string
    {
        return substr(hash('sha256', $rawPublicKey), 0, 32);
    }

    public static function keyHash(string $rawPublicKey): string
    {
        return hash('sha256', $rawPublicKey);
    }

    /**
     * Move SEALED_FIELDS out of the lease into a "sealed" envelope.
     *
     * @param array<string,mixed> $lease
     * @return array<string,mixed>
     */
    public static function sealLease(array $lease, string $rawPublicKey, ?string $ephemeralPem = null, ?string $iv = null): array
    {
        $secret = [];
        foreach (self::SEALED_FIELDS as $field) {
            if (array_key_exists($field, $lease)) {
                $secret[$field] = $lease[$field];
                unset($lease[$field]);
            }
        }
        $plaintext = json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lease['sealed'] = self::seal($plaintext, $rawPublicKey, (string) ($lease['lease_id'] ?? ''), $ephemeralPem, $iv);
        return $lease;
    }

    /**
     * @return array{v:int,alg:string,kid:string,epk:string,iv:string,ct:string}
     */
    public static function seal(string $plaintext, string $rawPublicKey, string $leaseId, ?string $ephemeralPem = null, ?string $iv = null): array
    {
        $devicePublic = self::publicKeyResource($rawPublicKey);
        if ($devicePublic === null) {
            throw new RuntimeException('lease_key_invalid');
        }
        $ephemeral = $ephemeralPem !== null
            ? openssl_pkey_get_private($ephemeralPem)
            : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($ephemeral === false) {
            throw new RuntimeException('lease_seal_keygen_failed');
        }
        $details = openssl_pkey_get_details($ephemeral);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('lease_seal_keygen_failed');
        }
        $epk = "\x04" . self::pad32((string) $details['ec']['x']) . self::pad32((string) $details['ec']['y']);
        $shared = openssl_pkey_derive($devicePublic, $ephemeral, 32);
        if (!is_string($shared) || $shared === '') {
            throw new RuntimeException('lease_seal_derive_failed');
        }
        $shared = self::pad32($shared);
        $key = hash_hkdf('sha256', $shared, 32, self::INFO, $epk . $rawPublicKey);
        $iv ??= random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad($leaseId), 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('lease_seal_encrypt_failed');
        }
        return [
            'v' => self::VERSION,
            'alg' => self::ALGORITHM,
            'kid' => self::keyId($rawPublicKey),
            'epk' => self::base64UrlEncode($epk),
            'iv' => self::base64UrlEncode($iv),
            'ct' => self::base64UrlEncode($ciphertext . $tag),
        ];
    }

    /**
     * Reference open side. The panel never needs it at runtime; tests use it to
     * prove a sealed lease round-trips with a device private key.
     */
    public static function open(array $sealed, string $devicePrivatePem, string $leaseId): ?string
    {
        $private = openssl_pkey_get_private($devicePrivatePem);
        $details = $private === false ? false : openssl_pkey_get_details($private);
        $epk = self::base64UrlDecode((string) ($sealed['epk'] ?? ''));
        $iv = self::base64UrlDecode((string) ($sealed['iv'] ?? ''));
        $blob = self::base64UrlDecode((string) ($sealed['ct'] ?? ''));
        if (!is_array($details) || $epk === null || $iv === null || $blob === null || strlen($blob) < 16) {
            return null;
        }
        $ephemeralPublic = self::publicKeyResource($epk);
        if ($ephemeralPublic === null) {
            return null;
        }
        $devicePub = "\x04" . self::pad32((string) $details['ec']['x']) . self::pad32((string) $details['ec']['y']);
        $shared = openssl_pkey_derive($ephemeralPublic, $private, 32);
        if (!is_string($shared)) {
            return null;
        }
        $key = hash_hkdf('sha256', self::pad32($shared), 32, self::INFO, $epk . $devicePub);
        $plaintext = openssl_decrypt(
            substr($blob, 0, -16),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            substr($blob, -16),
            self::aad($leaseId)
        );
        return is_string($plaintext) ? $plaintext : null;
    }

    public static function aad(string $leaseId): string
    {
        return self::INFO . '|' . $leaseId;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $value = strtr(trim($value), '-_', '+/');
        $decoded = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }

    private static function publicKeyResource(string $raw): mixed
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            return null;
        }
        $der = hex2bin(self::SPKI_P256_PREFIX) . $raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        return $key === false ? null : $key;
    }

    private static function pad32(string $value): string
    {
        return strlen($value) >= 32 ? substr($value, -32) : str_repeat("\0", 32 - strlen($value)) . $value;
    }
}
