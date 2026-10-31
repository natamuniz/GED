<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use App\Services\Administration;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['profile_id' => Profile::where('name', 'Administrador')->value('id')]);
    }

    private function permission(string $key): int
    {
        return Permission::where('key', $key)->value('id');
    }

    public function test_admin_routes_require_authentication_and_resource_permission(): void
    {
        foreach (['users', 'profiles', 'permissions', 'units'] as $resource) {
            $this->getJson('/api/v1/admin/'.$resource)->assertUnauthorized();
        }
        $reader = User::factory()->create(['profile_id' => Profile::where('name', 'Leitor')->value('id')]);
        $this->actingAs($reader);
        foreach (['users', 'profiles', 'permissions', 'units'] as $resource) {
            $this->getJson('/api/v1/admin/'.$resource)->assertForbidden();
            if ($resource !== 'permissions') {
                $this->postJson('/api/v1/admin/'.$resource, [])->assertForbidden();
            }
        }
    }

    public function test_create_user_assigns_profile_units_and_individual_overrides_and_never_exposes_credentials(): void
    {
        $unit = OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
        $profile = Profile::where('name', 'Colaborador')->first();
        $password = 'uma-senha-de-teste-123';
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [
            'name' => 'Ana', 'email' => 'ANA@EXAMPLE.TEST', 'password' => $password, 'password_confirmation' => $password,
            'profile_id' => $profile->id, 'unit_ids' => [$unit->id],
            'permission_overrides' => [
                ['permission_id' => $this->permission('documents.archive'), 'effect' => 'allow'],
                ['permission_id' => $this->permission('shares.create'), 'effect' => 'deny'],
            ],
        ])->assertCreated()->assertJsonPath('data.email', 'ana@example.test');
        $user = User::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->hasPermission('documents.archive'));
        $this->assertFalse($user->hasPermission('shares.create'));
        $this->assertEquals([$unit->id], $user->units()->pluck('organizational_units.id')->all());
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $event = AuditEvent::where('action', 'user.created')->sole();
        $this->assertStringNotContainsString($password, json_encode($event->changes));
        $this->assertStringNotContainsString($user->password, json_encode($event->changes));
        $this->getJson('/api/v1/admin/users/'.$user->id)->assertOk()
            ->assertJsonPath('data.permission_overrides.0.effect', 'allow');
    }

    public function test_patch_preserves_absent_fields_and_empty_arrays_clear_links_and_overrides(): void
    {
        $user = User::factory()->create();
        $unit = OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
        $user->units()->attach($unit);
        $user->permissionOverrides()->attach($this->permission('documents.view'), ['effect' => 'allow']);
        $this->actingAs($this->admin)->patchJson('/api/v1/admin/users/'.$user->id, ['name' => 'Alterado'])->assertOk();
        $this->assertEquals(1, $user->units()->count());
        $this->assertEquals(1, $user->permissionOverrides()->count());
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['unit_ids' => [], 'permission_overrides' => [], 'profile_id' => null])->assertOk();
        $this->assertEquals(0, $user->units()->count());
        $this->assertEquals(0, $user->permissionOverrides()->count());
        $this->assertNull($user->fresh()->profile_id);
        $this->deleteJson('/api/v1/admin/users/'.$user->id)->assertMethodNotAllowed();
    }

    public function test_user_administration_alone_cannot_assign_profiles_or_permission_overrides(): void
    {
        $operator = User::factory()->create();
        $operator->permissionOverrides()->attach($this->permission('users.manage'), ['effect' => 'allow']);
        $target = User::factory()->create();
        $this->actingAs($operator)->patchJson('/api/v1/admin/users/'.$target->id, ['name' => 'Permitido'])->assertOk();
        $this->patchJson('/api/v1/admin/users/'.$target->id, ['profile_id' => $this->admin->profile_id])->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$target->id, ['permission_overrides' => []])->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$target->id, ['profile_id' => null])->assertForbidden();
    }

    public function test_last_administrator_cannot_be_disabled_demoted_or_blocked_and_rollback_preserves_audit(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            ['is_active' => false], ['profile_id' => null],
            ['permission_overrides' => [['permission_id' => $this->permission('profiles.manage'), 'effect' => 'deny']]],
        ] as $data) {
            $this->patchJson('/api/v1/admin/users/'.$this->admin->id, $data)->assertUnprocessable()->assertJsonValidationErrors('administration');
        }
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertNotNull($this->admin->fresh()->profile_id);
        $this->assertDatabaseMissing('audit_events', ['action' => 'user.updated']);
        $this->patchJson('/api/v1/admin/profiles/'.$this->admin->profile_id, ['permission_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('administration');
        $this->assertTrue($this->admin->fresh()->hasPermission('users.manage'));
        $this->assertDatabaseMissing('audit_events', ['action' => 'profile.updated']);
    }

    public function test_administrator_can_be_disabled_when_another_user_has_effective_recovery_permissions(): void
    {
        $backup = User::factory()->create();
        $backup->permissionOverrides()->attach($this->permission('users.manage'), ['effect' => 'allow']);
        $backup->permissionOverrides()->attach($this->permission('profiles.manage'), ['effect' => 'allow']);
        $this->actingAs($this->admin)->patchJson('/api/v1/admin/users/'.$this->admin->id, ['is_active' => false])->assertOk();
        $this->assertFalse($this->admin->fresh()->is_active);
        $this->actingAs($this->admin->fresh())->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_profiles_can_be_customized_and_deleted_only_when_custom_and_unused(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/v1/admin/profiles', ['name' => 'Sem acesso', 'permission_ids' => []])
            ->assertCreated()->assertJsonPath('data.is_system', false)->assertJsonCount(0, 'data.permissions');
        $response = $this->postJson('/api/v1/admin/profiles', [
            'name' => 'Auditor', 'permission_ids' => [$this->permission('documents.view')],
        ])->assertCreated();
        $id = $response->json('data.id');
        $this->getJson('/api/v1/admin/profiles/'.$id)->assertOk()->assertJsonPath('data.users_count', 0);
        $user = User::factory()->create(['profile_id' => $id]);
        $this->patchJson('/api/v1/admin/profiles/'.$id, ['permission_ids' => [$this->permission('audit.view')]])->assertOk();
        $this->assertTrue($user->fresh()->hasPermission('audit.view'));
        $this->assertFalse($user->fresh()->hasPermission('documents.view'));
        $this->deleteJson('/api/v1/admin/profiles/'.$id)->assertConflict();
        $user->profile_id = null;
        $user->save();
        $this->deleteJson('/api/v1/admin/profiles/'.$id)->assertNoContent();
        $this->deleteJson('/api/v1/admin/profiles/'.$this->admin->profile_id)->assertConflict();
        $this->patchJson('/api/v1/admin/profiles/'.$this->admin->profile_id, ['name' => 'Outro nome'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/profiles', ['name' => 'Inválido', 'permission_ids' => [], 'is_system' => true])->assertUnprocessable();
    }

    public function test_units_reject_cycles_and_inactive_parents_and_preserve_existing_links_when_deactivated(): void
    {
        $this->actingAs($this->admin);
        $parentId = $this->postJson('/api/v1/admin/units', ['name' => 'Financeiro', 'code' => 'FIN'])
            ->assertCreated()->json('data.id');
        $childId = $this->postJson('/api/v1/admin/units', ['name' => 'Contas a pagar', 'code' => 'CP', 'parent_id' => $parentId])
            ->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/admin/units/'.$parentId, ['parent_id' => $childId])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/units/'.$childId, ['parent_id' => $childId])->assertUnprocessable();
        $this->admin->units()->attach($childId);
        $this->patchJson('/api/v1/admin/units/'.$childId, ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('organizational_unit_user', ['user_id' => $this->admin->id, 'organizational_unit_id' => $childId]);
        $this->postJson('/api/v1/admin/units', ['name' => 'Filha', 'code' => 'FILHA', 'parent_id' => $childId])->assertUnprocessable();
        $newUser = User::factory()->create();
        $this->patchJson('/api/v1/admin/users/'.$newUser->id, ['unit_ids' => [$childId]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/users/'.$this->admin->id, ['unit_ids' => [$childId]])->assertOk();
        $this->deleteJson('/api/v1/admin/units/'.$childId)->assertMethodNotAllowed();
        $this->getJson('/api/v1/admin/units/'.$parentId)->assertOk()->assertJsonCount(1, 'data.children');
        $this->getJson('/api/v1/admin/units?is_active=0')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_catalog_is_read_only_and_lists_are_paginated_filtered_and_validated(): void
    {
        $this->actingAs($this->admin);
        $this->getJson('/api/v1/admin/permissions?group=documents&per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonStructure(['links', 'meta']);
        $id = $this->permission('users.manage');
        $this->getJson('/api/v1/admin/permissions/'.$id)->assertOk()->assertJsonPath('data.key', 'users.manage');
        $this->patchJson('/api/v1/admin/permissions/'.$id, ['key' => 'anything'])->assertMethodNotAllowed();
        foreach (['users', 'profiles', 'units', 'permissions'] as $resource) {
            $this->getJson('/api/v1/admin/'.$resource.'?per_page=101')->assertUnprocessable();
            $this->getJson('/api/v1/admin/'.$resource.'/999999')->assertNotFound();
        }
        $this->getJson('/api/v1/admin/users?search='.urlencode($this->admin->email))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/admin/profiles', ['name' => 'Teste', 'permission_ids' => [999999]])->assertUnprocessable();
        $this->postJson('/api/v1/admin/units', ['name' => 'Um', 'code' => 'UNICO'])->assertCreated();
        $this->postJson('/api/v1/admin/units', ['name' => 'Dois', 'code' => 'UNICO'])->assertUnprocessable();
    }

    public function test_password_reset_deletes_existing_sessions_and_audits_only_a_change_marker(): void
    {
        $target = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $password = 'outra-senha-segura-123';
        $this->actingAs($this->admin)->patchJson('/api/v1/admin/users/'.$target->id, [
            'password' => $password, 'password_confirmation' => $password,
        ])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
        $this->assertTrue(Hash::check($password, $target->fresh()->password));
        $event = AuditEvent::where('action', 'user.updated')->sole();
        $this->assertTrue($event->changes['after']['password_changed']);
        $this->assertStringNotContainsString($password, json_encode($event->changes));
    }

    public function test_mutations_hold_a_cross_connection_transaction_lock(): void
    {
        config(['database.connections.admin_probe' => config('database.connections.pgsql')]);
        app(Administration::class)->mutate($this->admin, 'units.manage', function () {
            $probe = DB::connection('admin_probe');
            $probe->beginTransaction();
            try {
                $locked = $probe->selectOne('SELECT pg_try_advisory_xact_lock(71932, 1) AS acquired');
                $this->assertFalse($locked->acquired);
            } finally {
                $probe->rollBack();
                DB::purge('admin_probe');
            }
        });
    }
}
