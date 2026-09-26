<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\CustomerContactLink;
use App\Shop\AccountPaymentAttempt;
use App\Shop\Order;
use App\Transaction;
use App\Shop\Channel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Auth;

class CustomerDashboardController extends Controller
{
    public function index()
    {
        $customer = Auth::guard('shop_customer')->user();
        $links = CustomerContactLink::query()->where('shop_customer_id', $customer->id)
            ->where('status', 'verified')->with('contact.business')->get();
        $invoices = collect();
        foreach ($links as $link) {
            $rows = Transaction::query()->where('business_id', $link->business_id)
                ->where('contact_id', $link->contact_id)->where('type', 'sell')->where('status', 'final')
                ->with('payment_lines')->latest('transaction_date')->limit(100)->get();
            foreach ($rows as $invoice) {
                $paid = $invoice->payment_lines->sum(fn ($payment) => $payment->is_return ? -$payment->amount : $payment->amount);
                $invoice->portal_balance = max(0, round((float) $invoice->final_total - (float) $paid, 2));
                $invoice->portal_business_name = $link->contact->business?->name;
                $invoices->push($invoice);
            }
        }
        $orders = Order::query()->where('shop_customer_id', $customer->id)->latest()->limit(50)->get();
        $pendingLinks = CustomerContactLink::query()->where('shop_customer_id', $customer->id)->where('status', 'pending')->count();
        $totalDue = $invoices->sum('portal_balance');
        $activeTransactionIds = AccountPaymentAttempt::query()->where('shop_customer_id', $customer->id)
            ->whereNotNull('active_transaction_id')->pluck('active_transaction_id')->map(fn ($id) => (int) $id)->all();

        return response()->view('shop.account.dashboard', compact('customer', 'links', 'pendingLinks', 'invoices', 'orders', 'totalDue', 'activeTransactionIds'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function showOrder(string $uuid)
    {
        $customer = Auth::guard('shop_customer')->user();
        $order = Order::query()->where('uuid', $uuid)->where('shop_customer_id', $customer->id)
            ->with(['items', 'channel'])->firstOrFail();
        $channel = $order->channel;
        $payUrl = null;
        $activeChannelId = config('shop.enabled') ? Channel::query()
            ->where('slug', config('shop.channel'))->where('enabled', true)->value('id') : null;
        if ((int) $activeChannelId === (int) $channel->id && config('shop.payfast.enabled')
            && $order->payment_status === 'pending' && $order->reservation_expires_at->isFuture()) {
            $payUrl = URL::temporarySignedRoute('shop.payfast.start', $order->reservation_expires_at, ['uuid' => $order->uuid]);
        }

        return response()->view('shop.orders.show', compact('channel', 'order', 'payUrl'))
            ->header('Cache-Control', 'no-store, private');
    }
}
