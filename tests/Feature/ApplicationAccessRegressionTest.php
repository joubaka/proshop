<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class ApplicationAccessRegressionTest extends RegressionTestCase
{
    public static function protectedJourneys(): array
    {
        return [
            'dashboard' => ['GET', '/home'],
            'products' => ['GET', '/products'],
            'create product' => ['POST', '/products'],
            'contacts' => ['GET', '/contacts'],
            'sales' => ['GET', '/sells'],
            'point of sale' => ['POST', '/pos'],
            'purchases' => ['POST', '/purchases'],
            'stock transfers' => ['GET', '/stock-transfers'],
            'stock adjustments' => ['GET', '/stock-adjustments'],
            'reports' => ['GET', '/reports/profit-loss'],
            'accounts' => ['GET', '/account/account'],
            'payment accounts' => ['DELETE', '/payment-account/1'],
            'users' => ['POST', '/users'],
            'roles' => ['POST', '/roles'],
            'settings' => ['POST', '/business/update'],
            'password' => ['POST', '/user/update-password'],
            'uploads' => ['POST', '/post-document-upload'],
            'bookings' => ['GET', '/bookings'],
            'audit report' => ['GET', '/reports/activity-log'],
            'backups' => ['GET', '/backup'],
            'modules' => ['POST', '/upload-module'],
        ];
    }

    #[DataProvider('protectedJourneys')]
    public function test_guests_cannot_enter_application_workflows(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_browser_guest_is_redirected_to_login(): void
    {
        $this->get('/home')->assertRedirect('/pos/login');
    }

    public function test_every_first_party_controller_route_has_an_explicit_access_boundary(): void
    {
        $publicControllers = [
            'App\\Http\\Controllers\\Auth\\LoginController',
            'App\\Http\\Controllers\\Auth\\RegisterController',
            'App\\Http\\Controllers\\Auth\\ForgotPasswordController',
            'App\\Http\\Controllers\\Auth\\ResetPasswordController',
            'App\\Http\\Controllers\\Auth\\ConfirmPasswordController',
        ];
        $publicActions = [
            'BusinessController@getRegister', 'BusinessController@postRegister',
            'BusinessController@postCheckUsername', 'BusinessController@postCheckEmail',
            'SellPosController@showInvoice', 'SellPosController@invoicePayment',
            'SellPosController@confirmPayment',
            'LightsController@login', 'LightsController@authenticate', 'LightsController@register',
            'LightsController@payfastNotify', 'LightsController@terms', 'LightsController@privacy',
            'LightsController@forgotPassword', 'LightsController@resetPasswordForm',
            'LightsController@resetPassword', 'LightsController@verifyEmail',
        ];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_starts_with($action, 'App\\Http\\Controllers\\')) {
                continue;
            }
            $checked++;
            $controller = explode('@', $action)[0];
            $shortAction = substr($action, strlen('App\\Http\\Controllers\\'));
            $middleware = $route->middleware();
            $protected = in_array('auth', $middleware, true)
                || in_array('EcomApi', $middleware, true)
                || in_array(\App\Http\Middleware\LightsAccess::class.':member', $middleware, true)
                || in_array(\App\Http\Middleware\EnsureInstallerAccess::class, $middleware, true);
            if ($controller === \App\Http\Controllers\LightsController::class) {
                $this->assertContains(\App\Http\Middleware\LightsAccess::class.':public', $middleware);
                if (str_starts_with($route->uri(), 'lights/admin')) {
                    $this->assertContains(\App\Http\Middleware\LightsAccess::class.':admin', $middleware);
                }
            }
            $this->assertTrue($protected || in_array($controller, $publicControllers, true)
                || in_array($shortAction, $publicActions, true), 'Unclassified access: '.$route->uri().' '.$action);
        }
        $this->assertGreaterThan(100, $checked, 'Route registration unexpectedly disappeared.');
    }
}
