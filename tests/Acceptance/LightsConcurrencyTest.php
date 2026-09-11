<?php

namespace Tests\Acceptance;

use App\Lights\Member;
use App\Lights\Portal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class LightsConcurrencyTest extends \Tests\TestCase
{
    private array $users = [];
    private array $courts = [];
    public function createApplication() { return require dirname(__DIR__, 2).'/scripts/local-acceptance/bootstrap.php'; }
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proshop_lights_acceptance', DB::connection('lights')->getDatabaseName());
        foreach ([1, 2] as $n) {
            $member = Member::create(['name' => 'Race fixture', 'email' => 'race-'.Str::uuid().'@lights.test', 'password' => bcrypt(Str::random(32))]);
            $this->users[] = $member->id;
            $id = app(Portal::class)->topup($member->id, 1000, (string) Str::uuid());
            app(Portal::class)->confirmTopup($member->id, $id, 'paid');
            $this->courts[] = DB::connection('lights')->table('lights_courts')->insertGetId(['name' => 'Race fixture '.Str::uuid(), 'rate_cents' => 6000, 'device_label' => 'race-'.Str::uuid(), 'channel' => 0]);
        }
    }
    protected function tearDown(): void
    {
        $db = DB::connection('lights');
        $db->transaction(function () use ($db) {
            $db->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            foreach ($db->table('lights_sessions')->whereIn('user_id', $this->users)->get() as $session) {
                $db->table('lights_events')->where('details', 'like', '%'.$session->id.'%')->delete();
            }
            foreach (['lights_ledger', 'lights_topups', 'lights_sessions'] as $table) { $db->table($table)->whereIn('user_id', $this->users)->delete(); }
            $db->table('lights_events')->whereIn('actor_id', $this->users)->delete();
            $db->table('lights_courts')->whereIn('id', $this->courts)->delete();
            $db->table('lights_users')->whereIn('id', $this->users)->delete();
        });
        parent::tearDown();
    }
    private function race(array $jobs): void
    {
        $barrier = base_path('.local-acceptance/cache/lights-race-'.bin2hex(random_bytes(12)).'.signal');
        file_put_contents($barrier, 'WAIT'); $workers = [];
        try {
            foreach ($jobs as [$mode, $user, $target]) {
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec,curl_multi_exec', '-d', 'allow_url_fopen=0', base_path('scripts/local-acceptance/lights-race-worker.php'), $mode, (string) $user, (string) $target, $barrier], base_path(), null, null, 30);
                $worker->start(); $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $deadline = microtime(true) + 15;
                while (!str_contains($worker->getOutput(), 'READY') && $worker->isRunning() && microtime(true) < $deadline) { usleep(10000); }
                $this->assertStringContainsString('READY', $worker->getOutput(), $worker->getErrorOutput());
            }
            file_put_contents($barrier, 'GO');
            foreach ($workers as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); $this->assertStringContainsString('DONE', $worker->getOutput()); }
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(1); } }
            unlink($barrier);
        }
    }
    public function test_two_members_racing_for_one_court_get_one_session(): void
    {
        $this->race([['start', $this->users[0], $this->courts[0]], ['start', $this->users[1], $this->courts[0]]]);
        $this->assertSame(1, DB::connection('lights')->table('lights_sessions')->whereIn('user_id', $this->users)->count());
    }
    public function test_member_racing_for_two_courts_gets_one_session(): void
    {
        $this->race([['start', $this->users[0], $this->courts[0]], ['start', $this->users[0], $this->courts[1]]]);
        $this->assertSame(1, DB::connection('lights')->table('lights_sessions')->whereIn('user_id', $this->users)->count());
    }
    public function test_parallel_topup_confirmation_credits_once(): void
    {
        $id = app(Portal::class)->topup($this->users[0], 1000, (string) Str::uuid());
        $this->race([['topup', $this->users[0], $id], ['topup', $this->users[0], $id]]);
        $this->assertSame(2000, Member::find($this->users[0])->balance_cents);
        $this->assertSame(1, DB::connection('lights')->table('lights_ledger')->where('reference', 'topup:'.$id)->count());
    }
    public function test_parallel_stops_settle_once(): void
    {
        $portal = app(Portal::class); $id = $portal->start($this->users[0], $this->courts[0]);
        $this->race([['stop', $this->users[0], $id], ['stop', $this->users[0], $id]]);
        $session = $portal->db()->table('lights_sessions')->find($id);
        $this->assertNull($session->active_user_id);
        $this->assertEquals($session->charged_cents, -$portal->db()->table('lights_ledger')->where('user_id', $this->users[0])->where('kind', 'usage')->sum('amount_cents'));
        $this->assertEquals(1000 - $session->charged_cents, Member::find($this->users[0])->balance_cents);
        $this->assertSame(1, $portal->db()->table('lights_events')->where('kind', 'simulated_shelly_off')->where('details', 'like', '%'.$id.'%')->count());
    }
}
