<?php

// A deliberately one-purpose helper: explicit OFF only. No ON/toggle/configuration path exists.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$lock = null;
try {
    $app = require __DIR__.'/bootstrap.php';
    $id = $argv[1] ?? '';
    if (!Illuminate\Support\Str::isUuid($id)) { throw new RuntimeException(); }
    $db = app(App\Lights\Portal::class)->db();
    $command = $db->table('lights_manual_commands')->where('id', $id)->where('action', 'off')->where('state', 'sending')->first();
    if (!$command || $command->command_at < time() - 20 || !in_array((int) $command->channel, [0, 1], true)) { throw new RuntimeException(); }
    $directory = base_path('.local-acceptance/private/shelly');
    $lock = fopen($directory.'/probe.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException(); }
    $settings = new App\Lights\Shelly\PrivateSettings($directory);
    (new App\Lights\Shelly\CloudControl($settings->secret()))->off((int) $command->channel);
    echo json_encode(['off_acknowledged' => true]);
} catch (Throwable) {
    echo json_encode(['error' => 'OFF outcome is uncertain. Check the court physically before taking another action.']);
} finally {
    if (is_resource($lock)) {
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) time()); fflush($lock);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
