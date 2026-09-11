@extends('lights.layout')
@section('title', 'Service terms')
@section('content')
<article class="panel legal"><p class="eyebrow">VERSION {{ config('lights.terms_version') }}</p><h1>Court lights service terms</h1>
<p>These terms cover the separate prepaid court-lights portal. A lights wallet is not the same as a shop or club account.</p>
<h2>Credits and charging</h2><p>Credits are used only for court-light time and are not cash or transferable. The system charges elapsed light time at the rate shown before you switch on. Switching off settles the final charge; a server and device cutoff can also end a session automatically.</p>
<h2>Safe use</h2><p>Use only the court assigned to you. Report a light that remains on, fails to start, or appears unsafe to venue staff immediately. The venue may stop a session or disable an account for safety, misuse, maintenance, or an outage.</p>
<h2>Payments and refunds</h2><p>PayFast processes top-ups. Wallet credit is issued only after a verified payment notification. Contact the venue about duplicate payments, failed service, or a refund request; any approved refund follows the venue policy and payment-provider rules.</p>
<h2>Availability</h2><p>Internet, power, payment-provider, and device interruptions can delay or prevent service. Never rely on the portal as an emergency lighting system.</p>
<p><strong>Venue review required:</strong> the operator should have these terms reviewed for its business before public launch. Questions: <a href="mailto:{{ config('lights.support_email') }}">{{ config('lights.support_email') }}</a>.</p></article>
@endsection
