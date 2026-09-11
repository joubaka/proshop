<?php

// Dedicated, read-only network exception. Never boot the shop app or load any .env.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = dirname(__DIR__, 2).'/.local-acceptance/private/shelly';
$lock = null;
try {
    $settings = new App\Lights\Shelly\PrivateSettings($directory);
    if (!$settings->configured()) { throw new RuntimeException('Save the cloud key first.'); }
    $lock = fopen($directory.'/probe.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('A read-only check is already running.'); }
    $last = (int) stream_get_contents($lock);
    if (time() - $last < 5) { throw new RuntimeException('Wait five seconds before checking again.'); }
    ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) time()); fflush($lock);
    try { $secret = $settings->secret(); }
    catch (Throwable) { throw new RuntimeException('The saved key cannot be opened. Save it again.'); }
    echo json_encode((new App\Lights\Shelly\CloudStatus)->check($secret), JSON_THROW_ON_ERROR);
} catch (RuntimeException $error) {
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable) {
    echo json_encode(['error' => 'The read-only check failed. No switching command was sent.']);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
