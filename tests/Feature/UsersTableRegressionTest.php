<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RegressionTestCase;

class UsersTableRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        Schema::table('users', function (Blueprint $table) {
            $table->string('surname')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_cmmsn_agnt')->default(false);
        });
        // The production query uses MySQL's CONCAT function.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
    }

    public function test_users_table_renders_all_authorized_action_urls(): void
    {
        $user = $this->signInWithPermissions(['user.view', 'user.update', 'user.delete']);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/users?draw=1&start=0&length=25')
            ->assertOk()
            ->assertJsonMissingPath('error')
            ->assertJsonCount(1, 'data');

        $actions = $response->json('data.0.action');
        $this->assertStringContainsString('href="'.route('users.edit', $user->id).'"', $actions);
        $this->assertStringContainsString('href="'.route('users.show', $user->id).'"', $actions);
        $this->assertStringContainsString('data-href="'.route('users.destroy', $user->id).'"', $actions);
    }

    public function test_view_only_staff_do_not_receive_edit_or_delete_controls(): void
    {
        $user = $this->signInWithPermissions(['user.view']);

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/users?draw=1&start=0&length=25')
            ->assertOk()
            ->assertJsonMissingPath('error')
            ->assertJsonCount(1, 'data');

        $actions = $response->json('data.0.action');
        $this->assertStringContainsString('href="'.route('users.show', $user->id).'"', $actions);
        $this->assertStringNotContainsString('glyphicon-edit', $actions);
        $this->assertStringNotContainsString('delete_user_button', $actions);
    }
}
