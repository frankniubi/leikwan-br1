<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

final class AppSettings
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1', [
            'setting_key' => $key,
        ]);

        if ($row === null) {
            return $default;
        }

        return array_key_exists('setting_value', $row) ? (string) $row['setting_value'] : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = strtolower((string) ($this->get($key, $default ? '1' : '0') ?? ($default ? '1' : '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, ?string $value): void
    {
        $existing = $this->db->fetchOne('SELECT id FROM app_settings WHERE setting_key = :setting_key LIMIT 1', [
            'setting_key' => $key,
        ]);

        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            $this->db->insert('app_settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $this->db->execute(
            'UPDATE app_settings SET setting_value = :setting_value, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $existing['id'],
                'setting_value' => $value,
                'updated_at' => $now,
            ]
        );
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }
}
