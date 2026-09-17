<?php

namespace App\Shop;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderOperationsService
{
    public function expireReservations(): int
    {
        if (!Schema::hasTable('shop_orders') || !Schema::hasTable('shop_stock_reservations')) {
            return 0;
        }
        $ids = Order::query()->where('payment_status', 'pending')
            ->where('reservation_expires_at', '<=', now())->orderBy('id')->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += DB::transaction(function () use ($id) {
                $order = Order::query()->whereKey($id)->lockForUpdate()->first();
                if (!$order || $order->payment_status !== 'pending' || $order->reservation_expires_at->isFuture()) {
                    return 0;
                }
                $order->reservations()->where('status', 'active')->update([
                    'status' => 'released', 'released_at' => now(),
                ]);
                $order->payments()->where('status', 'pending')->update(['status' => 'expired']);
                $order->update(['payment_status' => 'expired', 'order_status' => 'expired']);
                $order->events()->create(['event_type' => 'reservation_expired']);
                return 1;
            }, 3);
        }
        return $expired;
    }

    public function markReady(Order $order, int $actorId): Order
    {
        return $this->transition($order, $actorId, 'not_ready', 'ready_for_collection', 'order_ready');
    }

    public function markCollected(Order $order, int $actorId): Order
    {
        return $this->transition($order, $actorId, 'ready_for_collection', 'collected', 'order_collected', true);
    }

    public function cancelUnpaid(Order $order, int $actorId, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actorId, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== 'pending' || $locked->order_status !== 'awaiting_payment') {
                throw ValidationException::withMessages(['order' => 'Only an unpaid order awaiting payment can be cancelled.']);
            }
            $locked->reservations()->where('status', 'active')->update(['status' => 'released', 'released_at' => now()]);
            $locked->payments()->where('status', 'pending')->update(['status' => 'cancelled']);
            $locked->update([
                'payment_status' => 'cancelled', 'order_status' => 'cancelled',
                'fulfilment_status' => 'cancelled', 'cancelled_at' => now(),
            ]);
            $locked->events()->create([
                'event_type' => 'order_cancelled', 'actor_type' => 'user', 'actor_id' => $actorId,
                'metadata' => ['reason' => trim($reason)],
            ]);
            return $locked->fresh();
        }, 3);
    }

    private function transition(
        Order $order,
        int $actorId,
        string $from,
        string $to,
        string $event,
        bool $complete = false
    ): Order {
        return DB::transaction(function () use ($order, $actorId, $from, $to, $event, $complete) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== 'paid' || $locked->order_status === 'cancelled') {
                throw ValidationException::withMessages(['order' => 'Only a paid active order can be fulfilled.']);
            }
            if ($locked->fulfilment_status === $to) {
                return $locked;
            }
            if ($locked->fulfilment_status !== $from) {
                throw ValidationException::withMessages(['order' => 'This fulfilment step is out of sequence.']);
            }
            $changes = ['fulfilment_status' => $to];
            if ($complete) {
                $changes['order_status'] = 'completed';
            }
            $locked->update($changes);
            $locked->events()->create([
                'event_type' => $event, 'actor_type' => 'user', 'actor_id' => $actorId,
                'metadata' => ['fulfilment_method' => $locked->fulfilment_method],
            ]);
            return $locked->fresh();
        }, 3);
    }
}
