<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\AccountPaymentAttempt;
use App\Shop\AccountPaymentService;
use App\Shop\CatalogService;
use App\Shop\PayFast\InvalidNotification;
use App\Shop\PayFast\VerificationUnavailable;
use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerAccountPaymentController extends Controller
{
    public function start(Transaction $transaction, AccountPaymentService $payments, CatalogService $catalog)
    {
        $checkout = $payments->checkout(Auth::guard('shop_customer')->user(), $transaction);
        $channel = $catalog->channel();
        return response()->view('shop.payfast-redirect', compact('checkout', 'channel'))->header('Cache-Control', 'no-store, private');
    }
    public function notify(Request $request, AccountPaymentService $payments)
    {
        try {
            $result = $payments->receive($request);
            return $result === 'amount_mismatch' ? response('INVALID', 400) : response('OK', 200);
        } catch (VerificationUnavailable $e) {
            report($e); return response('RETRY', 503);
        } catch (InvalidNotification $e) {
            report($e); return response('INVALID', 400);
        }
    }
    public function returned(string $payment)
    {
        AccountPaymentAttempt::query()->where('shop_customer_id', Auth::guard('shop_customer')->id())->findOrFail($payment);
        return redirect()->route('shop.account.dashboard')->with('status', 'Payment received for verification. Your balance updates only after PayFast confirms it.');
    }
    public function cancelled(string $payment)
    {
        $attempt = AccountPaymentAttempt::query()->where('shop_customer_id', Auth::guard('shop_customer')->id())->findOrFail($payment);
        if ($attempt->status === 'provider_pending') {
            $attempt->update(['status' => 'customer_cancelled', 'failure_reason' => 'browser_cancelled']);
        }
        return redirect()->route('shop.account.dashboard')->with('status', 'Payment was cancelled and no account payment was recorded.');
    }
}
