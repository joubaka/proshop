<?php

// One-purpose worker helper for an already-persisted manual ON or OFF command.
// ON always includes a device-side cutoff timer and is never replayed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$lock = null;
try {
    $app = require __DIR__.'/bootstrap.php';
    $id = $argv[1] ?? '';
    if (!Illuminate\Support\Str::isUuid($id)) { throw new RuntimeException(); }
    $db = app(App\Lights\Portal::class)->db();
    $command = $db->table('lights_manual_commands')->where('id', $id)->where('state', 'sending')->first();
    if (!$command || $command->command_at < time() - 40
        || !in_array((int) $command->channel, [0, 1], true)
        || !in_array($command->action, ['on', 'off'], true)) {
        throw new RuntimeException();
    }
    $directory = base_path('.local-acceptance/private/shelly');
    $lock = fopen($directory.'/probe.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException(); }
    $settings = new App\Lights\Shelly\PrivateSettings($directory);
    $control = new App\Lights\Shelly\CloudControl($settings->secret());
    if ($command->action === 'on') {
        $receipt = $control->on((int) $command->channel, (int) $command->duration_seconds);
        echo json_encode(['action' => 'on', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
    } else {
        $control->off((int) $command->channel);
        echo json_encode(['action' => 'off', 'receipt' => ['output' => false]], JSON_THROW_ON_ERROR);
    }
} catch (App\Lights\Shelly\CommandNotSent) {
    echo json_encode(['error' => 'The Shelly rejected the ON preflight. No switching command was sent.', 'not_sent' => true]);
} catch (Throwable) {
    echo json_encode(['error' => 'Command outcome is uncertain. Check the court before sending another ON command.']);
} finally {
    if (is_resource($lock)) {
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) time()); fflush($lock);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
