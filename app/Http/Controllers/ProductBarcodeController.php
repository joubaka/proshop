<?php

namespace App\Http\Controllers;

use App\Business;
use App\Product;
use App\Utils\ProductUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductBarcodeController extends Controller
{
    private function products(Request $request)
    {
        abort_unless(auth()->user()?->can('product.update'), 403);
        $businessId = (int) $request->session()->get('user.business_id');
        abort_unless($businessId && $businessId === (int) auth()->user()->business_id, 403);
        $query = Product::where('business_id', $businessId)->whereIn('type', ['single', 'variable', 'combo']);
        $locations = auth()->user()->permitted_locations();
        if ($locations !== 'all') {
            $query->whereHas('product_locations', fn ($q) => $q->whereIn('business_locations.id', $locations));
        }
        return $query;
    }

    public function edit(Request $request, int $product)
    {
        $product = $this->products($request)->with('variations')->findOrFail($product);
        return view('product.barcode', compact('product'));
    }

    public function update(Request $request, int $product, ProductUtil $util)
    {
        $this->products($request)->findOrFail($product);
        $data = $request->validate([
            'variation_id' => 'required|integer',
            'barcode' => ['required', 'string', 'max:64', 'regex:/^[!-~]+$/'],
            'previous_barcode' => 'present|nullable|string|max:255',
            'previous_sku' => 'required|string|max:255',
        ], ['barcode.regex' => 'Scan a barcode without spaces or control characters.']);

        $result = DB::transaction(function () use ($request, $product, $data, $util) {
            // Serialise barcode changes within a business before checking for collisions.
            Business::whereKey(auth()->user()->business_id)->lockForUpdate()->firstOrFail();
            $product = $this->products($request)->lockForUpdate()->findOrFail($product);
            $variation = $product->variations()->whereKey($data['variation_id'])->lockForUpdate()->firstOrFail();
            if ((string) $variation->sub_sku !== (string) $data['previous_barcode'] || (string) $product->sku !== $data['previous_sku']) {
                throw ValidationException::withMessages(['barcode' => 'This barcode changed since you opened the page. Reload before scanning again.']);
            }
            $barcode = $data['barcode'];
            $single = $product->type !== 'variable' && $product->variations()->count() === 1;
            $duplicateProduct = Product::where('business_id', $product->business_id)->where('sku', $barcode);
            if ($single) $duplicateProduct->where('id', '!=', $product->id);
            $duplicateVariation = \App\Variation::where('sub_sku', $barcode)->where('id', '!=', $variation->id)
                ->whereHas('product', fn ($q) => $q->where('business_id', $product->business_id));
            if ($duplicateProduct->exists() || $duplicateVariation->exists()) {
                throw ValidationException::withMessages(['barcode' => 'That barcode is already assigned to another product or variation. Nothing was changed.']);
            }
            if ($barcode !== (string) $variation->sub_sku || ($single && $barcode !== $product->sku)) {
                $old = ['barcode' => $variation->sub_sku, 'sku' => $product->sku, 'barcode_type' => $product->barcode_type];
                $variation->sub_sku = $barcode;
                $variation->save();
                if ($single) $product->sku = $barcode;
                $product->barcode_type = 'C128';
                $product->save();
                $util->activityLog($product, 'barcode_updated', null, [
                    'variation_id' => $variation->id, 'old' => $old,
                    'new' => ['barcode' => $barcode, 'sku' => $product->sku, 'barcode_type' => $product->barcode_type],
                ], false, $product->business_id);
            }
            return ['barcode' => $variation->sub_sku, 'sku' => $product->sku, 'message' => 'Barcode saved. You can now scan this product at the till.'];
        });
        return $request->expectsJson() ? response()->json($result) : back()->with('status', ['success' => 1, 'msg' => $result['message']]);
    }
}
