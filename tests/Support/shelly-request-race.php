<?php
// Synthetic transport only. The parent also disables cURL in every child process.
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (function_exists('curl_exec')) { throw new \RuntimeException('Synthetic race requires cURL disabled.'); }

[$script, $directory, $mode] = $argv;
$coordinator = new \App\Lights\Shelly\RequestCoordinator($directory.'/requests.lock');
$transport = function ($endpoint, $body = []) use ($coordinator, $directory, $mode) {
    return $coordinator->run(function () use ($endpoint, $body, $directory, $mode) {
        $now = microtime(true);
        $device = json_decode(file_get_contents($directory.'/device.json'), true);
        $code = $now - ($device['last_end'] ?? 0) < 1.05 ? 429 : 200;
        file_put_contents($directory.'/trace.jsonl', json_encode(['actor' => $mode, 'endpoint' => $endpoint,
            'start' => $now, 'code' => $code, 'channel' => $body['channel'] ?? null, 'on' => $body['on'] ?? null]).PHP_EOL, FILE_APPEND);
        if ($code !== 200) { return [$code, '']; }
        if ($endpoint === '/set/switch') {
            $device['channels'][$body['channel']] = ['output' => $body['on'], 'timer_started_at' => time() + 3.11,
                'timer_duration' => $body['toggle_after'] ?? null];
        }
        usleep(30000);
        $device['last_end'] = microtime(true);
        file_put_contents($directory.'/device.json', json_encode($device));
        return [$code, $endpoint === '/set/switch' ? '' : json_encode([[
            'id' => \App\Lights\Shelly\PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
            'status' => ['switch:0' => $device['channels'][0], 'switch:1' => $device['channels'][1]],
        ]])];
    });
};
file_put_contents($directory.'/ready-'.$mode, 'ready');
$deadline = microtime(true) + 20;
while (!is_file($directory.'/go')) {
    clearstatcache();
    if (microtime(true) > $deadline) { throw new \RuntimeException('Synthetic barrier timed out.'); }
    usleep(10000);
}
if ($mode === 'poll') {
    $status = new \App\Lights\Shelly\CloudStatus(fn () => $transport('/get'));
    $status->check('synthetic-key'); $status->check('synthetic-key');
    echo 'poll complete';
} else {
    $control = new \App\Lights\Shelly\CloudControl('synthetic-key', $transport);
    $receipt = $control->on((int) $mode, 120);
    if ($receipt['output'] !== true || $receipt['timer_duration'] !== 120) { throw new \RuntimeException('Invalid synthetic receipt.'); }
    echo 'confirmed '.$mode;
}
