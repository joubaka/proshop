<?php

// Creates permission only; never sends an ON/OFF/configuration command.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$required = ['--empty-court-confirmed', '--operator-onsite', '--reboot-off-checked', '--overrides-checked'];
try {
    $app = require __DIR__.'/bootstrap.php';
    $court = $argv[1] ?? '';
    $channel = match ($court) { 'court3' => 0, 'court4' => 1, default => throw new RuntimeException('Choose court3 or court4.') };
    foreach ($required as $flag) { if (!in_array($flag, $argv, true)) { throw new RuntimeException('Missing explicit safety confirmation: '.$flag); } }
    if (!config('lights.control.live_enabled')) { throw new RuntimeException('The deployment pilot kill switch is disabled.'); }
    $directory = base_path('.local-acceptance/private/shelly');
    $settings = new App\Lights\Shelly\PrivateSettings($directory);
    $report = (new App\Lights\Shelly\CloudStatus)->check($settings->secret());
    $state = $report['channels'][$channel];
    if (!$report['online'] || $state['output'] !== false || $state['has_errors']) {
        throw new RuntimeException('Refusing to arm: cloud must report the selected channel online, OFF and fault-free. No command sent.');
    }
    $heartbeat = (int) app(App\Lights\Portal::class)->db()->table('lights_worker')->where('id', 1)->value('seen_at');
    if ($heartbeat < time() - 5 || $heartbeat > time() + 5) { throw new RuntimeException('Refusing to arm: accounting worker is not healthy.'); }
    $approval = ['device' => App\Lights\Shelly\PrivateSettings::DEVICE, 'channel' => $channel,
        'expires_at' => time() + 600, 'onsite_empty_court_confirmed' => true, 'operator_onsite_confirmed' => true,
        'reboot_off_and_overrides_checked' => true];
    $path = $directory.'/pilot-approval.json';
    if (file_put_contents($path, json_encode($approval, JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new RuntimeException('Could not save approval.'); }
    chmod($path, 0600);
    echo ucfirst($court).' armed once for 10 minutes. No switching command was sent. Reload the safety workspace.\n';
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
