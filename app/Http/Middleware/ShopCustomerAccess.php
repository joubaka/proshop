<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopCustomerAccess
{
    public function handle(Request $request, Closure $next, string $level = 'customer')
    {
        abort_unless(config('shop.customer_accounts_enabled'), 404);
        $guard = Auth::guard('shop_customer');

        if ($level === 'guest') {
            return $guard->check() ? redirect()->route('shop.account.dashboard') : $next($request);
        }
        if (!$guard->check()) {
            return redirect()->guest(route('shop.account.login'));
        }
        $customer = $guard->user();
        if (!$customer->active) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Customer account access is no longer available.');
        }
        if ($level === 'verified' && !$customer->hasVerifiedEmail()) {
            return redirect()->route('shop.account.verification.notice');
        }

        return $next($request);
    }
}
