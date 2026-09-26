<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\CustomerContactLink;
use App\Shop\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerLinkAdminController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('customer.update'), 403);
        $businessId = (int) $request->session()->get('user.business_id');
        $links = CustomerContactLink::query()->where('business_id', $businessId)
            ->with(['customer', 'contact'])->latest()->paginate(50);
        return view('shop.admin-customer-links', compact('links'));
    }

    public function verify(Request $request, CustomerContactLink $link)
    {
        $this->authorizeLink($request, $link);
        DB::transaction(function () use ($request, $link) {
            Customer::query()->whereKey($link->shop_customer_id)->lockForUpdate()->firstOrFail();
            $link = CustomerContactLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            abort_unless($link->status === 'pending', 409, 'Only pending links can be verified.');
            abort_if(CustomerContactLink::query()->where('shop_customer_id', $link->shop_customer_id)
                ->where('business_id', $link->business_id)->where('status', 'verified')
                ->whereKeyNot($link->id)->exists(), 409, 'This customer already has a verified contact for this business.');
            $link->update([
                'status' => 'verified', 'verified_by' => $request->user()->id,
                'verified_at' => now(), 'revoked_by' => null, 'revoked_at' => null,
            ]);
        }, 3);
        return back()->with('status', 'Customer account link verified.');
    }

    public function revoke(Request $request, CustomerContactLink $link)
    {
        $this->authorizeLink($request, $link);
        abort_unless($link->status === 'verified', 409, 'Only verified links can be revoked.');
        $link->update(['status' => 'revoked', 'revoked_by' => $request->user()->id, 'revoked_at' => now()]);
        return back()->with('status', 'Customer account link revoked.');
    }

    private function authorizeLink(Request $request, CustomerContactLink $link): void
    {
        abort_unless($request->user()->can('customer.update'), 403);
        abort_unless((int) $link->business_id === (int) $request->session()->get('user.business_id'), 404);
        abort_unless($link->contact()->where('business_id', $link->business_id)->whereIn('type', ['customer', 'both'])->exists(), 404);
    }
}
