<?php

namespace Tests\Feature;

use App\Utils\ProductUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class AuditFixRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
    }

    private function accountSchema(): void
    {
        Schema::create('accounts', function ($table) {
            $table->id(); $table->integer('business_id'); $table->softDeletes();
        });
        Schema::create('account_transactions', function ($table) {
            $table->id(); $table->integer('account_id'); $table->string('sub_type');
            $table->integer('transfer_transaction_id')->nullable(); $table->softDeletes(); $table->timestamps();
        });
        DB::table('accounts')->insert([['id' => 1, 'business_id' => 1], ['id' => 2, 'business_id' => 2]]);
    }

    public function test_authorized_paired_transfer_deletion_removes_both_owned_sides(): void
    {
        $this->accountSchema();
        DB::table('account_transactions')->insert([
            ['id' => 1, 'account_id' => 1, 'sub_type' => 'fund_transfer', 'transfer_transaction_id' => 2],
            ['id' => 2, 'account_id' => 1, 'sub_type' => 'fund_transfer', 'transfer_transaction_id' => 1],
        ]);
        $this->signInWithPermissions(['delete_account_transaction']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/account/delete-account-transaction/1')
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSoftDeleted('account_transactions', ['id' => 1]);
        $this->assertSoftDeleted('account_transactions', ['id' => 2]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_foreign_link_cannot_delete_either_side_of_a_transfer(): void
    {
        $this->accountSchema();
        DB::table('account_transactions')->insert([
            ['id' => 1, 'account_id' => 1, 'sub_type' => 'fund_transfer', 'transfer_transaction_id' => 2],
            ['id' => 2, 'account_id' => 2, 'sub_type' => 'fund_transfer', 'transfer_transaction_id' => 1],
        ]);
        $this->signInWithPermissions(['delete_account_transaction']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/account/delete-account-transaction/1')
            ->assertOk()->assertJsonPath('success', false);
        $this->assertDatabaseHas('account_transactions', ['id' => 1, 'deleted_at' => null]);
        $this->assertDatabaseHas('account_transactions', ['id' => 2, 'deleted_at' => null]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_non_deposit_sale_payment_cannot_be_deleted_through_transfer_endpoint(): void
    {
        $this->accountSchema();
        DB::table('account_transactions')->insert(['id' => 1, 'account_id' => 1, 'sub_type' => 'sell']);
        $this->signInWithPermissions(['delete_account_transaction']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/account/delete-account-transaction/1')
            ->assertOk()->assertJsonPath('success', false);
        $this->assertDatabaseHas('account_transactions', ['id' => 1, 'deleted_at' => null]);
    }

    public function test_legacy_account_operations_cannot_mutate_data_even_with_account_permission(): void
    {
        $this->signInWithPermissions(['account.access']);
        $this->get('/payment-account')->assertRedirect('/account/account');
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/payment-account/1')->assertStatus(410);
        }
        $this->getJson('/payment-account/1/edit')->assertStatus(410);
    }

    public static function invalidPasswords(): array
    {
        return [
            'missing' => [[], 'new_password'],
            'empty' => [['new_password' => '', 'confirm_password' => ''], 'new_password'],
            'short' => [['new_password' => 'abc', 'confirm_password' => 'abc'], 'new_password'],
            'array' => [['new_password' => ['unexpected'], 'confirm_password' => 'unexpected'], 'new_password'],
            'unconfirmed' => [['new_password' => 'updated-password'], 'confirm_password'],
            'mismatch' => [['new_password' => 'updated-password', 'confirm_password' => 'different'], 'confirm_password'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_input_is_rejected_without_changing_the_hash(array $input, string $field): void
    {
        $user = $this->signInWithPermissions();
        $this->postJson('/user/update-password', ['current_password' => 'test-password'] + $input)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertTrue(Hash::check('test-password', $user->fresh()->password));
    }

    public function test_revoked_access_covers_routes_outside_main_dashboard_group_and_clears_session(): void
    {
        $user = $this->signInWithPermissions();
        DB::table('users')->where('id', $user->id)->update(['allow_login' => false]);
        $this->getJson('/get-total-unread')->assertForbidden()->assertSessionMissing('user')->assertSessionMissing('business');
        $this->assertGuest();
    }

    public function test_revoked_api_user_is_rejected_before_the_user_profile_response(): void
    {
        $user = $this->signInWithPermissions();
        $this->actingAs($user, 'api');
        DB::table('users')->where('id', $user->id)->update(['status' => 'inactive']);
        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_stale_business_session_cannot_be_reused_after_user_moves_business(): void
    {
        $user = $this->signInWithPermissions(['brand.create']);
        DB::table('users')->where('id', $user->id)->update(['business_id' => 2]);
        $this->getJson('/brands')->assertForbidden()->assertSessionMissing('business');
    }

    public function test_localised_modifier_quantities_are_parsed_once(): void
    {
        session()->put('currency', ['thousand_separator' => '.', 'decimal_separator' => ',']);
        $result = (new ProductUtil)->calculateInvoiceTotal([
            ['unit_price_inc_tax' => '10,00', 'quantity' => '1', 'modifier_price' => ['2,50'], 'modifier_quantity' => ['1,50']],
        ], null);
        $this->assertEqualsWithDelta(13.75, $result['final_total'], 0.00001);
    }

    public function test_expired_stock_removal_is_business_scoped_and_rolls_back_denials(): void
    {
        Schema::create('transactions', function ($table) {
            $table->id(); $table->integer('business_id'); $table->integer('location_id');
        });
        Schema::create('purchase_lines', function ($table) {
            $table->id(); $table->integer('transaction_id');
        });
        DB::table('transactions')->insert(['id' => 1, 'business_id' => 2, 'location_id' => 2]);
        DB::table('purchase_lines')->insert(['id' => 1, 'transaction_id' => 1]);
        $this->signInWithPermissions(['purchase.delete']);
        $this->postJson('/stock-adjustments/remove-expired-stock/1')->assertOk()->assertJsonPath('success', 0);
        $this->assertDatabaseCount('purchase_lines', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(0, DB::transactionLevel());
    }
}
