<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';

use PDOException;
use Throwable;

final class LeigodOnlyRolloutLockService
{
    public function __construct(private object $db) {}

    public function acquire(string $scope, string $lockKey, string $ownerToken, string $fencingToken, string $artifactSnapshotHash, int $ttlSeconds, ?int $forwardId = null, ?string $externalAuthorizationRef = null): void
    {
        self::requireFence($scope, $ownerToken, $fencingToken);
        self::requireHash($artifactSnapshotHash, 'artifact_snapshot_hash');
        $existing = $this->db->fetchOne('SELECT * FROM leigod_only_rollout_locks WHERE scope = :scope AND lock_key = :lock_key LIMIT 1', ['scope'=>$scope,'lock_key'=>$lockKey]);
        if ($existing === null) {
            try {
                $this->db->insert('leigod_only_rollout_locks', [
                    'scope'=>$scope,'lock_key'=>$lockKey,'forward_id'=>$forwardId,'lock_state'=>'held','artifact_snapshot_hash'=>$artifactSnapshotHash,
                    'owner_token'=>$ownerToken,'fencing_token'=>$fencingToken,'write_hash'=>$this->writeHash($scope,$lockKey,$ownerToken,$fencingToken,$artifactSnapshotHash),
                    'external_authorization_ref'=>$externalAuthorizationRef,'lease_expires_at'=>$this->expiry($ttlSeconds),
                ]);
                return;
            } catch (Throwable $throwable) {
                if ($this->isDuplicateRolloutLock($throwable)) {
                    throw new \RuntimeException('rollout_lock_held scope=' . $scope . ' lock_key=' . $lockKey, 0, $throwable);
                }
                throw $throwable;
            }
        }
        if ($this->isHeldUnexpired($existing)) {
            throw new \RuntimeException('rollout_lock_held scope=' . $scope . ' lock_key=' . $lockKey);
        }
        $count = $this->db->execute(
            "UPDATE leigod_only_rollout_locks SET lock_state = 'held', owner_token = :owner_token, fencing_token = :fencing_token, artifact_snapshot_hash = :artifact_snapshot_hash, write_hash = :write_hash, lease_expires_at = :lease_expires_at, external_authorization_ref = :external_authorization_ref WHERE scope = :scope AND lock_key = :lock_key AND (lock_state = 'released' OR lease_expires_at <= CURRENT_TIMESTAMP)",
            ['scope'=>$scope,'lock_key'=>$lockKey,'owner_token'=>$ownerToken,'fencing_token'=>$fencingToken,'artifact_snapshot_hash'=>$artifactSnapshotHash,'write_hash'=>$this->writeHash($scope,$lockKey,$ownerToken,$fencingToken,$artifactSnapshotHash),'lease_expires_at'=>$this->expiry($ttlSeconds),'external_authorization_ref'=>$externalAuthorizationRef]
        );
        $this->requireExactlyOne($count, 'acquire');
    }

    public function release(string $scope, string $lockKey, string $ownerToken, string $fencingToken): void
    {
        self::requireFence($scope, $ownerToken, $fencingToken);
        $count = $this->db->execute("UPDATE leigod_only_rollout_locks SET lock_state = 'released' WHERE scope = :scope AND lock_key = :lock_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND lock_state = 'held'", ['scope'=>$scope,'lock_key'=>$lockKey,'owner_token'=>$ownerToken,'fencing_token'=>$fencingToken]);
        $this->requireExactlyOne($count, 'release');
    }

    public function releaseExpired(string $scope, string $lockKey): void
    {
        if (trim($scope) === '' || trim($lockKey) === '') { throw new \InvalidArgumentException('fencing_token_required scope_or_lock_key'); }
        $count = $this->db->execute("UPDATE leigod_only_rollout_locks SET lock_state = 'released' WHERE scope = :scope AND lock_key = :lock_key AND lock_state = 'held' AND lease_expires_at <= CURRENT_TIMESTAMP", ['scope'=>$scope,'lock_key'=>$lockKey]);
        $this->requireExactlyOne($count, 'releaseExpired');
    }

    public function renew(string $scope, string $lockKey, string $ownerToken, string $fencingToken, int $ttlSeconds): void
    {
        self::requireFence($scope, $ownerToken, $fencingToken);
        $count = $this->db->execute("UPDATE leigod_only_rollout_locks SET lease_expires_at = :lease_expires_at WHERE scope = :scope AND lock_key = :lock_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND lock_state = 'held' AND lease_expires_at > CURRENT_TIMESTAMP", ['scope'=>$scope,'lock_key'=>$lockKey,'owner_token'=>$ownerToken,'fencing_token'=>$fencingToken,'lease_expires_at'=>$this->expiry($ttlSeconds)]);
        $this->requireExactlyOne($count, 'renew');
    }

    public function requireHeld(string $scope, string $lockKey, string $ownerToken, string $fencingToken): void
    {
        self::requireFence($scope, $ownerToken, $fencingToken);
        $row = $this->db->fetchOne("SELECT * FROM leigod_only_rollout_locks WHERE scope = :scope AND lock_key = :lock_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND lock_state = 'held' LIMIT 1", ['scope'=>$scope,'lock_key'=>$lockKey,'owner_token'=>$ownerToken,'fencing_token'=>$fencingToken]);
        if ($row === null || (string)($row['scope'] ?? '') !== $scope || (string)($row['lock_key'] ?? '') !== $lockKey || (string)($row['owner_token'] ?? '') !== $ownerToken || (string)($row['fencing_token'] ?? '') !== $fencingToken || !$this->isHeldUnexpired($row)) {
            throw new \RuntimeException('rollout_lock_not_held scope=' . $scope . ' lock_key=' . $lockKey);
        }
    }

    public static function requireFence(?string $scope, ?string $ownerToken, ?string $fencingToken): void
    {
        if (!is_string($scope) || trim($scope) === '' || !is_string($ownerToken) || trim($ownerToken) === '' || !is_string($fencingToken) || trim($fencingToken) === '') {
            throw new \InvalidArgumentException('fencing_token_required');
        }
    }

    public static function requireHash(?string $hash, string $field): void
    {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            throw new \InvalidArgumentException('fencing_token_required ' . $field);
        }
    }

    public static function requireExactlyOneCount(int $count, string $operation): void
    {
        if ($count !== 1) {
            throw new \RuntimeException('exactly_one_row_required operation=' . $operation . ' affected=' . $count);
        }
    }

    private function requireExactlyOne(int $count, string $operation): void { self::requireExactlyOneCount($count, $operation); }
    private function writeHash(string $scope, string $lockKey, string $owner, string $fence, string $artifact): string { return hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope'=>$scope,'lock_key'=>$lockKey,'owner_token'=>$owner,'fencing_token'=>$fence,'artifact_snapshot_hash'=>$artifact])); }
    private function expiry(int $ttlSeconds): string { return gmdate('Y-m-d H:i:s', time() + max(1, $ttlSeconds)); }
    /** @param array<string,mixed> $row */
    private function isHeldUnexpired(array $row): bool { return ($row['lock_state'] ?? '') === 'held' && strtotime((string)($row['lease_expires_at'] ?? '')) > time(); }
    private function isDuplicateRolloutLock(Throwable $throwable): bool
    {
        if ($throwable instanceof PDOException) {
            $state = (string)($throwable->errorInfo[0] ?? $throwable->getCode());
            $driver = (int)($throwable->errorInfo[1] ?? 0);
            $message = $throwable->getMessage();
            return ($state === '23000' && $driver === 1062 && str_contains($message, 'uniq_leigod_rollout_lock'));
        }
        return false;
    }
}
