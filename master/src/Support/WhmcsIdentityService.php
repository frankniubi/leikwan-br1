<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class WhmcsIdentityService
{
    private const CHALLENGE_TTL_MINUTES = 10;

    /**
     * Wrong codes a binding challenge accepts before it is burned. Six digits
     * leave a 5 in 1,000,000 guess, and creating new challenges is limited by
     * AuthRateLimiter::BINDING_REQUEST_* per user and per target email.
     */
    public const MAX_CHALLENGE_ATTEMPTS = 5;

    public function __construct(
        private Config $config,
        private Database $db,
        private ?WhmcsClient $whmcs = null,
        private ?AuthRateLimiter $rateLimiter = null,
    ) {
        $this->whmcs ??= new WhmcsClient($config);
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config->get('WHMCS_BASE_URL', '') ?? '')) !== ''
            && trim((string) ($this->config->get('WHMCS_API_IDENTIFIER', '') ?? '')) !== ''
            && trim((string) ($this->config->get('WHMCS_API_SECRET', '') ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $authenticatedIdentity
     * @return array<string, mixed>|null
     */
    public function tryDirectBindForUser(int $userId, array $authenticatedIdentity = []): ?array
    {
        $user = $this->bindingUser($userId);
        $profile = $this->profileFromAuthenticatedIdentity($authenticatedIdentity, $user);
        $profile ??= $this->profileByEmail((string) ($user['email'] ?? ''));
        if ($profile === null) {
            return null;
        }

        return $this->bindUserToProfile($user, $profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function requestBinding(int $userId, string $whmcsEmail = ''): array
    {
        $user = $this->bindingUser($userId);
        $ownEmail = strtolower((string) ($user['email'] ?? ''));
        $targetEmail = strtolower(trim($whmcsEmail));
        if ($targetEmail === '') {
            $targetEmail = $ownEmail;
        }
        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('whmcs_binding_email_invalid', 422, ['message' => 'Please enter a valid Buy station email address.']);
        }

        // Every request is counted before the Buy station lookup, so a tenant
        // can neither enumerate Buy station emails nor flood someone else's
        // Buy station account with verification tickets.
        try {
            $this->rateLimiter()->hit([
                AuthRateLimiter::bucket('whmcs-binding-request:user', (string) (int) $user['id'], AuthRateLimiter::BINDING_REQUEST_USER_LIMIT, AuthRateLimiter::BINDING_REQUEST_WINDOW_SECONDS),
                AuthRateLimiter::bucket('whmcs-binding-request:email', $targetEmail, AuthRateLimiter::BINDING_REQUEST_EMAIL_LIMIT, AuthRateLimiter::BINDING_REQUEST_WINDOW_SECONDS),
            ]);
        } catch (ApiException $exception) {
            $retryAfter = (int) ($exception->details()['retry_after'] ?? 60);
            throw new ApiException('rate_limited', 429, [
                'retry_after' => $retryAfter,
                'message' => 'Too many Buy station verification requests. Please wait about ' . max(1, (int) ceil($retryAfter / 60)) . ' minute(s) and try again.',
            ]);
        }

        if ($targetEmail === $ownEmail) {
            // The tenant's own, already verified email: being explicit here
            // reveals nothing about anyone else.
            $profile = $this->profileByEmail($targetEmail);
            if ($profile === null) {
                throw new ApiException('whmcs_client_not_found', 404, ['message' => 'No Buy station account with the same email was found. If your Buy station uses another email, enter that email below and we will send a verification ticket.']);
            }
            if (strcasecmp((string) ($profile['email'] ?? ''), $ownEmail) === 0) {
                return [
                    'mode' => 'direct',
                    'user' => $this->bindUserToProfile($user, $profile),
                ];
            }
        } else {
            if (!empty($user['whmcs_client_id'])) {
                throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'Your panel account is already linked to a Buy station account.']);
            }
            $profile = $this->profileByEmail($targetEmail);
            // Another email with no Buy station account, or whose account is
            // already linked to a different panel account, gets the same
            // answer as a real one: a challenge no code can satisfy. Otherwise
            // this form would tell any tenant which emails exist at the Buy
            // station.
            if ($profile === null) {
                return $this->decoyChallenge($user, $targetEmail);
            }
            $linkedUser = $this->boundUserForClient((int) ($profile['whmcs_client_id'] ?? 0));
            if ($linkedUser !== null && (int) $linkedUser['id'] !== (int) $user['id']) {
                return $this->decoyChallenge($user, $targetEmail);
            }
        }

        if (
            !empty($user['whmcs_client_id']) &&
            (int) $user['whmcs_client_id'] !== (int) ($profile['whmcs_client_id'] ?? 0)
        ) {
            throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'Your panel account is already linked to another Buy station account.']);
        }

        $existingBinding = $this->boundUserForClient((int) ($profile['whmcs_client_id'] ?? 0));
        if ($existingBinding !== null && (int) $existingBinding['id'] !== (int) $user['id']) {
            throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'This Buy station account is already linked to another panel account.']);
        }

        $now = $this->now();
        $publicToken = bin2hex(random_bytes(18));
        $code = $this->generateChallengeCode();
        $expiresAt = $this->future('+' . self::CHALLENGE_TTL_MINUTES . ' minutes');
        $ticket = $this->openVerificationTicket($profile, $code);
        $challengeId = $this->db->insert('whmcs_login_challenges', [
            'public_token' => $publicToken,
            'user_id' => $user['id'],
            'whmcs_client_id' => $profile['whmcs_client_id'],
            'whmcs_user_id' => $profile['whmcs_user_id'],
            'whmcs_email' => $profile['email'],
            'verification_code_hash' => hash('sha256', $code),
            'ticket_id' => $ticket['ticket_id'],
            'ticket_tid' => $ticket['ticket_tid'],
            'ticket_access_code' => $ticket['ticket_access_code'],
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'mode' => 'challenge',
            'id' => $challengeId,
            'public_token' => $publicToken,
            'user' => $user,
            'ticket_id' => $ticket['ticket_id'],
            'ticket_tid' => $ticket['ticket_tid'],
            'ticket_access_code' => $ticket['ticket_access_code'],
            'expires_at' => $expiresAt,
            'email' => $profile['email'],
            'display_name' => $profile['full_name'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $email, string $password): array
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('whmcs_login_invalid', 422, ['message' => 'Please enter a valid Buy station email address.']);
        }
        if (trim($password) === '') {
            throw new ApiException('whmcs_login_invalid', 422, ['message' => 'Please enter your Buy station password.']);
        }

        $auth = $this->whmcs->validateLogin($normalizedEmail, $password);
        $userId = max(0, (int) ($auth['userid'] ?? 0));
        if ($userId <= 0) {
            throw new ApiException('whmcs_login_invalid', 401, ['message' => 'Buy station login failed. Please check your email and password.']);
        }

        $client = $this->resolveClientForAuthenticatedUser($normalizedEmail, $userId);

        return [
            'whmcs_user_id' => $userId,
            'whmcs_client_id' => max(0, (int) ($client['id'] ?? 0)),
            'email' => (string) ($client['email'] ?? $normalizedEmail),
            'full_name' => $this->displayNameFromClient($client),
            'company_name' => (string) ($client['companyname'] ?? ''),
            'currency_code' => strtoupper(trim((string) ($client['currency_code'] ?? 'CNY'))),
            'credit' => $this->parseMoney($client['credit'] ?? 0),
            'credit_formatted' => (string) ($client['formattedcredit'] ?? ''),
            'stats' => is_array($client['stats'] ?? null) ? $client['stats'] : [],
            'client' => $client,
            'products' => $this->whmcs->fetchClientProducts(100, 0, (int) ($client['id'] ?? 0)),
        ];
    }

    /**
     * @param array<string, mixed> $remoteIdentity
     * @return array<string, mixed>
     */
    public function loginWithAuthenticatedIdentity(array $remoteIdentity): array
    {
        $whmcsClientId = max(0, (int) ($remoteIdentity['whmcs_client_id'] ?? 0));
        if ($whmcsClientId <= 0) {
            throw new ApiException('whmcs_client_not_found', 404, ['message' => 'The authenticated Buy station account has no billing client.']);
        }

        $user = $this->boundUserForClient($whmcsClientId) ?? $this->resolveTargetUser($remoteIdentity);
        $user = $this->activateAuthenticatedTenant($user);

        return $this->bindUserToProfile($user, $remoteIdentity);
    }

    /**
     * @param array<string, mixed> $remoteIdentity
     * @param array<string, mixed>|null $currentUser
     * @return array<string, mixed>
     */
    public function beginLoginChallenge(array $remoteIdentity, ?array $currentUser = null): array
    {
        $targetUser = $this->resolveTargetUser($remoteIdentity, $currentUser);
        $existingBinding = $this->boundUserForClient((int) $remoteIdentity['whmcs_client_id']);

        if ($existingBinding !== null && (int) $existingBinding['id'] !== (int) $targetUser['id']) {
            throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'This Buy station account is already linked to another panel account.']);
        }

        if (
            !empty($targetUser['whmcs_client_id']) &&
            (int) $targetUser['whmcs_client_id'] !== (int) $remoteIdentity['whmcs_client_id']
        ) {
            throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'Your current panel account is already linked to a different Buy station account.']);
        }

        $now = $this->now();
        $publicToken = bin2hex(random_bytes(18));
        $code = $this->generateChallengeCode();
        $expiresAt = $this->future('+' . self::CHALLENGE_TTL_MINUTES . ' minutes');
        $ticket = $this->openVerificationTicket($remoteIdentity, $code);

        $challengeId = $this->db->insert('whmcs_login_challenges', [
            'public_token' => $publicToken,
            'user_id' => $targetUser['id'],
            'whmcs_client_id' => $remoteIdentity['whmcs_client_id'],
            'whmcs_user_id' => $remoteIdentity['whmcs_user_id'],
            'whmcs_email' => $remoteIdentity['email'],
            'verification_code_hash' => hash('sha256', $code),
            'ticket_id' => $ticket['ticket_id'],
            'ticket_tid' => $ticket['ticket_tid'],
            'ticket_access_code' => $ticket['ticket_access_code'],
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $challengeId,
            'public_token' => $publicToken,
            'user' => $targetUser,
            'ticket_id' => $ticket['ticket_id'],
            'ticket_tid' => $ticket['ticket_tid'],
            'ticket_access_code' => $ticket['ticket_access_code'],
            'expires_at' => $expiresAt,
            'email' => $remoteIdentity['email'],
            'display_name' => $remoteIdentity['full_name'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyLoginChallenge(string $publicToken, string $code): array
    {
        $challenge = $this->challengeByPublicToken($publicToken);

        // Claim one attempt atomically before comparing, so parallel guesses
        // cannot get more than MAX_CHALLENGE_ATTEMPTS tries at one code.
        $claimed = $this->db->execute(
            'UPDATE whmcs_login_challenges
             SET failed_attempts = failed_attempts + 1,
                 updated_at = :updated_at
             WHERE id = :id
               AND verified_at IS NULL
               AND failed_attempts < :max_attempts',
            [
                'id' => $challenge['id'],
                'updated_at' => $this->now(),
                'max_attempts' => self::MAX_CHALLENGE_ATTEMPTS,
            ]
        );
        if ($claimed < 1) {
            throw $this->burnedChallengeException();
        }

        $submitted = preg_replace('/\s+/', '', $code) ?? '';
        $codeMatches = hash_equals((string) $challenge['verification_code_hash'], hash('sha256', $submitted));
        if (!$codeMatches || (int) ($challenge['whmcs_client_id'] ?? 0) <= 0) {
            $remaining = self::MAX_CHALLENGE_ATTEMPTS - ((int) ($challenge['failed_attempts'] ?? 0) + 1);
            if ($remaining <= 0) {
                throw $this->burnedChallengeException();
            }
            throw new ApiException('whmcs_verification_code_invalid', 422, [
                'message' => 'The verification code is incorrect. ' . $remaining . ' attempt(s) left before this code is cancelled.',
                'attempts_remaining' => $remaining,
            ]);
        }

        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $challenge['user_id']]);
        if ($user === null) {
            throw new ApiException('whmcs_binding_user_missing', 404, ['message' => 'The panel account waiting for binding no longer exists.']);
        }

        $profile = $this->profileByClientId((int) $challenge['whmcs_client_id']);
        $now = $this->now();
        $boundUser = $this->bindUserToProfile($user, $profile);
        $this->db->execute(
            'UPDATE whmcs_login_challenges
             SET verified_at = :verified_at,
                 failed_attempts = CASE WHEN failed_attempts > 0 THEN failed_attempts - 1 ELSE 0 END,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $challenge['id'],
                'verified_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (!empty($challenge['ticket_id'])) {
            try {
                $this->closeTicket((int) $challenge['ticket_id']);
                $this->db->execute(
                    'UPDATE whmcs_login_challenges
                     SET closed_at = :closed_at,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $challenge['id'],
                        'closed_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            } catch (\Throwable) {
            }
        }

        return $boundUser;
    }

    /**
     * @return array<string, mixed>
     */
    public function challengeDetails(string $publicToken): array
    {
        $challenge = $this->challengeByPublicToken($publicToken);
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $challenge['user_id']]);

        return [
            'challenge' => $challenge,
            'user' => $user,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loginBoundUser(array $remoteIdentity): array
    {
        $user = $this->boundUserForClient((int) $remoteIdentity['whmcs_client_id']);
        if ($user === null) {
            throw new ApiException('whmcs_binding_required', 404, ['message' => 'This Buy station account has not been linked to a panel account yet.']);
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'This account is not active and cannot be signed in with Buy station right now.']);
        }

        $now = $this->now();
        $this->db->execute(
            'UPDATE users
             SET wallet_currency = :wallet_currency,
                 whmcs_user_id = :whmcs_user_id,
                 whmcs_email = :whmcs_email,
                 whmcs_full_name = :whmcs_full_name,
                 whmcs_currency_code = :whmcs_currency_code,
                 whmcs_last_synced_at = :whmcs_last_synced_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $user['id'],
                'wallet_currency' => $remoteIdentity['currency_code'],
                'whmcs_user_id' => $remoteIdentity['whmcs_user_id'],
                'whmcs_email' => $remoteIdentity['email'],
                'whmcs_full_name' => $remoteIdentity['full_name'],
                'whmcs_currency_code' => $remoteIdentity['currency_code'],
                'whmcs_last_synced_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $user['id']]) ?? $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function accountSnapshot(int $userId): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        if ($user === null) {
            throw new ApiException('user_not_found', 404);
        }

        $transactions = $this->db->fetchAll(
            'SELECT * FROM wallet_transactions WHERE user_id = :user_id ORDER BY id DESC LIMIT 20',
            ['user_id' => $userId]
        );

        if (empty($user['whmcs_client_id'])) {
            return [
                'user' => $user,
                'linked' => false,
                'remote' => null,
                'transactions' => $transactions,
            ];
        }

        $profile = $this->profileByClientId((int) $user['whmcs_client_id']);
        $now = $this->now();
        $this->db->execute(
            'UPDATE users
             SET whmcs_user_id = :whmcs_user_id,
                 whmcs_email = :whmcs_email,
                 whmcs_full_name = :whmcs_full_name,
                 whmcs_currency_code = :whmcs_currency_code,
                 whmcs_last_synced_at = :whmcs_last_synced_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $userId,
                'whmcs_user_id' => $profile['whmcs_user_id'],
                'whmcs_email' => $profile['email'],
                'whmcs_full_name' => $profile['full_name'],
                'whmcs_currency_code' => $profile['currency_code'],
                'whmcs_last_synced_at' => $now,
                'updated_at' => $now,
            ]
        );

        $freshUser = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $userId]) ?? $user;

        return [
            'user' => $freshUser,
            'linked' => true,
            'remote' => $profile,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transferBalance(int $userId, string $direction, mixed $rawAmount): array
    {
        $snapshot = $this->accountSnapshot($userId);
        if (empty($snapshot['linked']) || !is_array($snapshot['remote'])) {
            throw new ApiException('whmcs_binding_required', 422, ['message' => 'Please bind your Buy station account before transferring balance.']);
        }

        $amount = $this->normalizeTransferAmount($rawAmount);
        $user = $snapshot['user'];
        $remote = $snapshot['remote'];
        $localBefore = $this->parseMoney($user['wallet_balance'] ?? 0);
        $remoteBefore = $this->parseMoney($remote['credit'] ?? 0);
        $currency = strtoupper(trim((string) ($remote['currency_code'] ?? $user['wallet_currency'] ?? 'CNY')));

        $whmcsClientId = (int) $remote['whmcs_client_id'];
        $now = $this->now();

        if ($direction === 'panel_to_whmcs') {
            // Security: debit the panel wallet ATOMICALLY and BEFORE crediting
            // the remote Buy-station account. The previous order (unlocked check
            // -> remote credit -> absolute panel write) let N concurrent requests
            // from separate sessions each pass the check and each credit WHMCS
            // while the panel balance was written down only once — minting remote
            // credit that pays real invoices. The conditional decrement commits
            // exactly once per request; a 0 row-count means the funds are gone.
            $affected = $this->db->execute(
                'UPDATE users
                 SET wallet_balance = wallet_balance - :amount,
                     wallet_currency = :wallet_currency,
                     whmcs_last_synced_at = :synced_at,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND wallet_balance >= :amount_guard',
                [
                    'id' => $userId,
                    'amount' => $this->money($amount),
                    'amount_guard' => $this->money($amount),
                    'wallet_currency' => $currency,
                    'synced_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ((int) $affected < 1) {
                throw new ApiException('wallet_balance_insufficient', 422, ['message' => 'Your panel balance is not enough for this transfer.']);
            }
            try {
                $this->whmcs->addCredit($whmcsClientId, 'LeiKwan panel balance transfer', $amount, 'add');
            } catch (\Throwable $throwable) {
                // Remote credit failed: refund the panel debit so money is conserved.
                $this->db->execute(
                    'UPDATE users SET wallet_balance = wallet_balance + :amount, updated_at = :updated_at WHERE id = :id',
                    ['id' => $userId, 'amount' => $this->money($amount), 'updated_at' => $this->now()]
                );
                throw $throwable;
            }
            $localAfter = $localBefore - $amount;
            $remoteAfter = $remoteBefore + $amount;
        } elseif ($direction === 'whmcs_to_panel') {
            if ($remoteBefore < $amount) {
                throw new ApiException('whmcs_balance_insufficient', 422, ['message' => 'Your Buy station credit is not enough for this transfer.']);
            }

            // Remove from the remote account first; credit the panel only on success.
            $this->whmcs->addCredit($whmcsClientId, 'LeiKwan panel balance transfer', $amount, 'remove');
            try {
                $this->db->execute(
                    'UPDATE users
                     SET wallet_balance = wallet_balance + :amount,
                         wallet_currency = :wallet_currency,
                         whmcs_last_synced_at = :synced_at,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $userId,
                        'amount' => $this->money($amount),
                        'wallet_currency' => $currency,
                        'synced_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            } catch (\Throwable $throwable) {
                // Panel credit failed: give the removed remote credit back.
                try {
                    $this->whmcs->addCredit($whmcsClientId, 'LeiKwan transfer rollback', $amount, 'add');
                } catch (\Throwable) {
                }
                throw $throwable;
            }
            $localAfter = $localBefore + $amount;
            $remoteAfter = $remoteBefore - $amount;
        } else {
            throw new ApiException('wallet_transfer_invalid_direction', 422, ['message' => 'Unsupported transfer direction.']);
        }

        // Ledger row (best-effort; the balance movement above is authoritative).
        $freshLocal = $this->db->fetchOne('SELECT wallet_balance FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $localAfterActual = $freshLocal !== null ? $this->parseMoney($freshLocal['wallet_balance'] ?? $localAfter) : $localAfter;
        try {
            $this->db->insert('wallet_transactions', [
                'user_id' => $userId,
                'direction' => $direction,
                'amount' => $this->money($amount),
                'currency' => $currency,
                'panel_balance_before' => $this->money($localBefore),
                'panel_balance_after' => $this->money($localAfterActual),
                'whmcs_balance_before' => $this->money($remoteBefore),
                'whmcs_balance_after' => $this->money($remoteAfter),
                'context_json' => json_encode([
                    'whmcs_client_id' => $whmcsClientId,
                    'source' => 'whmcs',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $this->now(),
            ]);
        } catch (\Throwable) {
        }

        return $this->accountSnapshot($userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function boundUserForClientId(int $whmcsClientId): ?array
    {
        return $this->boundUserForClient($whmcsClientId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function boundUserForClient(int $whmcsClientId): ?array
    {
        if ($whmcsClientId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM users WHERE whmcs_client_id = :whmcs_client_id LIMIT 1',
            ['whmcs_client_id' => $whmcsClientId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingUser(int $userId): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        if ($user === null) {
            throw new ApiException('user_not_found', 404);
        }
        if ((string) ($user['role'] ?? '') !== 'tenant') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'Only tenant accounts can be linked with a Buy station account.']);
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'This account is not active and cannot be linked right now.']);
        }
        if (empty($user['email_verified_at'])) {
            throw new ApiException('email_verification_required', 422, ['message' => 'Please finish email verification before binding your Buy station account.']);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileByEmail(string $email): ?array
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return null;
        }

        try {
            $client = $this->whmcs->getClientDetailsByEmail($normalizedEmail, true);
        } catch (ApiException $exception) {
            if ($exception->getMessage() === 'whmcs_client_not_found') {
                return null;
            }

            throw $exception;
        }

        $clientId = max(0, (int) ($client['id'] ?? 0));
        if ($clientId <= 0) {
            return null;
        }

        return [
            'whmcs_user_id' => max(0, (int) ($client['owner_user_id'] ?? 0)),
            'whmcs_client_id' => $clientId,
            'email' => (string) ($client['email'] ?? $normalizedEmail),
            'full_name' => $this->displayNameFromClient($client),
            'company_name' => (string) ($client['companyname'] ?? ''),
            'currency_code' => strtoupper(trim((string) ($client['currency_code'] ?? 'CNY'))),
            'credit' => $this->parseMoney($client['credit'] ?? 0),
            'credit_formatted' => (string) ($client['formattedcredit'] ?? ''),
            'stats' => is_array($client['stats'] ?? null) ? $client['stats'] : [],
            'client' => $client,
            // Product inventory is fetched when the billing snapshot is opened. It is
            // not part of resolving or binding an identity.
            'products' => [],
        ];
    }

    /**
     * UserInfo from the first-party WHMCS adapter can carry the already verified
     * client/owner relationship. Use it before the management API lookup, which may
     * be blocked by WHMCS source-IP allowlisting even when OIDC succeeded.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function profileFromAuthenticatedIdentity(array $identity, array $user): ?array
    {
        $clientId = max(0, (int) ($identity['whmcs_client_id'] ?? 0));
        $ownerId = max(0, (int) ($identity['whmcs_user_id'] ?? 0));
        $identityEmail = strtolower(trim((string) ($identity['email'] ?? '')));
        $userEmail = strtolower(trim((string) ($user['email'] ?? '')));
        if (
            ($identity['email_verified'] ?? null) !== true
            || $clientId <= 0
            || $ownerId <= 0
            || $identityEmail === ''
            || $identityEmail !== $userEmail
        ) {
            return null;
        }

        return [
            'whmcs_user_id' => $ownerId,
            'whmcs_client_id' => $clientId,
            'email' => $identityEmail,
            'full_name' => trim((string) ($identity['name'] ?? '')),
            'company_name' => '',
            'currency_code' => 'CNY',
            'credit' => 0.0,
            'credit_formatted' => '',
            'stats' => [],
            'client' => [],
            'products' => [],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function bindUserToProfile(array $user, array $profile): array
    {
        if ((string) ($user['role'] ?? '') !== 'tenant') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'Only tenant accounts can be linked with a Buy station account.']);
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'This account is not active and cannot be linked right now.']);
        }

        $whmcsClientId = max(0, (int) ($profile['whmcs_client_id'] ?? 0));
        if ($whmcsClientId <= 0) {
            throw new ApiException('whmcs_client_not_found', 404, ['message' => 'The target Buy station account could not be resolved.']);
        }

        $existingBinding = $this->boundUserForClient($whmcsClientId);
        if ($existingBinding !== null && (int) $existingBinding['id'] !== (int) $user['id']) {
            throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'This Buy station account is already linked to another panel account.']);
        }

        if (!empty($user['whmcs_client_id']) && (int) $user['whmcs_client_id'] !== $whmcsClientId) {
            throw new ApiException('whmcs_binding_conflict', 409, ['message' => 'Your panel account is already linked to another Buy station account.']);
        }

        $walletBalance = $this->parseMoney($user['wallet_balance'] ?? 0);
        $walletCurrency = trim((string) ($user['wallet_currency'] ?? ''));
        $profileCurrency = trim((string) ($profile['currency_code'] ?? ''));
        $targetWalletCurrency = ($walletBalance > 0 && $walletCurrency !== '')
            ? strtoupper($walletCurrency)
            : strtoupper($profileCurrency !== '' ? $profileCurrency : ($walletCurrency !== '' ? $walletCurrency : 'CNY'));
        $now = $this->now();

        $this->db->execute(
            'UPDATE users
             SET wallet_currency = :wallet_currency,
                 whmcs_client_id = :whmcs_client_id,
                 whmcs_user_id = :whmcs_user_id,
                 whmcs_email = :whmcs_email,
                 whmcs_full_name = :whmcs_full_name,
                 whmcs_currency_code = :whmcs_currency_code,
                 whmcs_bound_at = COALESCE(whmcs_bound_at, :whmcs_bound_at),
                 whmcs_last_synced_at = :whmcs_last_synced_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $user['id'],
                'wallet_currency' => $targetWalletCurrency,
                'whmcs_client_id' => $whmcsClientId,
                'whmcs_user_id' => max(0, (int) ($profile['whmcs_user_id'] ?? 0)),
                'whmcs_email' => (string) ($profile['email'] ?? ''),
                'whmcs_full_name' => (string) ($profile['full_name'] ?? ''),
                'whmcs_currency_code' => strtoupper(trim((string) ($profile['currency_code'] ?? 'CNY'))),
                'whmcs_bound_at' => $now,
                'whmcs_last_synced_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $user['id']]) ?? $user;
    }

    /**
     * A successful Buy password authentication is sufficient to verify a pending tenant.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function activateAuthenticatedTenant(array $user): array
    {
        if ((string) ($user['role'] ?? '') !== 'tenant') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'Only tenant accounts can sign in with a Buy station account.']);
        }

        $status = (string) ($user['status'] ?? '');
        if ($status === 'active') {
            return $user;
        }
        if ($status !== 'pending_verification') {
            throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'This panel account is not active.']);
        }

        $now = $this->now();
        $this->db->execute(
            'UPDATE users
             SET status = :status,
                 email_verified_at = COALESCE(email_verified_at, :email_verified_at),
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

        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $user['id']]) ?? $user;
    }

    /**
     * @param array<string, mixed> $remoteIdentity
     * @param array<string, mixed>|null $currentUser
     * @return array<string, mixed>
     */
    private function resolveTargetUser(array $remoteIdentity, ?array $currentUser = null): array
    {
        if ($currentUser !== null) {
            if ((string) ($currentUser['role'] ?? '') !== 'tenant') {
                throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'Only tenant accounts can be linked with a Buy station identity.']);
            }

            return $currentUser;
        }

        $existingUser = $this->db->fetchOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower((string) $remoteIdentity['email'])]
        );
        if ($existingUser !== null) {
            if ((string) ($existingUser['role'] ?? '') !== 'tenant') {
                throw new ApiException('whmcs_binding_not_supported', 422, ['message' => 'This email already belongs to a non-tenant panel account. Please use your panel login instead.']);
            }

            return $existingUser;
        }

        $now = $this->now();
        $userId = $this->db->insert('users', [
            'name' => $remoteIdentity['full_name'] !== '' ? $remoteIdentity['full_name'] : $remoteIdentity['email'],
            'email' => strtolower((string) $remoteIdentity['email']),
            'api_token' => bin2hex(random_bytes(24)),
            'password_hash' => null,
            'role' => 'tenant',
            'feature_flags_json' => null,
            'status' => 'active',
            'email_verified_at' => $now,
            'email_verification_token' => null,
            'verification_sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'wallet_balance' => '0.00',
            'wallet_currency' => $remoteIdentity['currency_code'],
            'whmcs_client_id' => null,
            'whmcs_user_id' => null,
            'whmcs_email' => null,
            'whmcs_full_name' => null,
            'whmcs_currency_code' => null,
            'whmcs_bound_at' => null,
            'whmcs_last_synced_at' => null,
        ]);

        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $userId]) ?? [];
    }

    /**
     * @param array<string, mixed> $remoteIdentity
     * @return array<string, mixed>
     */
    private function openVerificationTicket(array $remoteIdentity, string $code): array
    {
        $response = $this->whmcs->openTicket([
            'deptid' => $this->pickDepartmentId(),
            'subject' => '[LeiKwan] Buy station verification code',
            'message' => "A LeiKwan panel binding/login request was just started for this Buy station account.\n\n"
                . "Verification code: {$code}\n"
                . 'This code expires in ' . self::CHALLENGE_TTL_MINUTES . " minutes.\n\n"
                . "If this was not you, please ignore this ticket and contact support.",
            'priority' => 'Low',
            'clientid' => (int) $remoteIdentity['whmcs_client_id'],
            'userid' => (int) $remoteIdentity['whmcs_user_id'],
            'name' => (string) $remoteIdentity['full_name'],
            'email' => (string) $remoteIdentity['email'],
        ]);

        return [
            'ticket_id' => max(0, (int) ($response['id'] ?? 0)),
            'ticket_tid' => (string) ($response['tid'] ?? ''),
            'ticket_access_code' => (string) ($response['c'] ?? ''),
        ];
    }

    private function closeTicket(int $ticketId): void
    {
        if ($ticketId <= 0) {
            return;
        }

        $closedStatus = 'Closed';
        foreach ($this->whmcs->getSupportStatuses() as $status) {
            $title = trim((string) ($status['title'] ?? ''));
            if (strcasecmp($title, 'Closed') === 0) {
                $closedStatus = $title;
                break;
            }
            if ($title !== '' && str_contains(strtolower($title), 'closed')) {
                $closedStatus = $title;
            }
        }

        $this->whmcs->updateTicket($ticketId, ['status' => $closedStatus]);
    }

    private function pickDepartmentId(): int
    {
        $configured = max(0, (int) ($this->config->getInt('WHMCS_SUPPORT_DEPARTMENT_ID', 0) ?? 0));
        if ($configured > 0) {
            return $configured;
        }

        foreach ($this->whmcs->getSupportDepartments() as $department) {
            $id = max(0, (int) ($department['id'] ?? 0));
            if ($id > 0) {
                return $id;
            }
        }

        throw new ApiException('whmcs_support_department_missing', 500, ['message' => 'No WHMCS support department is available for verification tickets.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeByPublicToken(string $publicToken): array
    {
        $challenge = $this->db->fetchOne(
            'SELECT * FROM whmcs_login_challenges WHERE public_token = :public_token LIMIT 1',
            ['public_token' => trim($publicToken)]
        );
        if ($challenge === null) {
            throw new ApiException('whmcs_challenge_not_found', 404, ['message' => 'This Buy station verification request could not be found.']);
        }
        if (!empty($challenge['verified_at'])) {
            throw new ApiException('whmcs_challenge_used', 410, ['message' => 'This Buy station verification code has already been used.']);
        }
        if ((int) ($challenge['failed_attempts'] ?? 0) >= self::MAX_CHALLENGE_ATTEMPTS) {
            throw $this->burnedChallengeException();
        }

        $expiresAt = strtotime((string) ($challenge['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            throw new ApiException('whmcs_challenge_expired', 410, ['message' => 'This Buy station verification code has expired. Please start again from the login page.']);
        }

        return $challenge;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveClientForAuthenticatedUser(string $email, int $whmcsUserId): array
    {
        try {
            $client = $this->whmcs->getClientDetailsByEmail($email, true);
            if ((int) ($client['owner_user_id'] ?? 0) === $whmcsUserId || $whmcsUserId === 0) {
                return $client;
            }
        } catch (\Throwable) {
        }

        foreach ($this->whmcs->getUsers($email) as $user) {
            if ((int) ($user['id'] ?? 0) !== $whmcsUserId && strtolower((string) ($user['email'] ?? '')) !== $email) {
                continue;
            }

            $clients = is_array($user['clients'] ?? null) ? $user['clients'] : [];
            $ownerClientId = 0;
            $firstClientId = 0;
            foreach ($clients as $clientLink) {
                if (!is_array($clientLink)) {
                    continue;
                }
                $clientId = max(0, (int) ($clientLink['id'] ?? 0));
                if ($clientId <= 0) {
                    continue;
                }
                if ($firstClientId === 0) {
                    $firstClientId = $clientId;
                }
                if (!empty($clientLink['isOwner'])) {
                    $ownerClientId = $clientId;
                    break;
                }
            }

            $targetClientId = $ownerClientId > 0 ? $ownerClientId : $firstClientId;
            if ($targetClientId > 0) {
                return $this->whmcs->getClientDetails($targetClientId, true);
            }
        }

        throw new ApiException('whmcs_client_not_found', 404, ['message' => 'The Buy station account was authenticated, but no linked billing client could be resolved.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileByClientId(int $clientId): array
    {
        $client = $this->whmcs->getClientDetails($clientId, true);

        return [
            'whmcs_user_id' => max(0, (int) ($client['owner_user_id'] ?? 0)),
            'whmcs_client_id' => $clientId,
            'email' => (string) ($client['email'] ?? ''),
            'full_name' => $this->displayNameFromClient($client),
            'company_name' => (string) ($client['companyname'] ?? ''),
            'currency_code' => strtoupper(trim((string) ($client['currency_code'] ?? 'CNY'))),
            'credit' => $this->parseMoney($client['credit'] ?? 0),
            'credit_formatted' => (string) ($client['formattedcredit'] ?? ''),
            'stats' => is_array($client['stats'] ?? null) ? $client['stats'] : [],
            'client' => $client,
            'products' => $this->whmcs->fetchClientProducts(100, 0, $clientId),
        ];
    }

    /**
     * @param array<string, mixed> $client
     */
    private function displayNameFromClient(array $client): string
    {
        $company = trim((string) ($client['companyname'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        return trim((string) (($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')));
    }

    private function burnedChallengeException(): ApiException
    {
        return new ApiException('whmcs_challenge_locked', 410, ['message' => 'Too many incorrect verification codes. This code has been cancelled; please request a new one.']);
    }

    /**
     * A challenge row that looks like a real one to the requester but has no
     * ticket and no usable code (whmcs_client_id 0, hash of random bytes).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function decoyChallenge(array $user, string $targetEmail): array
    {
        $now = $this->now();
        $publicToken = bin2hex(random_bytes(18));
        $expiresAt = $this->future('+' . self::CHALLENGE_TTL_MINUTES . ' minutes');
        $challengeId = $this->db->insert('whmcs_login_challenges', [
            'public_token' => $publicToken,
            'user_id' => $user['id'],
            'whmcs_client_id' => 0,
            'whmcs_user_id' => null,
            'whmcs_email' => $targetEmail,
            'verification_code_hash' => hash('sha256', random_bytes(32)),
            'ticket_id' => null,
            'ticket_tid' => null,
            'ticket_access_code' => null,
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'mode' => 'challenge',
            'id' => $challengeId,
            'public_token' => $publicToken,
            'user' => $user,
            'ticket_id' => 0,
            'ticket_tid' => '',
            'ticket_access_code' => '',
            'expires_at' => $expiresAt,
            'email' => $targetEmail,
            'display_name' => '',
        ];
    }

    private function rateLimiter(): AuthRateLimiter
    {
        return $this->rateLimiter ??= new AuthRateLimiter($this->db);
    }

    private function generateChallengeCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function normalizeTransferAmount(mixed $value): float
    {
        $amount = round((float) $value, 2);
        if ($amount <= 0) {
            throw new ApiException('wallet_transfer_invalid_amount', 422, ['message' => 'Please enter a transfer amount greater than 0.']);
        }

        return $amount;
    }

    private function parseMoney(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }
        return round((float) $value, 2);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
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
