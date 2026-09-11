<?php

namespace App\Http\Middleware;

use App\Lights\Portal;
use Closure;
use Illuminate\Support\Facades\Auth;

class LightsAccess
{
    public function handle($request, Closure $next, string $level = 'public')
    {
        app(\App\Lights\PayFast\Settings::class)->apply();
        abort_unless(app(Portal::class)->enabled(), 404);
        $guard = Auth::guard('lights');
        $member = $guard->user()?->fresh();
        if ($member && !$member->active) { $guard->logout(); $member = null; }
        if ($level !== 'public' && !$member) {
            return $request->expectsJson() ? response()->json(['message' => 'Please sign in to Lights.'], 401) : redirect()->route('lights.login');
        }
        if ($level === 'admin') { abort_unless($member?->is_admin, 403); }
        if ($member) { $guard->setUser($member); }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
