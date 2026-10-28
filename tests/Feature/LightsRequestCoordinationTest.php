<?php

namespace Tests\Feature;

use App\Lights\Shelly\CloudControl;
use App\Lights\Shelly\CloudStatus;
use App\Lights\Shelly\PrivateSettings;
use App\Lights\Shelly\RequestCoordinator;
use Symfony\Component\Process\Process;
use Tests\Support\RegressionTestCase;

class LightsRequestCoordinationTest extends RegressionTestCase
{
    public function test_real_transport_paths_share_the_coordinator_without_invoking_network(): void
    {
        $report = fn ($output) => [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL',
            'gen' => 'G2', 'online' => 1, 'status' => ['switch:0' => ['output' => $output,
                'timer_started_at' => time(), 'timer_duration' => 120]]]])];
        // The callbacks would perform real I/O; returning fake responses here verifies
        // both real adapters acquire a slot, without ever invoking those callbacks.
        $this->mock(RequestCoordinator::class)->shouldReceive('run')->times(4)
            ->andReturn($report(false), $report(false), [200, ''], $report(true));
        $this->assertTrue((new CloudStatus)->check('synthetic-key')['online']);
        $this->assertTrue((new CloudControl('synthetic-key'))->on(0, 120)['output']);
    }

    public function test_two_courts_and_background_polling_share_a_paced_cross_process_transport(): void
    {
        $directory = base_path('tests/.cache/shelly-race-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/device.json', json_encode(['channels' => [['output' => false], ['output' => false]]]));
        $workers = [];
        try {
            foreach (['0', '1', 'poll'] as $mode) {
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec,curl_multi_exec',
                    '-d', 'allow_url_fopen=0', base_path('tests/Support/shelly-request-race.php'), $directory, $mode], base_path(), null, null, 45);
                $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            while (count(glob($directory.'/ready-*')) !== 3 && microtime(true) < $deadline) { usleep(20000); clearstatcache(); }
            $this->assertCount(3, glob($directory.'/ready-*'), 'All synthetic callers must reach the barrier.');
            file_put_contents($directory.'/go', 'go');
            foreach ($workers as $worker) { $worker->wait(); $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput()); }
            $device = json_decode(file_get_contents($directory.'/device.json'), true);
            $this->assertTrue($device['channels'][0]['output']); $this->assertTrue($device['channels'][1]['output']);
            $trace = array_map(fn ($line) => json_decode($line, true), file($directory.'/trace.jsonl', FILE_IGNORE_NEW_LINES));
            $this->assertCount(8, $trace);
            foreach ($trace as $index => $entry) {
                $this->assertSame(200, $entry['code'], 'No synthetic rate-limit rejection.');
                if ($index > 0) { $this->assertGreaterThanOrEqual(1.05, $entry['start'] - $trace[$index - 1]['start']); }
            }
            $on = array_values(array_filter($trace, fn ($entry) => $entry['endpoint'] === '/set/switch' && $entry['on'] === true));
            $this->assertCount(2, $on); $this->assertEqualsCanonicalizing([0, 1], array_column($on, 'channel'));
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } }
            foreach (glob($directory.'/*') as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    public function test_busy_lock_times_out_without_dispatch_and_releases_after_failure(): void
    {
        $path = base_path('tests/.cache/shelly-lock-'.bin2hex(random_bytes(8)));
        $handle = fopen($path, 'c+'); flock($handle, LOCK_EX);
        $called = false;
        try {
            try { (new RequestCoordinator($path))->run(function () use (&$called) { $called = true; }, 0.05); $this->fail('Busy lock accepted.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('busy', $error->getMessage()); }
            $this->assertFalse($called);
            flock($handle, LOCK_UN);
            try { (new RequestCoordinator($path))->run(fn () => throw new \RuntimeException('synthetic failure')); }
            catch (\RuntimeException) {}
            $this->assertSame('recovered', (new RequestCoordinator($path))->run(fn () => 'recovered'));
        } finally { fclose($handle); unlink($path); }
    }
}
