<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class TenantRegistrationService
{
    private AppSettings $settings;
    private SmtpMailer $mailer;

    public function __construct(
        private Config $config,
        private Database $db,
    ) {
        $this->settings = new AppSettings($db);
        $this->mailer = new SmtpMailer($config);
    }

    /**
     * @return array{require_invite_code: bool}
     */
    public function registrationSettings(): array
    {
        return [
            'require_invite_code' => $this->settings->getBool('tenant_registration_require_code', true),
        ];
    }

    public function saveRegistrationSettings(bool $requireInviteCode): void
    {
        $this->settings->setBool('tenant_registration_require_code', $requireInviteCode);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listInviteCodes(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, u.email AS created_by_email
             FROM tenant_registration_codes c
             LEFT JOIN users u ON u.id = c.created_by_user_id
             ORDER BY c.updated_at DESC, c.id DESC'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createInviteCode(array $payload, ?int $createdByUserId = null): array
    {
        $label = trim((string) ($payload['label'] ?? ''));
        $maxUses = max(1, (int) ($payload['max_uses'] ?? 1));
        $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
        $code = $this->generateCode();

        $id = $this->db->insert('tenant_registration_codes', [
            'code' => $code,
            'label' => $label !== '' ? $label : null,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'is_active' => 1,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'created_by_user_id' => $createdByUserId,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return $this->db->fetchOne('SELECT * FROM tenant_registration_codes WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
    }

    public function toggleInviteCode(int $codeId): void
    {
        $code = $this->db->fetchOne('SELECT * FROM tenant_registration_codes WHERE id = :id LIMIT 1', ['id' => $codeId]);
        if ($code === null) {
            throw new ApiException('registration_code_not_found', 404);
        }

        $this->db->execute(
            'UPDATE tenant_registration_codes SET is_active = :is_active, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $codeId,
                'is_active' => (int) ((int) ($code['is_active'] ?? 0) === 1 ? 0 : 1),
                'updated_at' => $this->now(),
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerTenant(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $passwordConfirm = (string) ($payload['password_confirm'] ?? '');
        $inviteCode = strtoupper(trim((string) ($payload['invite_code'] ?? '')));

        if ($name === '' || $email === '' || $password === '') {
            throw new ApiException('registration_invalid', 422, ['message' => 'Name, email and password are required.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('registration_invalid', 422, ['message' => 'Invalid email format.']);
        }
        if ($password !== $passwordConfirm) {
            throw new ApiException('registration_invalid', 422, ['message' => 'Password confirmation does not match.']);
        }
        if (strlen($password) < 8) {
            throw new ApiException('registration_invalid', 422, ['message' => 'Password must be at least 8 characters.']);
        }

        $settings = $this->registrationSettings();
        $code = null;
        if ($settings['require_invite_code']) {
            if ($inviteCode === '') {
                throw new ApiException('registration_invite_required', 422, ['message' => 'Invite code is required for tenant registration.']);
            }
            $code = $this->validInviteCode($inviteCode);
        }

        $user = $this->db->transaction(function () use ($name, $email, $password, $code): array {
            $existing = $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
            if ($existing !== null && (string) ($existing['role'] ?? '') !== 'tenant') {
                throw new ApiException('registration_invalid', 422, ['message' => 'This email is already used by another account.']);
            }
            if ($existing !== null && (string) ($existing['status'] ?? '') === 'active') {
                throw new ApiException('registration_invalid', 422, ['message' => 'This email has already been registered.']);
            }

            $verificationToken = bin2hex(random_bytes(24));
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $now = $this->now();

            if ($existing === null) {
                $userId = $this->db->insert('users', [
                    'name' => $name,
                    'email' => $email,
                    'api_token' => bin2hex(random_bytes(24)),
                    'password_hash' => $passwordHash,
                    'role' => 'tenant',
                    'feature_flags_json' => null,
                    'status' => 'pending_verification',
                    'email_verified_at' => null,
                    'email_verification_token' => $verificationToken,
                    'verification_sent_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
            } else {
                $this->db->execute(
                    'UPDATE users
                     SET name = :name,
                         password_hash = :password_hash,
                         role = :role,
                         status = :status,
                         email_verified_at = NULL,
                         email_verification_token = :email_verification_token,
                         verification_sent_at = :verification_sent_at,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $existing['id'],
                        'name' => $name,
                        'password_hash' => $passwordHash,
                        'role' => 'tenant',
                        'status' => 'pending_verification',
                        'email_verification_token' => $verificationToken,
                        'verification_sent_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $existing['id']]);
            }

            if ($user === null) {
                throw new ApiException('registration_failed', 500);
            }

            if ($code !== null) {
                $this->db->execute(
                    'UPDATE tenant_registration_codes
                     SET used_count = used_count + 1,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $code['id'],
                        'updated_at' => $now,
                    ]
                );
            }

            $this->sendVerificationEmail($user);
            return $user;
        });

        $this->provisioning()->ensureDefaultClientForUser((int) ($user['id'] ?? 0));

        return $user;
    }

    private function provisioning(): ProvisioningService
    {
        return new ProvisioningService($this->config, $this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyEmailToken(string $token): array
    {
        $trimmedToken = trim($token);
        if ($trimmedToken === '') {
            throw new ApiException('verification_token_missing', 422);
        }

        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE email_verification_token = :token AND role = :role LIMIT 1',
            [
                'token' => $trimmedToken,
                'role' => 'tenant',
            ]
        );
        if ($user === null) {
            throw new ApiException('verification_token_invalid', 404, ['message' => 'Verification link is invalid or expired.']);
        }

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
                'email_verified_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $user['id']]) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function validInviteCode(string $inviteCode): array
    {
        $code = $this->db->fetchOne('SELECT * FROM tenant_registration_codes WHERE code = :code LIMIT 1', ['code' => $inviteCode]);
        if ($code === null) {
            throw new ApiException('registration_invite_invalid', 422, ['message' => 'Invite code is invalid.']);
        }
        if ((int) ($code['is_active'] ?? 0) !== 1) {
            throw new ApiException('registration_invite_invalid', 422, ['message' => 'Invite code has been disabled.']);
        }
        if (!empty($code['expires_at']) && strtotime((string) $code['expires_at']) !== false && strtotime((string) $code['expires_at']) < time()) {
            throw new ApiException('registration_invite_invalid', 422, ['message' => 'Invite code has expired.']);
        }
        if ((int) ($code['max_uses'] ?? 0) > 0 && (int) ($code['used_count'] ?? 0) >= (int) ($code['max_uses'] ?? 0)) {
            throw new ApiException('registration_invite_invalid', 422, ['message' => 'Invite code has already been used up.']);
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendVerificationEmail(array $user): void
    {
        $appUrl = rtrim((string) ($this->config->get('APP_URL', 'http://localhost') ?? 'http://localhost'), '/');
        $brandName = (string) ($this->config->get('DISPLAY_BRAND_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
        $productName = (string) ($this->config->get('APP_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
        $verificationUrl = $appUrl . '/verify-email?token=' . urlencode((string) ($user['email_verification_token'] ?? ''));
        $name = (string) ($user['name'] ?? $user['email'] ?? 'Tenant');

        $subject = $brandName . ' - Verify your tenant email';
        $html = '<div style="font-family:Segoe UI,Aptos,sans-serif;line-height:1.7;color:#132238;">'
            . '<h2 style="margin:0 0 12px;">Verify your tenant account</h2>'
            . '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your ' . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . ' tenant registration has been created. Click the button below to verify your email and activate the account.</p>'
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 18px;border-radius:12px;background:#0f6d86;color:#ffffff;text-decoration:none;font-weight:700;">Verify Email</a></p>'
            . '<p>If the button does not work, open this link directly:</p>'
            . '<p><a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '</div>';
        $text = "Verify your tenant account\n\n"
            . "Hello {$name},\n\n"
            . "Your {$productName} tenant registration has been created.\n"
            . "Open this link to verify your email:\n{$verificationUrl}\n";

        $this->mailer->send((string) $user['email'], $name, $subject, $html, $text);
    }

    private function generateCode(): string
    {
        return 'LKH-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
