<?php

namespace App\Http\Controllers;

use App\Lights\Member;
use App\Lights\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LightsController extends Controller
{
    public function serviceWorker()
    {
        return response()->file(base_path('public/lights-assets/service-worker.js'), [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/lights/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function __construct(private Portal $portal) {}
    private function member(): Member { return Auth::guard('lights')->user(); }
    private function requireVerified(): void
    {
        if (config('lights.require_verified_email') && !$this->member()->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'Verify your email before using payments or court controls.']);
        }
    }

    public function login() { return view('lights.login'); }
    public function authenticate(Request $request)
    {
        $input = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:128']);
        $input['email'] = strtolower(trim($input['email']));
        if (!Auth::guard('lights')->attempt($input + ['active' => true])) { throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']); }
        $request->session()->regenerate();
        $this->member()->forceFill(['last_login_at' => $this->portal->now()])->save();
        return redirect()->route('lights.home');
    }
    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:lights.lights_users,email',
            'password' => 'required|string|min:4|max:72|confirmed', 'terms' => 'accepted']);
        $user = Member::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        $user->terms_accepted_at = $this->portal->now();
        if (config('lights.mode') !== 'live') { $user->email_verified_at = $this->portal->now(); }
        $user->save();
        Auth::guard('lights')->login($user);
        $request->session()->regenerate();
        if (!$user->email_verified_at) {
            try { app(\App\Lights\AccountTokens::class)->send($user, 'verify'); }
            catch (\Throwable $error) { Log::error('Lights verification email failed', ['member' => $user->id]); }
        }
        return redirect()->route('lights.home')->with('status', $user->email_verified_at
            ? 'Your Lights account is ready.' : 'Account created. Check your email to verify it before paying or switching on.');
    }
    public function terms() { return view('lights.terms'); }
    public function privacy() { return view('lights.privacy'); }
    public function forgotPassword(Request $request, \App\Lights\AccountTokens $tokens)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $member = Member::where('email', strtolower(trim($data['email'])))->where('active', true)->first();
        if ($member) {
            try { $tokens->send($member, 'reset'); }
            catch (\Throwable $error) { Log::error('Lights reset email failed', ['member' => $member->id]); }
        }
        return back()->with('status', 'If that active account exists, a password-reset link has been sent.');
    }
    public function resetPasswordForm(string $token) { return view('lights.reset-password', compact('token')); }
    public function resetPassword(Request $request, \App\Lights\AccountTokens $tokens)
    {
        $data = $request->validate(['token' => 'required|size:64', 'password' => 'required|string|min:4|max:72|confirmed']);
        $member = $tokens->resetPassword($data['token'], $data['password']);
        Auth::guard('lights')->login($member); $request->session()->regenerate();
        return redirect()->route('lights.home')->with('status', 'Your password has been changed.');
    }
    public function verifyEmail(Request $request, string $token, \App\Lights\AccountTokens $tokens)
    {
        $member = $tokens->verifyEmail($token);
        Auth::guard('lights')->login($member); $request->session()->regenerate();
        return redirect()->route('lights.home')->with('status', 'Email verified. Your account is ready.');
    }
    public function sendVerification(\App\Lights\AccountTokens $tokens)
    {
        if (!$this->member()->email_verified_at) { $tokens->send($this->member(), 'verify'); }
        return back()->with('status', 'If verification is still required, a new link has been sent.');
    }
    public function logout(Request $request)
    {
        Auth::guard('lights')->logout();
        // Do not sign the member out of the separate shop guard.
        $request->session()->regenerate();
        return redirect()->route('lights.login');
    }
    public function index(\App\Lights\SafetySessions $safety)
    {
        $state = $this->portal->snapshot($this->member()->id);
        $sessions = $this->portal->db()->table('lights_sessions')->join('lights_courts', 'lights_courts.id', '=', 'court_id')
            ->where('user_id', $this->member()->id)->orderByDesc('started_at')->limit(20)->get(['lights_sessions.*', 'lights_courts.name']);
        $topups = $this->portal->db()->table('lights_topups')->where('user_id', $this->member()->id)->orderByDesc('created_at')->limit(20)->get();
        $hardwareSessions = $safety->available() && $this->portal->db()->getSchemaBuilder()->hasColumn('lights_control_sessions', 'court_id')
            ? $this->portal->db()->table('lights_control_sessions')->leftJoin('lights_courts', 'lights_courts.id', '=', 'court_id')
                ->where('user_id', $this->member()->id)->whereIn('driver', ['customer_cloud', 'cloud_customer'])->orderByDesc('created_at')->limit(20)
                ->get(['lights_control_sessions.*', 'lights_courts.name'])
            : collect();
        return view('lights.home', compact('state', 'sessions', 'topups', 'hardwareSessions'));
    }
    public function state(\App\Lights\SafetySessions $safety)
    {
        return response()->json($this->portal->snapshot($this->member()->id));
    }
    public function start(Request $request, int $court, \App\Lights\SafetySessions $safety)
    {
        $this->requireVerified();
        if (config('lights.mode') === 'live' && !config('lights.control.customer_enabled')) {
            throw ValidationException::withMessages(['lights' => 'Court control is not yet available. No session was started.']);
        }
        $data = $request->validate(['request_key' => 'required|uuid', 'quoted_rate_cents' => 'required|integer|min:1|max:100000']);
        if (config('lights.control.customer_enabled')) {
            $id = $safety->startCustomer($this->member()->id, $court, $data['request_key'], (int) $data['quoted_rate_cents']);
        } else {
            $id = $this->portal->start($this->member()->id, $court, $data['request_key'], (int) $data['quoted_rate_cents']);
        }
        return $request->expectsJson() ? response()->json(['session_id' => $id]) : redirect()->route('lights.home');
    }
    public function stop(Request $request, string $session, \App\Lights\SafetySessions $safety)
    {
        $isHardwareSession = $safety->available() && $this->portal->db()->table('lights_control_sessions')
            ->where('id', $session)->where('user_id', $this->member()->id)->exists();
        if (config('lights.control.customer_enabled') && $isHardwareSession) {
            $safety->stopCustomer($this->member()->id, $session);
        } else { $this->portal->stop($this->member()->id, $session); }
        return $request->expectsJson() ? response()->json(['stopped' => true]) : redirect()->route('lights.home')->with('status',
            config('lights.control.customer_enabled') ? 'Stop requested. Check that the court lights are physically off.' : 'Lights off. Your session charge has been recorded.');
    }
    public function topup(Request $request)
    {
        $this->requireVerified();
        if (config('lights.mode') === 'live' && !config('lights.payfast.enabled')) {
            throw ValidationException::withMessages(['payment' => 'Online payments are not yet available. No top-up was created.']);
        }
        $data = $request->validate(['amount' => 'required|string', 'request_key' => 'required|uuid']);
        $gateway = config('lights.mode') === 'live' ? 'payfast' : 'payfast_simulator';
        $id = $this->portal->topup($this->member()->id, Portal::cents($data['amount']), $data['request_key'], $gateway);
        return redirect()->route('lights.checkout', $id);
    }
    public function checkout(string $topup, \App\Lights\PayFast\Gateway $gateway)
    {
        $this->requireVerified();
        $payment = $this->portal->db()->table('lights_topups')->where('user_id', $this->member()->id)->where('id', $topup)->firstOrFail();
        $payfast = $payment->gateway === 'payfast' && $payment->status === 'pending' ? $gateway->checkout($payment, $this->member()) : null;
        return view('lights.checkout', compact('payment', 'payfast'));
    }
    public function simulate(Request $request, string $topup)
    {
        $data = $request->validate(['outcome' => 'required|in:paid,cancelled,failed']);
        $this->portal->confirmTopup($this->member()->id, $topup, $data['outcome']);
        return redirect()->route('lights.home')->with('status', 'Simulated payment processed. No real money moved.');
    }
    public function payfastReturn(string $topup)
    {
        $this->portal->db()->table('lights_topups')->where('user_id', $this->member()->id)->where('id', $topup)
            ->where('gateway', 'payfast')->firstOrFail();
        return redirect()->route('lights.home')->with('status', 'Payment received for verification. Your wallet updates only after PayFast confirms it.');
    }
    public function payfastCancel(string $topup)
    {
        $this->portal->db()->table('lights_topups')->where('user_id', $this->member()->id)->where('id', $topup)
            ->where('gateway', 'payfast')->firstOrFail();
        $this->portal->cancelPayFast($this->member()->id, $topup);
        return redirect()->route('lights.home')->with('status', 'PayFast checkout was cancelled. No wallet credit was added.');
    }
    public function payfastNotify(Request $request, \App\Lights\PayFast\Gateway $gateway)
    {
        \Illuminate\Support\Facades\Log::info('PayFast ITN received.', [
            'source_ip' => $request->ip(),
            'topup_id' => is_string($request->input('m_payment_id')) ? $request->input('m_payment_id') : null,
        ]);
        try {
            $payment = $gateway->verify($request);
            $this->portal->confirmPayFast($payment['topup'], $payment['reference'], $payment['amount_cents']);
            \Illuminate\Support\Facades\Log::info('PayFast ITN credited.', ['topup_id' => $payment['topup']]);
            return response('OK', 200)->header('Content-Type', 'text/plain');
        } catch (\App\Lights\PayFast\VerificationUnavailable $exception) {
            \Illuminate\Support\Facades\Log::warning('PayFast ITN verification unavailable.', $this->payfastLogContext($request, $exception));
            return response('RETRY', 503)->header('Content-Type', 'text/plain');
        } catch (\App\Lights\PayFast\InvalidNotification|\UnexpectedValueException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
            \Illuminate\Support\Facades\Log::warning('PayFast ITN rejected.', $this->payfastLogContext($request, $exception));
            return response('INVALID', 400)->header('Content-Type', 'text/plain');
        }
    }

    private function payfastLogContext(Request $request, \Throwable $exception): array
    {
        $topup = $request->input('m_payment_id');

        return [
            'reason' => $exception->getMessage(),
            'exception' => $exception::class,
            'source_ip' => $request->ip(),
            'topup_id' => is_string($topup) && preg_match('/\A[0-9a-f-]{36}\z/i', $topup) ? $topup : null,
        ];
    }
    public function admin(\App\Lights\HealthReport $health, \App\Lights\PayFast\Settings $payFastSettings)
    {
        $this->portal->tick();
        $courts = $this->portal->db()->table('lights_courts')->orderBy('id')->get();
        $active = $this->portal->db()->table('lights_sessions')->join('lights_users', 'lights_users.id', '=', 'user_id')->join('lights_courts', 'lights_courts.id', '=', 'court_id')
            ->whereNotNull('active_user_id')->get(['lights_sessions.*', 'lights_users.name as member_name', 'lights_courts.name as court_name']);
        $events = $this->portal->db()->table('lights_events')->orderByDesc('id')->limit(50)->get();
        $ledger = $this->portal->db()->table('lights_ledger')->join('lights_users', 'lights_users.id', '=', 'user_id')->orderByDesc('lights_ledger.id')->limit(50)->get(['lights_ledger.*', 'lights_users.name']);
        $liveActive = $this->portal->db()->getSchemaBuilder()->hasColumn('lights_control_sessions', 'court_id')
            ? $this->portal->db()->table('lights_control_sessions')->join('lights_users', 'lights_users.id', '=', 'user_id')
                ->leftJoin('lights_courts', 'lights_courts.id', '=', 'court_id')->whereIn('driver', ['customer_cloud', 'cloud_customer'])
                ->whereNotNull('active_user_id')->get(['lights_control_sessions.*', 'lights_users.name as member_name', 'lights_courts.name as court_name'])
            : collect();
        $memberLedger = $this->portal->db()->table('lights_ledger')
            ->select('user_id')
            ->selectRaw('SUM(CASE WHEN amount_cents > 0 THEN amount_cents ELSE 0 END) as total_credit_cents')
            ->selectRaw('SUM(CASE WHEN amount_cents < 0 THEN -amount_cents ELSE 0 END) as total_debit_cents')
            ->selectRaw('COUNT(*) as movement_count')
            ->selectRaw('MAX(created_at) as last_movement_at')
            ->groupBy('user_id');
        $members = $this->portal->db()->table('lights_users')
            ->leftJoinSub($memberLedger, 'member_ledger', 'member_ledger.user_id', '=', 'lights_users.id')
            ->orderByDesc('lights_users.created_at')->limit(100)
            ->get(['lights_users.*', 'member_ledger.total_credit_cents', 'member_ledger.total_debit_cents',
                'member_ledger.movement_count', 'member_ledger.last_movement_at']);
        $payments = $this->portal->db()->table('lights_topups')->join('lights_users', 'lights_users.id', '=', 'user_id')
            ->where('gateway', 'payfast')->orderByDesc('lights_topups.created_at')->limit(50)
            ->get(['lights_topups.*', 'lights_users.name as member_name', 'lights_users.email as member_email']);
        $healthReport = $health->get();
        $payFast = $payFastSettings->summary();
        return view('lights.admin', compact('courts', 'active', 'events', 'ledger', 'liveActive', 'members', 'payments', 'healthReport', 'payFast'));
    }
    public function health(\App\Lights\HealthReport $health) { return response()->json($health->get()); }
    public function memberStatus(Request $request, int $member, \App\Lights\SafetySessions $safety)
    {
        $data = $request->validate(['active' => 'required|boolean']);
        $active = (bool) $data['active'];
        if (!$active) {
            foreach ($this->portal->db()->table('lights_sessions')->where('user_id', $member)->whereNotNull('active_user_id')->pluck('id') as $id) {
                $this->portal->stop($this->member()->id, $id, true);
            }
            if ($safety->available()) {
                foreach ($this->portal->db()->table('lights_control_sessions')->where('user_id', $member)->whereNotNull('active_user_id')->pluck('id') as $id) {
                    $safety->stop($this->member()->id, $id);
                }
            }
        }
        $this->portal->setMemberActive($this->member()->id, $member, $active);
        $message = $active ? 'Client account enabled.' : 'Client account disabled and active sessions stopped.';
        return $request->expectsJson() ? response()->json(['message' => $message, 'active' => $active]) : back()->with('status', $message);
    }
    public function memberAdjustment(Request $request, int $member)
    {
        $data = $request->validate(['direction' => 'required|in:credit,debit', 'amount' => 'required|string',
            'reason' => 'required|string|min:5|max:200', 'payment_type' => 'nullable|in:cash',
            'request_key' => 'required|uuid']);
        $amount = Portal::cents($data['amount']) * ($data['direction'] === 'credit' ? 1 : -1);
        if (($data['payment_type'] ?? null) === 'cash') {
            if ($data['direction'] !== 'credit' || $amount < 100 || $amount > 500000) {
                throw ValidationException::withMessages(['amount' => 'Cash received must be between R1 and R5,000.']);
            }
            $this->portal->recordCashTopup($this->member()->id, $member, $amount, $data['reason'], $data['request_key']);
            $balance = (int) $this->portal->db()->table('lights_users')->where('id', $member)->value('balance_cents');
            return $request->expectsJson()
                ? response()->json(['message' => 'Cash received and wallet credited.', 'balance_cents' => $balance])
                : redirect(route('lights.admin').'#members')->with('status', 'Cash received and wallet credited.');
        }
        $this->portal->adjustBalance($this->member()->id, $member, $amount, $data['reason'], $data['request_key']);
        $balance = (int) $this->portal->db()->table('lights_users')->where('id', $member)->value('balance_cents');
        return $request->expectsJson()
            ? response()->json(['message' => 'Audited wallet adjustment recorded.', 'balance_cents' => $balance])
            : back()->with('status', 'Audited wallet adjustment recorded.');
    }
    public function hardwareState(\App\Lights\Shelly\HardwareStatus $hardwareStatus, \App\Lights\ManualSwitches $switches)
    {
        $report = $hardwareStatus->latest() ?? ['online' => false, 'checked_at' => null, 'channels' => []];
        $report['server_time'] = $this->portal->now();
        $report['commands'] = $switches->rows()->map(fn ($command) => [
            'id' => $command->id, 'channel' => (int) $command->channel, 'action' => $command->action,
            'state' => $command->state, 'duration_seconds' => $command->duration_seconds,
            'created_at' => (int) $command->created_at, 'note' => $command->note,
        ])->values();

        return response()->json($report);
    }
    public function saveCourt(Request $request)
    {
        $data = $request->validate(['court_id' => 'nullable|integer|min:1', 'name' => 'required|string|max:100', 'rate' => 'required|string',
            'device_label' => 'required|string|max:80', 'channel' => 'required|integer|in:0,1', 'active' => 'required|boolean']);
        $rate = Portal::cents($data['rate']);
        if ($rate < 100 || $rate > 100000) { throw ValidationException::withMessages(['rate' => 'Rate must be between R1 and R1,000 per hour.']); }
        $this->portal->saveCourt($this->member()->id, $data['court_id'] ?? null, ['name' => $data['name'], 'rate_cents' => $rate,
            'device_label' => $data['device_label'], 'channel' => $data['channel'], 'active' => (bool) $data['active']]);
        return redirect(route('lights.admin').'#settings')->with('status', 'Court settings saved.');
    }
    public function savePayFast(Request $request, \App\Lights\PayFast\Settings $settings)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean', 'sandbox' => 'required|boolean',
            'merchant_id' => 'nullable|string|max:255', 'merchant_key' => 'nullable|string|max:255',
            'passphrase' => 'nullable|string|max:255',
        ]);
        $settings->save($this->member()->id, (bool) $data['enabled'], (bool) $data['sandbox'], $data);
        return redirect(route('lights.admin').'#settings')->with('status', 'PayFast settings saved securely.');
    }
    public function emergencyStop(string $session)
    {
        $this->portal->stop($this->member()->id, $session, true);
        return redirect()->route('lights.admin')->with('status', 'Session stopped and usage settled.');
    }

    private function shellySettings(Request $request): \App\Lights\Shelly\PrivateSettings
    {
        abort_unless(config('lights.shelly_setup') && app()->environment('acceptance', 'testing')
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 404);
        return app(\App\Lights\Shelly\PrivateSettings::class, ['directory' => base_path('.local-acceptance/private/shelly')]);
    }

    public function control(Request $request, \App\Lights\SafetySessions $safety)
    {
        $this->shellySettings($request);
        abort_unless($safety->available(), 503, 'Run the Lights migration setup first.');
        $manual = app(\App\Lights\ManualSwitches::class);
        $hardwareStatus = app(\App\Lights\Shelly\HardwareStatus::class)->latest();
        return view('lights.control', ['manualCommands' => $manual->rows(),
            'hardwareChannels' => collect($hardwareStatus['channels'] ?? [])->keyBy('channel')]);
    }
    public function controlManualOn(Request $request, \App\Lights\ManualSwitches $switches)
    {
        $this->shellySettings($request);
        abort_unless(config('lights.control.live_enabled'), 422, 'Admin hardware control is not enabled.');
        $data = $request->validate(['channel' => 'required|integer|in:0,1', 'request_key' => 'required|uuid']);
        $seconds = app(\App\Lights\OperatingHours::class)
            ->limitSeconds(min(300, max(1, (int) config('lights.control.max_seconds', 60))));
        $command = $switches->on($this->member()->id, (int) $data['channel'], $data['request_key'], $seconds);
        $message = 'ON command queued with a '.$seconds.'-second automatic cutoff. The worker will send it once.';
        if ($request->expectsJson()) { return response()->json(['message' => $message, 'command' => $command]); }
        return redirect()->route('lights.admin.control')->with('status', $message);
    }
    public function controlEmergencyOff(Request $request, \App\Lights\ManualSwitches $switches)
    {
        $this->shellySettings($request);
        abort_unless(config('lights.control.live_enabled'), 422, 'Admin hardware control is not enabled.');
        $data = $request->validate(['channel' => 'required|integer|in:0,1', 'request_key' => 'required|uuid']);
        $command = $switches->off($this->member()->id, (int) $data['channel'], $data['request_key']);
        $message = 'OFF command queued. The worker will send it once.';
        if ($request->expectsJson()) { return response()->json(['message' => $message, 'command' => $command]); }
        return redirect()->route('lights.admin.control')->with('status', $message);
    }
    public function armCustomerControl(Request $request, \App\Lights\Shelly\PilotArmer $armer)
    {
        $this->shellySettings($request);
        abort_unless(config('lights.control.customer_enabled'), 422, 'Customer hardware control is not enabled.');
        $data = $request->validate(['channel' => 'required|integer|in:0,1', 'empty_court' => 'accepted',
            'operator_onsite' => 'accepted', 'reboot_off_checked' => 'accepted']);
        $armer->arm((int) $data['channel']);
        $message = 'The next customer ON request for Court '.((int) $data['channel'] === 0 ? '3' : '4').' is enabled for 10 minutes.';
        return $request->expectsJson() ? response()->json(['message' => $message])
            : redirect()->route('lights.admin.control')->with('status', $message);
    }
    public function controlStop(Request $request, string $session, \App\Lights\SafetySessions $safety)
    {
        $safety->stop($this->member()->id, $session);
        return redirect()->route('lights.admin')->with('status', 'OFF request queued. Billing is frozen while the safety worker confirms the result.');
    }

    public function controlReview(Request $request, string $session, \App\Lights\SafetySessions $safety)
    {
        $data = $request->validate([
            'action' => 'required|in:off,confirmed_off',
            'physical_off' => 'nullable|accepted_if:action,confirmed_off',
        ]);
        $safety->review($this->member()->id, $session, $data['action']);
        $message = $data['action'] === 'confirmed_off'
            ? 'Physical OFF confirmed. The court reservation was safely released.'
            : 'Another OFF request was queued. Confirm the physical court is OFF before releasing it.';
        return redirect()->route('lights.admin')->with('status', $message);
    }

    public function shelly(Request $request)
    {
        $configured = $this->shellySettings($request)->configured();
        return response()->view('lights.shelly', compact('configured'))
            ->header('X-Frame-Options', 'DENY')->header('Referrer-Policy', 'no-referrer');
    }

    public function saveShelly(Request $request)
    {
        $settings = $this->shellySettings($request);
        $secret = $request->input('shelly_key');
        // Never put this field in validation old-input, redirects, logs or HTML.
        $request->request->remove('shelly_key');
        if (!is_string($secret) || !preg_match('/\A[A-Za-z0-9+\/_=.-]{16,4096}\z/D', $secret)) {
            return redirect()->route('lights.admin.shelly')->with('status', 'Enter the cloud authorization key, without spaces.');
        }
        try { $settings->save($secret); }
        catch (\Throwable) { return redirect()->route('lights.admin.shelly')->with('status', 'Private settings could not be saved. No connection was attempted.'); }
        $request->session()->forget('shelly_report');
        return redirect()->route('lights.admin.shelly')->with('status', 'Key saved privately. No connection or switching command has been sent.');
    }

    public function checkShelly(Request $request, \App\Lights\Shelly\Probe $probe, \App\Lights\Shelly\HardwareStatus $hardwareStatus)
    {
        $settings = $this->shellySettings($request);
        $destination = match ($request->input('return_to')) {
            'admin' => 'lights.admin',
            'control' => 'lights.admin.control',
            default => 'lights.admin.shelly',
        };
        if (!$settings->configured()) {
            $message = 'Save the cloud key first.';
            return $request->expectsJson() ? response()->json(['message' => $message], 422)
                : redirect()->route($destination)->with('status', $message);
        }
        $manualBusy = $this->portal->db()->getSchemaBuilder()->hasTable('lights_manual_commands')
            && $this->portal->db()->table('lights_manual_commands')->whereIn('state', ['queued', 'sending'])->exists();
        if (($this->portal->db()->getSchemaBuilder()->hasTable('lights_control_sessions')
            && $this->portal->db()->table('lights_control_sessions')->whereNotNull('active_user_id')
                ->whereIn('state', ['reserved', 'starting', 'running', 'stopping'])->exists()) || $manualBusy) {
            $message = 'A relay command is active. Live status will update automatically without competing with it.';
            return $request->expectsJson() ? response()->json(['message' => $message], 409)
                : redirect()->route($destination)->with('status', $message);
        }
        try {
            $report = $probe->run();
            $hardwareStatus->record($report);
            if ($request->expectsJson()) { return response()->json(['message' => 'Live status refreshed.', 'report' => $report]); }
            return redirect()->route($destination)->with('shelly_report', $report);
        } catch (\RuntimeException $error) {
            if ($request->expectsJson()) { return response()->json(['message' => $error->getMessage()], 502); }
            return redirect()->route($destination)->with('shelly_error', $error->getMessage());
        }
    }
}
