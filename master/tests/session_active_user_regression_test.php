<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<string, mixed>|null $user */
        public function __construct(private ?array $user) {}

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return $this->user;
        }
    }
}

namespace Leikwan\Master\Support {
    class ApiException extends \RuntimeException {}
}

namespace {
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\SessionAuth;

    require_once __DIR__ . '/../src/Support/HttpSecurity.php';
    require_once __DIR__ . '/../src/Support/SessionAuth.php';

    function fail_session_active_user(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    SessionAuth::boot();
    $_SESSION['auth'] = ['user_id' => 7, 'role' => 'tenant'];
    $inactive = SessionAuth::currentUser(new Database([
        'id' => 7,
        'role' => 'tenant',
        'status' => 'suspended',
    ]));
    if ($inactive !== null || SessionAuth::identity() !== null) {
        fail_session_active_user('inactive user must be logged out of an existing session');
    }

    $_SESSION['auth'] = ['user_id' => 7, 'role' => 'tenant'];
    $active = SessionAuth::currentUser(new Database([
        'id' => 7,
        'role' => 'tenant',
        'status' => 'active',
    ]));
    if (($active['id'] ?? null) !== 7 || SessionAuth::identity() === null) {
        fail_session_active_user('active user session must remain valid');
    }

    SessionAuth::logout();
    echo "ok\n";
}
