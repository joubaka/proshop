<?php

namespace Tests\Feature;

use App\Transaction;
use App\Utils\Util;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RegressionTestCase;

class ActivityLogRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        foreach ([
            '2019_03_12_120336_create_activity_log_table.php' => \CreateActivityLogTable::class,
            '2021_03_16_120705_add_business_id_to_activity_log_table.php' => \AddBusinessIdToActivityLogTable::class,
            '2024_01_01_000000_add_batch_uuid_to_activity_log_table.php' => \AddBatchUuidToActivityLogTable::class,
        ] as $file => $class) {
            require_once database_path('migrations/'.$file);
            (new $class)->up();
        }
    }

    public function test_existing_logger_records_actor_subject_business_and_change_history(): void
    {
        $user = $this->signInWithPermissions();
        $subject = new Transaction;
        $subject->forceFill(['id' => 10, 'business_id' => 1, 'type' => 'sell', 'status' => 'draft', 'final_total' => 100]);
        $before = clone $subject;
        $subject->status = 'final';
        (new Util)->activityLog($subject, 'edited', $before, ['update_note' => 'Test correction']);
        $activity = Activity::sole();
        $this->assertEquals($user->id, $activity->causer_id);
        $this->assertSame(Transaction::class, $activity->subject_type);
        $this->assertEquals(10, $activity->subject_id);
        $this->assertEquals(1, $activity->business_id);
        $this->assertSame('edited', $activity->description);
        $this->assertSame('Test correction', $activity->properties['update_note']);
        $this->assertSame('draft', $activity->properties['old']['status']);
        $this->assertSame('final', $activity->properties['attributes']['status']);
    }

    public function test_background_logging_uses_explicit_business_without_a_signed_in_actor(): void
    {
        $subject = new Transaction;
        $subject->forceFill(['id' => 11, 'business_id' => 2]);
        (new Util)->activityLog($subject, 'scheduled', null, [], false, 2);
        $activity = Activity::sole();
        $this->assertEquals(2, $activity->business_id);
        $this->assertNull($activity->causer_id);
        $this->assertSame('scheduled', $activity->description);
    }
}
