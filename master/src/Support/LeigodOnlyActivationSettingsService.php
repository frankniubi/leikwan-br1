<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';

final class LeigodOnlyActivationSettingsService
{
    public function __construct(private object $db) {}

    /** @param array<string,mixed> $request */
    public function updateSetting(array $request): string
    {
        $scope = (string)($request['scope'] ?? '');
        $owner = $request['owner_token'] ?? null;
        $fence = $request['fencing_token'] ?? null;
        LeigodOnlyRolloutLockService::requireFence($scope, is_string($owner) ? $owner : null, is_string($fence) ? $fence : null);
        $key = LeigodOnlyActivationPlan::nonEmptyString($request['setting_key'] ?? null, 'setting_key');
        $slotKey = $scope . ':' . $key . ':current';
        $valueJson = LeigodOnlyActivationPlan::canonicalJson($request['setting_value'] ?? $request['setting_value_json'] ?? null);
        $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope'=>$scope,'setting_key'=>$key,'setting_value_json'=>$valueJson,'owner_token'=>$owner,'fencing_token'=>$fence]));
        return $this->db->transaction(function (object $db) use ($scope,$owner,$fence,$key,$slotKey,$valueJson,$writeHash,$request): string {
            $db->fetchOne('SELECT * FROM leigod_only_activation_settings WHERE scope = :scope AND setting_key = :setting_key AND current_slot_key = :current_slot_key FOR UPDATE', ['scope'=>$scope,'setting_key'=>$key,'current_slot_key'=>$slotKey]);
            $count = $db->execute('UPDATE leigod_only_activation_settings SET current_slot_key = NULL, superseded_at = CURRENT_TIMESTAMP WHERE scope = :scope AND setting_key = :setting_key AND current_slot_key = :current_slot_key AND owner_token = :owner_token AND fencing_token = :fencing_token', ['scope'=>$scope,'setting_key'=>$key,'current_slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence]);
            if ($count !== 0 && $count !== 1) { throw new \RuntimeException('exactly_one_row_required operation=settings_supersede affected=' . $count); }
            $db->insert('leigod_only_activation_settings', ['scope'=>$scope,'setting_key'=>$key,'setting_value_json'=>$valueJson,'current_slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence,'write_hash'=>$writeHash,'external_authorization_ref'=>$request['external_authorization_ref'] ?? null]);
            return $writeHash;
        });
    }
}
