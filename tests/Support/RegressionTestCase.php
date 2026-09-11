<?php

namespace Tests\Support;

use App\Business;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

abstract class RegressionTestCase extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
        parent::setUp();
        // Fail before any fixture writes if someone changes the test connection.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    // Focused SQLite fixtures, NOT a substitute for the full MySQL migration chain.
    protected function createIdentitySchema(): void
    {
        Schema::create('business', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('time_zone')->default('Africa/Johannesburg');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('username')->unique();
            $table->string('first_name')->default('Tester');
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('user_type')->default('user');
            $table->string('status')->default('active');
            $table->boolean('allow_login')->default(true);
            $table->string('language')->default('en');
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
        (require base_path('vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub'))->up();
        DB::table('business')->insert([
            ['id' => 1, 'name' => 'Shop One'], ['id' => 2, 'name' => 'Shop Two'],
        ]);
    }

    protected function signInWithPermissions(array $permissions = [], int $businessId = 1): User
    {
        $user = User::create([
            'business_id' => $businessId, 'username' => 'tester-'.User::count(),
            'password' => bcrypt('test-password'), 'user_type' => 'user',
        ]);
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $business = Business::findOrFail($businessId);
        $business->enabled_modules = [];
        $business->date_format = 'd/m/Y';
        $business->time_format = 24;
        $this->actingAs($user)->withSession([
            'user' => ['id' => $user->id, 'business_id' => $businessId, 'language' => 'en'],
            'business' => $business,
            'currency' => ['symbol' => 'R', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        ]);
        // Presentation-only menu needs unrelated modules; auth and permission checks stay enabled.
        $this->withoutMiddleware(\App\Http\Middleware\AdminSidebarMenu::class);
        return $user;
    }
}
