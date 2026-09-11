<?php

namespace Tests\Acceptance;

use App\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class LocalWorkflowTest extends \Tests\TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/scripts/local-acceptance/bootstrap.php';
        $app['config']->set('session.driver', 'array');
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('acceptance'));
        $this->assertSame('proshop_acceptance', DB::connection()->getDatabaseName());
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
        $this->assertSame(0, DB::table('business')->whereNotIn('time_format', ['12', '24'])->count());
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
        }
        parent::tearDown();
    }

    private function login(string $username = 'local.admin'): void
    {
        $this->withSession(['_token' => 'local-test-token'])->post('/login', [
            '_token' => 'local-test-token', 'username' => $username, 'password' => 'LocalAcceptance!2026',
        ])->assertRedirect();
        $this->assertAuthenticatedAs(User::where('username', $username)->firstOrFail());
    }

    public static function pages(): array
    {
        return array_map(fn ($path) => [$path], [
            '/home', '/products', '/products/create', '/purchases', '/purchases/create', '/sells', '/sells/create',
            '/contacts?type=customer', '/contacts?type=supplier', '/account/account', '/stock-transfers',
            '/stock-adjustments', '/sell-return', '/purchase-return', '/import-products', '/import-sales',
            '/user/profile', '/cash-register/create', '/reports/stock-report',
        ]);
    }

    #[DataProvider('pages')]
    public function test_administrator_pages_render_with_real_schema_and_middleware(string $path): void
    {
        $this->login();
        if ($path === '/cash-register/create') {
            \App\CashRegister::where('user_id', auth()->id())->update(['status' => 'close']);
        }
        $this->get($path)->assertOk();
    }

    public function test_cashier_is_denied_account_and_purchase_writes(): void
    {
        $this->login('local.cashier');
        $this->get('/home'); // Establish canonical business/session context.
        $this->get('/account/account')->assertForbidden();
        $this->withSession(['_token' => 'local-test-token'])->post('/purchases', ['_token' => 'local-test-token'])
            ->assertForbidden();
    }

    public function test_csrf_is_not_bypassed_in_local_acceptance(): void
    {
        $this->login();
        $this->post('/brands', ['name' => 'Must not save'])->assertStatus(419);
        $this->assertDatabaseMissing('brands', ['name' => 'Must not save']);
    }

    public function test_rendered_sign_out_form_uses_post_and_clears_the_session(): void
    {
        $this->login();
        $response = $this->get('/home')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $forms = $xpath->query('//form[@method="POST" and contains(@action,"/logout")]');
        $this->assertSame(1, $forms->length);
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $forms->item(0))->length);
        $this->withSession(['_token' => 'local-test-token'])->post('/logout', ['_token' => 'local-test-token'])
            ->assertRedirect('/login')->assertSessionMissing('business');
        $this->assertGuest();
    }

    public function test_purchase_sale_and_returns_preserve_stock_payments_and_receipts(): void
    {
        $this->login();
        $this->get('/purchases/create')->assertOk();
        $business = User::where('username', 'local.admin')->value('business_id');
        $product = \App\Product::where('business_id', $business)->where('name', 'Test Tennis Balls')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $location = \App\BusinessLocation::where('business_id', $business)->firstOrFail();
        $before = (float) DB::table('variation_location_details')->where('variation_id', $variation->id)->where('location_id', $location->id)->value('qty_available');
        $purchaseInput = [
            '_token' => 'local-test-token', 'ref_no' => 'LOCAL-AUTOMATED-PURCHASE', 'status' => 'received',
            'contact_id' => \App\Contact::where('business_id', $business)->where('type', 'supplier')->value('id'),
            'transaction_date' => '03/09/2026 10:00', 'location_id' => $location->id,
            'total_before_tax' => '100', 'final_total' => '100', 'exchange_rate' => '1',
            'discount_type' => null, 'discount_amount' => '0', 'tax_amount' => '0', 'shipping_charges' => '0',
            'purchases' => [[
                'product_id' => $product->id, 'variation_id' => $variation->id, 'quantity' => '2',
                'purchase_price' => '50', 'purchase_price_inc_tax' => '50', 'pp_without_discount' => '50',
                'discount_percent' => '0', 'item_tax' => '0', 'purchase_line_tax_id' => null,
                'product_unit_id' => $product->unit_id, 'sub_unit_id' => $product->unit_id,
            ]],
            'payment' => [['amount' => '40', 'method' => 'cash', 'paid_on' => '03/09/2026 10:00']],
        ];
        $this->withSession(['_token' => 'local-test-token'])->post('/purchases', $purchaseInput)
            ->assertRedirect('/purchases')->assertSessionHas('status.success', 1);
        $purchase = \App\Transaction::where('ref_no', 'LOCAL-AUTOMATED-PURCHASE')->firstOrFail();
        $this->assertSame('partial', $purchase->payment_status);
        $this->assertEquals(100, $purchase->final_total);
        $this->assertEquals(40, $purchase->payment_lines()->sum('amount'));
        $this->assertEquals($before + 2, DB::table('variation_location_details')->where('variation_id', $variation->id)->where('location_id', $location->id)->value('qty_available'));
        $this->get('/purchases/'.$purchase->id)->assertOk()->assertSee('LOCAL-AUTOMATED-PURCHASE');

        \App\CashRegister::where('user_id', auth()->id())->update(['status' => 'close']);
        $this->post('/cash-register', ['_token' => 'local-test-token', 'amount' => '0', 'location_id' => $location->id])->assertRedirect();
        $this->postJson('/pos', [
            '_token' => 'local-test-token', 'status' => 'final', 'invoice_no' => 'LOCAL-AUTOMATED-SALE',
            'contact_id' => \App\Contact::where('business_id', $business)->where('is_default', 1)->value('id'),
            'location_id' => $location->id, 'discount_type' => 'fixed', 'discount_amount' => '0',
            'tax_rate_id' => null, 'final_total' => '200', 'change_return' => '0',
            'products' => [[
                'product_id' => $product->id, 'variation_id' => $variation->id, 'quantity' => '2',
                'product_type' => 'single', 'enable_stock' => 1, 'unit_price' => '100', 'unit_price_inc_tax' => '100',
                'unit_price_before_discount' => '100', 'line_discount_type' => 'fixed', 'line_discount_amount' => '0',
                'item_tax' => '0', 'tax_id' => null, 'product_unit_id' => $product->unit_id,
            ]],
            'payment' => [['amount' => '200', 'method' => 'cash']],
        ])->assertOk()->assertJsonPath('success', 1);
        $sale = \App\Transaction::where('invoice_no', 'LOCAL-AUTOMATED-SALE')->firstOrFail();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertEquals(200, $sale->payment_lines()->sum('amount'));
        $stock = fn () => (float) DB::table('variation_location_details')->where('variation_id', $variation->id)->where('location_id', $location->id)->value('qty_available');
        $this->assertEquals($before, $stock());
        $this->get('/sells/'.$sale->id)->assertOk()->assertSee('LOCAL-AUTOMATED-SALE');

        $line = $sale->sell_lines()->firstOrFail();
        $this->postJson('/sell-return', [
            '_token' => 'local-test-token', 'transaction_id' => $sale->id,
            'products' => [['sell_line_id' => $line->id, 'quantity' => '1', 'unit_price_inc_tax' => '100']],
        ])->assertOk()->assertJsonPath('success', 1);
        $this->assertEquals(1, $line->fresh()->quantity_returned);
        $this->assertEquals($before + 1, $stock());
        $this->assertEquals(100, \App\Transaction::where('return_parent_id', $sale->id)->where('type', 'sell_return')->value('final_total'));

        $purchaseLine = $purchase->purchase_lines()->firstOrFail();
        $this->post('/purchase-return', ['_token' => 'local-test-token', 'transaction_id' => $purchase->id,
            'returns' => [$purchaseLine->id => '1'], 'tax_amount' => '0'])->assertRedirect()->assertSessionHas('status.success', 1);
        $this->assertEquals(1, $purchaseLine->fresh()->quantity_returned);
        $this->assertEquals($before, $stock());
        $this->assertEquals(50, \App\Transaction::where('return_parent_id', $purchase->id)->where('type', 'purchase_return')->value('final_total'));

        // Independent new receipt, transfer completion/replay/deletion, adjustment,
        // purchase editing/deletion and register closing through the real controllers.
        $purchaseInput['ref_no'] = 'LOCAL-TRANSFER-RECEIPT';
        $purchaseInput['purchases'][0]['quantity'] = '5';
        $purchaseInput['total_before_tax'] = $purchaseInput['final_total'] = '250';
        $purchaseInput['payment'] = [];
        $this->post('/purchases', $purchaseInput)->assertSessionHas('status.success', 1);
        $receipt = \App\Transaction::where('ref_no', 'LOCAL-TRANSFER-RECEIPT')->firstOrFail();
        $destination = $location->replicate();
        $destination->name = 'Automated transfer destination';
        $destination->save();
        $destinationStock = fn () => (float) DB::table('variation_location_details')->where('variation_id', $variation->id)->where('location_id', $destination->id)->value('qty_available');
        $transferInput = [
            '_token' => 'local-test-token', 'location_id' => $location->id, 'transfer_location_id' => $destination->id,
            'ref_no' => 'LOCAL-AUTOMATED-TRANSFER', 'transaction_date' => now()->format('d/m/Y H:i'),
            'status' => 'pending', 'shipping_charges' => '0', 'final_total' => '50',
            'products' => [['product_id' => $product->id, 'variation_id' => $variation->id, 'quantity' => '1',
                'unit_price' => '50', 'enable_stock' => 1, 'product_unit_id' => $product->unit_id]],
        ];
        $this->post('/stock-transfers', $transferInput)->assertSessionHas('status.success', 1);
        $transfer = \App\Transaction::where('ref_no', 'LOCAL-AUTOMATED-TRANSFER')->where('type', 'sell_transfer')->firstOrFail();
        $this->get('/stock-transfers/'.$transfer->id.'/edit')->assertOk();
        $this->assertEquals($before + 5, $stock());
        foreach ([1, 2] as $attempt) {
            $this->postJson('/stock-transfers/update-status/'.$transfer->id, ['_token' => 'local-test-token', 'status' => 'completed'])
                ->assertOk()->assertJsonPath('success', 1);
            $this->assertEquals($before + 4, $stock());
            $this->assertEquals(1, $destinationStock());
        }
        $this->putJson('/stock-transfers/'.$transfer->id, $transferInput)->assertUnprocessable();
        $this->getJson('/stock-transfers/print/'.$transfer->id)->assertJsonPath('success', 1)->assertJsonStructure(['receipt' => ['html_content']]);
        $this->postJson('/stock-transfers/update-status/'.$transfer->id, ['_token' => 'local-test-token', 'status' => 'pending'])
            ->assertJsonPath('success', 0);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/stock-transfers/'.$transfer->id, ['_token' => 'local-test-token'])
            ->assertOk()->assertJsonPath('success', 1);
        $this->assertEquals($before + 5, $stock());
        $this->assertEquals(0, $destinationStock());

        $this->post('/stock-adjustments', [
            '_token' => 'local-test-token', 'location_id' => $location->id, 'ref_no' => 'LOCAL-ADJUSTMENT',
            'transaction_date' => now()->format('d/m/Y H:i'), 'adjustment_type' => 'normal',
            'total_amount_recovered' => '0', 'final_total' => '50', 'products' => $transferInput['products'],
        ])->assertSessionHas('status.success', 1);
        $adjustment = \App\Transaction::where('ref_no', 'LOCAL-ADJUSTMENT')->firstOrFail();
        $this->assertEquals($before + 4, $stock());
        $this->deleteJson('/stock-adjustments/'.$adjustment->id, ['_token' => 'local-test-token'])->assertJsonPath('success', 1);
        $this->assertEquals($before + 5, $stock());

        $purchaseInput['purchases'][0]['purchase_line_id'] = $receipt->purchase_lines()->firstOrFail()->id;
        $purchaseInput['purchases'][0]['quantity'] = '6';
        $purchaseInput['total_before_tax'] = $purchaseInput['final_total'] = '300';
        $this->put('/purchases/'.$receipt->id, $purchaseInput)->assertSessionHas('status.success', 1);
        $this->assertEquals($before + 6, $stock());
        $this->deleteJson('/purchases/'.$receipt->id, ['_token' => 'local-test-token'])->assertJsonPath('success', true);
        $this->assertEquals($before, $stock());
        $this->get('/cash-register/close-register')->assertOk();
        $this->post('/cash-register/close-register', ['_token' => 'local-test-token', 'user_id' => auth()->id(),
            'closing_amount' => '200', 'total_card_slips' => 0, 'total_cheques' => 0, 'closing_note' => 'Automated reconciliation'])
            ->assertSessionHas('status.success', 1);
        $this->assertSame(0, \App\CashRegister::where('user_id', auth()->id())->where('status', 'open')->count());
    }

    public function test_foreign_stock_deletions_and_cash_register_access_are_denied(): void
    {
        $this->login();
        $this->get('/home')->assertOk();
        $this->withSession(['_token' => 'local-test-token']);
        $other = User::where('username', 'local.other')->firstOrFail();
        $location = \App\BusinessLocation::where('business_id', $other->business_id)->firstOrFail();
        foreach (['sell_transfer' => 'stock-transfers', 'stock_adjustment' => 'stock-adjustments'] as $type => $path) {
            $transaction = \App\Transaction::create(['business_id' => $other->business_id, 'location_id' => $location->id,
                'type' => $type, 'status' => 'final', 'payment_status' => 'paid', 'created_by' => $other->id,
                'transaction_date' => now(), 'total_before_tax' => 0, 'final_total' => 0]);
            $this->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/'.$path.'/'.$transaction->id, ['_token' => 'local-test-token'])
                ->assertNotFound();
            $this->assertNotNull($transaction->fresh());
        }
        $register = \App\CashRegister::create(['business_id' => $other->business_id, 'location_id' => $location->id,
            'user_id' => $other->id, 'status' => 'open']);
        $this->get('/cash-register/close-register/'.$register->id)->assertNotFound();
        $this->get('/cash-register/'.$register->id)->assertNotFound();
        $this->post('/cash-register', ['_token' => 'local-test-token', 'location_id' => $location->id, 'amount' => 0])->assertNotFound();
        $this->post('/cash-register/close-register', ['_token' => 'local-test-token', 'user_id' => $other->id, 'closing_amount' => 0])
            ->assertNotFound();
        $this->assertSame('open', $register->fresh()->status);
        $ownLocation = \App\BusinessLocation::where('business_id', auth()->user()->business_id)->firstOrFail();
        $foreignProduct = \App\Product::where('business_id', $other->business_id)->firstOrFail();
        $this->postJson('/stock-adjustments', ['_token' => 'local-test-token', 'location_id' => $ownLocation->id,
            'products' => [['product_id' => $foreignProduct->id, 'variation_id' => $foreignProduct->variations()->firstOrFail()->id]]])
            ->assertNotFound();
        $this->postJson('/stock-transfers', ['_token' => 'local-test-token', 'location_id' => $ownLocation->id,
            'transfer_location_id' => $location->id])->assertNotFound();
    }
}
