<?php

namespace App\Http\Middleware;

use App\User;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, function ($request) use ($next) {
            $user = $request->user();
            if ($user instanceof User) {
                // Never trust cached business/session attributes after access is revoked.
                $current = $user->fresh(['business']);
                $sessionMismatch = $request->hasSession() && $request->session()->has('user')
                    && ((int) $request->session()->get('user.id') !== (int) $user->id
                        || (int) $request->session()->get('user.business_id') !== (int) $current?->business_id);
                if (!$current || $current->status !== 'active' || !$current->allow_login
                    || !$current->business || !$current->business->is_active || $sessionMismatch) {
                    $guard = $this->auth->guard();
                    if ($guard instanceof StatefulGuard) {
                        $guard->logout();
                    }
                    if ($request->hasSession()) {
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();
                    }
                    abort(403, 'Account access is no longer available.');
                }
            }
            return $next($request);
        }, ...$guards);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            return route('pos.login');
        }
    }
}
