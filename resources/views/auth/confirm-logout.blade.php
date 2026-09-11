<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('lang_v1.sign_out') }} - {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f6f8; color: #243949; font-family: system-ui, sans-serif; }
        main { box-sizing: border-box; width: min(440px, calc(100% - 32px)); padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 8px 32px #24394912; }
        h1 { margin-top: 0; font-size: 24px; }
        p { line-height: 1.6; }
        button { width: 100%; margin-top: 12px; padding: 12px 20px; border: 0; border-radius: 6px; background: #243949; color: #fff; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('lang_v1.sign_out') }}</h1>
        <p>{{ __('This page was open for a while. Please confirm to finish signing out.') }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">{{ __('lang_v1.sign_out') }}</button>
        </form>
    </main>
</body>
</html>
