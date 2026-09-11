<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
$app = require __DIR__.'/bootstrap.php';
$command = $argv[1] ?? 'migrate:status';
if ($command === 'check') {
    echo 'Isolated server verified; migrations: '.Illuminate\Support\Facades\DB::table('migrations')->count().PHP_EOL;
    exit(0);
}
if ($command === 'seed') {
    require __DIR__.'/fixtures.php';
    exit;
}
if (!in_array($command, ['migrate', 'migrate:status', 'view:clear', 'lights:health'], true)) {
    throw new RuntimeException('Allowed commands: migrate, migrate:status, view:clear, lights:health, seed.');
}
$parameters = ['--no-interaction' => true];
if ($command === 'lights:health' && in_array('--json', $argv, true)) { $parameters['--json'] = true; }
$status = Illuminate\Support\Facades\Artisan::call($command, $parameters);
echo Illuminate\Support\Facades\Artisan::output();
exit($status);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
