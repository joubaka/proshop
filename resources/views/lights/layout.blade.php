<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#102f2d">
    <meta name="description" content="Prepaid court-light access: top up, choose a court and pay only for the light time you use.">
    <meta name="application-name" content="Court Lights"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>@yield('title', 'Court lights') · Lights</title>
    <link rel="manifest" href="/lights-assets/manifest.webmanifest"><link rel="icon" href="/lights-assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/lights-assets/portal.css?v=14"><link rel="stylesheet" href="/lights-assets/live-status.css?v=2"><script defer src="/lights-assets/portal.js?v=11"></script>
</head>
<body>
<header class="topbar"><a class="brand" href="{{ route('lights.home') }}"><span class="brand-icon">↯</span> COURT<span class="brand-light">LIGHTS</span></a>
    <nav aria-label="Main navigation">
    @if(Auth::guard('lights')->check())
        <a href="{{ route('lights.home') }}">My lights</a>
        @if(Auth::guard('lights')->user()->is_admin)<a href="{{ route('lights.admin') }}">Admin</a>@endif
        <form method="POST" action="{{ route('lights.logout') }}">@csrf<button class="text-button">Sign out</button></form>
    @else<a href="{{ route('lights.login') }}#login">Sign in</a>@endif
        <a href="{{ route('pos.login') }}">POS staff access</a>
    </nav>
</header>
@if(request()->routeIs('lights.admin.control'))
<div class="simulation">{{ config('lights.control.live_enabled') ? 'ADMIN HARDWARE CONTROL' : 'SAFE SIMULATION' }} <span>{{ config('lights.control.live_enabled') ? 'Direct admin ON/OFF controls. ON always has a device-side automatic cutoff; customer controls remain separate.' : 'No real ON/OFF command can be sent in this mode.' }}</span></div>
@elseif(request()->routeIs('lights.admin.shelly'))
<div class="simulation">READ-ONLY SETUP <span>Real Shelly status check only. Wallets and ON/OFF controls remain simulated.</span></div>
@elseif(config('lights.mode') === 'live')
<div class="simulation live-mode">{{ config('lights.payfast.enabled') && config('lights.control.customer_enabled') ? 'LIVE SERVICE' : 'COMMISSIONING' }} <span>PayFast wallet and connected court-light controls.</span></div>
@elseif(config('lights.control.customer_enabled'))
<div class="simulation">HARDWARE PILOT <span>Real Shelly controls are safety-gated. PayFast remains simulated.</span></div>
@else
<div class="simulation">LOCAL DEMO <span>Simulated PayFast &amp; Shelly. No real money or lights.</span></div>
@endif
<main class="shell">
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    <div id="connection-notice" class="notice error" role="alert" hidden></div>
    @yield('content')
</main>
<footer class="footer"><span>Separate lights wallet · ZAR</span><span>Keep playing. Pay only for your light time.</span></footer>
@if(request()->routeIs('lights.admin', 'lights.admin.control'))<script defer src="/lights-assets/hardware-status.js?v=2"></script>@endif
@if(request()->routeIs('lights.admin.control'))<script defer src="/lights-assets/control.js?v=4"></script>@endif
@if(request()->routeIs('lights.admin'))<script defer src="/lights-assets/admin.js?v=5"></script>@endif
</body>
</html>
