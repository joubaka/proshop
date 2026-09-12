<?php

namespace Tests\Feature;

use App\User;
use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class AuthenticationRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        $util = Mockery::mock(BusinessUtil::class)->makePartial();
        $util->shouldReceive('activityLog')->andReturnNull(); // Logger is exercised separately.
        $this->app->instance(BusinessUtil::class, $util);
    }

    private function account(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'player', 'password' => bcrypt('correct-password'), 'business_id' => 1,
            'status' => 'active', 'allow_login' => true, 'user_type' => 'user',
        ], $overrides));
    }

    public function test_active_staff_can_log_in_by_username(): void
    {
        $user = $this->account();
        $this->post('/login', ['username' => 'player', 'password' => 'correct-password'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public static function disabledAccounts(): array
    {
        return [
            'inactive user' => [['status' => 'inactive'], false],
            'login disabled' => [['allow_login' => false], false],
            'inactive business' => [[], true],
        ];
    }

    #[DataProvider('disabledAccounts')]
    public function test_disabled_accounts_are_logged_out(array $overrides, bool $inactiveBusiness): void
    {
        $this->account($overrides);
        if ($inactiveBusiness) {
            DB::table('business')->where('id', 1)->update(['is_active' => false]);
        }
        $this->post('/login', ['username' => 'player', 'password' => 'correct-password'])
            ->assertRedirect('/pos/login')->assertSessionHas('status.success', 0);
        $this->assertGuest();
    }

    public function test_wrong_password_and_deleted_user_cannot_log_in(): void
    {
        $user = $this->account();
        $this->postJson('/login', ['username' => 'player', 'password' => 'wrong-password'])->assertUnprocessable();
        $this->assertGuest();
        $user->delete();
        $this->postJson('/login', ['username' => 'player', 'password' => 'correct-password'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_repeated_invalid_logins_are_throttled(): void
    {
        $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['username' => 'player', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/login', ['username' => 'player', 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_password_change_requires_current_password_and_ignores_submitted_user_id(): void
    {
        $other = $this->account();
        $user = $this->signInWithPermissions();
        $this->post('/user/update-password', ['current_password' => 'wrong', 'new_password' => 'updated-password', 'confirm_password' => 'updated-password'])
            ->assertRedirect('/user/profile')->assertSessionHas('status.success', 0);
        $this->assertTrue(Hash::check('test-password', $user->fresh()->password));
        $this->post('/user/update-password', [
            'current_password' => 'test-password', 'new_password' => 'A123', 'confirm_password' => 'A123', 'user_id' => $other->id,
        ])->assertRedirect('/user/profile')->assertSessionHas('status.success', 1);
        $this->assertTrue(Hash::check('A123', $user->fresh()->password));
        $this->assertTrue(Hash::check('correct-password', $other->fresh()->password));
    }

    public function test_logout_clears_authentication_and_business_session(): void
    {
        $this->signInWithPermissions();
        $this->post('/logout')->assertRedirect('/pos/login')->assertSessionMissing('user')->assertSessionMissing('business');
        $this->assertGuest();
    }
}
