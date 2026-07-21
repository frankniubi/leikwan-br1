<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class LeigodOnlyArtifactConsistencyService
{
    /** @var list<string> */
    private const NEGATIVE_EVIDENCE_ONLY = [
        'native_mptcp',
        'mptcp_relay',
        'leikwan_mptcp_relay_linux_amd64',
        'leikwan_mptcp_relay_windows_amd64',
        'leikwan-mptcp-relay',
    ];

    public function __construct(
        private mixed $routeProbe = null,
        private mixed $metadataProbe = null,
        private mixed $sourceBuildMap = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,matrix:array<string,mixed>,blockers:list<string>}
     */
    public function evaluate(array $context): array
    {
        if (!array_key_exists('expected_pins', $context) && is_array($context['expected_artifact_pins'] ?? null)) {
            $context['expected_pins'] = $context['expected_artifact_pins'];
        }
        if (!array_key_exists('platform_family', $context)) {
            $context['platform_family'] = 'linux-bond';
        }
        return $this->validateForActivation($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,matrix:array<string,mixed>,blockers:list<string>}
     */
    public function validateForActivation(array $context): array
    {
        $blockers = [];
        $matrix = ['artifacts' => [], 'capabilities' => []];
        $platformFamily = $this->stringValue($context['platform_family'] ?? '');
        if (!LeigodOnlyPlatformFamilyGate::isSupported($platformFamily)) {
            return $this->result($matrix, ['unsupported_platform_family:' . ($platformFamily !== '' ? $platformFamily : '<missing>')]);
        }

        $keys = $this->stringList($context['activation_artifact_keys'] ?? $this->defaultKeysForPlatform($platformFamily));
        $helperKeys = $this->stringList($context['route_only_helper_keys'] ?? []);
        foreach ($keys as $key) {
            if (in_array($key, self::NEGATIVE_EVIDENCE_ONLY, true)) {
                $blockers[] = 'negative_evidence_only:' . $key;
            }
        }

        $routeProbe = $this->routeProbe;
        $metadataProbe = $this->metadataProbe;
        $sourceMap = $this->sourceBuildMap;
        $testMode = !empty($context['test_mode']);
        if ($routeProbe === null) {
            $routeProbe = $testMode ? null : new LeigodOnlyArtifactRouteProbe();
        }
        if ($metadataProbe === null) {
            $metadataProbe = $testMode ? null : new LeigodOnlyArtifactMetadataProbe();
        }
        if ($sourceMap === null) {
            $sourceMap = $testMode ? null : new LeigodOnlyArtifactSourceBuildMap();
        }
        if (!is_object($routeProbe) || !method_exists($routeProbe, 'probe')) {
            $blockers[] = 'route_probe_unavailable';
        }
        if (!is_object($metadataProbe) || !method_exists($metadataProbe, 'metadataForActivation')) {
            $blockers[] = 'metadata_probe_unavailable';
        }
        if (!is_object($sourceMap) || !method_exists($sourceMap, 'activationCriticalMap')) {
            $blockers[] = 'source_build_probe_unavailable';
        }
        if ($blockers !== []) {
            return $this->result($matrix, $blockers);
        }

        $activationMap = $this->arrayMap($sourceMap->activationCriticalMap());
        $helperMap = method_exists($sourceMap, 'routeOnlyHelperMap') ? $this->arrayMap($sourceMap->routeOnlyHelperMap()) : [];
        $manifestPackage = $this->arrayMap($context['manifest_package'] ?? []);
        $manifestRoute = $this->probe($routeProbe, '/artifacts/versions.json');
        if (($manifestRoute['status'] ?? 0) !== 200) {
            $blockers[] = 'manifest_route_status_not_200';
        }
        $manifestRouteJson = json_decode((string) ($manifestRoute['body'] ?? ''), true);
        if (!is_array($manifestRouteJson)) {
            $blockers[] = 'manifest_route_json_invalid';
            $manifestRouteJson = [];
        }
        if (!$this->selectedManifestEquals($manifestPackage, $manifestRouteJson, $keys)) {
            $blockers[] = 'manifest_route_json_mismatch';
        }

        $metadata = $metadataProbe->metadataForActivation(array_values(array_unique(array_merge($keys, $helperKeys))));
        $metadata = is_array($metadata) ? $metadata : [];

        foreach ($keys as $key) {
            $matrix['artifacts'][$key] = ['platform_family' => $platformFamily];
            if (!isset($activationMap[$key]) || !is_array($activationMap[$key])) {
                $blockers[] = 'source_build_mapping_missing:' . $key;
                continue;
            }
            if (!$this->keyAllowedForPlatform($key, $platformFamily, $activationMap[$key])) {
                $blockers[] = 'artifact_platform_family_mismatch:' . $key;
                continue;
            }
            $manifestEntry = $this->entryFor($manifestPackage, $key);
            $routePath = $this->routePath($key, $manifestEntry, $activationMap[$key]);
            $route = $this->probe($routeProbe, $routePath);
            $routeSha = (string) ($route['sha256'] ?? hash('sha256', (string) ($route['body'] ?? '')));
            $expectedSha = strtolower($this->stringValue($manifestEntry['sha256'] ?? ''));
            if (($route['status'] ?? 0) !== 200) {
                $blockers[] = 'route_status_not_200:' . $key;
            }
            if ($expectedSha !== '' && strtolower($routeSha) !== $expectedSha) {
                $blockers[] = 'route_bytes_mismatch:' . $key;
            }
            $routeSize = (int) ($route['size_bytes'] ?? strlen((string) ($route['body'] ?? '')));
            if (isset($manifestEntry['size_bytes']) && (int) $manifestEntry['size_bytes'] !== $routeSize) {
                $blockers[] = 'route_size_mismatch:' . $key;
            }
            $static = $this->staticMirrorEntry($context['static_mirror'] ?? [], $key);
            if ($static !== null && $this->truthy($static['present'] ?? true)) {
                $staticSha = strtolower($this->stringValue($static['sha256'] ?? ''));
                if ($staticSha !== '' && $expectedSha !== '' && $staticSha !== $expectedSha) {
                    $blockers[] = 'public_shadow_bytes_mismatch:' . $key;
                }
            }
            $metaEntry = $this->entryFor($metadata, $key);
            $metaSha = strtolower($this->stringValue($metaEntry['sha256'] ?? $metaEntry['binary_sha256'] ?? ''));
            if ($metaSha === '' || ($expectedSha !== '' && $metaSha !== $expectedSha)) {
                $blockers[] = 'base_controller_sha_mismatch:' . $key;
            }
            $pin = strtolower($this->stringValue(($context['expected_pins'] ?? [])[$key] ?? ''));
            if ($pin === '') {
                if ($this->requiresExpectedPin($key)) {
                    $blockers[] = 'expected_pin_missing:' . $key;
                }
            } elseif ($expectedSha !== '' && $pin !== $expectedSha) {
                $blockers[] = 'expected_pin_mismatch:' . $key;
            }
            $prov = is_array(($context['source_build_provenance'] ?? [])[$key] ?? null) ? ($context['source_build_provenance'] ?? [])[$key] : [];
            $actualFingerprint = method_exists($sourceMap, 'sourceFingerprint') ? (string) $sourceMap->sourceFingerprint($key) : '';
            $provFingerprint = $this->stringValue($prov['source_fingerprint'] ?? '');
            if ($actualFingerprint !== '' && $provFingerprint !== '' && $provFingerprint !== $actualFingerprint) {
                $blockers[] = 'source_fingerprint_mismatch:' . $key;
            }
            $actualBuildSha = strtolower(method_exists($sourceMap, 'buildOutputSha256') ? (string) $sourceMap->buildOutputSha256($key) : '');
            $provBuildSha = strtolower($this->stringValue($prov['build_output_sha256'] ?? ''));
            if ($provBuildSha === '' || ($actualBuildSha !== '' && $provBuildSha !== $actualBuildSha) || ($expectedSha !== '' && $provBuildSha !== $expectedSha)) {
                $blockers[] = 'build_output_sha_mismatch:' . $key;
            }
            if (method_exists($sourceMap, 'requiresImmutableProvenance') && $sourceMap->requiresImmutableProvenance($key)) {
                $immutable = trim($this->stringValue($prov['immutable_provenance'] ?? ''));
                if ($immutable === '' || $immutable === '0') {
                    $blockers[] = 'immutable_provenance_missing:' . $key;
                }
            }
        }

        foreach ($helperKeys as $key) {
            if (!isset($helperMap[$key]) || !is_array($helperMap[$key])) {
                $blockers[] = 'route_helper_metadata_missing:' . $key;
                continue;
            }
            $routePath = $this->routePath($key, [], $helperMap[$key]);
            $route = $this->probe($routeProbe, $routePath);
            $routeSha = strtolower((string) ($route['sha256'] ?? hash('sha256', (string) ($route['body'] ?? ''))));
            $metaEntry = $this->entryFor($metadata, $key);
            if ($metaEntry === []) {
                $blockers[] = 'route_helper_metadata_missing:' . $key;
                continue;
            }
            $metaSha = strtolower($this->stringValue($metaEntry['sha256'] ?? ''));
            if ($metaSha === '' || $metaSha !== $routeSha) {
                $blockers[] = 'update_helper_metadata_mismatch:' . $key;
            }
        }

        array_push($blockers, ...$this->validateCapabilities($context, $keys, $platformFamily, $manifestPackage, $activationMap, $matrix));
        return $this->result($matrix, $blockers);
    }

    /** @param array<string,mixed> $matrix @param list<string> $blockers */
    private function result(array $matrix, array $blockers): array
    {
        $blockers = array_values(array_unique($blockers));
        return ['ok' => $blockers === [], 'matrix' => $matrix, 'blockers' => $blockers];
    }

    /** @return list<string> */
    private function validateCapabilities(array $context, array $keys, string $platformFamily, array $manifest, array $activationMap, array &$matrix): array
    {
        if (!isset($context['expected_capabilities']) && !isset($context['capability_reports'])) {
            return [];
        }
        $blockers = [];
        $expected = is_array($context['expected_capabilities'] ?? null) ? $context['expected_capabilities'] : [];
        $reports = $this->capabilityReportRows($context['capability_reports'] ?? null);
        $session = $this->stringValue($expected['session_id'] ?? '');
        $assignment = $this->stringValue($expected['assignment_id'] ?? '');
        $generation = (int) ($expected['generation'] ?? 0);
        $freshWithin = (int) ($expected['fresh_within_seconds'] ?? 300);
        $now = (int) ($expected['now'] ?? time());
        foreach ($keys as $key) {
            if (!isset($activationMap[$key])) {
                $blockers[] = 'capability_unknown_artifact_key:' . $key;
                continue;
            }
            $matching = [];
            $reportedOther = false;
            foreach ($reports as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowKey = $this->stringValue($row['artifact_key'] ?? '');
                if ($rowKey === $key) {
                    $matching[] = $row;
                } elseif ($rowKey !== '' && !in_array($rowKey, $keys, true)) {
                    $reportedOther = true;
                }
            }
            if ($reportedOther) {
                $blockers[] = 'capability_gate_failed:' . $key . ':reported_only';
            }
            if ($matching === []) {
                $expectedKeys = $this->stringList($expected['expected_artifact_keys'] ?? []);
                $reason = in_array($key, $expectedKeys, true) ? 'expected_only' : 'missing';
                $blockers[] = 'capability_gate_failed:' . $key . ':' . $reason;
                continue;
            }
            $row = $matching[0];
            $role = strtolower($this->stringValue($row['role'] ?? 'client'));
            if ($role !== 'client') {
                $blockers[] = 'capability_gate_failed:' . $key . ':wrong_role';
            }
            if ($session !== '' && $this->stringValue($row['session_id'] ?? '') !== $session) {
                $blockers[] = 'capability_gate_failed:' . $key . ':wrong_session';
            }
            if ($assignment !== '' && $this->stringValue($row['assignment_id'] ?? '') !== $assignment) {
                $blockers[] = 'capability_gate_failed:' . $key . ':wrong_assignment';
            }
            if ($generation > 0 && (int) ($row['generation'] ?? 0) !== $generation) {
                $blockers[] = 'capability_gate_failed:' . $key . ':wrong_generation';
            }
            $rowFamily = $this->stringValue($row['platform_family'] ?? '');
            if ($rowFamily !== '' && $rowFamily !== $platformFamily) {
                $reason = $platformFamily === 'windows-bond' ? 'wrong_platform' : 'wrong_family';
                $blockers[] = 'capability_gate_failed:' . $key . ':' . $reason;
            }
            $rowPlatform = strtolower($this->stringValue($row['platform'] ?? ''));
            if ($rowPlatform !== '' && !$this->platformMatchesFamily($rowPlatform, $platformFamily)) {
                $blockers[] = 'capability_gate_failed:' . $key . ':wrong_platform';
            }
            $reportedAt = (int) ($row['reported_at'] ?? 0);
            if ($reportedAt <= 0 || ($now - $reportedAt) > $freshWithin) {
                $blockers[] = 'capability_gate_failed:' . $key . ':stale';
            }
            $expectedSha = strtolower($this->stringValue(($manifest[$key] ?? [])['sha256'] ?? ''));
            $reportedSha = strtolower($this->stringValue($row['artifact_sha256'] ?? $row['sha256'] ?? ''));
            if ($expectedSha !== '' && $reportedSha !== $expectedSha) {
                $blockers[] = 'capability_gate_failed:' . $key . ':hash_mismatch';
            }
            $matrix['capabilities'][$key] = $row;
        }
        foreach ($reports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowKey = $this->stringValue($row['artifact_key'] ?? '');
            if ($rowKey !== '' && !in_array($rowKey, $keys, true)) {
                foreach ($keys as $key) {
                    $blockers[] = 'capability_gate_failed:' . $key . ':reported_only';
                    break;
                }
            }
        }
        return $blockers;
    }

    private function probe(object $routeProbe, string $path): array
    {
        $result = $routeProbe->probe($path);
        return is_array($result) ? $result : [];
    }

    private function selectedManifestEquals(array $expected, array $actual, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($expected[$key]) || !isset($actual[$key]) || !is_array($expected[$key]) || !is_array($actual[$key])) {
                return false;
            }
            foreach (['sha256', 'size_bytes', 'artifact_url', 'path'] as $field) {
                if (array_key_exists($field, $expected[$key]) && array_key_exists($field, $actual[$key])) {
                    if ((string) $expected[$key][$field] !== (string) $actual[$key][$field]) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function routePath(string $key, array $manifestEntry, array $mapEntry): string
    {
        foreach ([$manifestEntry['artifact_url'] ?? null, $manifestEntry['install_url'] ?? null, $mapEntry['route_path'] ?? null] as $path) {
            if (is_string($path) && $path !== '') {
                return str_starts_with($path, 'http') ? (string) parse_url($path, PHP_URL_PATH) : $path;
            }
        }
        return '/artifacts/' . $key;
    }

    private function entryFor(array $map, string $key): array
    {
        $entry = $map[$key] ?? null;
        return is_array($entry) ? $entry : [];
    }

    private function staticMirrorEntry(mixed $staticMirror, string $key): ?array
    {
        if (!is_array($staticMirror)) {
            return null;
        }
        $entry = $staticMirror[$key] ?? null;
        return is_array($entry) ? $entry : null;
    }

    private function keyAllowedForPlatform(string $key, string $platformFamily, array $entry): bool
    {
        $entryFamily = $this->stringValue($entry['platform_family'] ?? '');
        if ($entryFamily !== '' && $entryFamily !== $platformFamily) {
            return false;
        }
        if ($platformFamily === 'linux-bond' && str_contains($key, 'windows')) {
            return false;
        }
        if ($platformFamily === 'windows-bond' && str_contains($key, 'linux')) {
            return false;
        }
        return true;
    }

    private function platformMatchesFamily(string $platform, string $family): bool
    {
        if ($family === 'linux-bond') {
            return str_contains($platform, 'linux');
        }
        if ($family === 'windows-bond') {
            return str_contains($platform, 'windows') || str_contains($platform, 'win');
        }
        return false;
    }

    private function requiresExpectedPin(string $key): bool
    {
        return in_array($key, ['leikwan_bond_linux_amd64', 'leikwan_bond_windows_amd64'], true);
    }

    /** @return list<array<string, mixed>> */
    private function capabilityReportRows(mixed $reports): array
    {
        if (!is_array($reports)) {
            return [];
        }
        $isList = array_keys($reports) === range(0, count($reports) - 1);
        if ($isList) {
            return array_values(array_filter($reports, 'is_array'));
        }
        if (isset($reports['artifact_key']) || isset($reports['sha256']) || isset($reports['artifact_sha256'])) {
            return [$reports];
        }
        return array_values(array_filter($reports, 'is_array'));
    }

    /** @return list<string> */
    private function defaultKeysForPlatform(string $platformFamily): array
    {
        return $platformFamily === 'windows-bond'
            ? ['windows_client_app', 'leikwan_bond_windows_amd64']
            : ['client_agent', 'leikwan_bond_linux_amd64', 'gost_linux_amd64', 'leigod_linux_amd64', 'lguot_linux_amd64'];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }
        return array_values(array_unique($out));
    }

    private function arrayMap(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function truthy(mixed $value): bool
    {
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        if (is_string($value) && in_array(strtolower(trim($value)), ['false', 'no', 'off'], true)) {
            return false;
        }
        return true;
    }
}
