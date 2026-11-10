<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $users = [];

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT online_device_limit FROM users WHERE id = :id')) {
                $userId = (int) ($params['id'] ?? 0);
                if (isset($this->users[$userId])) {
                    return ['online_device_limit' => $this->users[$userId]['online_device_limit'] ?? null];
                }
                return null;
            }

            return null;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            if ($table === 'users') {
                $id = (int) ($data['id'] ?? (count($this->users) + 1));
                $this->users[$id] = ['id' => $id] + $data;
                return $id;
            }

            return 1;
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
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

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UserDeviceLimit;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $db = new Database();

    // Test 1: effectiveForClient returns client limit when no owner_user_id.
    $clientNoOwner = ['id' => 1, 'online_device_limit' => 3];
    $effective = UserDeviceLimit::effectiveForClient($clientNoOwner, $db);
    if ($effective !== 3) {
        fail("Test 1: expected effective limit 3 for client with no owner, got $effective");
    }

    // Test 2: effectiveForClient returns user override when owner has one.
    $db->insert('users', ['id' => 10, 'online_device_limit' => 7]);
    $clientWithOwner = ['id' => 2, 'owner_user_id' => 10, 'online_device_limit' => 3];
    $effective = UserDeviceLimit::effectiveForClient($clientWithOwner, $db);
    if ($effective !== 7) {
        fail("Test 2: expected effective limit 7 from user override, got $effective");
    }

    // Test 3: effectiveForClient returns client limit when user override is null.
    $db->insert('users', ['id' => 11, 'online_device_limit' => null]);
    $clientNullOverride = ['id' => 3, 'owner_user_id' => 11, 'online_device_limit' => 5];
    $effective = UserDeviceLimit::effectiveForClient($clientNullOverride, $db);
    if ($effective !== 5) {
        fail("Test 3: expected effective limit 5 (fallback to client) with null override, got $effective");
    }

    // Test 4: effectiveForClient clamps zero override to 1.
    $db->insert('users', ['id' => 12, 'online_device_limit' => 0]);
    $clientZeroOverride = ['id' => 4, 'owner_user_id' => 12, 'online_device_limit' => 2];
    $effective = UserDeviceLimit::effectiveForClient($clientZeroOverride, $db);
    if ($effective !== 1) {
        fail("Test 4: expected effective limit clamped to 1 for zero override, got $effective");
    }

    // Test 5: effectiveForClient returns 1 as default when client limit is missing.
    $clientNoLimit = ['id' => 5, 'owner_user_id' => 0];
    $effective = UserDeviceLimit::effectiveForClient($clientNoLimit, $db);
    if ($effective !== 1) {
        fail("Test 5: expected default limit 1 for client with no limit, got $effective");
    }

    // Test 6: overrideForUser returns null when user has no override.
    $userNoOverride = ['id' => 20];
    $override = UserDeviceLimit::overrideForUser($userNoOverride);
    if ($override !== null) {
        fail("Test 6: expected null for user with no override, got $override");
    }

    // Test 7: overrideForUser returns the override value.
    $userWithOverride = ['id' => 21, 'online_device_limit' => 8];
    $override = UserDeviceLimit::overrideForUser($userWithOverride);
    if ($override !== 8) {
        fail("Test 7: expected override 8, got " . ($override === null ? 'null' : $override));
    }

    // Test 8: overrideForUser returns null when override is null.
    $userNullOverride = ['id' => 22, 'online_device_limit' => null];
    $override = UserDeviceLimit::overrideForUser($userNullOverride);
    if ($override !== null) {
        fail("Test 8: expected null for user with null override, got $override");
    }

    // Test 9: overrideForUser clamps zero to 1.
    $userZeroOverride = ['id' => 23, 'online_device_limit' => 0];
    $override = UserDeviceLimit::overrideForUser($userZeroOverride);
    if ($override !== 1) {
        fail("Test 9: expected override clamped to 1 for zero, got " . ($override === null ? 'null' : $override));
    }

    // Test 10: sourceForClient returns 'user' when user override applies.
    $db->insert('users', ['id' => 30, 'online_device_limit' => 9]);
    $clientUser = ['id' => 6, 'owner_user_id' => 30, 'online_device_limit' => 2];
    $source = UserDeviceLimit::sourceForClient($clientUser, $db);
    if ($source !== 'user') {
        fail("Test 10: expected source 'user', got '$source'");
    }

    // Test 11: sourceForClient returns 'client' when no user override.
    $clientNoUserOverride = ['id' => 7, 'owner_user_id' => 0, 'online_device_limit' => 4];
    $source = UserDeviceLimit::sourceForClient($clientNoUserOverride, $db);
    if ($source !== 'client') {
        fail("Test 11: expected source 'client', got '$source'");
    }

    // Test 12: sourceForClient returns 'client' when user override is null.
    $db->insert('users', ['id' => 31, 'online_device_limit' => null]);
    $clientNullUserOverride = ['id' => 8, 'owner_user_id' => 31, 'online_device_limit' => 6];
    $source = UserDeviceLimit::sourceForClient($clientNullUserOverride, $db);
    if ($source !== 'client') {
        fail("Test 12: expected source 'client' with null user override, got '$source'");
    }

    // Test 13: sourceForClient gracefully handles missing user (defensive fallback).
    $clientMissingUser = ['id' => 9, 'owner_user_id' => 999, 'online_device_limit' => 3];
    $source = UserDeviceLimit::sourceForClient($clientMissingUser, $db);
    if ($source !== 'client') {
        fail("Test 13: expected source 'client' for missing user, got '$source'");
    }

    echo "ok\n";
}
