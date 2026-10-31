<?php

namespace Tests\Feature;

use App\Actions\AddDocumentVersion;
use App\Actions\CreateDocument;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $manager;

    private User $reader;

    private User $outsider;

    private User $admin;

    private OrganizationalUnit $finance;

    private OrganizationalUnit $receivable;

    private OrganizationalUnit $payable;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->finance = OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
        $this->receivable = OrganizationalUnit::create(['name' => 'Contas a receber', 'code' => 'CR', 'parent_id' => $this->finance->id]);
        $this->payable = OrganizationalUnit::create(['name' => 'Contas a pagar', 'code' => 'CP', 'parent_id' => $this->finance->id]);
        $this->creator = $this->user('Colaborador', $this->receivable);
        $this->manager = $this->user('Gestor', $this->finance);
        $this->reader = $this->user('Leitor', $this->receivable);
        $this->outsider = $this->user('Gestor', $this->payable);
        $this->admin = $this->user('Administrador');
        $this->document = app(CreateDocument::class)->execute([
            'title' => 'Relatório original', 'description' => 'Descrição original',
            'document_type_id' => DocumentType::where('name', 'Relatório')->value('id'),
            'producing_unit_id' => $this->receivable->id, 'production_date' => '2026-10-31',
        ], UploadedFile::fake()->createWithContent('relatorio.pdf', '%PDF-1.4 original'), $this->creator);
    }

    private function user(string $profile, ?OrganizationalUnit $unit = null): User
    {
        $user = User::factory()->create(['profile_id' => Profile::where('name', $profile)->value('id')]);
        if ($unit) {
            $user->units()->attach($unit);
        }

        return $user;
    }

    private function path(string $suffix = ''): string
    {
        return '/api/v1/documents/'.$this->document->id.$suffix;
    }

    private function shareToken(): string
    {
        return $this->actingAs($this->creator)->postJson('/api/v1/versions/'.$this->document->latestVersion->id.'/shares', [
            'recipient_ids' => [$this->outsider->id], 'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('token');
    }

    public function test_metadata_patch_preserves_absent_fields_and_files_and_records_before_and_after(): void
    {
        $version = $this->document->latestVersion;
        $response = $this->actingAs($this->creator)->patchJson($this->path(), [
            'title' => 'Título atualizado', 'description' => null, 'author' => 'Autor externo',
            'interested_party' => 'Empresa ABC', 'reference_number' => 'REL-10/2026', 'production_date' => '2026-10-30',
        ])->assertOk()->assertJsonPath('data.title', 'Título atualizado')->assertJsonPath('data.description', null);
        $response->assertJsonPath('data.updated_by', $this->creator->id)->assertJsonPath('data.latest_version.sha256', $version->sha256);
        $this->assertEquals($this->document->document_type_id, $this->document->fresh()->document_type_id);
        $this->assertDatabaseCount('document_versions', 1);
        Storage::disk('local')->assertExists($version->storage_path);
        $event = AuditEvent::where('action', 'document.metadata-updated')->sole();
        $this->assertSame('Relatório original', $event->changes['before']['title']);
        $this->assertSame('Título atualizado', $event->changes['after']['title']);
        $this->assertSame('2026-10-30', $event->changes['after']['production_date']);
        $this->assertEquals($this->document->id, $event->document_id);
    }

    public function test_metadata_validation_rejects_empty_invalid_and_protected_fields_without_side_effects(): void
    {
        $this->actingAs($this->creator);
        foreach ([[], ['title' => null], ['production_date' => '2026-02-30'], ['document_type_id' => 999999],
            ['producing_unit_id' => null], ['archived_at' => null], ['updated_by' => $this->admin->id], ['file' => null],
        ] as $invalid) {
            $this->patchJson($this->path(), $invalid)->assertUnprocessable();
        }
        $this->assertSame('Relatório original', $this->document->fresh()->title);
        $this->assertDatabaseMissing('audit_events', ['action' => 'document.metadata-updated']);
    }

    public function test_readers_outsiders_and_unauthenticated_requests_cannot_mutate_documents(): void
    {
        $this->patchJson($this->path(), ['title' => 'Não permitido'])->assertUnauthorized();
        foreach ([$this->reader, $this->outsider] as $user) {
            $this->actingAs($user)->patchJson($this->path(), ['title' => 'Não permitido'])->assertForbidden();
            $this->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->payable->id])->assertForbidden();
            $this->postJson($this->path('/archive'))->assertForbidden();
            $this->postJson($this->path('/restore'))->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'document.archived']);
    }

    public function test_manager_can_move_between_descendants_and_access_follows_the_new_unit(): void
    {
        $this->actingAs($this->manager)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->payable->id])
            ->assertOk()->assertJsonPath('data.producing_unit_id', $this->payable->id)->assertJsonPath('data.updated_by', $this->manager->id);
        $this->actingAs($this->creator)->getJson($this->path())->assertForbidden();
        $this->actingAs($this->outsider)->getJson($this->path())->assertOk();
        $event = AuditEvent::where('action', 'document.unit-changed')->sole();
        $this->assertEquals($this->receivable->id, $event->changes['before']['producing_unit_id']);
        $this->assertEquals($this->payable->id, $event->changes['after']['producing_unit_id']);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_move_requires_access_to_both_origin_and_destination(): void
    {
        $hr = OrganizationalUnit::create(['name' => 'RH', 'code' => 'RH']);
        $this->actingAs($this->manager)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $hr->id])->assertForbidden();
        $this->actingAs($this->outsider)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->payable->id])->assertForbidden();
        $this->actingAs($this->creator)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->receivable->id])->assertForbidden();
        $this->assertEquals($this->receivable->id, $this->document->fresh()->producing_unit_id);
        $this->assertDatabaseMissing('audit_events', ['action' => 'document.unit-changed']);
    }

    public function test_moving_to_or_from_general_access_requires_the_specific_permission(): void
    {
        $this->actingAs($this->manager)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => null])->assertForbidden();
        $this->actingAs($this->admin)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => null])->assertOk()->assertJsonPath('data.producing_unit_id', null);
        $this->actingAs($this->outsider)->getJson($this->path())->assertOk();
        $this->actingAs($this->manager)->patchJson($this->path(), ['title' => 'Restrito'])->assertForbidden();
        $this->postJson($this->path('/archive'))->assertForbidden();
        $this->postJson($this->path('/restore'))->assertForbidden();
        $this->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->receivable->id])->assertForbidden();
        $this->actingAs($this->admin)->patchJson($this->path(), ['title' => 'Orientação geral'])->assertOk();
        $this->postJson($this->path('/archive'))->assertOk();
        $this->postJson($this->path('/restore'))->assertOk();
        $this->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->receivable->id])->assertOk();
        $this->actingAs($this->outsider)->getJson($this->path())->assertForbidden();
    }

    public function test_move_validates_the_destination_and_requires_an_explicit_field(): void
    {
        $inactive = OrganizationalUnit::create(['name' => 'Inativa', 'code' => 'INATIVA', 'is_active' => false]);
        $this->actingAs($this->manager);
        foreach ([[], ['producing_unit_id' => 999999], ['producing_unit_id' => $inactive->id], ['producing_unit_id' => []]] as $invalid) {
            $this->patchJson($this->path('/producing-unit'), $invalid)->assertUnprocessable();
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'document.unit-changed']);
    }

    public function test_archive_blocks_writes_preserves_files_download_and_existing_shares_and_restore_reenables_writes(): void
    {
        $token = $this->shareToken();
        $version = $this->document->latestVersion;
        $this->actingAs($this->outsider)->patchJson($this->path(), ['title' => 'Acesso apenas por link'])->assertForbidden();
        $this->postJson($this->path('/archive'))->assertForbidden();
        $this->actingAs($this->manager)->postJson($this->path('/archive'))->assertOk()
            ->assertJsonPath('data.archived_by', $this->manager->id);
        $this->patchJson($this->path(), ['title' => 'Bloqueado'])->assertForbidden();
        $this->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->payable->id])->assertForbidden();
        $this->actingAs($this->creator)->post($this->path('/versions'), [
            'file' => UploadedFile::fake()->createWithContent('novo.pdf', '%PDF-1.4 new'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/api/v1/versions/'.$version->id.'/shares', [
            'recipient_ids' => [$this->outsider->id], 'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertForbidden();
        $this->getJson($this->path())->assertOk();
        $this->get('/api/v1/versions/'.$version->id.'/download')->assertOk()->assertDownload('relatorio.pdf');
        $this->getJson('/api/v1/documents')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents?archived=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.total_documents', 1)->assertJsonPath('data.archived_documents', 1);
        $this->actingAs($this->outsider)->getJson('/api/v1/shared/'.$token)->assertOk();
        Storage::disk('local')->assertExists($version->storage_path);
        $this->assertDatabaseCount('document_versions', 1);
        $this->actingAs($this->manager)->postJson($this->path('/restore'))->assertOk()
            ->assertJsonPath('data.archived_at', null)->assertJsonPath('data.archived_by', null);
        $this->actingAs($this->creator)->patchJson($this->path(), ['title' => 'Após restauração'])->assertOk();
        $this->post($this->path('/versions'), [
            'file' => UploadedFile::fake()->createWithContent('novo.pdf', '%PDF-1.4 restored'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseHas('audit_events', ['action' => 'document.restored', 'actor_id' => $this->manager->id]);
    }

    public function test_repeated_operations_are_idempotent_without_duplicate_audit_or_timestamp_changes(): void
    {
        $this->actingAs($this->manager)->postJson($this->path('/archive'))->assertOk();
        $archivedAt = $this->document->fresh()->archived_at;
        $this->travel(1)->hours();
        $this->postJson($this->path('/archive'))->assertOk();
        $this->assertTrue($archivedAt->equalTo($this->document->fresh()->archived_at));
        $this->assertEquals(1, AuditEvent::where('action', 'document.archived')->count());
        $this->postJson($this->path('/restore'))->assertOk();
        $this->postJson($this->path('/restore'))->assertOk();
        $this->assertEquals(1, AuditEvent::where('action', 'document.restored')->count());
        $this->patchJson($this->path(), ['title' => 'Relatório original'])->assertOk();
        $this->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->receivable->id])->assertOk();
        $this->assertDatabaseMissing('audit_events', ['action' => 'document.metadata-updated']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'document.unit-changed']);
    }

    public function test_inactive_document_type_can_be_retained_but_cannot_be_selected_as_a_new_type(): void
    {
        $original = DocumentType::findOrFail($this->document->document_type_id);
        $original->is_active = false;
        $original->save();
        $inactive = DocumentType::create(['name' => 'Tipo desativado', 'is_active' => false]);
        $active = DocumentType::where('name', 'Ofício')->first();
        $this->actingAs($this->creator)->patchJson($this->path(), ['title' => 'Corrigido', 'document_type_id' => $original->id])->assertOk();
        $this->patchJson($this->path(), ['document_type_id' => $inactive->id])->assertUnprocessable();
        $this->patchJson($this->path(), ['document_type_id' => $active->id])->assertOk()->assertJsonPath('data.document_type_id', $active->id);
    }

    public function test_individual_permission_denials_override_manager_and_administrator_profiles(): void
    {
        foreach ([['documents.update', '', 'patch', ['title' => 'Não permitido']],
            ['documents.move', '/producing-unit', 'patch', ['producing_unit_id' => $this->payable->id]],
            ['documents.archive', '/archive', 'post', []],
        ] as [$key, $suffix, $method, $payload]) {
            $permission = Permission::where('key', $key)->first();
            $this->admin->permissionOverrides()->attach($permission, ['effect' => 'deny']);
            $this->actingAs($this->admin)->json(strtoupper($method), $this->path($suffix), $payload)->assertForbidden();
            $this->admin->permissionOverrides()->detach($permission);
        }
        $this->creator->is_active = false;
        $this->creator->save();
        $this->actingAs($this->creator)->patchJson($this->path(), ['title' => 'Inativo'])->assertForbidden();
    }

    public function test_movement_revalidates_existing_shares_without_revoking_them(): void
    {
        $token = $this->shareToken();
        $this->actingAs($this->manager)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->finance->id])->assertOk();
        $this->actingAs($this->outsider)->getJson('/api/v1/shared/'.$token)->assertForbidden();
        $this->getJson('/api/v1/shares/received')->assertJsonPath('data.0.state', 'unavailable');
        $this->actingAs($this->manager)->patchJson($this->path('/producing-unit'), ['producing_unit_id' => $this->receivable->id])->assertOk();
        $this->actingAs($this->outsider)->getJson('/api/v1/shared/'.$token)->assertOk();
    }

    public function test_version_action_rechecks_archive_state_even_when_passed_a_stale_document(): void
    {
        $stale = $this->document;
        $this->actingAs($this->manager)->postJson($this->path('/archive'))->assertOk();
        try {
            app(AddDocumentVersion::class)->execute($stale,
                UploadedFile::fake()->createWithContent('novo.pdf', '%PDF-1.4 should not persist'), $this->creator);
            $this->fail('Uma nova versão de documento arquivado deveria ser recusada.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('document_versions', 1);
        }
    }
}
