<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $app = require __DIR__.'/bootstrap.php';
    $db = Illuminate\Support\Facades\DB::connection('lights');
    if ($db->getDatabaseName() !== 'proshop_lights_acceptance' || !$app->environment('acceptance')) {
        throw new RuntimeException('Refusing a non-acceptance lights database.');
    }
    // bootstrap.php checked the server's exact @@datadir before reaching here.
    Illuminate\Support\Facades\DB::connection('mysql')->statement('CREATE DATABASE IF NOT EXISTS proshop_lights_acceptance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $exit = Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'lights', '--path' => 'database/migrations/lights', '--no-interaction' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    if ($exit !== 0) { exit($exit); }
    $admin = App\Lights\Member::firstOrCreate(['email' => 'admin@lights.test'], ['name' => 'Lights Admin', 'password' => Illuminate\Support\Facades\Hash::make('LocalLights!2026')]);
    $admin->forceFill(['is_admin' => true, 'active' => true, 'email_verified_at' => time(), 'terms_accepted_at' => time()])->save();
    $player = App\Lights\Member::firstOrCreate(['email' => 'player@lights.test'], ['name' => 'Alex Player', 'password' => Illuminate\Support\Facades\Hash::make('LocalLights!2026')]);
    $player->forceFill(['active' => true, 'email_verified_at' => time(), 'terms_accepted_at' => time()])->save();
    if (!$db->table('lights_courts')->exists()) {
        app(App\Lights\Portal::class)->saveCourt($admin->id, null, ['name' => 'Court 3', 'rate_cents' => 6000, 'device_label' => 'Shelly Pro 2 PM 2cbcbba011f8', 'channel' => 0, 'active' => true]);
        app(App\Lights\Portal::class)->saveCourt($admin->id, null, ['name' => 'Court 4', 'rate_cents' => 6000, 'device_label' => 'Shelly Pro 2 PM 2cbcbba011f8', 'channel' => 1, 'active' => true]);
    } else {
        // Correct only the untouched original demo rows; never overwrite administrator customisation.
        foreach ([1 => ['Court 1', 'Court 3', 0], 2 => ['Court 2', 'Court 4', 1]] as $id => [$old, $name, $channel]) {
            $court = $db->table('lights_courts')->find($id);
            if ($court && $court->name === $old && $court->device_label === 'Shelly Pro 2 demo' && (int) $court->channel === $channel) {
                app(App\Lights\Portal::class)->saveCourt($admin->id, $id, ['name' => $name, 'rate_cents' => $court->rate_cents,
                    'device_label' => 'Shelly Pro 2 PM 2cbcbba011f8', 'channel' => $channel, 'active' => (bool) $court->active]);
            }
        }
    }
    echo "Lights database: proshop_lights_acceptance. Demo accounts start with zero credit; top up in the simulator.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
