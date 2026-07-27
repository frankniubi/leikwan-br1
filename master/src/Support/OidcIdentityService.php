<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeInterface;
use Leikwan\Master\Database\Database;
use PDOException;

final class OidcIdentityService
{
    /** @var callable(): mixed */
    private $clock;

    public function __construct(private Database $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): string => gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public function login(array $claims): array
    {
        $issuer = $this->requiredClaim($claims, 'issuer');
        $subject = $this->requiredClaim($claims, 'subject');
        $email = strtolower(trim($this->requiredClaim($claims, 'email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('oidc_invalid_email', 422, ['field' => 'email']);
        }
        if (($claims['email_verified'] ?? null) !== true) {
            throw new ApiException('oidc_email_not_verified', 403, ['field' => 'email_verified']);
        }

        $name = trim(is_string($claims['name'] ?? null) ? $claims['name'] : '');
        if ($name === '') {
            $name = strstr($email, '@', true) ?: $email;
        }

        $issuerHash = hash('sha256', $issuer);
        $subjectHash = hash('sha256', $subject);

        try {
            return $this->db->transaction(function () use ($issuer, $subject, $email, $name, $issuerHash, $subjectHash): array {
                $now = $this->now();
                $identity = $this->db->fetchOne(
                    'SELECT * FROM oidc_identities WHERE issuer_hash = :issuer_hash AND subject_hash = :subject_hash LIMIT 1',
                    ['issuer_hash' => $issuerHash, 'subject_hash' => $subjectHash]
                );

                if ($identity !== null) {
                    return $this->loginExistingIdentity($identity, $email, $now);
                }

                $user = $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
                if ($user === null) {
                    $user = $this->createTenant($name, $email, $now);
                } else {
                    $this->assertTenantCanLogin($user);
                    $bound = $this->db->fetchOne(
                        'SELECT id FROM oidc_identities WHERE user_id = :user_id AND issuer_hash = :issuer_hash LIMIT 1',
                        ['user_id' => $user['id'], 'issuer_hash' => $issuerHash]
                    );
                    if ($bound !== null) {
                        throw new ApiException('oidc_subject_conflict', 409, ['reason' => 'user_already_bound_to_issuer']);
                    }
                    if ((string) $user['status'] === 'pending_verification') {
                        $this->db->execute(
                            'UPDATE users
                             SET status = :status,
                                 email_verified_at = :email_verified_at,
                                 email_verification_token = NULL,
                                 verification_sent_at = NULL,
                                 updated_at = :updated_at
                             WHERE id = :id',
                            [
                                'id' => $user['id'],
                                'status' => 'active',
                                'email_verified_at' => $now,
                                'updated_at' => $now,
                            ]
                        );
                        $user = $this->userById((int) $user['id']);
                    } elseif (empty($user['email_verified_at'])) {
                        $this->db->execute(
                            'UPDATE users SET email_verified_at = :email_verified_at, updated_at = :updated_at WHERE id = :id',
                            ['id' => $user['id'], 'email_verified_at' => $now, 'updated_at' => $now]
                        );
                        $user = $this->userById((int) $user['id']);
                    }
                }

                $this->db->insert('oidc_identities', [
                    'user_id' => $user['id'],
                    'issuer' => $issuer,
                    'subject' => $subject,
                    'issuer_hash' => $issuerHash,
                    'subject_hash' => $subjectHash,
                    'email' => $email,
                    'email_verified_at' => $now,
                    'last_login_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $this->userById((int) $user['id']);
            });
        } catch (PDOException $exception) {
            if ($this->isUniqueConflict($exception)) {
                throw new ApiException('oidc_identity_conflict', 409, ['reason' => 'concurrent_identity_conflict']);
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function loginExistingIdentity(array $identity, string $email, string $now): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $identity['user_id']]);
        if ($user === null) {
            throw new ApiException('oidc_identity_account_missing', 409);
        }
        $this->assertTenantCanLogin($user);
        if ((string) $user['status'] !== 'active') {
            throw new ApiException('oidc_account_not_allowed', 403, ['reason' => 'active_tenant_required']);
        }

        if ((string) $user['email'] !== $email) {
            $owner = $this->db->fetchOne('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
            if ($owner !== null && (int) $owner['id'] !== (int) $user['id']) {
                throw new ApiException('oidc_email_conflict', 409, ['reason' => 'email_owned_by_another_user']);
            }
            $this->db->execute(
                'UPDATE users SET email = :email, email_verified_at = :email_verified_at, updated_at = :updated_at WHERE id = :id',
                ['id' => $user['id'], 'email' => $email, 'email_verified_at' => $now, 'updated_at' => $now]
            );
        }

        $this->db->execute(
            'UPDATE oidc_identities
             SET email = :email,
                 email_verified_at = :email_verified_at,
                 last_login_at = :last_login_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $identity['id'],
                'email' => $email,
                'email_verified_at' => $now,
                'last_login_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->userById((int) $user['id']);
    }

    /** @return array<string, mixed> */
    private function createTenant(string $name, string $email, string $now): array
    {
        $id = $this->db->insert('users', [
            'name' => $name,
            'email' => $email,
            'api_token' => $this->uniqueApiToken(),
            'password_hash' => null,
            'role' => 'tenant',
            'feature_flags_json' => null,
            'status' => 'active',
            'email_verified_at' => $now,
            'email_verification_token' => null,
            'verification_sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->userById($id);
    }

    /** @param array<string, mixed> $user */
    private function assertTenantCanLogin(array $user): void
    {
        if ((string) ($user['role'] ?? '') !== 'tenant') {
            throw new ApiException('oidc_account_not_allowed', 403, ['reason' => 'tenant_role_required']);
        }
        $status = (string) ($user['status'] ?? '');
        if ($status !== 'active' && $status !== 'pending_verification') {
            throw new ApiException('oidc_account_not_allowed', 403, ['reason' => 'tenant_status_not_allowed']);
        }
    }

    /** @return array<string, mixed> */
    private function userById(int $id): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($user === null) {
            throw new ApiException('oidc_account_not_found', 500);
        }
        return $user;
    }

    private function uniqueApiToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(24));
            if ($this->db->fetchOne('SELECT id FROM users WHERE api_token = :api_token LIMIT 1', ['api_token' => $token]) === null) {
                return $token;
            }
        }
        throw new ApiException('oidc_account_creation_failed', 500);
    }

    /** @param array<string, mixed> $claims */
    private function requiredClaim(array $claims, string $field): string
    {
        $value = $claims[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException('oidc_invalid_claims', 422, ['field' => $field]);
        }
        return trim($value);
    }

    private function now(): string
    {
        $now = ($this->clock)();
        if ($now instanceof DateTimeInterface) {
            return $now->format('Y-m-d H:i:s');
        }
        if (is_string($now) && trim($now) !== '') {
            return trim($now);
        }
        throw new \LogicException('OIDC clock must return a date/time string or DateTimeInterface');
    }

    private function isUniqueConflict(PDOException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        return $state === '23000' || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
