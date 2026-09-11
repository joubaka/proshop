<?php

namespace App\Console\Commands;

use App\Lights\Member;
use App\Lights\Portal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateLightsAdmin extends Command
{
    protected $signature = 'lights:create-admin {email} {--name=Lights Administrator}';
    protected $description = 'Create or promote a Lights administrator without exposing a password';

    public function handle(Portal $portal): int
    {
        if (!$portal->enabled()) { $this->error('The Lights database is not enabled for this environment.'); return self::FAILURE; }
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name'));
        $validation = Validator::make(compact('email', 'name'), ['email' => 'required|email|max:255', 'name' => 'required|string|max:100']);
        if ($validation->fails()) { $this->error($validation->errors()->first()); return self::FAILURE; }
        $password = $this->secret('New password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || !hash_equals($password, (string) $confirmation)) {
            $this->error('Passwords must match and contain 12 to 72 characters.'); return self::FAILURE;
        }
        $member = Member::firstOrNew(['email' => $email]);
        $member->name = $name; $member->password = Hash::make($password); $member->active = true; $member->is_admin = true; $member->save();
        $this->info('Lights administrator saved. The password was not printed or logged.');
        return self::SUCCESS;
    }
}
