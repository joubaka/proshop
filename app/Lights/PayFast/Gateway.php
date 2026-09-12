<?php

namespace App\Lights\PayFast;

use App\Lights\Member;
use Illuminate\Http\Request;

class Gateway
{
    public function checkout(object $topup, Member $member): array
    {
        $this->configured();
        $data = [
            'merchant_id' => (string) config('lights.payfast.merchant_id'),
            'merchant_key' => (string) config('lights.payfast.merchant_key'),
            'return_url' => route('lights.payfast.return', $topup->id),
            'cancel_url' => route('lights.payfast.cancel', $topup->id),
            'notify_url' => route('lights.payfast.notify'),
            'email_address' => $member->email,
            'm_payment_id' => $topup->id,
            'amount' => number_format($topup->amount_cents / 100, 2, '.', ''),
            'item_name' => 'Court Lights wallet top-up',
        ];
        $data['signature'] = $this->signature($data);
        return ['url' => config('lights.payfast.process_url'), 'fields' => $data];
    }

    public function verify(Request $request): array
    {
        $this->configured();
        $data = $request->post();
        foreach (['m_payment_id', 'pf_payment_id', 'payment_status', 'amount_gross', 'merchant_id', 'signature'] as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field])) { throw new InvalidNotification('Required payment field missing.'); }
        }
        if (!$this->validSource($request->ip())) { throw new InvalidNotification('Unrecognised payment source.'); }
        $provided = strtolower((string) $data['signature']);
        unset($data['signature']);
        $parameters = $this->notificationParameterString($request, $data);
        $expected = md5($parameters.'&passphrase='.urlencode(trim((string) config('lights.payfast.passphrase'))));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $provided) || !hash_equals($expected, $provided)) {
            throw new InvalidNotification('Payment signature rejected.');
        }
        if ((string) $data['merchant_id'] !== (string) config('lights.payfast.merchant_id')) {
            throw new InvalidNotification('Payment merchant rejected.');
        }
        if ((string) $data['payment_status'] !== 'COMPLETE') { throw new InvalidNotification('Payment is not complete.'); }
        $amountCents = $this->cents((string) $data['amount_gross']);
        if (!$this->serverConfirmation($parameters)) {
            throw new InvalidNotification('PayFast did not validate the notification.');
        }
        return ['topup' => (string) $data['m_payment_id'], 'reference' => (string) $data['pf_payment_id'], 'amount_cents' => $amountCents];
    }

    private function configured(): void
    {
        if (!config('lights.payfast.enabled') || config('lights.mode') !== 'live'
            || !is_string(config('lights.payfast.merchant_id')) || config('lights.payfast.merchant_id') === ''
            || !is_string(config('lights.payfast.merchant_key')) || config('lights.payfast.merchant_key') === ''
            || !is_string(config('lights.payfast.passphrase')) || config('lights.payfast.passphrase') === '') {
            throw new \RuntimeException('PayFast is not configured.');
        }
        if (!str_starts_with((string) config('app.url'), 'https://')) { throw new \RuntimeException('The live Lights URL must use HTTPS.'); }
        foreach (['process_url', 'validate_url'] as $key) {
            if (!str_starts_with((string) config('lights.payfast.'.$key), 'https://')) { throw new \RuntimeException('PayFast HTTPS configuration is invalid.'); }
        }
    }

    private function signature(array $data): string
    {
        $parameters = $this->parameterString($data);
        $passphrase = config('lights.payfast.passphrase');
        if (is_string($passphrase) && $passphrase !== '') { $parameters .= '&passphrase='.urlencode(trim($passphrase)); }
        return md5($parameters);
    }

    private function parameterString(array $data): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            if ($key !== 'signature' && is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = $key.'='.urlencode(trim((string) $value));
            }
        }
        return implode('&', $parts);
    }

    private function notificationParameterString(Request $request, array $data): string
    {
        $raw = $request->getContent();
        if (!is_string($raw) || $raw === '') { return $this->parameterString($data); }

        $parts = array_filter(explode('&', $raw), static function (string $part): bool {
            $key = explode('=', $part, 2)[0];
            return urldecode($key) !== 'signature';
        });

        return implode('&', $parts);
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/\A\d{1,7}(?:\.\d{1,2})?\z/D', $amount)) { throw new InvalidNotification('Payment amount is invalid.'); }
        [$rands, $cents] = array_pad(explode('.', $amount), 2, '0');
        return ((int) $rands * 100) + (int) str_pad($cents, 2, '0');
    }

    private function validSource(?string $ip): bool
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { return false; }
        $value = ip2long($ip);
        foreach ((array) config('lights.payfast.source_cidrs') as $cidr) {
            [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
            $mask = -1 << (32 - (int) $bits);
            if (($value & $mask) === (ip2long($network) & $mask)) { return true; }
        }
        return false;
    }

    protected function serverConfirmation(string $parameters): bool
    {
        if (!function_exists('curl_exec')) { throw new VerificationUnavailable('PayFast verification transport is unavailable.'); }
        $handle = curl_init((string) config('lights.payfast.validate_url'));
        try {
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $parameters,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],]);
            $response = curl_exec($handle);
            if ($response === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new VerificationUnavailable('PayFast verification is temporarily unavailable.');
            }
            return trim((string) $response) === 'VALID';
        } finally { curl_close($handle); }
    }
}
