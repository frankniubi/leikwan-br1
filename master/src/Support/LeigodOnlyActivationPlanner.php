<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/StandaloneBondLegPolicy.php';

final class LeigodOnlyActivationPlanner
{
    public function __construct(private object $assignmentService, private ?object $artifactGate = null, private ?object $sideEffects = null) {}

    /** @param array<string,mixed> $request */
    public function plan(array $request): LeigodOnlyActivationPlan
    {
        $scope = LeigodOnlyActivationPlan::nonEmptyString($request['scope'] ?? 'global', 'scope');
        $candidates = $this->normalizeCandidates($request['candidates'] ?? []);
        $artifactGate = $this->artifactGate($request, $scope, $candidates);
        $desiredGeneration = (int)($request['desired_generation'] ?? 0);
        $legCandidates = [];
        $legBlockers = [];
        foreach ($candidates as $candidate) {
            $forwardId = $candidate['forward_id'];
            $clientId = $candidate['client_id'];
            $deviceId = $candidate['device_id'];
            $current = $this->assignmentService->loadCurrent($forwardId, $clientId, $deviceId, $scope);
            $rows = $this->assignmentService->desiredLegRows($forwardId, $clientId, $deviceId, $scope);
            $generation = $desiredGeneration > 0 ? $desiredGeneration : $this->generationFromRows($rows, $current);
            $classification = StandaloneBondLegPolicy::classify($forwardId, $generation, $rows);
            $legHash = StandaloneBondLegPolicy::canonicalLegSetHash($forwardId, $generation, $rows);
            $candidatePayload = [
                'forward_id'=>$forwardId,
                'client_id'=>$clientId,
                'device_id'=>$deviceId,
                'desired_generation'=>$generation,
                'current_assignment'=>$current,
                'desired_leg_set_hash'=>$legHash,
                'classification'=>$classification,
            ];
            $legCandidates[] = $candidatePayload;
            if (($classification['outcome'] ?? '') !== 'speed_plus_eligible' && ($classification['outcome'] ?? '') !== 'single_leg_only') {
                $legBlockers[] = 'leg_policy:' . $forwardId . ':' . (string)($classification['outcome'] ?? 'unknown');
            }
        }
        usort($legCandidates, static fn(array $a, array $b): int => [$a['forward_id'],$a['client_id'] ?? 0,$a['device_id'] ?? 0] <=> [$b['forward_id'],$b['client_id'] ?? 0,$b['device_id'] ?? 0]);

        $legGate = ['ok'=>count($legBlockers) === 0, 'blockers'=>$legBlockers, 'candidates'=>$legCandidates];
        return LeigodOnlyActivationPlan::fromPayload([
            'scope'=>$scope,
            'dry_run_only'=>(bool)($request['dry_run_only'] ?? true),
            'artifact_gate'=>$artifactGate,
            'runtime_contract_gate'=>$this->gate($request['runtime_contract_gate'] ?? ['ok'=>true]),
            'leg_policy_gate'=>$legGate,
            'telemetry_gate'=>$this->gate($request['telemetry_gate'] ?? ['ok'=>true]),
            'canary_gate'=>$this->gate($request['canary_gate'] ?? ['ok'=>true]),
            'normalization_gate'=>$this->gate($request['normalization_gate'] ?? ['ok'=>true]),
            'retirement_gate'=>$this->gate($request['retirement_gate'] ?? ['ok'=>true]),
            'rollback_gate'=>$this->gate($request['rollback_gate'] ?? ['ok'=>true]),
            'blockers'=>LeigodOnlyActivationPlan::stringList($request['blockers'] ?? []),
            'desired_generation'=>$desiredGeneration,
            'desired_state_hash'=>$request['desired_state_hash'] ?? null,
            'artifact_snapshot_hash'=>$artifactGate['artifact_snapshot_hash'] ?? ($request['artifact_snapshot_hash'] ?? null),
            'candidates'=>$candidates,
        ]);
    }

    /** @return array<string,mixed> */
    private function artifactGate(array $request, string $scope, array $candidates): array
    {
        if (isset($request['artifact_gate'])) { return $this->gate($request['artifact_gate']); }
        if ($this->artifactGate !== null && method_exists($this->artifactGate, 'evaluate')) {
            $result = $this->artifactGate->evaluate(['scope'=>$scope,'candidates'=>$candidates]);
            return $this->gate(is_array($result) ? $result : ['ok'=>false,'blockers'=>['artifact_gate_malformed']]);
        }
        return ['ok'=>false,'blockers'=>['artifact_gate_unavailable']];
    }

    /** @return array<string,mixed> */
    private function gate(mixed $gate): array
    {
        if (!is_array($gate)) { return ['ok'=>false,'blockers'=>['malformed_gate']]; }
        $gate['ok'] = (bool)($gate['ok'] ?? false);
        $gate['blockers'] = LeigodOnlyActivationPlan::stringList($gate['blockers'] ?? []);
        return $gate;
    }

    /** @return list<array{forward_id:int,client_id:?int,device_id:?int}> */
    private function normalizeCandidates(mixed $value): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('candidates_required'); }
        $items=[];
        foreach ($value as $candidate) {
            if (!is_array($candidate)) { throw new \InvalidArgumentException('candidate_malformed'); }
            $forwardId=(int)($candidate['forward_id'] ?? 0); if ($forwardId<=0) throw new \InvalidArgumentException('candidate_forward_id_required');
            $items[]=['forward_id'=>$forwardId,'client_id'=>isset($candidate['client_id'])?(int)$candidate['client_id']:null,'device_id'=>isset($candidate['device_id'])?(int)$candidate['device_id']:null];
        }
        usort($items, static fn(array $a, array $b): int => [$a['forward_id'],$a['client_id'] ?? 0,$a['device_id'] ?? 0] <=> [$b['forward_id'],$b['client_id'] ?? 0,$b['device_id'] ?? 0]);
        return $items;
    }

    private function generationFromRows(array $rows, mixed $current): int
    {
        foreach ($rows as $row) { if (isset($row['desired_generation'])) return (int)$row['desired_generation']; }
        return is_array($current) ? (int)($current['desired_generation'] ?? 0) : 0;
    }
}
