<?php
declare(strict_types=1);

namespace Leikwan\Master;

final class Config
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(string $envPath)
    {
        if (is_file($envPath)) {
            $this->loadEnvFile($envPath);
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return (string) $value;
        }

        return $this->values[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? (string) $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = strtolower((string) ($this->get($key) ?? ($default ? 'true' : 'false')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function loadEnvFile(string $envPath): void
    {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $pair = explode('=', $trimmed, 2);
            if (count($pair) !== 2) {
                continue;
            }

            $key = trim($pair[0]);
            $value = trim($pair[1]);
            $value = trim($value, "\"'");
            $this->values[$key] = $value;
        }
    }
}
