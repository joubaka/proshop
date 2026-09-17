<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\CatalogService;
use App\Shop\Order;
use App\Shop\PayFast\InvalidNotification;
use App\Shop\PayFast\VerificationUnavailable;
use App\Shop\Payment;
use App\Shop\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class PayFastController extends Controller
{
    public function start(string $uuid, CatalogService $catalog, PaymentService $payments)
    {
        abort_unless(config('shop.checkout_enabled') && config('shop.payfast.enabled'), 404);
        $channel = $catalog->channel();
        $order = Order::query()->where('shop_channel_id', $channel->id)->where('uuid', $uuid)->firstOrFail();
        try {
            $checkout = $payments->checkout($order);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
        return response()->view('shop.payfast-redirect', compact('checkout', 'channel'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function notify(Request $request, PaymentService $payments)
    {
        try {
            $result = $payments->receive($request);
            if ($result === 'amount_mismatch') {
                return response('INVALID', 400)->header('Content-Type', 'text/plain');
            }
            return response('OK', 200)->header('Content-Type', 'text/plain');
        } catch (VerificationUnavailable $e) {
            report($e);
            return response('RETRY', 503)->header('Content-Type', 'text/plain');
        } catch (InvalidNotification $e) {
            report($e);
            return response('INVALID', 400)->header('Content-Type', 'text/plain');
        }
    }

    public function returned(string $payment)
    {
        $payment = Payment::query()->where('uuid', $payment)->with('order')->firstOrFail();
        return redirect()->to(URL::temporarySignedRoute('shop.orders.show', now()->addDays(7), ['uuid' => $payment->order->uuid]));
    }

    public function cancelled(string $payment)
    {
        $payment = Payment::query()->where('uuid', $payment)->with('order')->firstOrFail();
        return redirect()->to(URL::temporarySignedRoute('shop.orders.show', now()->addDays(7), ['uuid' => $payment->order->uuid]))
            ->with('payment_cancelled', 'Payment was cancelled. Your order remains unpaid while the reservation is active.');
    }
}
