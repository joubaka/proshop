<?php

namespace App\Http\Controllers\Shop;

use App\BusinessLocation;
use App\Http\Controllers\Controller;
use App\Product;
use App\Shop\CatalogAdminService;
use App\Shop\Channel;
use App\Shop\ShopProduct;
use App\Shop\ShopStaffAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCatalogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeView($request);
        $businessId = (int) $request->session()->get('user.business_id');
        $permittedLocations = $request->user()->permitted_locations();
        $channels = Channel::query()->where('business_id', $businessId)
            ->when($permittedLocations !== 'all', fn ($query) => $query->whereIn('location_id', $permittedLocations))
            ->with('products')->get();
        $locations = BusinessLocation::forDropdown($businessId, false, false);
        if ($permittedLocations !== 'all') {
            $permittedLocationIds = array_map('intval', $permittedLocations);
            $locations = $locations->filter(fn ($name, $id) => in_array((int) $id, $permittedLocationIds, true));
        }
        $canManage = ShopStaffAccess::allows($request->user(), $businessId, 'shop.catalog.manage');
        return view('shop.admin-catalog', compact('channels', 'locations', 'canManage'));
    }

    public function storeChannel(Request $request)
    {
        $this->authorizeUpdate($request);
        $businessId = (int) $request->session()->get('user.business_id');
        $data = $request->validate([
            'location_id' => ['required', 'integer'], 'slug' => ['required', 'alpha_dash', 'max:80', 'unique:shop_channels,slug'],
            'name' => ['required', 'string', 'max:191'], 'enabled' => ['nullable', 'boolean'],
        ]);
        BusinessLocation::query()->where('business_id', $businessId)->findOrFail($data['location_id']);
        $this->authorizeLocation((int) $data['location_id']);
        Channel::create([
            'business_id' => $businessId, 'location_id' => $data['location_id'], 'slug' => $data['slug'],
            'name' => $data['name'], 'currency' => 'ZAR', 'enabled' => !empty($data['enabled']),
        ]);
        return redirect()->route('shop.admin.catalog.index')->with('status', ['success' => 1, 'msg' => 'Shop channel created.']);
    }

    public function editChannel(Request $request, Channel $channel)
    {
        $this->authorizeUpdate($request);
        $this->authorizeChannel($request, $channel);
        $location = BusinessLocation::query()
            ->where('business_id', $channel->business_id)
            ->findOrFail($channel->location_id);

        return view('shop.admin-channel-edit', compact('channel', 'location'));
    }

    public function updateChannel(Request $request, Channel $channel)
    {
        $this->authorizeUpdate($request);
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $channel->update([
            'name' => $data['name'],
            'enabled' => $request->boolean('enabled'),
        ]);

        return redirect()->route('shop.admin.catalog.index')
            ->with('status', ['success' => 1, 'msg' => 'Shop channel updated.']);
    }

    public function products(Request $request, Channel $channel)
    {
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
        ]);
        $search = trim($data['search'] ?? '');

        $products = Product::query()->where('business_id', $channel->business_id)->where('is_inactive', false)
            ->where('not_for_selling', false)->where('type', '!=', 'combo')->forLocation($channel->location_id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%');
                });
            })
            ->with(['variations.product_variation', 'variations.variation_location_details' => fn ($query) => $query->where('location_id', $channel->location_id)])
            ->orderBy('name')->paginate(30)->withQueryString();
        $configured = ShopProduct::query()->where('shop_channel_id', $channel->id)->get()->keyBy('product_id');
        $canManage = ShopStaffAccess::allows($request->user(), (int) $channel->business_id, 'shop.catalog.manage');
        return view('shop.admin-products', compact('channel', 'products', 'configured', 'search', 'canManage'));
    }

    public function edit(Request $request, Channel $channel, Product $product)
    {
        $this->authorizeUpdate($request);
        $this->authorizeChannel($request, $channel);
        abort_unless((int) $product->business_id === (int) $channel->business_id, 404);
        $product->load(['variations.product_variation', 'variations.variation_location_details' => fn ($query) => $query->where('location_id', $channel->location_id)]);
        $shopProduct = ShopProduct::query()->where('shop_channel_id', $channel->id)->where('product_id', $product->id)
            ->with('variations')->first();
        return view('shop.admin-product-edit', compact('channel', 'product', 'shopProduct'));
    }

    public function update(Request $request, Channel $channel, Product $product, CatalogAdminService $catalog)
    {
        $this->authorizeUpdate($request);
        $this->authorizeChannel($request, $channel);
        $existing = ShopProduct::query()->where('shop_channel_id', $channel->id)->where('product_id', $product->id)->first();
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:191', Rule::unique('shop_products', 'slug')->where('shop_channel_id', $channel->id)->ignore($existing?->id)],
            'short_description' => ['nullable', 'string', 'max:500'], 'web_description' => ['nullable', 'string', 'max:10000'],
            'featured' => ['nullable', 'boolean'], 'published' => ['nullable', 'boolean'],
            'online_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4882'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'], 'variations' => ['required', 'array'],
            'variations.*.display_name' => ['nullable', 'string', 'max:191'],
            'variations.*.published' => ['nullable', 'boolean'], 'variations.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'variations.*.safety_stock' => ['required', 'numeric', 'min:0'],
            'variations.*.maximum_order_quantity' => ['nullable', 'numeric', 'min:1'],
        ]);
        $shopProduct = $catalog->saveProduct($channel, $product, $data);
        if ($request->hasFile('online_image')) {
            $catalog->replacePrimaryImage($shopProduct, $request->file('online_image'));
        }
        return redirect()->route('shop.admin.catalog.products', $channel)->with('status', ['success' => 1, 'msg' => 'Online product settings saved.']);
    }

    private function authorizeChannel(Request $request, Channel $channel): void
    {
        $this->authorizeView($request);
        abort_unless((int) $channel->business_id === (int) $request->session()->get('user.business_id'), 404);
        $this->authorizeLocation((int) $channel->location_id);
    }

    private function authorizeView(Request $request): void
    {
        $businessId = (int) $request->session()->get('user.business_id');
        abort_unless(ShopStaffAccess::allows($request->user(), $businessId, 'shop.catalog.view')
            || ShopStaffAccess::allows($request->user(), $businessId, 'shop.catalog.manage'), 403);
    }

    private function authorizeUpdate(Request $request): void
    {
        $businessId = (int) $request->session()->get('user.business_id');
        abort_unless(ShopStaffAccess::allows($request->user(), $businessId, 'shop.catalog.manage'), 403);
    }
    private function authorizeLocation(int $id): void
    {
        $locations = auth()->user()->permitted_locations();
        abort_unless($locations === 'all' || in_array($id, array_map('intval', $locations), true), 403);
    }
}
