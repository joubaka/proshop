<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withSchedule([\App\Console\ScheduleRegistrar::class, 'register'])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: ['lights/payfast/notify', 'shop/payfast/notify', 'shop/account/payfast/notify']);
        $middleware->prepend(\App\Http\Middleware\ShellySetupPrivacy::class);
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\EnsureInstallerAccess::class
        );
        $middleware->alias([
            'auth'             => \App\Http\Middleware\Authenticate::class,
            'auth.basic'       => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'cache.headers'    => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can'              => \Illuminate\Auth\Middleware\Authorize::class,
            'guest'            => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'language'         => \App\Http\Middleware\Language::class,
            'timezone'         => \App\Http\Middleware\Timezone::class,
            'SetSessionData'   => \App\Http\Middleware\SetSessionData::class,
            'setData'          => \App\Http\Middleware\IsInstalled::class,
            'authh'            => \App\Http\Middleware\IsInstalled::class,
            'EcomApi'          => \App\Http\Middleware\EcomApi::class,
            'AdminSidebarMenu' => \App\Http\Middleware\AdminSidebarMenu::class,
            'superadmin'       => \App\Http\Middleware\Superadmin::class,
            'CheckUserLogin'   => \App\Http\Middleware\CheckUserLogin::class,
            'shop.customer'    => \App\Http\Middleware\ShopCustomerAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['shelly_key']);
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // A stale logout form must not bypass CSRF or strand the user on a 419 page.
            if ($request->isMethod('POST') && $request->routeIs('logout')
                && !$request->expectsJson()
                && ($e instanceof \Illuminate\Session\TokenMismatchException
                    || ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                        && $e->getStatusCode() === 419
                        && $e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException))) {
                if (!$request->user()) {
                    return redirect()->route('login')->header('Cache-Control', 'no-store');
                }

                return response()->view('auth.confirm-logout')->withHeaders([
                    'Cache-Control' => 'no-store',
                    'X-Frame-Options' => 'DENY',
                    'Content-Security-Policy' => "frame-ancestors 'none'",
                ]);
            }

            // Preserve Laravel's validation errors and authentication responses.
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            if ($request->ajax() || $request->wantsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                return response()->json([
                    'error' => true,
                    'message' => config('app.debug') ? $e->getMessage()
                        : (\Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Request failed'),
                ], $status);
            }
        });
    })->create();
