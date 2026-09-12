@extends('lights.layout')
@section('title', 'Reset password')
@section('content')
<section class="panel checkout">
    <p class="eyebrow">ACCOUNT RECOVERY</p><h1>Choose a new password</h1>
    <form method="POST" action="{{ route('lights.password.update') }}">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>New password<input name="password" type="password" minlength="4" maxlength="72" required autocomplete="new-password"><small>At least 4 characters.</small></label>
        <label>Confirm password<input name="password_confirmation" type="password" required autocomplete="new-password"></label>
        <button class="button primary full">Change password</button>
    </form>
</section>
@endsection
