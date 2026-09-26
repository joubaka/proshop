<?php

namespace App\Shop;

use App\Shop\PayFast\InvalidNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProviderPaymentClaim
{
    public function claim(string $gateway, string $reference, string $ownerType, string|int $ownerId): void
    {
        $ownerId = (string) $ownerId;
        $existing = DB::table('shop_provider_payment_references')
            ->where('gateway', $gateway)->where('provider_reference', $reference)->first();
        if ($existing) {
            $this->assertOwner($existing, $ownerType, $ownerId);
            return;
        }

        try {
            DB::table('shop_provider_payment_references')->insert([
                'gateway' => $gateway,
                'provider_reference' => $reference,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $existing = DB::table('shop_provider_payment_references')
                ->where('gateway', $gateway)->where('provider_reference', $reference)->first();
            if (!$existing) {
                throw $e;
            }
            $this->assertOwner($existing, $ownerType, $ownerId);
        }
    }

    private function assertOwner(object $existing, string $ownerType, string $ownerId): void
    {
        if ($existing->owner_type !== $ownerType || (string) $existing->owner_id !== $ownerId) {
            throw new InvalidNotification('PayFast reference has already been used.');
        }
    }
}
