<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class LeigodActivationGateContext
{
    public string $scope;
    public int $forward_id;
    public ?int $client_id;
    public ?int $client_device_session_id;
    public int $desired_generation;
    public int $assignment_id;
    public string $assignment_hash;
    public ?string $desired_leg_set_hash;
    public string $platform_family;
    public string $write_mode;
    /** @var array<string,mixed> */ public array $flags;
    /** @var array<string,mixed> */ public array $settings;
    /** @var array<string,string> */ public array $expected_artifact_pins;
    public ?string $owner_token;
    public ?string $fencing_token;
    public ?int $campaign_id;
    /** @var array<string,mixed>|null */ public ?array $runtime_row;
    /** @var list<array<string,mixed>>|null */ public ?array $leg_rows;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->scope = self::string($data['scope'] ?? 'global', 'scope');
        $this->forward_id = self::positiveInt($data['forward_id'] ?? 0, 'forward_id');
        $this->client_id = isset($data['client_id']) ? (int)$data['client_id'] : null;
        $this->client_device_session_id = isset($data['client_device_session_id']) ? (int)$data['client_device_session_id'] : (isset($data['device_id']) ? (int)$data['device_id'] : null);
        $this->desired_generation = self::positiveInt($data['desired_generation'] ?? 0, 'desired_generation');
        $this->assignment_id = self::positiveInt($data['assignment_id'] ?? 0, 'assignment_id');
        $this->assignment_hash = self::hash($data['assignment_hash'] ?? '', 'assignment_hash');
        $this->desired_leg_set_hash = isset($data['desired_leg_set_hash']) ? self::hash($data['desired_leg_set_hash'], 'desired_leg_set_hash') : null;
        $this->platform_family = self::string($data['platform_family'] ?? 'linux', 'platform_family');
        $writeMode = self::string($data['write_mode'] ?? 'read_only', 'write_mode');
        if (!in_array($writeMode, ['read_only','activation','retirement','rollback'], true)) {
            throw new \InvalidArgumentException('invalid_write_mode');
        }
        $this->write_mode = $writeMode;
        $this->flags = is_array($data['flags'] ?? null) ? $data['flags'] : [];
        $this->settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $this->expected_artifact_pins = self::stringMap($data['expected_artifact_pins'] ?? []);
        $this->owner_token = isset($data['owner_token']) ? (string)$data['owner_token'] : null;
        $this->fencing_token = isset($data['fencing_token']) ? (string)$data['fencing_token'] : null;
        $this->campaign_id = isset($data['campaign_id']) ? (int)$data['campaign_id'] : null;
        $this->runtime_row = is_array($data['runtime_row'] ?? null) ? $data['runtime_row'] : null;
        $this->leg_rows = is_array($data['leg_rows'] ?? null) ? array_values(array_filter($data['leg_rows'], 'is_array')) : null;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'scope'=>$this->scope,
            'forward_id'=>$this->forward_id,
            'client_id'=>$this->client_id,
            'client_device_session_id'=>$this->client_device_session_id,
            'desired_generation'=>$this->desired_generation,
            'assignment_id'=>$this->assignment_id,
            'assignment_hash'=>$this->assignment_hash,
            'desired_leg_set_hash'=>$this->desired_leg_set_hash,
            'platform_family'=>$this->platform_family,
            'write_mode'=>$this->write_mode,
            'flags'=>$this->flags,
            'settings'=>$this->settings,
            'expected_artifact_pins'=>$this->expected_artifact_pins,
            'owner_token'=>$this->owner_token,
            'fencing_token'=>$this->fencing_token,
            'campaign_id'=>$this->campaign_id,
            'runtime_row'=>$this->runtime_row,
            'leg_rows'=>$this->leg_rows,
        ];
    }

    private static function string(mixed $value, string $field): string
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            throw new \InvalidArgumentException('missing_' . $field);
        }
        return trim((string)$value);
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        $int = is_numeric($value) ? (int)$value : 0;
        if ($int <= 0) {
            throw new \InvalidArgumentException('missing_' . $field);
        }
        return $int;
    }

    private static function hash(mixed $value, string $field): string
    {
        if (!is_scalar($value) || preg_match('/^[a-f0-9]{64}$/i', (string)$value) !== 1) {
            throw new \InvalidArgumentException('missing_' . $field);
        }
        return strtolower((string)$value);
    }

    /** @return array<string,string> */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v) && trim((string)$v) !== '') {
                $out[(string)$k] = (string)$v;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
