<?php

namespace Tests\Feature;

use App\Utils\BusinessUtil;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Mockery;
use Tests\Support\RegressionTestCase;

class LogoutRecoveryTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        $util = Mockery::mock(BusinessUtil::class)->makePartial();
        $util->shouldReceive('activityLog')->andReturnNull();
        $this->app->instance(BusinessUtil::class, $util);

        // Laravel normally skips CSRF in tests; exercise the real validation here.
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    public function test_valid_logout_invalidates_session_and_rotates_token(): void
    {
        $this->signInWithPermissions();
        $this->withSession(['_token' => 'current-token']);
        $sessionId = session()->getId();

        $this->post('/logout', ['_token' => 'current-token'])
            ->assertRedirect(route('pos.login'))
            ->assertSessionMissing('user')->assertSessionMissing('business');

        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame('current-token', session()->token());
        $this->assertNotEmpty(session()->token());
    }

    public function test_stale_authenticated_logout_requires_confirmation_with_current_token(): void
    {
        $user = $this->signInWithPermissions();
        $this->withSession(['_token' => 'current-token']);

        $response = $this->post('/logout', ['_token' => 'stale-token'])
            ->assertOk()->assertViewIs('auth.confirm-logout')
            ->assertSee('current-token')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout', ['_token' => 'current-token'])->assertRedirect(route('pos.login'));
        $this->assertGuest();
    }

    public function test_expired_guest_logout_returns_to_login(): void
    {
        $this->post('/logout', ['_token' => 'expired-token'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_logout_with_valid_token_is_idempotent(): void
    {
        $util = Mockery::mock(BusinessUtil::class);
        $util->shouldNotReceive('activityLog');
        $this->app->instance(BusinessUtil::class, $util);
        $this->withSession(['_token' => 'current-token']);

        $this->post('/logout', ['_token' => 'current-token'])->assertRedirect(route('pos.login'));
        $this->assertGuest();
    }

    public function test_json_logout_and_other_posts_keep_csrf_protection(): void
    {
        $user = $this->signInWithPermissions();
        $this->withSession(['_token' => 'current-token']);

        $this->postJson('/logout', ['_token' => 'stale-token'])->assertStatus(419);
        $this->post('/user/update-password', ['_token' => 'stale-token'])->assertStatus(419);
        $this->assertAuthenticatedAs($user);
    }

    public function test_get_request_cannot_log_out(): void
    {
        $user = $this->signInWithPermissions();
        $this->get('/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($user);
    }
}
