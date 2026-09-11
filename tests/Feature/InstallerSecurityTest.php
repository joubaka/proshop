<?php

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallController;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\RegressionTestCase;

class InstallerSecurityTest extends RegressionTestCase
{
    public function test_disabled_installer_never_clears_cache_or_enables_debug(): void
    {
        Artisan::spy();
        Cache::put('audit-sentinel', 'preserved');
        foreach (['/install-start', '/install/check-server', '/install/details', '/install/success', '/install/update'] as $url) {
            $this->get($url)->assertNotFound();
        }
        foreach (['/install/post-details', '/install/install-alternate', '/install/update'] as $url) {
            $this->post($url)->assertNotFound();
        }
        $this->assertSame('preserved', Cache::get('audit-sentinel'));
        $this->assertFalse(config('app.debug'));
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_existing_installation_rejects_initial_setup_without_side_effects(): void
    {
        config(['installer.enabled' => true]);
        Artisan::spy();
        $this->get('/install-start')->assertNotFound();
        $this->get('/install/details')->assertNotFound();
        $this->post('/install/post-details')->assertNotFound();
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_alternate_install_requires_a_live_one_time_setup_session(): void
    {
        config(['installer.enabled' => true]);
        Artisan::spy();
        $this->post('/install/install-alternate')->assertForbidden();
        $this->withSession(['installer.expires_at' => time() - 1])->post('/install/install-alternate')->assertForbidden();
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_alternate_install_cannot_rebuild_an_existing_database(): void
    {
        config(['installer.enabled' => true]);
        Schema::create('migrations', fn ($table) => $table->id());
        Artisan::spy();
        $this->withSession(['installer.expires_at' => time() + 900])
            ->post('/install/install-alternate')->assertForbidden()->assertSessionMissing('installer.expires_at');
        Artisan::shouldNotHaveReceived('call');
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_updates_require_an_authenticated_system_administrator(): void
    {
        config(['installer.enabled' => true, 'constants.administrator_usernames' => 'owner']);
        Artisan::spy();
        $this->get('/install/update')->assertRedirect('/pos/login');
        $this->createIdentitySchema();
        $user = $this->signInWithPermissions();
        $this->actingAs($user)->get('/install/update')->assertForbidden();
        $this->post('/install/update')->assertForbidden();
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_enabled_administrator_can_reach_update_confirmation(): void
    {
        config(['installer.enabled' => true, 'constants.administrator_usernames' => 'owner']);
        $controller = Mockery::mock(InstallController::class)->makePartial();
        $controller->shouldReceive('updateConfirmation')->once()->andReturn(response('Update confirmation'));
        $this->app->instance(InstallController::class, $controller);
        $this->createIdentitySchema();
        $user = $this->signInWithPermissions();
        $user->update(['username' => 'owner']);
        $this->actingAs($user)->get('/install/update')->assertOk()->assertSee('Update confirmation');
    }
}
