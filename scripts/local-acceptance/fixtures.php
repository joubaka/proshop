<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (!app()->environment('acceptance') || DB::connection()->getDatabaseName() !== 'proshop_acceptance') {
    throw new RuntimeException('Fixtures are restricted to the isolated acceptance database.');
}
if (DB::table('migrations')->count() !== count(glob(database_path('migrations/*.php')))) {
    throw new RuntimeException('Complete all application migrations before creating acceptance fixtures.');
}
$ensureShopFixtures = function (): void {
    $business = App\Business::where('name', 'Local Acceptance Proshop')->first();
    if (!$business) return;
    $location = $business->locations()->where('name', 'Test Court Shop')->firstOrFail();
    $product = App\Product::where('business_id', $business->id)->where('name', 'Test Tennis Balls')->firstOrFail();
    $variation = $product->variations()->firstOrFail();
    App\VariationLocationDetails::updateOrCreate([
        'product_id' => $product->id, 'product_variation_id' => $variation->product_variation_id,
        'variation_id' => $variation->id, 'location_id' => $location->id,
    ], ['qty_available' => 10]);
    $channel = App\Shop\Channel::updateOrCreate(['slug' => 'main'], [
        'business_id' => $business->id, 'location_id' => $location->id,
        'name' => 'Local Acceptance ProShop', 'currency' => 'ZAR', 'enabled' => true,
    ]);
    app(App\Shop\CatalogAdminService::class)->saveProduct($channel, $product, [
        'slug' => 'test-tennis-balls', 'short_description' => 'Synthetic tennis balls for the isolated shop pilot.',
        'web_description' => 'Acceptance-only product using POS price and stock.', 'featured' => true,
        'published' => true, 'sort_order' => 1, 'variations' => [$variation->id => [
            'display_name' => 'Can', 'published' => true, 'sort_order' => 1,
            'safety_stock' => 1, 'maximum_order_quantity' => 9,
        ]],
    ]);
};
if (App\User::where('username', 'local.admin')->exists()) {
    // Repair the first fixture revision's numeric ENUM binding; never overwrite a chosen format.
    App\Business::whereIn('name', ['Local Acceptance Proshop', 'Other Test Business'])->where('time_format', '')->update(['time_format' => '24']);
    $ensureShopFixtures();
    echo "Synthetic fixtures already exist; existing test work was preserved and shop fixtures were checked.\n";
    return;
}
DB::transaction(function () {
    (new DatabaseSeeder)->run();
    $currency = App\Currency::where('code', 'ZAR')->firstOrFail();
    $util = new App\Utils\BusinessUtil;
    foreach (['Local Acceptance Proshop', 'Other Test Business'] as $index => $name) {
        $user = App\User::create([
            'surname' => '', 'first_name' => $index ? 'Other' : 'Local', 'last_name' => 'Administrator',
            'username' => $index ? 'local.other' : 'local.admin', 'email' => ($index ? 'other' : 'admin').'@example.invalid',
            'password' => Hash::make('LocalAcceptance!2026'), 'language' => 'en', 'status' => 'active', 'allow_login' => true,
        ]);
        $business = $util->createNewBusiness([
            'name' => $name, 'currency_id' => $currency->id, 'owner_id' => $user->id,
            'tax_number_1' => 'TEST-ONLY', 'tax_label_1' => 'VAT', 'time_zone' => 'Africa/Johannesburg',
            'fy_start_month' => 1, 'accounting_method' => 'fifo', 'is_active' => true,
            'date_format' => 'd/m/Y', 'time_format' => '24', 'start_date' => '2026-01-01',
            'enabled_modules' => ['purchases', 'add_sale', 'pos', 'stock_transfers', 'stock_adjustment', 'account'],
            'pos_settings' => json_encode($util->defaultPosSettings()), 'email_settings' => [], 'sms_settings' => [],
        ]);
        $user->business_id = $business->id;
        $user->save();
        $util->newBusinessDefaultResources($business->id, $user->id);
        $location = $util->addLocation($business->id, [
            'name' => 'Test Court Shop', 'landmark' => 'Synthetic test location', 'city' => 'Cape Town',
            'state' => 'Western Cape', 'zip_code' => '0000', 'country' => 'South Africa',
        ]);
        $location->default_payment_accounts = json_encode(['cash' => ['is_enabled' => 1, 'account' => null]]);
        $location->save();
        foreach (['customer' => 'Test Customer', 'supplier' => 'Test Supplier'] as $type => $contactName) {
            App\Contact::create(['business_id' => $business->id, 'type' => $type, 'name' => $contactName,
                'first_name' => $contactName, 'created_by' => $user->id, 'mobile' => '0000000000', 'email' => $type.'@example.invalid']);
        }
        foreach (['Test Tennis Balls' => [50, 100], 'Test Racket Grip' => [25, 60]] as $productName => [$cost, $price]) {
            $product = App\Product::create([
                'name' => $productName, 'business_id' => $business->id, 'type' => 'single',
                'unit_id' => App\Unit::where('business_id', $business->id)->value('id'),
                'tax_type' => 'inclusive', 'enable_stock' => true, 'alert_quantity' => 5,
                'sku' => 'LOCAL-'.$business->id.'-'.$cost, 'barcode_type' => 'C128', 'created_by' => $user->id,
            ]);
            $product->product_locations()->attach($location->id);
            (new App\Utils\ProductUtil)->createSingleProductVariation($product, $product->sku, $cost, $cost, 100, $price, $price);
        }
        App\Account::create(['business_id' => $business->id, 'name' => 'Test Cash Account', 'account_number' => 'LOCAL-'.$business->id, 'created_by' => $user->id]);
        if (!$index) {
            $cashier = App\User::create(['surname' => '', 'first_name' => 'Local', 'last_name' => 'Cashier',
                'username' => 'local.cashier', 'email' => 'cashier@example.invalid', 'business_id' => $business->id,
                'password' => Hash::make('LocalAcceptance!2026'), 'status' => 'active', 'allow_login' => true]);
            $cashier->assignRole('Cashier#'.$business->id);
        }
    }
});
$ensureShopFixtures();
echo "Synthetic businesses, staff, customers, suppliers, products and accounts created.\n";
echo "Users: local.admin / local.cashier / local.other. Password: LocalAcceptance!2026\n";
