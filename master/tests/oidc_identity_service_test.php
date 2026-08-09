<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        private array $users = [];
        /** @var array<int, array<string, mixed>> */
        private array $identities = [];
        private int $nextUserId = 1;
        private int $nextIdentityId = 1;

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $query = strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
            if (str_contains($query, 'from oidc_identities where issuer_hash')) {
                foreach ($this->identities as $identity) {
                    if ($identity['issuer_hash'] === ($params['issuer_hash'] ?? null) && $identity['subject_hash'] === ($params['subject_hash'] ?? null)) {
                        return $identity;
                    }
                }
                return null;
            }
            if (str_contains($query, 'from oidc_identities where user_id') && isset($params['issuer_hash'])) {
                foreach ($this->identities as $identity) {
                    if ((int) $identity['user_id'] === (int) ($params['user_id'] ?? 0) && $identity['issuer_hash'] === $params['issuer_hash']) {
                        return $identity;
                    }
                }
                return null;
            }
            if (str_contains($query, 'from oidc_identities where id')) {
                return $this->identities[(int) ($params['id'] ?? 0)] ?? null;
            }
            if (str_contains($query, 'from oidc_identities where user_id')) {
                foreach ($this->identities as $identity) {
                    if ((int) $identity['user_id'] === (int) ($params['id'] ?? $params['user_id'] ?? 0)) {
                        return $identity;
                    }
                }
                return null;
            }
            if (str_contains($query, 'from oidc_identities')) {
                return array_values($this->identities)[0] ?? null;
            }
            if (str_contains($query, 'from users where email')) {
                foreach ($this->users as $user) {
                    if ($user['email'] === ($params['email'] ?? null)) {
                        return $user;
                    }
                }
                return null;
            }
            if (str_contains($query, 'from users where api_token')) {
                foreach ($this->users as $user) {
                    if ($user['api_token'] === ($params['api_token'] ?? null)) {
                        return $user;
                    }
                }
                return null;
            }
            if (str_contains($query, 'from users where id')) {
                return $this->users[(int) ($params['id'] ?? 0)] ?? null;
            }
            return null;
        }

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $query = strtolower($sql);
            if (str_contains($query, 'from users')) {
                return array_values($this->users);
            }
            if (str_contains($query, 'from oidc_identities')) {
                return array_values($this->identities);
            }
            return [];
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            if ($table === 'users') {
                $id = $this->nextUserId++;
                $data['id'] = $id;
                $this->users[$id] = $data;
                return $id;
            }
            if ($table === 'oidc_identities') {
                $id = $this->nextIdentityId++;
                $data['id'] = $id;
                $this->identities[$id] = $data;
                return $id;
            }
            throw new \RuntimeException('unexpected table ' . $table);
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $query = strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
            if (str_contains($query, 'update users set status')) {
                $id = (int) $params['id'];
                $this->users[$id] = array_replace($this->users[$id], [
                    'status' => $params['status'],
                    'email_verified_at' => $params['email_verified_at'],
                    'email_verification_token' => null,
                    'verification_sent_at' => null,
                    'updated_at' => $params['updated_at'],
                ]);
                return 1;
            }
            if (str_contains($query, 'update users set email_verified_at')) {
                $id = (int) $params['id'];
                $this->users[$id] = array_replace($this->users[$id], [
                    'email_verified_at' => $params['email_verified_at'],
                    'updated_at' => $params['updated_at'],
                ]);
                return 1;
            }
            if (str_contains($query, 'update users set email')) {
                $id = (int) $params['id'];
                $this->users[$id] = array_replace($this->users[$id], [
                    'email' => $params['email'],
                    'email_verified_at' => $params['email_verified_at'],
                    'updated_at' => $params['updated_at'],
                ]);
                return 1;
            }
            if (str_contains($query, 'update oidc_identities')) {
                $id = (int) $params['id'];
                $this->identities[$id] = array_replace($this->identities[$id], [
                    'email' => $params['email'],
                    'email_verified_at' => $params['email_verified_at'],
                    'last_login_at' => $params['last_login_at'],
                    'updated_at' => $params['updated_at'],
                ]);
                return 1;
            }
            throw new \RuntimeException('unexpected execute: ' . $sql);
        }

        public function transaction(callable $callback): mixed
        {
            $snapshot = [$this->users, $this->identities, $this->nextUserId, $this->nextIdentityId];
            try {
                return $callback($this);
            } catch (\Throwable $exception) {
                [$this->users, $this->identities, $this->nextUserId, $this->nextIdentityId] = $snapshot;
                throw $exception;
            }
        }
    }
}

