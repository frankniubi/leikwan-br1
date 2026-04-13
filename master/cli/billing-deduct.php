<?php
declare(strict_types=1);

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $baseDir . '/src/Bootstrap.php';

use Leikwan\Master\Bootstrap;

$envPath = $baseDir . '/.env';
if (!is_file($envPath)) {
    echo "Error: .env not found at {$envPath}\n";
    exit(1);
}

$app = new Bootstrap($envPath);
$provisioning = $app->provisioning();

$db = $app->db();

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$hourAgo = $now->modify('-1 hour');
$hourStart = $hourAgo->format('Y-m-d H:00:00');
$hourEnd = $now->format('Y-m-d H:00:00');

echo "[billing-deduct] Running for hour {$hourStart} to {$hourEnd}\n";

// Get all users who have active services
$users = $db->fetchAll(
    'SELECT DISTINCT u.id, u.wallet_balance, u.wallet_low_balance_threshold, u.wallet_warning_sent, u.wallet_currency
     FROM users u
     INNER JOIN services s ON s.user_id = u.id
     WHERE s.status = :status',
    ['status' => 'active']
);

$totalDeducted = 0;
$usersProcessed = 0;

foreach ($users as $user) {
    $userId = (int) $user['id'];
    $currency = strtoupper(trim((string) ($user['wallet_currency'] ?? 'CNY')));

    // Sum traffic per node for this user's services in the last hour
    // NOTE: Use period_start (not hour_start) as that's the column name in schema
    $trafficRows = $db->fetchAll(
        'SELECT t.node_id, SUM(t.bytes_in + t.bytes_out) AS total_bytes
         FROM traffic_usage_hourly t
         INNER JOIN service_endpoints e ON e.id = t.endpoint_id
         INNER JOIN forwards f ON f.id = e.forward_id
         INNER JOIN services s ON s.id = f.service_id
         WHERE s.user_id = :user_id
           AND s.status = :status
           AND t.period_start >= :hour_start
           AND t.period_start < :hour_end
         GROUP BY t.node_id',
        [
            'user_id' => $userId,
            'status' => 'active',
            'hour_start' => $hourStart,
            'hour_end' => $hourEnd,
        ]
    );

    $totalCost = 0.0;
    $breakdown = [];

    foreach ($trafficRows as $row) {
        $nodeId = (int) ($row['node_id'] ?? 0);
        $bytes = (int) ($row['total_bytes'] ?? 0);
        if ($bytes <= 0 || $nodeId <= 0) {
            continue;
        }

        // Check if node is user-owned (free)
        $node = $db->fetchOne(
            'SELECT owner_service_id FROM nodes WHERE id = :id LIMIT 1',
            ['id' => $nodeId]
        );
        if ($node !== null && (int) ($node['owner_service_id'] ?? 0) > 0) {
            continue; // User-owned node, free
        }

        $price = $provisioning->resolveTrafficPrice($nodeId, 'slave');
        if ($price === null) {
            continue; // No price configured
        }

        $gb = $bytes / (1024 * 1024 * 1024);
        $cost = $gb * (float) $price['price_per_gb'];
        $totalCost += $cost;
        $breakdown[] = [
            'node_id' => $nodeId,
            'bytes' => $bytes,
            'price_per_gb' => $price['price_per_gb'],
            'currency' => $price['currency'],
            'cost' => round($cost, 4),
        ];
    }

    if ($totalCost <= 0) {
        continue;
    }

    $usersProcessed++;
    $totalDeducted += $totalCost;

    // Deduct in transaction
    $db->transaction(function () use ($db, $userId, $totalCost, $breakdown, $currency, $hourStart) {
        $beforeUser = $db->fetchOne('SELECT wallet_balance FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $balanceBefore = (float) ($beforeUser['wallet_balance'] ?? 0);

        // Deduct
        $db->execute(
            'UPDATE users SET wallet_balance = wallet_balance - :cost WHERE id = :id',
            ['cost' => round($totalCost, 2), 'id' => $userId]
        );

        $afterUser = $db->fetchOne('SELECT wallet_balance, wallet_low_balance_threshold, wallet_warning_sent FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $balanceAfter = (float) ($afterUser['wallet_balance'] ?? 0);
        $threshold = (float) ($afterUser['wallet_low_balance_threshold'] ?? 5.00);
        $warningSent = (int) ($afterUser['wallet_warning_sent'] ?? 0);

        // Record transaction — use the correct column names
        $db->insert('wallet_transactions', [
            'user_id' => $userId,
            'direction' => 'traffic_deduction',
            'amount' => -round($totalCost, 2),
            'currency' => $currency,
            'panel_balance_before' => round($balanceBefore, 2),
            'panel_balance_after' => round($balanceAfter, 2),
            'context_json' => json_encode(['breakdown' => $breakdown, 'hour' => $hourStart]),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);

        // Low balance warning
        if ($balanceAfter <= $threshold && $warningSent === 0) {
            $db->execute(
                'UPDATE users SET wallet_warning_sent = 1 WHERE id = :id',
                ['id' => $userId]
            );
        }

        // Auto-stop on zero balance
        if ($balanceAfter <= 0) {
            $db->execute(
                'UPDATE forwards SET enabled = 0
                 WHERE service_id IN (SELECT id FROM services WHERE user_id = :user_id AND status = :status)',
                ['user_id' => $userId, 'status' => 'active']
            );

            $db->insert('audit_logs', [
                'action' => 'auto_suspend_insufficient_balance',
                'context_json' => json_encode(['user_id' => $userId, 'balance' => $balanceAfter, 'hour' => $hourStart]),
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);
        }
    });
}

echo "[billing-deduct] Processed {$usersProcessed} users, total deducted: {$totalDeducted}\n";
