<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ShellySetupPrivacy
{
    public function handle(Request $request, Closure $next)
    {
        // Run before CSRF/auth so errors on a secret-bearing form never render debug request data.
        if ($request->is('lights/admin/shelly', 'lights/admin/shelly/*')) {
            config(['app.debug' => false]);
        }
        return $next($request);
    }
}
