<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use JsonException;
use RuntimeException;

final class MainlandRegionPolicy
{
    private const DEFAULT_ARTIFACT = __DIR__ . '/../../resources/access-policy/mainland-region-adjacency-v1.json';

    /** @var array<string, mixed> */
    private array $artifact;

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, array<int, string>> */
    private array $neighbors = [];

    /** @var array<int, string> */
    private array $provinces = [];

    public function __construct(?string $artifactPath = null)
    {
        $path = $artifactPath ?? self::DEFAULT_ARTIFACT;
        $json = @file_get_contents($path);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('mainland region policy artifact is unavailable');
        }

        try {
            $artifact = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('mainland region policy artifact is invalid', 0, $exception);
        }
        if (!is_array($artifact)) {
            throw new RuntimeException('mainland region policy artifact must be an object');
        }

        $regions = $artifact['regions'] ?? null;
        $aliases = $artifact['aliases'] ?? null;
        if (!is_array($regions) || !is_array($aliases)) {
            throw new RuntimeException('mainland region policy artifact is incomplete');
        }

        $this->artifact = $artifact;
        foreach ($regions as $code => $neighbors) {
            $normalizedCode = strtoupper(trim((string) $code));
            if ($normalizedCode === '' || !is_array($neighbors)) {
                throw new RuntimeException('mainland region policy artifact has an invalid region');
            }
            $this->provinces[] = $normalizedCode;
            $this->neighbors[$normalizedCode] = array_values(array_unique(array_map(
                static fn(mixed $value): string => strtoupper(trim((string) $value)),
                $neighbors
            )));
        }
        sort($this->provinces);

        foreach ($aliases as $alias => $code) {
            $aliasKey = strtolower(trim((string) $alias));
            $codeValue = strtoupper(trim((string) $code));
            if ($aliasKey !== '' && in_array($codeValue, $this->provinces, true)) {
                $this->aliases[$aliasKey] = $codeValue;
            }
        }

        foreach ($this->provinces as $province) {
            foreach ($this->neighbors[$province] as $neighbor) {
                if (!in_array($neighbor, $this->provinces, true)
                    || !in_array($province, $this->neighbors[$neighbor] ?? [], true)
                    || $neighbor === $province) {
                    throw new RuntimeException('mainland region policy adjacency is invalid');
                }
            }
        }
    }

    /** @return array<int, string> */
    public function allProvinces(): array
    {
        return $this->provinces;
    }

    public function isMainlandProvince(string $value): bool
    {
        return $this->normalizeProvince($value) !== null;
    }

    public function normalizeProvince(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $key = strtolower($value);
        $keyWithoutSuffix = preg_replace('/(?:省|市|自治区|壮族自治区|回族自治区|维吾尔自治区)$/u', '', $key) ?? $key;
        return $this->aliases[$key] ?? $this->aliases[$keyWithoutSuffix] ?? null;
    }

    public function normalizeCity(string $province, string $city): ?string
    {
        $provinceCode = $this->normalizeProvince($province);
        $city = trim($city);
        if ($provinceCode === null || $city === '') {
            return null;
        }
        if (str_contains($city, ':')) {
            [$cityProvince, $cityName] = explode(':', $city, 2);
            if ($this->normalizeProvince($cityProvince) !== $provinceCode) {
                return null;
            }
            $city = trim($cityName);
        }
        $city = preg_replace('/市$/u', '', $city) ?? $city;
        $city = strtolower(trim($city));
        return $city !== '' ? $provinceCode . ':' . $city : null;
    }

    /** @return array<int, string> */
    public function directNeighbors(string $province): array
    {
        $code = $this->normalizeProvince($province);
        if ($code === null) {
            return [];
        }
        $neighbors = $this->neighbors[$code] ?? [];
        sort($neighbors);
        return $neighbors;
    }

    /** @return array<int, string> */
    public function allowedProvinces(string $mainProvince): array
    {
        $code = $this->normalizeProvince($mainProvince);
        if ($code === null) {
            return [];
        }

        if (in_array($code, ['SH', 'JS', 'ZJ'], true)) {
            return ['SH', 'JS', 'ZJ'];
        }
        if (in_array($code, ['BJ', 'TJ', 'HE'], true)) {
            return ['BJ', 'TJ', 'HE'];
        }
        if (in_array($code, ['XJ', 'XZ', 'NM', 'SD'], true)) {
            return [$code];
        }

        $allowed = array_values(array_unique(array_merge([$code], $this->directNeighbors($code))));
        sort($allowed);
        return $allowed;
    }

    public function artifactVersion(): string
    {
        return trim((string) ($this->artifact['version'] ?? ''));
    }

    public function artifactSha256(): string
    {
        return trim((string) ($this->artifact['sha256'] ?? ''));
    }
}
