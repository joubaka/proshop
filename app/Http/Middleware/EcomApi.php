<?php

namespace App\Http\Middleware;

use Closure;
use Modules\Ecommerce\Entities\EcomApiSetting;

class EcomApi
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        abort_unless(class_exists(EcomApiSetting::class), 503, 'Ecommerce integration is unavailable.');
        $token = $request->header('API-TOKEN');
        abort_unless(is_string($token) && trim($token) !== '', 401);
        $is_api_settings_exists = EcomApiSetting::where('api_token', $token)
                                            // ->where('shop_domain', $shop_domain)
                                            ->exists();

        if (!$is_api_settings_exists) {
            abort(401, 'Invalid API credentials.');
        }
        return $next($request);
    }
}
