<?php

namespace Tests\Feature;

use App\Actions\CreateDocument;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use App\Services\AuditAccess;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $leafManager;

    private User $outsideManager;

    private User $creator;

    private User $reader;

    private OrganizationalUnit $finance;

    private OrganizationalUnit $receivable;

    private OrganizationalUnit $payable;

    private OrganizationalUnit $hr;

    private Document $receiptDocument;

    private Document $payableDocument;

    private Document $financeDocument;

    private Document $hrDocument;

    private Document $generalDocument;

    private AuditEvent $administrativeEvent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->finance = OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
        $this->receivable = OrganizationalUnit::create(['name' => 'Receber', 'code' => 'CR', 'parent_id' => $this->finance->id]);
        $this->payable = OrganizationalUnit::create(['name' => 'Pagar', 'code' => 'CP', 'parent_id' => $this->finance->id]);
        $this->hr = OrganizationalUnit::create(['name' => 'RH', 'code' => 'RH']);
        $this->admin = $this->user('Administrador');
        $this->manager = $this->user('Gestor', $this->finance);
        $this->leafManager = $this->user('Gestor', $this->payable);
        $this->outsideManager = $this->user('Gestor', $this->hr);
        $this->creator = $this->user('Colaborador', $this->receivable);
        $this->reader = $this->user('Leitor', $this->finance);
        $this->receiptDocument = $this->document($this->receivable);
        $this->payableDocument = $this->document($this->payable);
        $this->financeDocument = $this->document($this->finance);
        $this->hrDocument = $this->document($this->hr);
        $this->generalDocument = $this->document(null);
        $this->administrativeEvent = AuditEvent::create([
            'actor_id' => $this->admin->id, 'action' => 'user.updated',
            'subject_type' => 'user', 'subject_id' => $this->reader->id,
            'changes' => ['before' => ['name' => 'Anterior'], 'after' => ['name' => 'Atual']],
        ]);
    }

    private function user(string $profile, ?OrganizationalUnit $unit = null): User
    {
        $user = User::factory()->create(['profile_id' => Profile::where('name', $profile)->value('id')]);
        if ($unit) {
            $user->units()->attach($unit);
        }

        return $user;
    }

    private function document(?OrganizationalUnit $unit): Document
    {
        return app(CreateDocument::class)->execute([
            'title' => 'Documento '.($unit?->code ?? 'Geral'), 'document_type_id' => DocumentType::first()->id,
            'production_date' => '2026-10-31', 'producing_unit_id' => $unit?->id,
        ], UploadedFile::fake()->createWithContent('documento.pdf', '%PDF-1.4 audit fixture'), $this->admin);
    }

    private function event(Document $document, array $attributes = []): AuditEvent
    {
        return AuditEvent::create([
            'actor_id' => $this->creator->id, 'action' => 'document.reviewed',
            'subject_type' => 'document', 'subject_id' => $document->id, 'document_id' => $document->id,
            ...$attributes,
        ]);
    }

    private function grant(User $user, string $key, string $effect = 'allow'): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', $key)->value('id') => ['effect' => $effect],
        ]);
    }

    public function test_authentication_and_audit_permission_are_required_for_every_read_endpoint(): void
    {
        $event = AuditEvent::where('document_id', $this->receiptDocument->id)->first();
        $paths = ['/api/v1/audit-events', '/api/v1/audit-events/'.$event->id, '/api/v1/documents/'.$this->receiptDocument->id.'/audit-events'];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach ([$this->reader, $this->creator] as $user) {
            $this->actingAs($user);
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
        $this->admin->is_active = false;
        $this->admin->save();
        $this->actingAs($this->admin)->getJson('/api/v1/audit-events')->assertForbidden();
    }

    public function test_managers_see_only_current_units_descendants_and_general_documents(): void
    {
        $response = $this->actingAs($this->manager)->getJson('/api/v1/audit-events')->assertOk();
        $ids = collect($response->json('data'))->pluck('document_id')->unique()->sort()->values()->all();
        $expected = [$this->receiptDocument->id, $this->payableDocument->id, $this->financeDocument->id, $this->generalDocument->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertFalse(collect($response->json('data'))->contains('id', $this->administrativeEvent->id));
        $leaf = $this->actingAs($this->leafManager)->getJson('/api/v1/audit-events')->assertOk();
        $this->assertEqualsCanonicalizing([$this->payableDocument->id, $this->generalDocument->id], collect($leaf->json('data'))->pluck('document_id')->unique()->values()->all());
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertForbidden();
        $this->getJson('/api/v1/documents/'.$this->financeDocument->id.'/audit-events')->assertForbidden();
    }

    public function test_administrator_sees_global_events_and_automatic_events_without_an_actor(): void
    {
        $automatic = $this->event($this->receiptDocument, ['actor_id' => null, 'action' => 'document.integrity-checked']);
        $response = $this->actingAs($this->admin)->getJson('/api/v1/audit-events')->assertOk();
        $this->assertTrue(collect($response->json('data'))->contains('id', $this->administrativeEvent->id));
        $this->getJson('/api/v1/audit-events/'.$this->administrativeEvent->id)->assertOk()->assertJsonPath('data.document', null);
        $this->getJson('/api/v1/audit-events/'.$automatic->id)->assertOk()->assertJsonPath('data.actor', null);
    }

    public function test_global_audit_permission_does_not_bypass_unit_scope_or_document_view_permission(): void
    {
        $this->grant($this->reader, 'audit.view-global');
        $this->actingAs($this->reader)->getJson('/api/v1/audit-events?document_id='.$this->hrDocument->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audit-events/'.$this->administrativeEvent->id)->assertNotFound();
        $this->grant($this->reader, 'access.global');
        $this->getJson('/api/v1/audit-events/'.$this->administrativeEvent->id)->assertOk();
        $this->getJson('/api/v1/documents/'.$this->hrDocument->id.'/audit-events')->assertOk();
        $this->grant($this->reader, 'documents.view', 'deny');
        $this->getJson('/api/v1/audit-events?document_id='.$this->hrDocument->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents/'.$this->hrDocument->id.'/audit-events')->assertForbidden();
    }

    public function test_individual_denials_and_global_access_alone_do_not_grant_audit_access(): void
    {
        $this->grant($this->manager, 'audit.view', 'deny');
        $this->actingAs($this->manager)->getJson('/api/v1/audit-events')->assertForbidden();
        $this->grant($this->reader, 'access.global');
        $this->actingAs($this->reader)->getJson('/api/v1/audit-events')->assertForbidden();
        $this->grant($this->admin, 'audit.view-global', 'deny');
        $this->actingAs($this->admin)->getJson('/api/v1/audit-events/'.$this->administrativeEvent->id)->assertNotFound();
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertOk();
        $this->grant($this->admin, 'audit.view', 'deny');
        $this->getJson('/api/v1/audit-events')->assertForbidden();
    }

    public function test_document_and_event_detail_enforce_the_same_scope_and_include_archived_documents(): void
    {
        $event = AuditEvent::where('document_id', $this->receiptDocument->id)->first();
        $this->actingAs($this->manager)->getJson('/api/v1/audit-events/'.$event->id)->assertOk();
        $this->postJson('/api/v1/documents/'.$this->receiptDocument->id.'/archive')->assertOk();
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($this->outsideManager)->getJson('/api/v1/audit-events/'.$event->id)->assertNotFound();
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/v1/audit-events/999999')->assertNotFound();
        $this->getJson('/api/v1/documents/999999/audit-events')->assertNotFound();
    }

    public function test_filters_are_intersections_with_authorized_scope_and_utc_date_bounds_are_inclusive(): void
    {
        $first = $this->event($this->receiptDocument, ['created_at' => '2026-11-02 00:00:00+00']);
        $last = $this->event($this->receiptDocument, ['created_at' => '2026-11-02 23:59:59+00']);
        $this->event($this->receiptDocument, ['created_at' => '2026-11-03 00:00:00+00']);
        $this->event($this->hrDocument, ['created_at' => '2026-11-02 12:00:00+00']);
        $query = http_build_query([
            'action' => 'document.reviewed', 'actor_id' => $this->creator->id,
            'date_from' => '2026-11-02', 'date_to' => '2026-11-02',
            'subject_type' => 'document', 'subject_id' => $this->receiptDocument->id,
        ]);
        $response = $this->actingAs($this->manager)->getJson('/api/v1/audit-events?'.$query)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$last->id, $first->id], collect($response->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/audit-events?document_id='.$this->hrDocument->id.'&actor_id='.$this->creator->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events?document_id='.$this->payableDocument->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audit-events?producing_unit_id='.$this->payable->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/audit-events?producing_unit_id='.$this->hr->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audit-events?actor_id=999999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pagination_is_stable_and_preserves_filters(): void
    {
        $events = [];
        for ($index = 0; $index < 3; $index++) {
            $events[] = $this->event($this->receiptDocument);
        }
        $response = $this->actingAs($this->manager)->getJson('/api/v1/audit-events?action=document.reviewed&per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->assertSame([$events[2]->id, $events[1]->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertStringContainsString('action=document.reviewed', $response->json('links.next'));
        $this->getJson('/api/v1/audit-events?action=document.reviewed&per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $events[0]->id);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_responses_omit_credentials_tokens_and_storage_paths_recursively_without_mutating_history(): void
    {
        $event = $this->event($this->receiptDocument, [
            'changes' => ['before' => ['title' => 'Anterior', 'password' => 'super-secret', 'nested' => [['token_hash' => 'hidden-hash', 'storage_path' => '/private/file']]],
                'after' => ['title' => 'Atual', 'password_changed' => true]],
            'metadata' => ['Authorization' => 'super-secret', 'nested' => ['access_token' => 'super-secret'], 'result' => 'match'],
        ]);
        $response = $this->actingAs($this->manager)->getJson('/api/v1/audit-events/'.$event->id)->assertOk()
            ->assertJsonPath('data.changes.before.title', 'Anterior')->assertJsonPath('data.changes.after.password_changed', true)
            ->assertJsonMissingPath('data.changes.before.password')->assertJsonMissingPath('data.changes.before.nested.0.token_hash')
            ->assertJsonMissingPath('data.changes.before.nested.0.storage_path')->assertJsonMissingPath('data.metadata.Authorization')
            ->assertJsonMissingPath('data.metadata.nested.access_token')->assertJsonMissingPath('data.actor.email');
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertSame('super-secret', $event->fresh()->changes['before']['password']);
    }

    public function test_current_unit_determines_access_to_all_document_history_after_a_move(): void
    {
        $event = AuditEvent::where('document_id', $this->receiptDocument->id)->first();
        $this->actingAs($this->manager)->getJson('/api/v1/audit-events/'.$event->id)->assertOk();
        $this->actingAs($this->admin)->patchJson('/api/v1/documents/'.$this->receiptDocument->id.'/producing-unit', ['producing_unit_id' => $this->hr->id])->assertOk();
        $this->actingAs($this->manager)->getJson('/api/v1/audit-events/'.$event->id)->assertNotFound();
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertForbidden();
        $this->getJson('/api/v1/audit-events?document_id='.$this->receiptDocument->id)->assertJsonCount(0, 'data');
        $this->actingAs($this->outsideManager)->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_a_temporary_share_never_grants_access_to_internal_audit_events(): void
    {
        $share = $this->actingAs($this->admin)->postJson('/api/v1/versions/'.$this->receiptDocument->latestVersion->id.'/shares', [
            'recipient_ids' => [$this->outsideManager->id], 'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated();
        $this->actingAs($this->outsideManager)->getJson('/api/v1/shared/'.$share->json('token'))->assertOk();
        $this->getJson('/api/v1/documents/'.$this->receiptDocument->id.'/audit-events')->assertForbidden();
        $this->getJson('/api/v1/audit-events?document_share_id='.$share->json('data.id'))->assertOk()->assertJsonCount(0, 'data');
        $event = AuditEvent::where('document_share_id', $share->json('data.id'))->where('action', 'share.created')->sole();
        $this->getJson('/api/v1/audit-events/'.$event->id)->assertNotFound();
        $this->actingAs($this->manager)->getJson('/api/v1/audit-events?document_share_id='.$share->json('data.id'))
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_reads_do_not_write_audit_events_and_no_write_routes_are_exposed(): void
    {
        $event = AuditEvent::where('document_id', $this->receiptDocument->id)->first();
        $before = AuditEvent::count();
        $this->actingAs($this->manager)->getJson('/api/v1/audit-events')->assertOk();
        $this->getJson('/api/v1/audit-events/'.$event->id)->assertOk();
        $this->postJson('/api/v1/audit-events', [])->assertMethodNotAllowed();
        $this->patchJson('/api/v1/audit-events/'.$event->id, ['action' => 'anything'])->assertMethodNotAllowed();
        $this->deleteJson('/api/v1/audit-events/'.$event->id)->assertMethodNotAllowed();
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_filter_validation_rejects_invalid_ranges_and_limits(): void
    {
        $this->actingAs($this->manager);
        foreach (['per_page=101', 'page=0', 'actor_id=-1', 'subject_id=0', 'date_from=2026-02-30',
            'date_from=2026-11-03&date_to=2026-11-01', 'date_to=not-a-date', 'date_from=0000-01-01',
        ] as $query) {
            $this->getJson('/api/v1/audit-events?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/audit-events?date_to=2026-11-01')->assertOk();
        $this->getJson('/api/v1/audit-events/999999999999999999999999')->assertUnprocessable();
    }

    public function test_scope_reads_hold_a_shared_lock_against_administrative_changes(): void
    {
        config(['database.connections.audit_probe' => config('database.connections.pgsql')]);
        app(AuditAccess::class)->read($this->manager, function () {
            $probe = DB::connection('audit_probe');
            $probe->beginTransaction();
            try {
                $locked = $probe->selectOne('SELECT pg_try_advisory_xact_lock(71932, 1) AS acquired');
                $this->assertFalse($locked->acquired);
            } finally {
                $probe->rollBack();
                DB::purge('audit_probe');
            }
        });
    }
}
