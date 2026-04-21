<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels great to relax.
|
*/

/*
|--------------------------------------------------------------------------
| CVE-2024-52301: Prevent environment manipulation via query string
|--------------------------------------------------------------------------
|
| PHP converts dots and spaces in query-string parameter names to underscores
| when populating $_GET (e.g. ?APP.ENV=testing sets $_GET['APP_ENV']).
| Strip any such normalised key before the framework loads.
| Backport of the fix in laravel/framework 6.20.45 / 7.30.7 / 8.83.28.
|
*/

if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    foreach (explode('&', $_SERVER['QUERY_STRING']) as $pair) {
        if ($pair === '') {
            continue;
        }

        [$rawKey] = explode('=', $pair, 2);
        $decodedKey = urldecode($rawKey);
        $normalizedKey = str_replace(['.', ' '], '_', $decodedKey);

        if ($decodedKey !== $normalizedKey) {
            unset($_GET[$normalizedKey]);

            if (isset($_REQUEST[$normalizedKey]) && ! isset($_POST[$normalizedKey])) {
                unset($_REQUEST[$normalizedKey]);
            }
        }
    }
}

require __DIR__.'/../bootstrap/autoload.php';

/*
|--------------------------------------------------------------------------
| Turn On The Lights
|--------------------------------------------------------------------------
|
| We need to illuminate PHP development, so let us turn on the lights.
| This bootstraps the framework and gets it ready for use, then it
| will load up this application so that we can run it and send
| the responses back to the browser and delight our users.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
