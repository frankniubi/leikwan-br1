<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;

/**
 * At-rest encryption for hosted user nodes.
 *
 * The whole canonical node (server, port, credentials) is sealed with an AEAD
 * whose associated data is the owning client id, so a ciphertext copied onto
 * another client's row does not open. Only the display name and protocol stay
 * in clear columns.
 *
 * Key: HOSTED_NODE_ENCRYPTION_KEY, else the existing ULTRA_TOKEN_KEY /
 * ULTRA_CACHE_KEY. Separate subkeys are derived for sealing and for the
 * duplicate fingerprint, so neither reuses the Ultra key directly. With no key
 * or no AEAD (sodium / openssl), sealing fails closed: nothing is stored.
 */
final class HostedNodeVault
{
    public const KEY_SETTING = 'HOSTED_NODE_ENCRYPTION_KEY';

    private ?string $sealKey;
    private ?string $fingerprintKey;

    public function __construct(?string $rawKey)
    {
        $master = self::decodeKey($rawKey);
        $this->sealKey = $master === null ? null : hash_hmac('sha256', 'leikwan-hosted-node-seal-v1', $master, true);
        $this->fingerprintKey = $master === null ? null : hash_hmac('sha256', 'leikwan-hosted-node-fingerprint-v1', $master, true);
    }

    public static function fromConfig(Config $config): self
    {
        foreach ([self::KEY_SETTING, UltraMode::TOKEN_KEY, UltraMode::CACHE_KEY] as $name) {
            $raw = trim((string) ($config->get($name, '') ?? ''));
            if ($raw !== '') {
                return new self($raw);
            }
        }
        return new self(null);
    }

    public function available(): bool
    {
        return $this->sealKey !== null
            && (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') || function_exists('openssl_encrypt'));
    }

    /** @param array<string, mixed> $node */
    public function seal(int $clientId, array $node): string
    {
        if (!$this->available() || $clientId <= 0) {
            throw new ApiException('hosted_nodes_unavailable', 503);
        }
        $plain = json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $aad = self::aad($clientId);
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, (string) $this->sealKey);
            return 'hn1x.' . self::b64($nonce . $cipher);
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', (string) $this->sealKey, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new ApiException('hosted_nodes_unavailable', 503);
        }
        return 'hn1g.' . self::b64($nonce . $tag . $cipher);
    }

    /** @return array<string, mixed>|null null when the blob does not open for this client */
    public function open(int $clientId, string $sealed): ?array
    {
        if ($this->sealKey === null || $clientId <= 0) {
            return null;
        }
        [$scheme, $encoded] = array_pad(explode('.', $sealed, 2), 2, '');
        $raw = self::unb64($encoded);
        if ($raw === null) {
            return null;
        }
        $aad = self::aad($clientId);
        $plain = false;
        if ($scheme === 'hn1x' && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($raw) > $nonceLength) {
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    substr($raw, $nonceLength),
                    $aad,
                    substr($raw, 0, $nonceLength),
                    $this->sealKey
                );
            }
        } elseif ($scheme === 'hn1g' && function_exists('openssl_decrypt') && strlen($raw) > 28) {
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->sealKey, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        }
        if (!is_string($plain) || $plain === '') {
            return null;
        }
        try {
            $decoded = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Keyed digest of the node's connection identity (protocol, server, port,
     * settings; not the name), used to make uploads idempotent per client
     * without storing anything that reveals the credentials.
     *
     * @param array{protocol:string,server:string,port:int,settings:array<string,mixed>} $node
     */
    public function fingerprint(int $clientId, array $node): string
    {
        if ($this->fingerprintKey === null) {
            throw new ApiException('hosted_nodes_unavailable', 503);
        }
        $identity = json_encode([
            'client_id' => $clientId,
            'protocol' => $node['protocol'],
            'server' => $node['server'],
            'port' => $node['port'],
            'settings' => $node['settings'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $identity, $this->fingerprintKey);
    }

    private static function decodeKey(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/i', $raw) === 1) {
            $decoded = hex2bin($raw);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
        }
        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }
        return hash('sha256', $raw, true);
    }

    private static function aad(int $clientId): string
    {
        return 'leikwan-hosted-node:client:' . $clientId;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $normalized = strtr($value, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        return is_string($decoded) ? $decoded : null;
    }
}
