<?php

namespace Tests\Feature;

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class DestructiveRequestRegressionTest extends RegressionTestCase
{
    public static function mutations(): array
    {
        return [
            'media' => ['DELETE', '/delete-media/1'],
            'import reversal' => ['POST', '/revert-sale-import/test'],
            'expired stock' => ['POST', '/stock-adjustments/remove-expired-stock/1'],
            'backup deletion' => ['DELETE', '/backup/delete/test.zip'],
            'backup creation' => ['POST', '/backup/create'],
            'account transaction' => ['DELETE', '/account/delete-account-transaction/1'],
        ];
    }

    #[DataProvider('mutations')]
    public function test_get_cannot_invoke_mutations(string $method, string $uri): void
    {
        $this->getJson($uri)->assertStatus(405);
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        $this->assertContains('web', $route->middleware());
        $this->assertContains('auth', $route->middleware());
    }

    #[DataProvider('mutations')]
    public function test_missing_csrf_token_is_rejected_with_csrf_enforcement_enabled(string $method, string $uri): void
    {
        $this->createIdentitySchema();
        $this->signInWithPermissions();
        // Enable Laravel's real CSRF checks without reloading environment or database config.
        $this->app->instance('env', 'csrf-regression');
        $this->json($method, $uri)->assertStatus(419);
    }

    public function test_valid_csrf_token_reaches_the_existing_delete_handler(): void
    {
        $this->createIdentitySchema();
        $this->signInWithPermissions(['product.update']);
        $controller = Mockery::mock(ProductController::class)->makePartial();
        $controller->shouldReceive('deleteMedia')->once()->with('1')->andReturn(['success' => true]);
        $this->app->instance(ProductController::class, $controller);
        $this->app->instance('env', 'csrf-regression');
        $this->withSession(['_token' => 'test-csrf-token'])->deleteJson('/delete-media/1', ['_token' => 'test-csrf-token'])
            ->assertOk()->assertJsonPath('success', true);
    }
}
