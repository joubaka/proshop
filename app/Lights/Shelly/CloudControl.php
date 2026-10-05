<?php
namespace App\Lights\Shelly;

use Closure;
use RuntimeException;
use App\Lights\Diagnostics;

/** Used only by the gated supervised-pilot helper; never by read-only status checks. */
class CloudControl
{
    private float $deadline;
    public function __construct(#[\SensitiveParameter] private string $secret, private ?Closure $transport = null, private ?Closure $clock = null) {}
    private function time(): float { return $this->clock ? ($this->clock)() : hrtime(true) / 1e9; }
    public function on(int $channel, int $seconds): array
    {
        return $this->timedOn($channel, $seconds, false);
    }
    public function adopt(int $channel, int $seconds): array
    {
        return $this->timedOn($channel, $seconds, true);
    }
    private function timedOn(int $channel, int $seconds, bool $alreadyOn): array
    {
        $this->validate($channel, $seconds);
        // Stay below the state machine's 30-second interrupted-command window.
        $this->deadline = $this->time() + 25;
        try { $before = $this->status(); }
        catch (\Throwable $error) {
            Diagnostics::write('Shelly ON rejected: preflight status unavailable; no switching command sent.', ['channel' => $channel], $error);
            throw new CommandNotSent('Preflight status is unavailable.');
        }
        $this->logStatus('Shelly ON preflight status.', $channel, $before);
        if (!$before['online'] || $before['channels'][$channel]['output'] !== $alreadyOn || $before['channels'][$channel]['has_errors']) {
            Diagnostics::write('Shelly ON rejected: preflight state mismatch or device fault; no switching command sent.', ['channel' => $channel, 'expected_output' => $alreadyOn]);
            throw new CommandNotSent($alreadyOn
                ? 'Adoption preflight requires an online, fault-free channel reported ON.'
                : 'Pilot preflight requires an online, fault-free channel reported OFF.');
        }
        $this->post('/set/switch', ['id' => PrivateSettings::DEVICE, 'channel' => $channel, 'on' => true, 'toggle_after' => $seconds]);
        // Cloud status can briefly lag a successful set request. Poll status only; never replay ON.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $after = $this->status();
            $this->logStatus('Shelly ON confirmation status.', $channel, $after, $attempt + 1);
            $state = $after['channels'][$channel];
            if ($after['online'] && !$state['has_errors'] && $state['output'] === true
                && is_numeric($state['timer_started_at']) && is_numeric($state['timer_duration'])
                && abs((float) $state['timer_duration'] - $seconds) < 0.01) {
                return $state;
            }
        }
        Diagnostics::write('Shelly ON acknowledgement lacks confirmed output/timer evidence.', ['channel' => $channel, 'expected_timer_duration' => $seconds, 'state' => 'review']);
        throw new RuntimeException('Shelly accepted ON but fresh output-and-timer status was not confirmed.');
    }
    public function off(int $channel): void
    {
        $this->validate($channel, 1);
        $this->deadline = $this->time() + 25;
        // OFF deliberately has no flip-back timer. It must never turn itself back ON.
        try {
            $this->post('/set/switch', ['id' => PrivateSettings::DEVICE, 'channel' => $channel, 'on' => false]);
        } catch (\Throwable $error) {
            // A lost acknowledgement does not mean OFF was ignored. Read status only;
            // never resend a switch command within this operation.
            Diagnostics::write('Shelly OFF acknowledgement unavailable; checking status without resending OFF.', ['channel' => $channel], $error);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $after = $this->status();
                $this->logStatus('Shelly OFF confirmation status.', $channel, $after, $attempt + 1);
                if ($after['online'] && !$after['channels'][$channel]['has_errors'] && $after['channels'][$channel]['output'] === false) { return; }
            } catch (\Throwable $error) {
                Diagnostics::write('Shelly OFF status confirmation unavailable.', ['channel' => $channel, 'attempt' => $attempt + 1], $error);
            }
        }
        throw new RuntimeException('OFF status could not be confirmed.');
    }
    private function validate(int $channel, int $seconds): void
    {
        if (!in_array($channel, [0, 1], true) || $seconds < 1 || $seconds > 14400) { throw new RuntimeException('Invalid court-light channel or duration.'); }
    }
    private function status(): array
    {
        return (new CloudStatus(fn ($body) => $this->request('/get', $body)))->check($this->secret);
    }
    private function logStatus(string $event, int $channel, array $report, ?int $attempt = null): void
    {
        $state = $report['channels'][$channel] ?? [];
        Diagnostics::write($event, ['channel' => $channel, 'attempt' => $attempt,
            'online' => $report['online'] ?? null, 'output' => $state['output'] ?? null,
            'has_errors' => $state['has_errors'] ?? null,
            'timer_started_at' => $state['timer_started_at'] ?? null,
            'timer_duration' => $state['timer_duration'] ?? null]);
    }
    private function post(string $endpoint, array $body): void
    {
        [$code, $raw] = $this->request($endpoint, json_encode($body, JSON_THROW_ON_ERROR));
        if ($code !== 200) {
            $providerCode = json_decode($raw, true)['error'] ?? null;
            $knownCodes = ['DEVICE_FAILED_COMMAND', 'DEVICE_OFFLINE', 'DEVICE_INVALID_MODE', 'DEVICE_INVALID_CHANNEL',
                'BAD_REQUEST', 'INSTANCE_NOT_FOUND', 'DEVICE_NOT_FOUND', 'UNEXPECTED_SUBSERVICE_ERROR'];
            Diagnostics::write('Shelly switch command rejected.', ['channel' => $body['channel'], 'on' => $body['on'],
                'http_status' => $code, 'provider_error' => in_array($providerCode, $knownCodes, true) ? $providerCode : 'UNRECOGNIZED', 'state' => 'review']);
            throw new RuntimeException('Shelly did not acknowledge the pilot command.');
        }
    }
    private function request(string $endpoint, string $body): array
    {
        $started = microtime(true);
        $safeBody = json_decode($body, true);
        $context = ['stage' => $endpoint === '/get' ? 'status' : 'switch',
            'channel' => $safeBody['channel'] ?? null, 'on' => $safeBody['on'] ?? null];
        Diagnostics::write('Shelly request started.', $context);
        try {
            if (!$this->transport) { usleep(1100000); }
            $remainingMs = (int) floor(($this->deadline - $this->time()) * 1000);
            if ($remainingMs <= 0) { throw new RuntimeException('Shelly operation time budget exhausted.'); }
            $timeoutMs = min($endpoint === '/get' ? 4000 : 10000, $remainingMs);
            $context += ['timeout_ms' => $timeoutMs];
            if ($this->transport) {
                $result = ($this->transport)($endpoint, $safeBody, $timeoutMs);
                Diagnostics::write('Shelly response received.', $context + ['http_status' => $result[0]]);
                return $result;
            }
            // The helper holds the same per-account lock used by read-only probes. Pace all
            // requests, including preflight and confirmation, below Shelly's 1 request/sec limit.
            $handle = curl_init(PrivateSettings::SERVER.'/v2/devices/api'.$endpoint.'?auth_key='.rawurlencode($this->secret));
            $raw = '';
            try {
                curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
                    CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$raw): int {
                        if (strlen($raw) + strlen($chunk) > 65536) { return 0; }
                        $raw .= $chunk; return strlen($chunk);
                    },
                ]);
                if (curl_exec($handle) === false) {
                    Diagnostics::write('Shelly transport failed.', $context + ['curl_errno' => curl_errno($handle),
                        'http_status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                        'elapsed_ms' => (int) ((microtime(true) - $started) * 1000)], new RuntimeException());
                    throw new RuntimeException();
                }
                Diagnostics::write('Shelly response received.', $context + [
                    'http_status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'elapsed_ms' => (int) ((microtime(true) - $started) * 1000)]);
                return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $raw];
            } finally { curl_close($handle); }
        } catch (\Throwable $error) {
            Diagnostics::write('Shelly request failed; outcome may be uncertain.', $context, $error);
            throw new RuntimeException('Pilot command outcome is uncertain. Do not repeat ON.');
        }
    }
}
