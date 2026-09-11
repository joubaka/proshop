<?php

namespace App\Lights;

use App\Notifications\LightsAccountLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountTokens
{
    public function __construct(private Portal $portal) {}

    public function send(Member $member, string $purpose): void
    {
        [$token] = $this->issue($member, $purpose);
        $member->notify(new LightsAccountLink($purpose, $token));
    }

    public function issue(Member $member, string $purpose): array
    {
        abort_unless(in_array($purpose, ['verify', 'reset'], true), 422);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        $now = $this->portal->now();
        $this->portal->db()->transaction(function () use ($member, $purpose, $token, $id, $now) {
            $this->portal->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->portal->db()->table('lights_account_tokens')->where('user_id', $member->id)
                ->where('purpose', $purpose)->whereNull('used_at')->update(['used_at' => $now]);
            $this->portal->db()->table('lights_account_tokens')->insert([
                'id' => $id, 'user_id' => $member->id, 'purpose' => $purpose,
                'token_hash' => hash('sha256', $token), 'expires_at' => $now + ($purpose === 'verify' ? 86400 : 3600),
                'created_at' => $now,
            ]);
        }, 3);

        return [$token, $id];
    }

    public function verifyEmail(string $token): Member
    {
        return $this->consume($token, 'verify', function (Member $member) {
            $member->email_verified_at = $this->portal->now();
            $member->save();
        });
    }

    public function resetPassword(string $token, string $password): Member
    {
        return $this->consume($token, 'reset', function (Member $member) use ($password) {
            $member->password = Hash::make($password);
            $member->setRememberToken(Str::random(60));
            $member->save();
        });
    }

    private function consume(string $token, string $purpose, callable $action): Member
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) { $this->invalid(); }
        return $this->portal->db()->transaction(function () use ($token, $purpose, $action) {
            $this->portal->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $row = $this->portal->db()->table('lights_account_tokens')->where('token_hash', hash('sha256', $token))
                ->where('purpose', $purpose)->whereNull('used_at')->first();
            if (!$row || (int) $row->expires_at < $this->portal->now()) { $this->invalid(); }
            $member = Member::findOrFail($row->user_id);
            $action($member);
            $this->portal->db()->table('lights_account_tokens')->where('id', $row->id)
                ->update(['used_at' => $this->portal->now()]);
            return $member->fresh();
        }, 3);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['token' => 'This account link is invalid or has expired.']);
    }
}
