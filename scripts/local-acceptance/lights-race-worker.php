<?php

try {
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('CLI only.'); }
    require __DIR__.'/bootstrap.php';
    [$script, $mode, $user, $target, $barrier] = $argv;
    if (realpath(dirname($barrier)) !== realpath(__DIR__.'/../../.local-acceptance/cache')
        || !preg_match('/^lights-race-[a-f0-9]+\.signal$/', basename($barrier))) { throw new RuntimeException('Invalid test barrier.'); }
    $member = App\Lights\Member::findOrFail((int) $user);
    if (!str_starts_with($member->email, 'race-') || !str_ends_with($member->email, '@lights.test')) { throw new RuntimeException('Not a race fixture member.'); }
    $portal = app(App\Lights\Portal::class);
    echo "READY\n"; fflush(STDOUT); $deadline = microtime(true) + 20;
    while (file_get_contents($barrier) !== 'GO') { if (microtime(true) > $deadline) { throw new RuntimeException('Missing barrier.'); } usleep(10000); }
    try {
        if ($mode === 'start') {
            $court = $portal->db()->table('lights_courts')->find((int) $target);
            if (!$court || !str_starts_with($court->name, 'Race fixture ')) { throw new RuntimeException('Not a race fixture court.'); }
            $portal->start($member->id, $court->id);
        } elseif ($mode === 'topup') { $portal->confirmTopup($member->id, $target, 'paid'); }
        elseif ($mode === 'stop') { $portal->stop($member->id, $target); }
        else { throw new RuntimeException('Unknown race mode.'); }
    } catch (Illuminate\Validation\ValidationException $expected) { echo "BLOCKED\n"; }
    echo "DONE\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); }
