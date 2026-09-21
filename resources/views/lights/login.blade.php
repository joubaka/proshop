@extends('lights.layout')
@section('title', 'Sign in')
@section('content')
<div class="login-grid">
    <section class="intro"><p class="eyebrow">YOUR COURT. YOUR TIME.</p><h1>A little more<br> time on court.</h1><p class="lead">Top up, switch on and play. Your lights wallet keeps track while you focus on the game.</p><div class="court-art" aria-hidden="true"><div></div><span></span></div><p class="muted">This portal has its own account. Your shop login and shop balance are separate.</p></section>
    <section class="panel login-panel" id="login" tabindex="-1" aria-labelledby="login-heading"><h2 id="login-heading">Welcome back</h2><p class="muted">Sign in to your lights account.</p>
        <form action="{{ route('lights.login') }}" method="POST">@csrf
            <label>Email<input type="email" name="email" autocomplete="username" value="{{ old('email') }}" required></label>
            <label for="login-password">Password</label>
            <div class="password-field">
                <input id="login-password" type="password" name="password" autocomplete="current-password" required maxlength="72">
                <button type="button" class="password-toggle" data-password-toggle="login-password" aria-controls="login-password" aria-pressed="false">Show</button>
            </div>
            <button class="button primary full">Sign in</button>
        </form>
        <details class="register"><summary>Forgot your password?</summary>
            <form action="{{ route('lights.password.email') }}" method="POST">@csrf
                <label>Email<input name="email" type="email" required autocomplete="email"></label>
                <button class="button secondary full">Send reset link</button>
            </form>
        </details>
        <details class="register"><summary>New here? Create a lights account</summary>
            <form action="{{ route('lights.register') }}" method="POST">@csrf
                <label>Your name<input name="name" required maxlength="100" autocomplete="name"></label>
                <label>Email<input name="email" type="email" required autocomplete="email"></label>
                <label>Password<input name="password" type="password" minlength="4" maxlength="72" required autocomplete="new-password"><small>At least 4 characters.</small></label>
                <label>Confirm password<input name="password_confirmation" type="password" required autocomplete="new-password"></label>
                <label class="check-label"><input name="terms" type="checkbox" value="1" required> <span>I accept the <a href="{{ route('lights.terms') }}" target="_blank">service terms</a> and have read the <a href="{{ route('lights.privacy') }}" target="_blank">privacy notice</a>.</span></label>
                <button class="button primary full">Create account</button>
            </form>
        </details>
    </section>
</div>
@endsection
