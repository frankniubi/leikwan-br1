<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;

require_once __DIR__ . '/../src/Bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$args = [];
for ($i = 1; $i < $argc; $i += 2) {
    $key = $argv[$i] ?? '';
    $value = $argv[$i + 1] ?? '';
    if (str_starts_with($key, '--')) {
        $args[substr($key, 2)] = $value;
    }
}

$email = strtolower(trim((string) ($args['email'] ?? '')));
$password = (string) ($args['password'] ?? '');
$role = (string) ($args['role'] ?? 'tenant');
$name = (string) ($args['name'] ?? $email);
$status = (string) ($args['status'] ?? 'active');

if ($email === '' || $password === '' || $name === '' || !in_array($role, ['admin', 'tenant'], true)) {
    fwrite(STDERR, "Usage: php master/scripts/create_user.php --email admin@example.com --password ChangeMe123! --role admin --name \"Platform Admin\" [--status active]\n");
    exit(1);
}

$bootstrap = new Bootstrap(__DIR__ . '/../.env');
$db = $bootstrap->db();
$existing = $db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

if ($existing === null) {
    $id = $db->insert('users', [
        'name' => $name,
        'email' => $email,
        'api_token' => bin2hex(random_bytes(24)),
        'password_hash' => $passwordHash,
        'role' => $role,
        'status' => $status,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    echo "created user id={$id} role={$role} email={$email}\n";
    exit(0);
}

$db->execute(
    'UPDATE users
     SET name = :name,
         password_hash = :password_hash,
         role = :role,
         status = :status,
         updated_at = :updated_at
     WHERE id = :id',
    [
        'id' => $existing['id'],
        'name' => $name,
        'password_hash' => $passwordHash,
        'role' => $role,
        'status' => $status,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]
);

echo "updated user id={$existing['id']} role={$role} email={$email}\n";
