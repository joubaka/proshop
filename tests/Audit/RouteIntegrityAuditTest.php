<?php

namespace Tests\Audit;

use Illuminate\Support\Facades\Route;
use Tests\Support\RegressionTestCase;

// These tests assert desired behaviour. Existing defects must fail, not be blessed as a baseline.
class RouteIntegrityAuditTest extends RegressionTestCase
{
    public function test_every_registered_controller_action_exists_and_is_public(): void
    {
        $broken = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_starts_with($action, 'App\\Http\\Controllers\\')) {
                continue;
            }
            [$class, $method] = array_pad(explode('@', $action, 2), 2, '__invoke');
            if (!class_exists($class) || !method_exists($class, $method)
                || !(new \ReflectionMethod($class, $method))->isPublic()) {
                $broken[] = implode('|', $route->methods()).' '.$route->uri().' => '.$action;
            }
        }
        $this->assertSame([], $broken, "Registered actions are missing:\n".implode("\n", $broken));
    }

    public function test_destructive_actions_are_not_exposed_as_get_requests(): void
    {
        $unsafe = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)
                && preg_match('/@(deleteMedia|delete|destroyAccountTransaction|revertSaleImport|removeExpiredStock)$/', $route->getActionName())) {
                $unsafe[] = $route->uri();
            }
        }
        $this->assertSame([], $unsafe, 'Destructive GET endpoints bypass normal CSRF protection: '.implode(', ', $unsafe));
    }

    public function test_absent_ecommerce_module_returns_a_controlled_unavailable_response(): void
    {
        if (class_exists(\Modules\Ecommerce\Entities\EcomApiSetting::class)) {
            $this->getJson('/api/ecom/products')->assertUnauthorized();
        } else {
            $this->getJson('/api/ecom/products')->assertStatus(503)->assertJsonPath('message', 'Service Unavailable');
        }
    }
}
