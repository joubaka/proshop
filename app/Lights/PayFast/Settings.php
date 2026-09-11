<?php

namespace App\Lights\PayFast;

use App\Lights\Portal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class Settings
{
    public function __construct(private Portal $portal) {}

    public function apply(): void
    {
        $row = $this->row();
        if (!$row) { return; }

        try {
            $sandbox = (bool) $row->sandbox;
            config([
                'lights.payfast.enabled' => (bool) $row->enabled,
                'lights.payfast.sandbox' => $sandbox,
                'lights.payfast.merchant_id' => $this->decrypt($row->merchant_id),
                'lights.payfast.merchant_key' => $this->decrypt($row->merchant_key),
                'lights.payfast.passphrase' => $this->decrypt($row->passphrase),
                'lights.payfast.process_url' => $sandbox
                    ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process',
                'lights.payfast.validate_url' => $sandbox
                    ? 'https://sandbox.payfast.co.za/eng/query/validate' : 'https://www.payfast.co.za/eng/query/validate',
            ]);
        } catch (\Throwable) {
            // A key rotation or corrupt record must fail closed instead of enabling payments.
            config(['lights.payfast.enabled' => false]);
        }
    }

    public function summary(): array
    {
        $row = $this->row();
        return [
            'source' => $row ? 'admin' : 'environment',
            'enabled' => (bool) config('lights.payfast.enabled'),
            'sandbox' => (bool) config('lights.payfast.sandbox'),
            'merchant_id_configured' => trim((string) config('lights.payfast.merchant_id')) !== '',
            'merchant_key_configured' => trim((string) config('lights.payfast.merchant_key')) !== '',
            'passphrase_configured' => trim((string) config('lights.payfast.passphrase')) !== '',
        ];
    }

    public function save(int $actor, bool $enabled, bool $sandbox, array $credentials): void
    {
        $existing = $this->row();
        $values = [];
        $replaced = [];
        foreach (['merchant_id', 'merchant_key', 'passphrase'] as $key) {
            $newValue = trim((string) ($credentials[$key] ?? ''));
            $fallback = $existing->{$key} ?? null;
            if ($fallback === null && trim((string) config("lights.payfast.{$key}")) !== '') {
                $fallback = Crypt::encryptString((string) config("lights.payfast.{$key}"));
            }
            $values[$key] = $newValue !== '' ? Crypt::encryptString($newValue) : $fallback;
            if ($newValue !== '') { $replaced[] = $key; }
        }
        if ($enabled && collect($values)->contains(fn ($value) => !$value)) {
            throw ValidationException::withMessages(['enabled' => 'Enter all three PayFast credentials before enabling payments.']);
        }

        $now = $this->portal->now();
        $this->portal->db()->transaction(function () use ($actor, $enabled, $sandbox, $values, $replaced, $now) {
            $this->portal->db()->table('lights_payfast_settings')->updateOrInsert(['id' => 1], [
                'enabled' => $enabled, 'sandbox' => $sandbox,
                'merchant_id' => $values['merchant_id'], 'merchant_key' => $values['merchant_key'],
                'passphrase' => $values['passphrase'], 'updated_by' => $actor, 'updated_at' => $now,
            ]);
            $this->portal->db()->table('lights_events')->insert([
                'actor_id' => $actor, 'kind' => 'payfast_settings_updated',
                'details' => json_encode(['enabled' => $enabled, 'sandbox' => $sandbox,
                    'credentials_replaced' => $replaced], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }, 3);
        $this->apply();
    }

    private function row(): ?object
    {
        try {
            $schema = $this->portal->db()->getSchemaBuilder();
            return $schema->hasTable('lights_payfast_settings')
                ? $this->portal->db()->table('lights_payfast_settings')->where('id', 1)->first() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function decrypt(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }
}
