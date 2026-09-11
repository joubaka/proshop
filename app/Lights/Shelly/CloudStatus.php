<?php

namespace App\Lights\Shelly;

use Closure;
use RuntimeException;

/** Deliberately no general RPC, configurable URL, relay command or retry method. */
class CloudStatus
{
    public function __construct(private ?Closure $transport = null) {}

    public function check(#[\SensitiveParameter] string $secret): array
    {
        $body = json_encode(['ids' => [PrivateSettings::DEVICE], 'select' => ['status'],
            'pick' => ['status' => ['sys', 'switch:0', 'switch:1']]], JSON_THROW_ON_ERROR);
        try {
            [$code, $raw] = $this->transport
                ? ($this->transport)($body, $secret)
                : $this->request($body, $secret);
        } catch (ConnectionFailure $error) {
            // Our own fixed numeric-code mapping is safe; discard the exception chain.
            throw new RuntimeException($error->getMessage());
        } catch (\Throwable) {
            // Never propagate transport errors: URLs may contain the authorization key.
            throw new RuntimeException('Shelly could not be reached securely. No switching command was sent.');
        }
        if (in_array($code, [401, 403], true)) { throw new RuntimeException('Shelly rejected the key. Check the key and cloud server in the Shelly app.'); }
        if ($code === 429) { throw new RuntimeException('Shelly rate limit reached. Wait before checking again.'); }
        if ($code !== 200) { throw new RuntimeException('Shelly did not return a successful status response.'); }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_is_list($data) || count($data) !== 1) { throw new RuntimeException('Unexpected Shelly status response.'); }
        $device = $data[0];
        if (!is_array($device) || ($device['id'] ?? null) !== PrivateSettings::DEVICE
            || ($device['code'] ?? null) !== 'SPSW-202PE12UL' || ($device['gen'] ?? null) !== 'G2'
            || !in_array($device['online'] ?? null, [0, 1], true)) {
            throw new RuntimeException('Device identity or model did not match the confirmed Shelly Pro 2 PM.');
        }
        $channels = [];
        foreach ([0, 1] as $channel) {
            $status = $device['status']['switch:'.$channel] ?? [];
            if (!is_array($status)) { $status = []; }
            $channels[] = ['channel' => $channel,
                'output' => is_bool($status['output'] ?? null) ? $status['output'] : null,
                'watts' => $this->number($status['apower'] ?? null),
                'volts' => $this->number($status['voltage'] ?? null),
                'has_errors' => !empty($status['errors']),
                'timer_started_at' => $this->number($status['timer_started_at'] ?? null),
                'timer_duration' => $this->number($status['timer_duration'] ?? null),
            ];
        }
        return ['online' => $device['online'] === 1,
            'device_time' => $this->number($device['status']['sys']['unixtime'] ?? null),
            'checked_at' => time(), 'channels' => $channels];
    }

    private function number(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? $value : null;
    }

    private function request(string $body, #[\SensitiveParameter] string $secret): array
    {
        if (!function_exists('curl_exec')) { throw new ConnectionFailure(0); }
        $handle = curl_init(PrivateSettings::SERVER.'/v2/devices/api/get?auth_key='.rawurlencode($secret));
        $raw = '';
        try {
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$raw): int {
                    if (strlen($raw) + strlen($chunk) > 65536) { return 0; }
                    $raw .= $chunk; return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false) { throw new ConnectionFailure(curl_errno($handle)); }
            return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $raw];
        } finally { curl_close($handle); }
    }
}
