<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class PasswordResetService
{
    private SmtpMailer $mailer;

    public function __construct(
        private Config $config,
        private Database $db,
    ) {
        $this->mailer = new SmtpMailer($config);
    }

    public function requestReset(string $email): void
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => $normalizedEmail]
        );
        if ($user === null || (string) ($user['status'] ?? '') !== 'active') {
            return;
        }

        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $now = $this->now();
        $expiresAt = $this->future('+1 hour');

        $this->db->transaction(function () use ($user, $tokenHash, $now, $expiresAt): void {
            $this->db->execute(
                'UPDATE password_reset_tokens
                 SET used_at = :used_at
                 WHERE user_id = :user_id AND used_at IS NULL',
                [
                    'used_at' => $now,
                    'user_id' => $user['id'],
                ]
            );

            $this->db->insert('password_reset_tokens', [
                'user_id' => $user['id'],
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'created_at' => $now,
            ]);
        });

        $this->sendResetEmail($user, $token, $expiresAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function tokenDetails(string $token): array
    {
        $reset = $this->findActiveToken($token);
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $reset['user_id']]);
        if ($user === null) {
            throw new ApiException('password_reset_user_not_found', 404, ['message' => 'This password reset link is no longer valid.']);
        }

        return [
            'user' => $user,
            'reset' => $reset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(string $token, string $password, string $passwordConfirm): array
    {
        if ($password === '' || $passwordConfirm === '') {
            throw new ApiException('password_reset_invalid', 422, ['message' => 'Please enter and confirm your new password.']);
        }
        if ($password !== $passwordConfirm) {
            throw new ApiException('password_reset_invalid', 422, ['message' => 'The two password fields do not match.']);
        }
        if (strlen($password) < 8) {
            throw new ApiException('password_reset_invalid', 422, ['message' => 'Password must be at least 8 characters long.']);
        }

        $reset = $this->findActiveToken($token);
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $reset['user_id']]);
        if ($user === null) {
            throw new ApiException('password_reset_user_not_found', 404, ['message' => 'This password reset link is no longer valid.']);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $now = $this->now();

        $this->db->transaction(function () use ($user, $reset, $passwordHash, $now): void {
            $this->db->execute(
                'UPDATE users
                 SET password_hash = :password_hash,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $user['id'],
                    'password_hash' => $passwordHash,
                    'updated_at' => $now,
                ]
            );

            $this->db->execute(
                'UPDATE password_reset_tokens
                 SET used_at = :used_at
                 WHERE id = :id',
                [
                    'id' => $reset['id'],
                    'used_at' => $now,
                ]
            );
        });

        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $user['id']]) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function findActiveToken(string $token): array
    {
        $trimmedToken = trim($token);
        if ($trimmedToken === '') {
            throw new ApiException('password_reset_token_missing', 422, ['message' => 'The password reset link is missing its token.']);
        }

        $reset = $this->db->fetchOne(
            'SELECT * FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => hash('sha256', $trimmedToken)]
        );
        if ($reset === null) {
            throw new ApiException('password_reset_token_invalid', 404, ['message' => 'This password reset link is invalid or has already been used.']);
        }
        if (!empty($reset['used_at'])) {
            throw new ApiException('password_reset_token_used', 410, ['message' => 'This password reset link has already been used.']);
        }

        $expiresAt = strtotime((string) ($reset['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            throw new ApiException('password_reset_token_expired', 410, ['message' => 'This password reset link has expired.']);
        }

        return $reset;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendResetEmail(array $user, string $token, string $expiresAt): void
    {
        $appUrl = rtrim((string) ($this->config->get('APP_URL', 'http://localhost') ?? 'http://localhost'), '/');
        $brandName = (string) ($this->config->get('DISPLAY_BRAND_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
        $productName = (string) ($this->config->get('APP_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
        $resetUrl = $appUrl . '/reset-password?token=' . urlencode($token);
        $name = (string) ($user['name'] ?? $user['email'] ?? 'User');

        $subject = $brandName . ' - Reset your password';
        $html = '<div style="font-family:Segoe UI,Aptos,sans-serif;line-height:1.7;color:#132238;">'
            . '<h2 style="margin:0 0 12px;">Reset your password</h2>'
            . '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>We received a request to reset the password for your ' . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . ' account.</p>'
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 18px;border-radius:12px;background:#0f6d86;color:#ffffff;text-decoration:none;font-weight:700;">Reset Password</a></p>'
            . '<p>This link expires at <strong>' . htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8') . ' UTC</strong>.</p>'
            . '<p>If you did not request this, you can safely ignore the email.</p>'
            . '<p>If the button does not work, open this link directly:</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '</div>';
        $text = "Reset your password\n\n"
            . "Hello {$name},\n\n"
            . "We received a request to reset the password for your {$productName} account.\n"
            . "Open this link to continue:\n{$resetUrl}\n\n"
            . "This link expires at {$expiresAt} UTC.\n";

        $this->mailer->send((string) $user['email'], $name, $subject, $html, $text);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function future(string $modifier): string
    {
        return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
