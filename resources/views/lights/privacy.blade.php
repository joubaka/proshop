@extends('lights.layout')
@section('title', 'Privacy notice')
@section('content')
<article class="panel legal"><p class="eyebrow">PRIVACY NOTICE</p><h1>How the lights portal uses your data</h1>
<p>We store your name, email address, encrypted password, wallet ledger, court sessions, account status, and security/audit events needed to operate the service.</p>
<h2>Payments</h2><p>PayFast processes payment details. The portal stores the requested amount, status, and provider reference; it does not store your card number or online-banking credentials.</p>
<h2>Why and how long</h2><p>Data is used to authenticate you, operate and reconcile prepaid lights, prevent misuse, support you, and meet legal or accounting duties. Records should be retained only for the venue’s documented operational and legal period.</p>
<h2>Your choices</h2><p>Contact the venue to ask for access, correction, or deletion where applicable. Some payment, safety, and accounting records may need to be retained.</p>
<p><strong>Venue review required:</strong> the operator must set its retention periods and complete its POPIA/privacy review before launch. Contact: <a href="mailto:{{ config('lights.support_email') }}">{{ config('lights.support_email') }}</a>.</p></article>
@endsection
