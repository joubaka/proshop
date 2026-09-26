<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\AccountPaymentAttempt;
use App\Shop\Order;
use App\Shop\Payment;
use App\Shop\PaymentService;
use App\Shop\ShopStaffAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentReviewController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $this->authorizeReview($request);
        $locations = $request->user()->permitted_locations();
        $orders = Order::query()->where('payment_status', 'payment_exception')
            ->whereHas('channel', fn ($query) => $query->where('business_id', $businessId)
                ->when($locations !== 'all', fn ($query) => $query->whereIn('location_id', $locations)))
            ->with(['channel', 'payments' => fn ($query) => $query->where('status', 'requires_attention')])
            ->latest()->paginate(50, ['*'], 'orders_page')->withQueryString();
        $accountAttempts = AccountPaymentAttempt::query()->where('business_id', $businessId)
            ->where('status', 'review_required')
            ->when($locations !== 'all', fn ($query) => $query->whereHas('transaction', fn ($transaction) => $transaction->whereIn('location_id', $locations)))
            ->with(['customer', 'transaction'])->latest()->paginate(50, ['*'], 'accounts_page')->withQueryString();

        return view('shop.admin-payment-reviews', compact('orders', 'accountAttempts'));
    }

    public function retryOrder(Request $request, Payment $payment, PaymentService $payments)
    {
        $businessId = $this->authorizeReview($request);
        $payment->load('order.channel');
        abort_unless((int) $payment->order->channel->business_id === $businessId, 404);
        $locations = $request->user()->permitted_locations();
        abort_unless($locations === 'all' || in_array((int) $payment->order->channel->location_id, array_map('intval', $locations), true), 403);
        try {
            $payments->retryFinalization($payment, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('shop.admin.orders.show', $payment->order->uuid)
            ->with('status', ['success' => 1, 'msg' => 'Verified payment reconciled and the POS sale was finalized.']);
    }

    private function authorizeReview(Request $request): int
    {
        $businessId = (int) $request->session()->get('user.business_id');
        abort_unless(ShopStaffAccess::allows($request->user(), $businessId, 'shop.payments.review'), 403);

        return $businessId;
    }
}
