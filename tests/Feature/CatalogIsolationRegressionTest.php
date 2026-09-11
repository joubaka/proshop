<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\RegressionTestCase;

class CatalogIsolationRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        require_once database_path('migrations/2017_07_23_113209_create_brands_table.php');
        (new \CreateBrandsTable)->up();
    }

    public function test_unprivileged_staff_cannot_create_update_or_delete_brands(): void
    {
        $this->signInWithPermissions();
        $this->postJson('/brands', ['name' => 'Denied'])->assertForbidden();
        $this->putJson('/brands/1', ['name' => 'Denied'])->assertForbidden();
        $this->deleteJson('/brands/1')->assertForbidden();
        $this->assertDatabaseCount('brands', 0);
    }

    public function test_create_uses_session_business_and_actor_not_submitted_ids(): void
    {
        $user = $this->signInWithPermissions(['brand.create']);
        $this->postJson('/brands', [
            'name' => 'Rackets', 'description' => 'Local brand', 'business_id' => 2, 'created_by' => 999,
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('brands', ['name' => 'Rackets', 'business_id' => 1, 'created_by' => $user->id]);
    }

    public function test_authorized_brand_update_and_soft_delete(): void
    {
        $user = $this->signInWithPermissions(['brand.update', 'brand.delete']);
        $id = DB::table('brands')->insertGetId(['business_id' => 1, 'created_by' => $user->id, 'name' => 'Old']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->putJson('/brands/'.$id, ['name' => 'New', 'description' => 'Updated'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('brands', ['id' => $id, 'name' => 'New']);
        $this->deleteJson('/brands/'.$id)->assertOk()->assertJsonPath('success', true);
        $this->assertSoftDeleted('brands', ['id' => $id]);
    }

    public function test_permissions_do_not_allow_modifying_another_business_brand(): void
    {
        $user = $this->signInWithPermissions(['brand.update', 'brand.delete']);
        $id = DB::table('brands')->insertGetId(['business_id' => 2, 'created_by' => $user->id, 'name' => 'Other shop']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->putJson('/brands/'.$id, ['name' => 'Changed', 'description' => 'Changed'])
            ->assertOk()->assertJsonPath('success', false);
        $this->deleteJson('/brands/'.$id)->assertOk()->assertJsonPath('success', false);
        $this->assertDatabaseHas('brands', ['id' => $id, 'name' => 'Other shop', 'deleted_at' => null]);
    }
}
