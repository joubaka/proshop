<?php

namespace Tests\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class AuthorizationAuditTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
    }

    public function test_staff_without_account_permissions_cannot_delete_payment_accounts(): void
    {
        // Legacy payment_accounts has no migration in this checkout; model-contract fixture only.
        Schema::create('payment_accounts', function ($table) {
            $table->id(); $table->string('name'); $table->softDeletes(); $table->timestamps();
        });
        DB::table('payment_accounts')->insert(['id' => 1, 'name' => 'Protected account']);
        $this->signInWithPermissions();
        $response = $this->deleteJson('/payment-account/1');
        $this->assertDatabaseHas('payment_accounts', ['id' => 1, 'deleted_at' => null]);
        $response->assertForbidden();
    }

    public function test_account_transaction_deletion_cannot_cross_business_boundaries(): void
    {
        Schema::create('accounts', function ($table) {
            $table->id(); $table->integer('business_id'); $table->string('name'); $table->softDeletes();
        });
        Schema::create('account_transactions', function ($table) {
            $table->id(); $table->integer('account_id'); $table->string('sub_type');
            $table->integer('transfer_transaction_id')->nullable(); $table->softDeletes(); $table->timestamps();
        });
        DB::table('accounts')->insert(['id' => 2, 'business_id' => 2, 'name' => 'Other business']);
        DB::table('account_transactions')->insert(['id' => 2, 'account_id' => 2, 'sub_type' => 'deposit']);
        $this->signInWithPermissions(['delete_account_transaction'], 1);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/account/delete-account-transaction/2');
        $this->assertDatabaseHas('account_transactions', ['id' => 2, 'deleted_at' => null]);
    }

    public function test_password_change_rejects_empty_new_password(): void
    {
        $user = $this->signInWithPermissions();
        $this->postJson('/user/update-password', ['current_password' => 'test-password', 'new_password' => '']);
        $this->assertTrue(Hash::check('test-password', $user->fresh()->password), 'Empty new password replaced the existing password.');
    }

    public static function revokedAccess(): array
    {
        return [
            'inactive staff' => ['users', ['status' => 'inactive']],
            'login revoked' => ['users', ['allow_login' => false]],
            'inactive business' => ['business', ['is_active' => false]],
        ];
    }

    #[DataProvider('revokedAccess')]
    public function test_revoked_access_stops_existing_sessions_from_mutating_data(string $table, array $changes): void
    {
        require_once database_path('migrations/2017_07_23_113209_create_brands_table.php');
        (new \CreateBrandsTable)->up();
        $user = $this->signInWithPermissions(['brand.create']);
        DB::table($table)->where('id', $table === 'users' ? $user->id : 1)->update($changes);
        // Refresh the guard user to simulate the next request loading fresh database attributes.
        $this->actingAs($user->fresh());
        $this->postJson('/brands', ['name' => 'Must not be created']);
        $this->assertDatabaseCount('brands', 0);
    }
}
