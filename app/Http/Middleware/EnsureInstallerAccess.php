<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureInstallerAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('installer.enabled'), 404);

        return $next($request);
    }
}
