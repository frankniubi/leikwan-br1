<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function readSqlFile(string $path): string
{
    if (!is_file($path)) {
        fail('expected SQL file to exist: ' . $path);
    }
    $sql = file_get_contents($path);
    if (!is_string($sql) || $sql === '') {
        fail('failed to read SQL file: ' . $path);
    }
    return $sql;
}

function assertContainsSql(string $sql, string $needle, string $label): void
{
    if (!str_contains($sql, $needle)) {
        fail('missing ' . $label . ': ' . $needle);
    }
}

function tableBlock(string $sql, string $table): string
{
    $pattern = '/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/si';
    if (preg_match($pattern, $sql, $matches) !== 1) {
        fail('missing MySQL CREATE TABLE block for ' . $table);
    }
    return $matches[1];
}

/**
 * @return array<string, string>
 */
function createTableStatements(string $sql, string $label, array $engineRequiredTables): array
{
    $tables = [];
    $offset = 0;
    $pattern = '/CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)\s*\(/i';
    while (preg_match($pattern, $sql, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $table = $matches[1][0];
        if (isset($tables[$table])) {
            fail($label . ' duplicate CREATE TABLE name: ' . $table);
        }

        $statementStart = $matches[0][1];
        $openParenOffset = $statementStart + strlen($matches[0][0]) - 1;
        $closeParenOffset = findMatchingParenthesis($sql, $openParenOffset, $label . ' ' . $table);
        $semicolonOffset = strpos($sql, ';', $closeParenOffset);
        if ($semicolonOffset === false) {
            fail($label . ' ' . $table . ' CREATE TABLE statement is missing semicolon termination');
        }

        $statement = substr($sql, $statementStart, $semicolonOffset - $statementStart + 1);
        $body = substr($sql, $openParenOffset + 1, $closeParenOffset - $openParenOffset - 1);
        assertCreateTableSmoke($statement, $body, $label . ' ' . $table, in_array($table, $engineRequiredTables, true));
        $tables[$table] = $body;
        $offset = $semicolonOffset + 1;
    }

    return $tables;
}

function skipSqlComment(string $sql, int $offset, string $label): ?int
{
    if ($sql[$offset] === '-' && ($sql[$offset + 1] ?? '') === '-') {
        $newlineOffset = strpos($sql, "\n", $offset + 2);
        return $newlineOffset === false ? strlen($sql) - 1 : $newlineOffset;
    }
    if ($sql[$offset] === '/' && ($sql[$offset + 1] ?? '') === '*') {
        $commentEndOffset = strpos($sql, '*/', $offset + 2);
        if ($commentEndOffset === false) {
            fail($label . ' has unterminated block comment');
        }
        return $commentEndOffset + 1;
    }
    return null;
}

function findMatchingParenthesis(string $sql, int $openParenOffset, string $label): int
{
    $depth = 0;
    $quote = null;
    $length = strlen($sql);
    for ($i = $openParenOffset; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === "\\") {
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        $commentEnd = skipSqlComment($sql, $i, $label);
        if ($commentEnd !== null) {
            $i = $commentEnd;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
            if ($depth < 0) {
                fail($label . ' has unbalanced parentheses');
            }
        }
    }

    fail($label . ' has unbalanced parentheses');
}

function assertCreateTableSmoke(string $statement, string $body, string $label, bool $requireEngineCharset): void
{
    if (!str_ends_with(rtrim($statement), ';')) {
        fail($label . ' CREATE TABLE statement is missing semicolon termination');
    }
    if ($requireEngineCharset && !str_ends_with(rtrim($statement), 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;')) {
        fail($label . ' missing required ENGINE/CHARSET semicolon suffix');
    }
    if (preg_match('/,\s*$/', rtrim($body)) === 1) {
        fail($label . ' has trailing comma before closing parenthesis');
    }
    if (parenthesisBalance($statement) !== 0) {
        fail($label . ' has unbalanced parentheses');
    }

    $indexNames = [];
    foreach (splitTopLevelDefinitions($body) as $definition) {
        if (preg_match('/^\s*(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?/i', $definition, $match) !== 1) {
            continue;
        }
        $indexName = strtolower($match[1]);
        if (isset($indexNames[$indexName])) {
            fail($label . ' duplicate KEY/INDEX name: ' . $match[1]);
        }
        $indexNames[$indexName] = true;
    }
}

function parenthesisBalance(string $sql): int
{
    $balance = 0;
    $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === "\\") {
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        $commentEnd = skipSqlComment($sql, $i, 'parenthesis balance');
        if ($commentEnd !== null) {
            $i = $commentEnd;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $balance++;
        } elseif ($char === ')') {
            $balance--;
            if ($balance < 0) {
                return $balance;
            }
        }
    }
    return $balance;
}

/**
 * @return list<string>
 */
function splitTopLevelDefinitions(string $body): array
{
    $definitions = [];
    $start = 0;
    $depth = 0;
    $quote = null;
    $length = strlen($body);
    for ($i = 0; $i < $length; $i++) {
        $char = $body[$i];
        if ($quote !== null) {
            if ($char === "\\") {
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        $commentEnd = skipSqlComment($body, $i, 'definition split');
        if ($commentEnd !== null) {
            $i = $commentEnd;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            $definition = trim(substr($body, $start, $i - $start));
            if ($definition !== '') {
                $definitions[] = $definition;
            }
            $start = $i + 1;
        }
    }

    $definition = trim(substr($body, $start));
    if ($definition !== '') {
        $definitions[] = $definition;
    }
    return $definitions;
}

$repoRoot = dirname(__DIR__, 2);
$files = [
    'migration' => $repoRoot . '/master/migrations/20260719_add_leigod_only_activation_rollout.sql',
    'schema mirror' => $repoRoot . '/master/migrations/schema.sql',
];

$tables = [
    'leigod_only_activation_settings',
    'leigod_only_activation_plans',
    'leigod_only_activation_batches',
    'leigod_only_activation_assignments',
    'leigod_only_activation_assignment_current_slots',
    'leigod_only_forward_desired_state_fences',
    'leigod_only_artifact_pointer_snapshots',
    'leigod_only_artifact_pointer_states',
    'leigod_only_fenced_publish_jobs',
    'leigod_only_rollout_locks',
    'leigod_only_canary_campaigns',
    'leigod_only_canary_forward_status',
    'leigod_only_activation_audit_events',
    'leigod_only_normalization_activation_events',
    'leigod_only_retirement_activation_records',
    'forward_retirement_cleanup_leases',
];

foreach ($files as $label => $path) {
    $sql = readSqlFile($path);
    $createTables = createTableStatements($sql, $label, $tables);
    foreach ($tables as $table) {
        if (!array_key_exists($table, $createTables)) {
            fail($label . ' static DDL smoke parser did not find required table: ' . $table);
        }
    }

    $fencedTables = array_values(array_filter($tables, static fn(string $table): bool => $table !== 'leigod_only_normalization_activation_events'));
    foreach ($fencedTables as $table) {
        $block = tableBlock($sql, $table);
        assertContainsSql($block, 'owner_token CHAR(64) NOT NULL', $label . ' owner_token for ' . $table);
        assertContainsSql($block, 'fencing_token CHAR(64) NOT NULL', $label . ' fencing_token for ' . $table);
    }

    $plans = tableBlock($sql, 'leigod_only_activation_plans');
    assertContainsSql($plans, 'write_hash CHAR(64) NOT NULL', $label . ' activation plan write hash');
    assertContainsSql($plans, 'desired_state_hash CHAR(64) NOT NULL', $label . ' activation plan desired-state hash');

    assertContainsSql(tableBlock($sql, 'leigod_only_activation_batches'), 'affected_forward_ids_json JSON NOT NULL', $label . ' affected forward ids on activation batches');
    assertContainsSql(tableBlock($sql, 'leigod_only_activation_batches'), 'external_authorization_ref VARCHAR(191) NULL', $label . ' nullable external authorization ref on batches');

    $assignments = tableBlock($sql, 'leigod_only_activation_assignments');
    foreach ([
        'client_id BIGINT UNSIGNED NULL',
        'client_device_session_id BIGINT UNSIGNED NULL',
        'protocol VARCHAR(16) NOT NULL DEFAULT \'tcp\'',
        'current_slot_key VARCHAR(191) NULL',
        'assignment_hash CHAR(64) NOT NULL',
        'desired_leg_set_hash CHAR(64) NOT NULL',
        'active_family VARCHAR(32) NOT NULL DEFAULT \'standalone_bond\'',
        'runtime_contract_json JSON NOT NULL',
        'row_expires_at DATETIME NOT NULL DEFAULT \'2099-01-01 00:00:00\'',
    ] as $needle) {
        assertContainsSql($assignments, $needle, $label . ' assignment current-slot column');
    }
    assertContainsSql($assignments, 'UNIQUE KEY uniq_leigod_activation_assignment_current_slot (current_slot_key)', $label . ' assignment current-slot supersession key');
    assertContainsSql($assignments, 'INDEX idx_leigod_activation_assignment_slot_identity (scope, forward_id, client_id, client_device_session_id, current_slot_key)', $label . ' assignment current-slot identity index');

    $currentSlots = tableBlock($sql, 'leigod_only_activation_assignment_current_slots');
    foreach ([
        'slot_key VARCHAR(191) NOT NULL',
        'client_id BIGINT UNSIGNED NULL',
        'client_device_session_id BIGINT UNSIGNED NULL',
        'protocol VARCHAR(16) NOT NULL DEFAULT \'tcp\'',
        'assignment_hash CHAR(64) NOT NULL',
        'desired_leg_set_hash CHAR(64) NOT NULL',
        'active_family VARCHAR(32) NOT NULL DEFAULT \'standalone_bond\'',
        'write_hash CHAR(64) NOT NULL',
    ] as $needle) {
        assertContainsSql($currentSlots, $needle, $label . ' current-slot service column');
    }
    assertContainsSql($currentSlots, 'PRIMARY KEY (slot_key)', $label . ' current slot primary key');
    assertContainsSql($currentSlots, 'INDEX idx_leigod_current_slot_identity (scope, forward_id, client_id, client_device_session_id)', $label . ' current slot identity index');

    $desiredState = tableBlock($sql, 'leigod_only_forward_desired_state_fences');
    foreach ([
        'forward_id BIGINT UNSIGNED NOT NULL',
        'client_id BIGINT UNSIGNED NULL',
        'desktop_client_session_id BIGINT UNSIGNED NULL',
        'client_device_session_id BIGINT UNSIGNED NULL',
        'desired_generation BIGINT UNSIGNED NOT NULL',
        'desired_state_hash CHAR(64) NOT NULL',
        'artifact_snapshot_hash CHAR(64) NOT NULL',
        'state VARCHAR(32) NOT NULL',
        'current_slot_key VARCHAR(191) NULL',
        'write_hash CHAR(64) NOT NULL',
        'superseded_at DATETIME NULL',
    ] as $needle) {
        assertContainsSql($desiredState, $needle, $label . ' desired-state fence column');
    }

    $snapshots = tableBlock($sql, 'leigod_only_artifact_pointer_snapshots');
    assertContainsSql($snapshots, 'artifact_snapshot_hash CHAR(64) NOT NULL', $label . ' artifact snapshot hash');
    assertContainsSql($snapshots, 'snapshot_immutable TINYINT(1) NOT NULL DEFAULT 1', $label . ' immutable artifact snapshots');

    $pointerState = tableBlock($sql, 'leigod_only_artifact_pointer_states');
    foreach ([
        'forward_id BIGINT UNSIGNED NOT NULL',
        'client_id BIGINT UNSIGNED NULL',
        'desktop_client_session_id BIGINT UNSIGNED NULL',
        'client_device_session_id BIGINT UNSIGNED NULL',
        'desired_generation BIGINT UNSIGNED NOT NULL',
        'desired_state_hash CHAR(64) NOT NULL',
        'artifact_snapshot_hash CHAR(64) NOT NULL',
        'status VARCHAR(32) NOT NULL',
        'current_slot_key VARCHAR(191) NULL',
        'write_hash CHAR(64) NOT NULL',
        'superseded_at DATETIME NULL',
    ] as $needle) {
        assertContainsSql($pointerState, $needle, $label . ' artifact-pointer state column');
    }
    assertContainsSql($pointerState, 'UNIQUE KEY uniq_leigod_pointer_state_current_slot (current_slot_key)', $label . ' artifact-pointer current-slot supersession key');
    assertContainsSql($pointerState, 'INDEX idx_leigod_pointer_state_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key)', $label . ' artifact-pointer current-slot identity index');

    $publish = tableBlock($sql, 'leigod_only_fenced_publish_jobs');
    foreach ([
        'job_hash CHAR(64) NOT NULL',
        'forward_id BIGINT UNSIGNED NOT NULL',
        'client_id BIGINT UNSIGNED NULL',
        'client_device_session_id BIGINT UNSIGNED NULL',
        'desktop_client_session_id BIGINT UNSIGNED NULL',
        'current_slot_key VARCHAR(191) NULL',
        'desired_generation BIGINT UNSIGNED NOT NULL',
        'desired_state_hash CHAR(64) NOT NULL',
        'publish_fence_token CHAR(64) NOT NULL',
        'write_hash CHAR(64) NOT NULL',
    ] as $needle) {
        assertContainsSql($publish, $needle, $label . ' fenced publish queue column');
    }
    assertContainsSql($publish, 'INDEX idx_leigod_publish_job_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key)', $label . ' fenced publish identity index');

    $normalizationEvents = tableBlock($sql, 'leigod_only_normalization_activation_events');
    foreach ([
        'forward_id BIGINT UNSIGNED NOT NULL',
        'plan_hash CHAR(64) NOT NULL',
        'event_type VARCHAR(64) NOT NULL',
        'artifact_snapshot_hash CHAR(64) NOT NULL',
        'owner_token CHAR(64) NOT NULL',
        'fencing_token CHAR(64) NOT NULL',
        'UNIQUE KEY uniq_leigod_normalization_event (forward_id, plan_hash, event_type)',
        'INDEX idx_leigod_normalization_event_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash)',
    ] as $needle) {
        assertContainsSql($normalizationEvents, $needle, $label . ' normalization activation event column/index');
    }

    $retirementRecords = tableBlock($sql, 'leigod_only_retirement_activation_records');
    foreach ([
        'record_hash CHAR(64) NOT NULL',
        'retirement_state VARCHAR(32) NOT NULL DEFAULT \'planned\'',
        'desired_generation BIGINT UNSIGNED NOT NULL',
        'desired_state_hash CHAR(64) NOT NULL',
        'record_json JSON NOT NULL',
        'UNIQUE KEY uniq_leigod_retirement_record_hash (record_hash)',
    ] as $needle) {
        assertContainsSql($retirementRecords, $needle, $label . ' retirement activation record column/index');
    }

    $cleanupLeases = tableBlock($sql, 'forward_retirement_cleanup_leases');
    foreach ([
        'lease_token CHAR(64) NOT NULL',
        'result_token CHAR(64) NOT NULL',
        'cleanup_protocol VARCHAR(64) NOT NULL DEFAULT \'forward_retirement_v1\'',
        'resources_json JSON NOT NULL',
        'result_json JSON NULL',
        'completed_at DATETIME NULL',
        'UNIQUE KEY uniq_forward_retirement_cleanup_lease (forward_id, lease_token, result_token)',
        'INDEX idx_forward_retirement_cleanup_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash)',
    ] as $needle) {
        assertContainsSql($cleanupLeases, $needle, $label . ' retirement cleanup lease column/index');
    }

    foreach ([
        'INDEX idx_leigod_activation_plan_fence (scope, owner_token, fencing_token, artifact_snapshot_hash)',
        'INDEX idx_leigod_activation_plan_write_hash (write_hash)',
        'INDEX idx_leigod_canary_campaign_fence (scope, owner_token, fencing_token, artifact_snapshot_hash)',
        'INDEX idx_leigod_canary_status_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash)',
        'INDEX idx_leigod_rollout_lock_exact_fence (scope, lock_key, owner_token, fencing_token)',
        'INDEX idx_leigod_publish_exact_fence (forward_id, owner_token, fencing_token, publish_fence_token, desired_generation)',
        'INDEX idx_leigod_desired_state_exact_fence (forward_id, owner_token, fencing_token, desired_generation)',
        'INDEX idx_leigod_desired_state_sessions (client_id, desktop_client_session_id, client_device_session_id)',
        'INDEX idx_leigod_desired_state_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key)',
        'INDEX idx_leigod_pointer_state_exact_fence (forward_id, owner_token, fencing_token, desired_generation)',
        'INDEX idx_leigod_pointer_state_sessions (client_id, desktop_client_session_id, client_device_session_id)',
        'INDEX idx_leigod_pointer_state_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key)',
    ] as $needle) {
        assertContainsSql($sql, $needle, $label . ' required exact fence index');
    }
}

echo "ok\n";
