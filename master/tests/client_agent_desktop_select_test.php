<?php
declare(strict_types=1);

require __DIR__ . '/../src/Bootstrap.php';

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$controller = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$service = @file_get_contents(__DIR__ . '/../src/Support/DesktopClientSessionService.php');

if ($bootstrap === false || strpos($bootstrap, "/api/client/select") === false) {
    fwrite(STDERR, "expected /api/client/select route\n");
    exit(1);
}
if ($controller === false || strpos($controller, 'function selectDesktopClient') === false) {
    fwrite(STDERR, "expected selectDesktopClient handler\n");
    exit(1);
}
if ($service === false || strpos($service, 'class DesktopClientSessionService') === false) {
    fwrite(STDERR, "expected DesktopClientSessionService\n");
    exit(1);
}
echo "ok\n";
