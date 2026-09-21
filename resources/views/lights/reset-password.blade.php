@extends('lights.layout')
@section('title', 'Reset password')
@section('content')
<section class="panel checkout">
    <p class="eyebrow">ACCOUNT RECOVERY</p><h1>Choose a new password</h1>
    <form method="POST" action="{{ route('lights.password.update') }}">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="reset-password">New password</label>
        <div class="password-field">
            <input id="reset-password" name="password" type="password" minlength="4" maxlength="72" required autocomplete="new-password">
            <button type="button" class="password-toggle" data-password-toggle="reset-password" aria-controls="reset-password" aria-pressed="false" aria-label="Show new password">Show</button>
        </div>
        <small>At least 4 characters.</small>
        <label for="reset-password-confirmation">Confirm password</label>
        <div class="password-field">
            <input id="reset-password-confirmation" name="password_confirmation" type="password" maxlength="72" required autocomplete="new-password">
            <button type="button" class="password-toggle" data-password-toggle="reset-password-confirmation" aria-controls="reset-password-confirmation" aria-pressed="false" aria-label="Show password confirmation">Show</button>
        </div>
        <button class="button primary full">Change password</button>
    </form>
</section>
@endsection
