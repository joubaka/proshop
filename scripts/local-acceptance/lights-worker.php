<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require __DIR__.'/bootstrap.php';
$lockPath = base_path('.local-acceptance/lights-worker.lock');
$pidPath = base_path('.local-acceptance/lights-worker.pid');
$stopPath = base_path('.local-acceptance/lights-worker.stop');
if (in_array('--status', $argv, true)) {
    $lastSeen = app(App\Lights\Portal::class)->db()->table('lights_worker')->where('id', 1)->value('seen_at');
    echo json_encode(['database' => 'proshop_lights_acceptance', 'mode' => 'simulation', 'worker_seen_at' => $lastSeen, 'healthy' => $lastSeen && time() - $lastSeen < 5]).PHP_EOL;
    exit($lastSeen && time() - $lastSeen < 5 ? 0 : 1);
}
if (in_array('--stop', $argv, true)) {
    $pid = is_file($pidPath) ? trim(file_get_contents($pidPath)) : '';
    if (!ctype_digit($pid)) { fwrite(STDERR, "No known local worker PID; no stop request sent.\n"); exit(1); }
    file_put_contents($stopPath, $pid);
    echo "Requested graceful stop of local lights worker $pid.\n";
    exit(0);
}
$lock = fopen($lockPath, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "A local lights worker already holds the lock.\n"); exit(0); }
ftruncate($lock, 0); fwrite($lock, (string) getmypid()); fflush($lock);
file_put_contents($pidPath, (string) getmypid());
try {
    do {
        clearstatcache(true, $stopPath);
        if (is_file($stopPath) && trim(file_get_contents($stopPath)) === (string) getmypid()) { unlink($stopPath); break; }
        app(App\Lights\Portal::class)->tick(true);
        app(App\Lights\SafetySessions::class)->tick();
        $manual = app(App\Lights\ManualSwitches::class);
        $manual->enforceMidnightCutoff();
        $manual->tick();
        app(App\Lights\Shelly\StatusSynchronizer::class)->refreshIfDue();
        if (in_array('--once', $argv, true)) { break; }
        sleep(1);
    } while (true);
} finally {
    if (is_file($pidPath) && trim(file_get_contents($pidPath)) === (string) getmypid()) { unlink($pidPath); }
    flock($lock, LOCK_UN); fclose($lock);
}
