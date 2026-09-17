<?php

namespace App\Shop\PayFast;

use App\Shop\Order;
use App\Shop\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class Gateway
{
    public function checkout(Payment $payment, Order $order): array
    {
        $this->configured();

        $data = [
            'merchant_id' => (string) config('shop.payfast.merchant_id'),
            'merchant_key' => (string) config('shop.payfast.merchant_key'),
            'return_url' => URL::temporarySignedRoute('shop.payfast.return', now()->addDays(1), ['payment' => $payment->uuid]),
            'cancel_url' => URL::temporarySignedRoute('shop.payfast.cancel', now()->addDays(1), ['payment' => $payment->uuid]),
            'notify_url' => route('shop.payfast.notify'),
            'name_first' => $order->customer_name,
            'email_address' => $order->customer_email,
            'm_payment_id' => $payment->merchant_payment_id,
            'amount' => number_format($payment->expected_amount_cents / 100, 2, '.', ''),
            'item_name' => 'ProShop order '.$order->order_number,
        ];
        $data['signature'] = $this->signature($data);

        return ['url' => (string) config('shop.payfast.process_url'), 'fields' => $data];
    }

    public function verify(Request $request): array
    {
        $this->configured();
        $data = $request->post();
        foreach (['m_payment_id', 'pf_payment_id', 'payment_status', 'amount_gross', 'merchant_id', 'signature'] as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field])) {
                throw new InvalidNotification('Required payment field missing.');
            }
        }
        if (!$this->validSource($request->ip())) {
            throw new InvalidNotification('Unrecognised payment source.');
        }

        $provided = strtolower((string) $data['signature']);
        unset($data['signature']);
        $parameters = $this->notificationParameterString($request, $data);
        $expected = md5($parameters.'&passphrase='.urlencode(trim((string) config('shop.payfast.passphrase'))));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $provided) || !hash_equals($expected, $provided)) {
            throw new InvalidNotification('Payment signature rejected.');
        }
        if ((string) $data['merchant_id'] !== (string) config('shop.payfast.merchant_id')) {
            throw new InvalidNotification('Payment merchant rejected.');
        }
        if ((string) $data['payment_status'] !== 'COMPLETE') {
            throw new InvalidNotification('Payment is not complete.');
        }
        if (!$this->serverConfirmation($parameters)) {
            throw new InvalidNotification('PayFast did not validate the notification.');
        }

        return [
            'merchant_payment_id' => (string) $data['m_payment_id'],
            'provider_reference' => (string) $data['pf_payment_id'],
            'amount_cents' => $this->cents((string) $data['amount_gross']),
            'notification_hash' => hash('sha256', (string) $request->getContent()),
        ];
    }

    private function configured(): void
    {
        foreach (['merchant_id', 'merchant_key', 'passphrase'] as $key) {
            if (!config('shop.payfast.enabled') || !is_string(config('shop.payfast.'.$key)) || config('shop.payfast.'.$key) === '') {
                throw new \RuntimeException('Shop PayFast is not configured.');
            }
        }
        if (!app()->environment(['testing', 'acceptance']) && !str_starts_with((string) config('app.url'), 'https://')) {
            throw new \RuntimeException('The shop URL must use HTTPS for PayFast.');
        }
        foreach (['process_url', 'validate_url'] as $key) {
            if (!str_starts_with((string) config('shop.payfast.'.$key), 'https://')) {
                throw new \RuntimeException('PayFast HTTPS configuration is invalid.');
            }
        }
    }

    private function signature(array $data): string
    {
        $parameters = $this->parameterString($data);
        $passphrase = config('shop.payfast.passphrase');
        if (is_string($passphrase) && $passphrase !== '') {
            $parameters .= '&passphrase='.urlencode(trim($passphrase));
        }
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
        if (!is_string($raw) || $raw === '') {
            return $this->parameterString($data);
        }
        return implode('&', array_filter(explode('&', $raw), static function (string $part): bool {
            return urldecode(explode('=', $part, 2)[0]) !== 'signature';
        }));
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/\A\d{1,7}(?:\.\d{1,2})?\z/D', $amount)) {
            throw new InvalidNotification('Payment amount is invalid.');
        }
        [$rands, $cents] = array_pad(explode('.', $amount), 2, '0');
        return ((int) $rands * 100) + (int) str_pad($cents, 2, '0');
    }

    private function validSource(?string $ip): bool
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $value = ip2long($ip);
        foreach ((array) config('shop.payfast.source_cidrs') as $cidr) {
            [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
            $mask = -1 << (32 - (int) $bits);
            if (($value & $mask) === (ip2long($network) & $mask)) {
                return true;
            }
        }
        return false;
    }

    protected function serverConfirmation(string $parameters): bool
    {
        if (!function_exists('curl_exec')) {
            throw new VerificationUnavailable('PayFast verification transport is unavailable.');
        }
        $handle = curl_init((string) config('shop.payfast.validate_url'));
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $parameters, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $response = curl_exec($handle);
            if ($response === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new VerificationUnavailable('PayFast verification is temporarily unavailable.');
            }
            return trim((string) $response) === 'VALID';
        } finally {
            curl_close($handle);
        }
    }
}
