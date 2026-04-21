<?php
namespace App\Http\Middleware;
<<<<<<< HEAD
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
=======
use Illuminate\Http\Request;
use Fideloper\Proxy\TrustProxies as Middleware;
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
<<<<<<< HEAD
     * @var array<int, string>|string|null
=======
     * @var array
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e
     */
    protected $proxies;
    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
<<<<<<< HEAD
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
=======
    protected $headers = Request::HEADER_X_FORWARDED_ALL;
}
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e
