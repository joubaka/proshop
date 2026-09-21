<?php

namespace App\Lights;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Portal
{
    public function enabled(): bool
    {
        if (!config('lights.enabled')) { return false; }
        return config('lights.mode') === 'simulation'
            ? app()->environment(['local', 'testing', 'acceptance'])
            : config('lights.mode') === 'live' && app()->environment(['production', 'staging']);
    }

    public function db() { abort_unless($this->enabled(), 503, 'Lights portal is unavailable.'); return DB::connection('lights'); }
    public function now(): int { return now()->getTimestamp(); }

    private function locked(callable $operation)
    {
        return $this->db()->transaction(function () use ($operation) {
            // One lock order for wallets, courts, top-ups and settlement. Small-venue baseline.
            $this->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            return $operation();
        }, 3);
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['lights' => $message]); }
    private function event(string $kind, array $details, ?int $actor = null): void
    {
        $this->db()->table('lights_events')->insert(['actor_id' => $actor, 'kind' => $kind, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => $this->now()]);
    }

    private function post(int $user, int $amount, string $kind, string $reference): void
    {
        $member = $this->db()->table('lights_users')->find($user);
        $balance = (int) $member->balance_cents + $amount;
        if ($balance < 0) { throw new \LogicException('Wallet balance invariant failed.'); }
        $this->db()->table('lights_users')->where('id', $user)->update(['balance_cents' => $balance]);
        $this->db()->table('lights_ledger')->insert(['user_id' => $user, 'amount_cents' => $amount,
            'balance_after' => $balance, 'kind' => $kind, 'reference' => $reference, 'created_at' => $this->now()]);
    }

    public static function cents(string $amount): int
    {
        if (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $amount)) { throw ValidationException::withMessages(['amount' => 'Use a rand amount with up to two decimal places.']); }
        [$rands, $cents] = array_pad(explode('.', $amount), 2, '0');
        return ((int) $rands * 100) + (int) str_pad($cents, 2, '0');
    }

    public function topup(int $user, int $amount, string $key, ?string $gateway = null): string
    {
        if ($amount < 1000 || $amount > 500000) { $this->fail('Top up between R10 and R5,000.'); }
        $gateway ??= config('lights.mode') === 'live' && config('lights.payfast.enabled') ? 'payfast' : 'payfast_simulator';
        abort_unless(in_array($gateway, ['payfast', 'payfast_simulator'], true), 422);
        return $this->locked(function () use ($user, $amount, $key, $gateway) {
            $existing = $this->db()->table('lights_topups')->where('user_id', $user)->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->amount_cents !== $amount) { $this->fail('That top-up request already has a different amount. Refresh and try again.'); }
                return $existing->id;
            }
            $id = (string) Str::uuid();
            $this->db()->table('lights_topups')->insert(['id' => $id, 'user_id' => $user, 'request_key' => $key,
                'amount_cents' => $amount, 'gateway' => $gateway, 'created_at' => $this->now()]);
            $this->event('topup_requested', ['topup' => $id, 'amount_cents' => $amount], $user);
            return $id;
        });
    }

    public function confirmTopup(int $user, string $id, string $outcome): void
    {
        abort_unless(in_array($outcome, ['paid', 'cancelled', 'failed'], true), 422);
        $this->locked(function () use ($user, $id, $outcome) {
            $topup = $this->db()->table('lights_topups')->where('user_id', $user)->where('id', $id)->firstOrFail();
            abort_unless($topup->gateway === 'payfast_simulator', 404);
            if ($topup->status !== 'pending') { return; }
            if ($outcome === 'paid') { $this->post($user, $topup->amount_cents, 'topup', 'topup:'.$id); }
            $this->db()->table('lights_topups')->where('id', $id)->update(['status' => $outcome, 'confirmed_at' => $this->now()]);
            $this->event('simulated_payfast_'.$outcome, ['topup' => $id], $user);
        });
    }

    public function confirmPayFast(string $id, string $providerReference, int $amountCents): void
    {
        $this->locked(function () use ($id, $providerReference, $amountCents) {
            $topup = $this->db()->table('lights_topups')->where('id', $id)->firstOrFail();
            if ($topup->gateway !== 'payfast' || (int) $topup->amount_cents !== $amountCents) {
                throw new \UnexpectedValueException('Payment does not match the requested top-up.');
            }
            $sameReference = $this->db()->table('lights_topups')->where('gateway_reference', $providerReference)->first();
            if ($sameReference && $sameReference->id !== $id) {
                throw new \UnexpectedValueException('Payment reference was already used.');
            }
            if ($topup->status === 'paid') {
                if ($topup->gateway_reference !== $providerReference) { throw new \UnexpectedValueException('Payment reference mismatch.'); }
                return;
            }
            $this->post((int) $topup->user_id, $amountCents, 'topup', 'payfast:'.$providerReference);
            $this->db()->table('lights_topups')->where('id', $id)->update(['status' => 'paid',
                'gateway_reference' => $providerReference, 'confirmed_at' => $this->now(), 'notified_at' => $this->now()]);
            $this->event('payfast_paid', ['topup' => $id, 'provider_reference' => $providerReference], (int) $topup->user_id);
        });
    }

    public function cancelPayFast(int $user, string $id): void
    {
        $this->locked(function () use ($user, $id) {
            $topup = $this->db()->table('lights_topups')->where('id', $id)->where('user_id', $user)
                ->where('gateway', 'payfast')->firstOrFail();
            if ($topup->status !== 'pending') { return; }
            $this->db()->table('lights_topups')->where('id', $id)
                ->update(['status' => 'cancelled', 'confirmed_at' => $this->now()]);
            $this->event('payfast_cancelled', ['topup' => $id], $user);
        });
    }

    public function adjustBalance(int $actor, int $user, int $amount, string $reason, string $requestKey): void
    {
        if ($amount === 0 || abs($amount) > 500000 || !Str::isUuid($requestKey)) { abort(422); }
        $this->locked(function () use ($actor, $user, $amount, $reason, $requestKey) {
            abort_unless($this->db()->table('lights_users')->where('id', $actor)->where('is_admin', true)->where('active', true)->exists(), 403);
            $member = $this->db()->table('lights_users')->find($user);
            abort_unless($member, 404);
            if ($this->db()->table('lights_ledger')->where('reference', 'adjustment:'.$requestKey)->exists()) { return; }
            if ((int) $member->balance_cents + $amount < 0) { $this->fail('The adjustment would make the wallet negative.'); }
            $this->post($user, $amount, 'admin_adjustment', 'adjustment:'.$requestKey);
            $this->event('admin_balance_adjusted', ['member' => $user, 'amount_cents' => $amount,
                'reason' => mb_substr($reason, 0, 200), 'request_key' => $requestKey], $actor);
        });
    }

    public function recordCashTopup(int $actor, int $user, int $amount, string $note, string $requestKey): void
    {
        if ($amount < 100 || $amount > 500000 || !Str::isUuid($requestKey)) { abort(422); }
        $this->locked(function () use ($actor, $user, $amount, $note, $requestKey) {
            abort_unless($this->db()->table('lights_users')->where('id', $actor)->where('is_admin', true)->where('active', true)->exists(), 403);
            abort_unless($this->db()->table('lights_users')->where('id', $user)->exists(), 404);
            if ($this->db()->table('lights_ledger')->where('reference', 'cash:'.$requestKey)->exists()) { return; }
            $this->post($user, $amount, 'cash_topup', 'cash:'.$requestKey);
            $this->event('cash_topup_recorded', ['member' => $user, 'amount_cents' => $amount,
                'note' => mb_substr($note, 0, 200), 'request_key' => $requestKey], $actor);
        });
    }

    public function setMemberActive(int $actor, int $user, bool $active): void
    {
        $this->locked(function () use ($actor, $user, $active) {
            abort_unless($this->db()->table('lights_users')->where('id', $actor)->where('is_admin', true)->where('active', true)->exists(), 403);
            if ($actor === $user && !$active) { $this->fail('You cannot disable your own administrator account.'); }
            $this->db()->table('lights_users')->where('id', $user)->firstOrFail();
            $this->db()->table('lights_users')->where('id', $user)->update(['active' => $active]);
            $this->event('member_status_changed', ['member' => $user, 'active' => $active], $actor);
        });
    }

    public function start(int $user, int $court, ?string $requestKey = null, ?int $quotedRate = null): string
    {
        $requestKey ??= (string) Str::uuid();
        return $this->locked(function () use ($user, $court, $requestKey, $quotedRate) {
            $this->settleAll();
            $member = $this->db()->table('lights_users')->where('active', true)->find($user);
            abort_unless($member, 403);
            $replay = $this->db()->table('lights_sessions')->where('user_id', $user)->where('request_key', $requestKey)->first();
            if ($replay) {
                if ((int) $replay->court_id !== $court) { $this->fail('That request was already used for a different court. Refresh the page.'); }
                return $replay->id;
            }
            $own = $this->db()->table('lights_sessions')->where('active_user_id', $user)->where('court_id', $court)->first();
            if ($own) { return $own->id; }
            if ($this->db()->getSchemaBuilder()->hasTable('lights_control_sessions')
                && $this->db()->table('lights_control_sessions')->where('active_user_id', $user)->exists()) {
                $this->fail('Finish your safety rehearsal or pilot review before starting a demo session.');
            }
            $lane = $this->db()->table('lights_courts')->find($court);
            abort_unless($lane, 404);
            if (!$lane->active) { $this->fail('This court is unavailable.'); }
            if ($quotedRate !== null && $quotedRate !== (int) $lane->rate_cents) { $this->fail('The court rate changed. Refresh to review the new rate before switching on.'); }
            if ($this->db()->table('lights_sessions')->where('active_court_id', $court)->exists()) { $this->fail('This court is already in use.'); }
            $activeForUser = $this->db()->table('lights_sessions')->where('active_user_id', $user)->get();
            $combinedRate = (int) $lane->rate_cents + $activeForUser->sum('rate_cents');
            $seconds = min((int) config('lights.max_session_seconds'), intdiv((int) $member->balance_cents * 3600, $combinedRate));
            if ($seconds < 1) { $this->fail('Top up your wallet before switching on.'); }
            $start = $this->now(); $deadline = $start + $seconds; $id = (string) Str::uuid();
            foreach ($activeForUser as $activeSession) {
                $this->db()->table('lights_sessions')->where('id', $activeSession->id)
                    ->update(['deadline_at' => min((int) $activeSession->deadline_at, $deadline)]);
            }
            $this->db()->table('lights_sessions')->insert(['id' => $id, 'user_id' => $user, 'court_id' => $court,
                'active_user_id' => $user, 'active_court_id' => $court, 'rate_cents' => $lane->rate_cents,
                'budget_cents' => $member->balance_cents, 'started_at' => $start, 'deadline_at' => $deadline, 'request_key' => $requestKey]);
            // Simulator only. A future hardware driver MUST confirm a device-side timer before billing.
            $this->db()->table('lights_courts')->where('id', $court)->update(['relay_on' => true, 'relay_until' => $deadline]);
            $this->event('simulated_shelly_on', ['session' => $id, 'court' => $court, 'channel' => $lane->channel, 'cutoff' => $deadline], $user);
            return $id;
        });
    }

    private function settleAll(): void
    {
        foreach ($this->db()->table('lights_sessions')->whereNotNull('active_user_id')->get() as $session) { $this->settle($session); }
    }

    private function settle(object $session, ?string $stop = null, ?int $actor = null): void
    {
        $at = max((int) $session->started_at, min($this->now(), (int) $session->deadline_at));
        $elapsed = $at - (int) $session->started_at;
        // Round the cumulative session charge, never each worker tick: at most one cent rounding.
        $memberBalance = (int) $this->db()->table('lights_users')->where('id', $session->user_id)->value('balance_cents');
        $total = max((int) $session->charged_cents, min((int) $session->budget_cents,
            (int) $session->charged_cents + $memberBalance,
            intdiv($elapsed * (int) $session->rate_cents + 3599, 3600)));
        $delta = $total - (int) $session->charged_cents;
        if ($delta > 0) { $this->post($session->user_id, -$delta, 'usage', 'usage:'.$session->id.':'.$total); }
        $updates = ['charged_cents' => $total];
        if ($at >= $session->deadline_at || $stop) {
            $creditDuration = intdiv((int) $session->budget_cents * 3600, (int) $session->rate_cents);
            $reason = $at >= $session->deadline_at ? (($session->deadline_at - $session->started_at) >= $creditDuration ? 'credit_exhausted' : 'session_limit') : $stop;
            $updates += ['stopped_at' => $at, 'stop_reason' => $reason, 'active_user_id' => null, 'active_court_id' => null];
            $this->db()->table('lights_courts')->where('id', $session->court_id)->update(['relay_on' => false, 'relay_until' => null]);
            $this->event('simulated_shelly_off', ['session' => $session->id, 'reason' => $reason], $actor);
        }
        $this->db()->table('lights_sessions')->where('id', $session->id)->update($updates);
    }

    public function stop(int $user, string $id, bool $admin = false): void
    {
        $this->locked(function () use ($user, $id, $admin) {
            if ($admin) { abort_unless($this->db()->table('lights_users')->where('id', $user)->where('is_admin', true)->where('active', true)->exists(), 403); }
            $query = $this->db()->table('lights_sessions')->where('id', $id);
            if (!$admin) { $query->where('user_id', $user); }
            $session = $query->firstOrFail();
            if ($session->active_user_id) { $this->settle($session, $admin ? 'admin_stop' : 'member_stop', $user); }
        });
    }

    public function tick(bool $worker = false): void
    {
        $this->locked(function () use ($worker) {
            $this->settleAll();
            if ($worker) { $this->db()->table('lights_worker')->updateOrInsert(['id' => 1], ['seen_at' => $this->now()]); }
        });
    }

    public function snapshot(int $user): array
    {
        return $this->locked(function () use ($user) {
            $this->settleAll();
            $customerControl = (bool) config('lights.control.customer_enabled');
            $customerSessions = collect();
            if ($customerControl && $this->db()->getSchemaBuilder()->hasColumn('lights_control_sessions', 'court_id')) {
                $customerSessions = $this->db()->table('lights_control_sessions')->where('user_id', $user)
                    ->whereIn('driver', ['customer_cloud', 'cloud_customer'])->whereNotNull('active_user_id')->get();
            }
            $now = $this->now();
            $workerSeenAt = $this->db()->table('lights_worker')->where('id', 1)->value('seen_at');
            $workerHealthy = $workerSeenAt && (int) $workerSeenAt >= $now - 15;
            $courts = $this->db()->table('lights_courts')->orderBy('id')->get();
            foreach ($courts as $court) {
                $active = $this->db()->table('lights_sessions')->where('active_court_id', $court->id)->first();
                $hardware = $customerControl && $this->db()->getSchemaBuilder()->hasTable('lights_control_sessions')
                    ? $this->db()->table('lights_control_sessions')->whereIn('driver', ['customer_cloud', 'cloud_customer'])
                        ->where('active_channel', $court->channel)->first() : null;
                $observed = $customerControl && $this->db()->getSchemaBuilder()->hasTable('lights_hardware_status')
                    ? $this->db()->table('lights_hardware_status')->where('channel', $court->channel)->first() : null;
                $court->hardware_output = $observed && $observed->output !== null ? (bool) $observed->output : null;
                $court->hardware_checked_at = $observed ? (int) $observed->checked_at : null;
                $court->hardware_stale = !$observed || (int) $observed->checked_at < $now - 120;
                $court->hardware_online = (bool) ($observed && $observed->online && !$court->hardware_stale && !$observed->has_errors);
                $court->in_use = (bool) ($active || $hardware || $court->hardware_output === true);
                $court->control_state = $hardware?->state;
                $court->pending_action = $hardware && in_array($hardware->state, ['reserved', 'starting', 'stopping'], true)
                    ? ($hardware->stop_requested_at !== null || $hardware->state === 'stopping' ? 'off' : 'on')
                    : null;
                $court->is_on = $court->hardware_output === true || ($hardware ? $hardware->state === 'running' && $hardware->started_at !== null
                    : $court->relay_on && $court->relay_until > $this->now());
                $pilotReady = !app()->environment('acceptance') || !config('lights.control.local_approval_required')
                    || \App\Lights\Shelly\PilotApproval::allows((int) $court->channel);
                $court->control_ready = !$customerControl || ($workerHealthy && $court->hardware_online && $pilotReady);
                $court->control_reason = !$court->active ? 'This court is unavailable.'
                    : (!$customerControl ? 'Charged by elapsed seconds.'
                    : (!$workerHealthy ? 'Light control is temporarily unavailable.'
                    : (!$court->hardware_online ? 'The court controller is offline or its status is stale.'
                    : (!$pilotReady ? 'This court has not been released for customer control.' : 'Charged by elapsed seconds.'))));
                unset($court->device_label, $court->channel, $court->relay_on, $court->relay_until);
            }
            $sessions = $this->db()->table('lights_sessions')->where('active_user_id', $user)->get();
            if ($customerSessions->isNotEmpty()) {
                $sessions = $customerSessions->map(fn ($customer) => (object) [
                    'id' => $customer->id, 'court_id' => $customer->court_id,
                    'rate_cents' => $customer->rate_cents, 'budget_cents' => $customer->budget_cents,
                    'charged_cents' => $customer->charged_cents, 'started_at' => $customer->started_at,
                    'deadline_at' => $customer->deadline_at, 'control_state' => $customer->state,
                    'billing_started' => $customer->started_at !== null, 'uncertain' => (bool) $customer->uncertain,
                ]);
            }
            $member = $this->db()->table('lights_users')->find($user);
            abort_unless($member, 404);
            return ['server_time' => $now, 'balance_cents' => (int) $member->balance_cents,
                'email_verified' => !$this->db()->getSchemaBuilder()->hasColumn('lights_users', 'email_verified_at') || (bool) $member->email_verified_at,
                'customer_control' => $customerControl,
                'session' => $sessions->first(), 'sessions' => $sessions->values(), 'courts' => $courts,
                'worker_seen_at' => $workerSeenAt];
        });
    }

    public function saveCourt(int $actor, ?int $id, array $data): void
    {
        $this->locked(function () use ($actor, $id, $data) {
            abort_unless($this->db()->table('lights_users')->where('id', $actor)->where('is_admin', true)->where('active', true)->exists(), 403);
            $this->settleAll();
            if ($id && $this->db()->table('lights_sessions')->where('active_court_id', $id)->exists()) { $this->fail('Stop the active session before editing this court.'); }
            if ($this->db()->table('lights_courts')->where('device_label', $data['device_label'])->where('channel', $data['channel'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) { $this->fail('That Shelly channel already belongs to another court.'); }
            if ($id) { $this->db()->table('lights_courts')->where('id', $id)->firstOrFail(); $this->db()->table('lights_courts')->where('id', $id)->update($data); }
            else { $id = $this->db()->table('lights_courts')->insertGetId($data); }
            $this->event('court_updated', ['court' => $id, 'settings' => $data], $actor);
        });
    }
}
