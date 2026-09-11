<?php
namespace App\Lights\Shelly;

use Closure;
use RuntimeException;

/** Used only by the gated supervised-pilot helper; never by read-only status checks. */
class CloudControl
{
    public function __construct(#[\SensitiveParameter] private string $secret, private ?Closure $transport = null) {}
    public function on(int $channel, int $seconds): array
    {
        $this->validate($channel, $seconds);
        try { $before = $this->status(); }
        catch (\Throwable) { throw new CommandNotSent('Preflight status is unavailable.'); }
        if (!$before['online'] || $before['channels'][$channel]['output'] !== false || $before['channels'][$channel]['has_errors']) {
            throw new CommandNotSent('Pilot preflight requires an online, fault-free channel reported OFF.');
        }
        $this->post('/set/switch', ['id' => PrivateSettings::DEVICE, 'channel' => $channel, 'on' => true, 'toggle_after' => $seconds]);
        // Cloud status can briefly lag a successful set request. Poll status only; never replay ON.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $after = $this->status();
            $state = $after['channels'][$channel];
            if ($after['online'] && !$state['has_errors'] && $state['output'] === true
                && is_numeric($state['timer_started_at']) && is_numeric($state['timer_duration'])
                && abs((float) $state['timer_duration'] - $seconds) < 0.01) {
                return $state;
            }
        }
        throw new RuntimeException('Shelly accepted ON but fresh output-and-timer status was not confirmed.');
    }
    public function off(int $channel): void
    {
        $this->validate($channel, 1);
        // OFF deliberately has no flip-back timer. It must never turn itself back ON.
        $this->post('/set/switch', ['id' => PrivateSettings::DEVICE, 'channel' => $channel, 'on' => false]);
        $after = $this->status();
        if (!$after['online'] || $after['channels'][$channel]['output'] !== false) { throw new RuntimeException('OFF status could not be confirmed.'); }
    }
    private function validate(int $channel, int $seconds): void
    {
        if (!in_array($channel, [0, 1], true) || $seconds < 1 || $seconds > 14400) { throw new RuntimeException('Invalid court-light channel or duration.'); }
    }
    private function status(): array
    {
        return (new CloudStatus(fn ($body) => $this->request('/get', $body)))->check($this->secret);
    }
    private function post(string $endpoint, array $body): void
    {
        [$code] = $this->request($endpoint, json_encode($body, JSON_THROW_ON_ERROR));
        if ($code !== 200) { throw new RuntimeException('Shelly did not acknowledge the pilot command.'); }
    }
    private function request(string $endpoint, string $body): array
    {
        try {
            if ($this->transport) { return ($this->transport)($endpoint, json_decode($body, true)); }
            // The helper holds the same per-account lock used by read-only probes. Pace all
            // requests, including preflight and confirmation, below Shelly's 1 request/sec limit.
            usleep(1100000);
            $handle = curl_init(PrivateSettings::SERVER.'/v2/devices/api'.$endpoint.'?auth_key='.rawurlencode($this->secret));
            $raw = '';
            try {
                curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
                    CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$raw): int {
                        if (strlen($raw) + strlen($chunk) > 65536) { return 0; }
                        $raw .= $chunk; return strlen($chunk);
                    },
                ]);
                if (curl_exec($handle) === false) { throw new RuntimeException(); }
                return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $raw];
            } finally { curl_close($handle); }
        } catch (\Throwable) { throw new RuntimeException('Pilot command outcome is uncertain. Do not repeat ON.'); }
    }
}