namespace {
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\OidcIdentityService;

require_once __DIR__ . '/../src/Support/ApiException.php';
require_once __DIR__ . '/../src/Support/OidcIdentityService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $actual, string $message): void
{
    if (!$actual) {
        fail($message);
    }
}

function assertApiException(string $message, callable $callback): void
{
    try {
        $callback();
    } catch (ApiException) {
        return;
    }

    fail($message);
}

/** @return array{0: Database, 1: OidcIdentityService} */
function makeService(): array
{
    $db = new Database();
    $service = new OidcIdentityService($db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-27 12:34:56+00:00'));
    return [$db, $service];
}

/** @param array<string, mixed> $overrides */
function claims(array $overrides = []): array
{
    return array_replace([
        'issuer' => 'https://idp.example.test/',
        'subject' => 'sub-123',
        'email' => 'Tenant@Example.test',
        'email_verified' => true,
        'name' => 'Tenant Name',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function insertUser(Database $db, array $overrides = []): int
{
    return $db->insert('users', array_replace([
        'name' => 'Existing Tenant',
        'email' => 'tenant@example.test',
        'api_token' => bin2hex(random_bytes(24)),
        'password_hash' => null,
        'role' => 'tenant',
        'feature_flags_json' => null,
        'status' => 'active',
        'email_verified_at' => null,
        'email_verification_token' => null,
        'verification_sent_at' => null,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ], $overrides));
}

// A verified identity with no local account creates an active tenant and binding.
[$db, $service] = makeService();
$user = $service->login(claims());
assertSame('tenant@example.test', $user['email'], 'new user email is normalized');
assertSame('active', $user['status'], 'new user is active');
assertSame('tenant', $user['role'], 'new user is a tenant');
assertSame(null, $user['password_hash'], 'OIDC user has no local password');
assertSame('2026-07-27 12:34:56', $user['email_verified_at'], 'new user is email verified');
assertTrue(strlen((string) $user['api_token']) >= 32, 'new user gets an API token');
$identity = $db->fetchOne('SELECT * FROM oidc_identities WHERE user_id = :id', ['id' => $user['id']]);
assertSame('tenant@example.test', $identity['email'] ?? null, 'identity stores normalized email');
assertSame(hash('sha256', 'https://idp.example.test/'), $identity['issuer_hash'] ?? null, 'identity hashes issuer');
assertSame(hash('sha256', 'sub-123'), $identity['subject_hash'] ?? null, 'identity hashes subject');

// Matching active tenant links instead of creating another account.
[$db, $service] = makeService();
$existingId = insertUser($db, ['email' => 'tenant@example.test']);
$user = $service->login(claims());
assertSame($existingId, (int) $user['id'], 'active tenant email is linked');
assertSame('2026-07-27 12:34:56', $user['email_verified_at'], 'active tenant link records verified email time');
assertSame(1, count($db->fetchAll('SELECT * FROM users')), 'linking does not create another user');
assertSame($existingId, (int) ($db->fetchOne('SELECT user_id FROM oidc_identities LIMIT 1')['user_id'] ?? 0), 'identity belongs to matched tenant');

// Pending tenant becomes active when a verified OIDC email proves ownership.
[$db, $service] = makeService();
$pendingId = insertUser($db, ['status' => 'pending_verification', 'email' => 'tenant@example.test']);
$user = $service->login(claims());
assertSame($pendingId, (int) $user['id'], 'pending tenant is retained');
assertSame('active', $user['status'], 'pending tenant is activated');
assertSame('2026-07-27 12:34:56', $user['email_verified_at'], 'activation records verification time');

// Returning identities may update their tenant email when it is unclaimed.
[$db, $service] = makeService();
$userId = insertUser($db, ['email' => 'old@example.test']);
$first = $service->login(claims(['email' => 'old@example.test']));
$updated = $service->login(claims(['email' => 'New@Example.test']));
assertSame($userId, (int) $updated['id'], 'returning identity remains attached to its tenant');
assertSame('new@example.test', $updated['email'], 'returning identity updates tenant email');
$identity = $db->fetchOne('SELECT * FROM oidc_identities WHERE user_id = :id', ['id' => $first['id']]);
assertSame('new@example.test', $identity['email'] ?? null, 'returning identity updates stored email');
assertSame('2026-07-27 12:34:56', $identity['last_login_at'] ?? null, 'returning identity records login time');

// A claimed email cannot be reassigned through an existing identity.
[$db, $service] = makeService();
$ownerId = insertUser($db, ['email' => 'owner@example.test']);
$otherId = insertUser($db, ['email' => 'other@example.test']);
$service->login(claims(['email' => 'owner@example.test']));
assertApiException('existing identity email collision must be rejected', static fn() => $service->login(claims(['email' => 'other@example.test'])));
assertSame('owner@example.test', $db->fetchOne('SELECT email FROM users WHERE id = :id', ['id' => $ownerId])['email'] ?? null, 'collision leaves owner email unchanged');
assertSame('other@example.test', $db->fetchOne('SELECT email FROM users WHERE id = :id', ['id' => $otherId])['email'] ?? null, 'collision leaves conflicting user unchanged');

// Administrator accounts must never gain OIDC bindings.
[$db, $service] = makeService();
insertUser($db, ['email' => 'tenant@example.test', 'role' => 'admin']);
assertApiException('admin email must be rejected', static fn() => $service->login(claims()));
assertSame(0, count($db->fetchAll('SELECT * FROM oidc_identities')), 'admin rejection creates no identity');

// Disabled tenants cannot use OIDC even with a verified provider email.
[$db, $service] = makeService();
insertUser($db, ['email' => 'tenant@example.test', 'status' => 'disabled']);
assertApiException('disabled tenant must be rejected', static fn() => $service->login(claims()));
assertSame(0, count($db->fetchAll('SELECT * FROM oidc_identities')), 'disabled rejection creates no identity');

// One tenant cannot obtain multiple subjects for the same issuer.
[$db, $service] = makeService();
insertUser($db, ['email' => 'tenant@example.test']);
$service->login(claims());
assertApiException('second subject for one issuer must be rejected', static fn() => $service->login(claims(['subject' => 'sub-456'])));
assertSame(1, count($db->fetchAll('SELECT * FROM oidc_identities')), 'second subject rejection preserves original binding only');

// Unverified provider email claims are never accepted.
[$db, $service] = makeService();
assertApiException('unverified email must be rejected', static fn() => $service->login(claims(['email_verified' => false])));
assertSame(0, count($db->fetchAll('SELECT * FROM users')), 'unverified claim creates no user');

echo "ok\n";
}
