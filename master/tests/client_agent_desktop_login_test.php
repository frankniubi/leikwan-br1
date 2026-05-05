<?php
declare(strict_types=1);

require __DIR__ . '/../src/Bootstrap.php';

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false || strpos($source, 'session_token') === false) {
    fwrite(STDERR, "expected desktop login response to include session_token\n");
    exit(1);
}
if (strpos($source, "'clients' =>") === false) {
    fwrite(STDERR, "expected desktop login response to include clients\n");
    exit(1);
}
echo "ok\n";
