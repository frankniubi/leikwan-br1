<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    function decode_token_payload(string $token): array
    {
        [$payloadB64] = explode('.', $token, 2);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (!is_array($payload)) {
            fail('token payload is not valid json');
        }
        return $payload;
    }

    $service = new ProvisioningService(new Config(''), new Database());
    $token = call_private($service, 'buildBondSessionToken', [
        'backend_mode' => 'cf_mode',
        'bond_sidecar_host' => '185.220.238.143',
        'bond_remote_host' => '185.220.238.143',
        'bond_remote_port' => 8899,
        'target_host' => '185.220.238.143',
        'target_port' => 5201,
        'service_id' => 2,
        'id' => 47,
    ], 'point-secret', 1778889600, 1779494400, 'tcp');
    $payload = decode_token_payload($token);

    if (($payload['target_host'] ?? null) !== '127.0.0.1') {
        fail('expected sidecar-local cf_mode bond token target_host 127.0.0.1, got ' . var_export($payload['target_host'] ?? null, true));
    }
    if (($payload['target_port'] ?? null) !== 5201) {
        fail('expected sidecar-local cf_mode bond token target_port 5201, got ' . var_export($payload['target_port'] ?? null, true));
    }

    $externalToken = call_private($service, 'buildBondSessionToken', [
        'backend_mode' => 'cf_mode',
        'bond_sidecar_host' => '185.220.238.143',
        'bond_remote_host' => '185.220.238.143',
        'bond_remote_port' => 8899,
        'target_host' => '38.207.137.131',
        'target_port' => 32501,
        'service_id' => 2,
        'id' => 59,
    ], 'point-secret', 1778889600, 1779494400, 'tcp');
    $externalPayload = decode_token_payload($externalToken);
    if (($externalPayload['target_host'] ?? null) !== '38.207.137.131' || ($externalPayload['target_port'] ?? null) !== 32501) {
        fail('external cf_mode target must stay unchanged, got ' . json_encode($externalPayload, JSON_UNESCAPED_SLASHES));
    }

    echo "ok\n";
}
