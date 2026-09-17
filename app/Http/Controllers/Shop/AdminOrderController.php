<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\Order;
use App\Shop\OrderOperationsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeView();
        $businessId = (int) $request->session()->get('user.business_id');
        $locations = auth()->user()->permitted_locations();
        $orders = Order::query()->whereHas('channel', fn ($query) => $query->where('business_id', $businessId))
            ->when($locations !== 'all', fn ($query) => $query->whereHas('channel', fn ($channel) => $channel->whereIn('location_id', $locations)))
            ->with(['channel', 'items'])->latest()->paginate(30);
        return view('shop.admin-orders', compact('orders'));
    }

    public function show(Request $request, string $uuid)
    {
        $order = $this->order($request, $uuid)->load(['channel', 'items', 'payments', 'events']);
        return view('shop.admin-order', compact('order'));
    }

    public function ready(Request $request, string $uuid, OrderOperationsService $operations)
    {
        $this->authorizeUpdate();
        return $this->run(fn () => $operations->markReady($this->order($request, $uuid), auth()->id()), $uuid);
    }

    public function collected(Request $request, string $uuid, OrderOperationsService $operations)
    {
        $this->authorizeUpdate();
        return $this->run(fn () => $operations->markCollected($this->order($request, $uuid), auth()->id()), $uuid);
    }

    public function cancel(Request $request, string $uuid, OrderOperationsService $operations)
    {
        $this->authorizeUpdate();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        return $this->run(fn () => $operations->cancelUnpaid($this->order($request, $uuid), auth()->id(), $data['reason']), $uuid);
    }

    private function run(callable $action, string $uuid)
    {
        try {
            $action();
            return redirect()->route('shop.admin.orders.show', $uuid)
                ->with('status', ['success' => 1, 'msg' => 'Online order updated.']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    private function order(Request $request, string $uuid): Order
    {
        $this->authorizeView();
        $businessId = (int) $request->session()->get('user.business_id');
        $order = Order::query()->where('uuid', $uuid)
            ->whereHas('channel', fn ($query) => $query->where('business_id', $businessId))->firstOrFail();
        $locations = auth()->user()->permitted_locations();
        abort_unless($locations === 'all' || in_array((int) $order->channel()->value('location_id'), array_map('intval', $locations), true), 403);
        return $order;
    }

    private function authorizeView(): void
    {
        abort_unless(auth()->user()?->can('sell.view') || auth()->user()?->can('sell.create'), 403);
    }

    private function authorizeUpdate(): void
    {
        abort_unless(auth()->user()?->can('sell.update') || auth()->user()?->can('sell.create'), 403);
    }
}
