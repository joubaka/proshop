<?php
// No arbitrary URL, key, channel or ON duration accepted from command-line/request input.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$lock = null;
try {
    $app = require __DIR__.'/bootstrap.php';
    $action = $argv[1] ?? ''; $id = $argv[2] ?? '';
    if (!in_array($action, ['on', 'off'], true) || !Illuminate\Support\Str::isUuid($id)) { throw new RuntimeException(); }
    $db = app(App\Lights\Portal::class)->db();
    $s = $db->table('lights_control_sessions')->where('id', $id)->whereIn('driver', ['cloud', 'cloud_customer'])->whereNotNull('active_user_id')->first();
    if (!$s || $s->state !== ($action === 'on' ? 'starting' : 'stopping') || $s->command_at < time() - 20) { throw new RuntimeException(); }
    $directory = base_path('.local-acceptance/private/shelly');
    if ($action === 'on') {
        if (config('lights.control.local_approval_required')) {
            // The supervised pilot mode uses a short-lived, one-use operator approval.
            if (!App\Lights\Shelly\PilotApproval::allows((int) $s->channel)) { throw new App\Lights\Shelly\CommandNotSent(); }
            // Consume before attempting ON: a crash/timeout cannot make this permission reusable.
            if (!unlink(App\Lights\Shelly\PilotApproval::path((int) $s->channel))) { throw new App\Lights\Shelly\CommandNotSent(); }
        }
    }
    $lock = fopen($directory.'/probe.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException(); }
    $settings = new App\Lights\Shelly\PrivateSettings($directory);
    $control = new App\Lights\Shelly\CloudControl($settings->secret());
    if ($action === 'on') { echo json_encode(['receipt' => $control->on((int) $s->channel, (int) $s->duration_seconds)]); }
    else { $control->off((int) $s->channel); echo json_encode(['off_acknowledged' => true]); }
} catch (App\Lights\Shelly\CommandNotSent) {
    echo json_encode(['error' => 'Preflight rejected. No switching command sent.', 'not_sent' => true]);
} catch (Throwable) {
    echo json_encode(['error' => 'Pilot command was blocked or its outcome is uncertain. Check the safety session; do not repeat ON.']);
} finally {
    if (is_resource($lock)) {
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) time()); fflush($lock);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
