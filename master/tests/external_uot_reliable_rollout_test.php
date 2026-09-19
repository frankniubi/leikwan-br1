<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Support\ExternalUoTDefaults;
use Leikwan\Master\Support\LeigodTokenBroker;

function expectRollout(bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
}
$config = new Config('/nonexistent');
$forwards = [
    ['external_uot' => ['enabled' => true, 'sidecar_target' => 'example.test:7443', 'token' => 'test-token']],
    ['external_uot' => ['enabled' => false]],
    [],
];
putenv('EXTERNAL_UOT_RELIABLE_CLIENT_IDS=3, 12');
try {
    expectRollout(ExternalUoTDefaults::withClientDeliveryMode($config, 13, $forwards) === $forwards, 'legacy client changed');
    expectRollout(ExternalUoTDefaults::withClientDeliveryMode($config, 0, $forwards) === $forwards, 'invalid client changed');
    $result = ExternalUoTDefaults::withClientDeliveryMode($config, 3, $forwards);
    expectRollout($result[0]['external_uot']['delivery_mode'] === 'reliable_datagram_v1', 'mode missing');
    expectRollout($result[0]['external_uot']['queue_packets'] === 4096, 'queue missing');
    expectRollout($result[1] === $forwards[1] && $result[2] === $forwards[2], 'grant created without authorization');
    $reflection = new ReflectionClass(LeigodTokenBroker::class);
    $broker = $reflection->newInstanceWithoutConstructor();
    $normalize = $reflection->getMethod('normalizeExternalUoT');
    $grant = $normalize->invoke($broker, $result[0]['external_uot'], 'target.test:53');
    expectRollout($grant['delivery_mode'] === 'reliable_datagram_v1', 'broker stripped reliable mode');
    $legacy = $normalize->invoke($broker, $forwards[0]['external_uot'], 'target.test:53');
    expectRollout(!array_key_exists('delivery_mode', $legacy), 'legacy unknown key emitted');
    $invalid = $forwards[0]['external_uot'] + ['delivery_mode' => 'unsupported_mode'];
    expectRollout($normalize->invoke($broker, $invalid, 'target.test:53')['delivery_mode'] === 'unsupported_mode', 'unsupported mode downgraded');
    echo "PASS external UoT reliable rollout\n";
} finally {
    putenv('EXTERNAL_UOT_RELIABLE_CLIENT_IDS');
}
