<?php

namespace Tests\Feature;

use App\Actions\AddDocumentVersion;
use App\Actions\CreateDocument;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DocumentSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $recipient;

    private User $other;

    private User $manager;

    private OrganizationalUnit $finance;

    private OrganizationalUnit $receivable;

    private Document $document;

    private DocumentVersion $version;

    private string $content = "%PDF-1.4\nOriginal shared version\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->finance = OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
        $this->receivable = OrganizationalUnit::create(['name' => 'Contas a receber', 'code' => 'CR', 'parent_id' => $this->finance->id]);
        $payable = OrganizationalUnit::create(['name' => 'Contas a pagar', 'code' => 'CP', 'parent_id' => $this->finance->id]);
        $this->creator = $this->user('Colaborador', $this->receivable);
        $this->recipient = $this->user('Leitor', $payable);
        $this->other = $this->user('Colaborador', $payable);
        $this->manager = $this->user('Gestor', $this->finance);
        $this->document = app(CreateDocument::class)->execute([
            'title' => 'Relatório de recebimentos', 'document_type_id' => DocumentType::first()->id,
            'producing_unit_id' => $this->receivable->id, 'production_date' => '2026-10-31',
        ], UploadedFile::fake()->createWithContent('relatorio.pdf', $this->content), $this->creator);
        $this->version = $this->document->latestVersion;
    }

    private function user(string $profile, ?OrganizationalUnit $unit = null): User
    {
        $user = User::factory()->create(['profile_id' => Profile::where('name', $profile)->value('id')]);
        if ($unit) {
            $user->units()->attach($unit);
        }

        return $user;
    }

    private function createShare(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->creator)->postJson('/api/v1/versions/'.$this->version->id.'/shares', [
            'recipient_ids' => [$this->recipient->id], 'expires_at' => now()->addHours(2)->toIso8601String(),
            ...$overrides,
        ]);
    }

    public function test_creation_binds_the_selected_version_and_stores_only_the_token_hash(): void
    {
        $response = $this->createShare()->assertCreated()->assertJsonPath('data.version.id', $this->version->id);
        $token = $response->json('token');
        $share = DocumentShare::findOrFail($response->json('data.id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $share->token_hash);
        $this->assertNotSame($token, $share->token_hash);
        $this->assertEquals([$this->recipient->id], $share->recipients()->pluck('users.id')->all());
        $this->assertStringContainsString('/api/v1/shared/'.$token, $response->json('access_url'));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));
        $this->assertArrayNotHasKey('storage_path', $response->json('data.version'));
        $event = AuditEvent::where('action', 'share.created')->sole();
        $this->assertEquals($share->id, $event->document_share_id);
        $this->assertStringNotContainsString($token, json_encode($event->toArray()));
        $this->getJson('/api/v1/versions/'.$this->version->id.'/shares')->assertOk()->assertJsonMissing(['token' => $token]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_recipient_can_consult_and_download_only_the_shared_version_across_units(): void
    {
        $response = $this->createShare(['recipient_ids' => [$this->recipient->id, $this->other->id]])->assertCreated();
        $id = $response->json('data.id');
        $token = $response->json('token');
        app(AddDocumentVersion::class)->execute($this->document,
            UploadedFile::fake()->createWithContent('novo.pdf', '%PDF-1.4 updated'), $this->creator);
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertOk()
            ->assertJsonPath('data.version.id', $this->version->id)->assertJsonPath('data.version.version_number', 1)
            ->assertJsonMissingPath('data.recipients')->assertJsonMissingPath('data.document.versions')
            ->assertJsonMissingPath('data.version.storage_path');
        $download = $this->get('/api/v1/shared/'.$token.'/download')->assertOk()->assertDownload('relatorio.pdf');
        $this->assertSame($this->content, $download->streamedContent());
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->getJson('/api/v1/shares/'.$id)->assertOk();
        $this->get('/api/v1/shares/'.$id.'/download')->assertOk();
        $this->getJson('/api/v1/documents/'.$this->document->id)->assertForbidden();
        $this->get('/api/v1/versions/'.$this->version->id.'/download', ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/api/v1/versions/'.$this->version->id.'/shares', [
            'recipient_ids' => [$this->other->id], 'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertForbidden();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.total_documents', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'share.viewed', 'actor_id' => $this->recipient->id, 'document_share_id' => $id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'share.downloaded', 'actor_id' => $this->recipient->id, 'document_share_id' => $id]);
    }

    public function test_forwarding_a_link_or_guessing_an_id_does_not_authorize_other_users(): void
    {
        $response = $this->createShare()->assertCreated();
        $token = $response->json('token');
        $id = $response->json('data.id');
        $this->actingAs($this->other)->getJson('/api/v1/shared/'.$token)->assertForbidden();
        $this->getJson('/api/v1/shared/'.$token.'/download')->assertForbidden();
        $this->getJson('/api/v1/shares/'.$id)->assertForbidden();
        $this->getJson('/api/v1/shares/'.$id.'/download')->assertForbidden();
        $this->getJson('/api/v1/shared/'.str_repeat('a', 64))->assertNotFound();
        $this->assertDatabaseMissing('audit_events', ['document_share_id' => $id, 'actor_id' => $this->other->id]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/shared/'.$token)->assertUnauthorized();
        $this->getJson('/api/v1/shares/received')->assertUnauthorized();
    }

    public function test_validity_uses_an_inclusive_start_and_exclusive_expiration(): void
    {
        $response = $this->createShare(['starts_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $token = $response->json('token');
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertForbidden();
        $this->getJson('/api/v1/shares/received')->assertOk()->assertJsonPath('data.0.state', 'scheduled')->assertJsonMissingPath('data.0.document');
        $this->travelTo(Carbon::parse('2026-11-01T13:00:00Z'));
        $this->getJson('/api/v1/shared/'.$token)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-01T14:00:00Z'));
        $this->getJson('/api/v1/shared/'.$token)->assertGone();
        $this->getJson('/api/v1/shared/'.$token.'/download')->assertGone();
        $this->getJson('/api/v1/shares/received')->assertOk()->assertJsonPath('data.0.state', 'expired')
            ->assertJsonMissingPath('data.0.document')->assertJsonMissingPath('data.0.version')->assertJsonMissingPath('data.0.download_url');
    }

    public function test_creator_can_revoke_early_and_repeated_revocation_is_idempotent(): void
    {
        $response = $this->createShare()->assertCreated();
        $id = $response->json('data.id');
        $token = $response->json('token');
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertOk();
        $this->actingAs($this->creator)->postJson('/api/v1/shares/'.$id.'/revoke')->assertOk()->assertJsonPath('data.revoked_by', $this->creator->id);
        $this->postJson('/api/v1/shares/'.$id.'/revoke')->assertOk();
        $this->assertEquals(1, AuditEvent::where('action', 'share.revoked')->where('document_share_id', $id)->count());
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertGone();
        $this->getJson('/api/v1/shares/'.$id.'/download')->assertGone();
        $this->getJson('/api/v1/shares/received')->assertOk()->assertJsonPath('data.0.state', 'revoked')->assertJsonMissingPath('data.0.document');
    }

    public function test_revocation_requires_ownership_or_management_permission_within_scope(): void
    {
        $id = $this->createShare()->assertCreated()->json('data.id');
        $this->actingAs($this->recipient)->postJson('/api/v1/shares/'.$id.'/revoke')->assertForbidden();
        $this->actingAs($this->other)->postJson('/api/v1/shares/'.$id.'/revoke')->assertForbidden();
        $outsideManager = $this->user('Gestor');
        $this->actingAs($outsideManager)->postJson('/api/v1/shares/'.$id.'/revoke')->assertForbidden();
        $this->actingAs($this->manager)->postJson('/api/v1/shares/'.$id.'/revoke')->assertOk();
        $this->assertDatabaseHas('document_shares', ['id' => $id, 'revoked_by' => $this->manager->id]);
    }

    public function test_creator_can_revoke_own_link_after_losing_unit_access(): void
    {
        $id = $this->createShare()->assertCreated()->json('data.id');
        $this->creator->units()->detach();
        $this->actingAs($this->creator)->postJson('/api/v1/shares/'.$id.'/revoke')->assertOk();
    }

    public function test_each_access_rechecks_creator_permissions_unit_membership_and_account_status(): void
    {
        $token = $this->createShare()->assertCreated()->json('token');
        foreach (['shares.create', 'documents.view', 'documents.download'] as $key) {
            $permission = Permission::where('key', $key)->first();
            $this->creator->permissionOverrides()->attach($permission, ['effect' => 'deny']);
            $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertForbidden();
            $this->getJson('/api/v1/shared/'.$token.'/download')->assertForbidden();
            $this->getJson('/api/v1/shares/received')->assertOk()->assertJsonPath('data.0.state', 'unavailable')->assertJsonMissingPath('data.0.document');
            $this->creator->permissionOverrides()->detach($permission);
        }
        $this->creator->units()->detach();
        $this->getJson('/api/v1/shared/'.$token)->assertForbidden();
        $this->creator->units()->attach($this->receivable);
        $this->creator->is_active = false;
        $this->creator->save();
        $this->getJson('/api/v1/shared/'.$token)->assertForbidden();
        $this->creator->is_active = true;
        $this->creator->save();
        $this->getJson('/api/v1/shared/'.$token)->assertOk();
    }

    public function test_inactive_recipient_cannot_access_even_with_a_valid_link(): void
    {
        $token = $this->createShare()->assertCreated()->json('token');
        $this->recipient->is_active = false;
        $this->recipient->save();
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertForbidden();
        $this->getJson('/api/v1/shared/'.$token.'/download')->assertForbidden();
    }

    public function test_creation_validates_recipients_and_time_period_without_persisting_failed_shares(): void
    {
        $inactive = $this->user('Leitor');
        $inactive->is_active = false;
        $inactive->save();
        foreach ([
            ['recipient_ids' => []], ['recipient_ids' => [$this->recipient->id, $this->recipient->id]],
            ['recipient_ids' => [999999]], ['recipient_ids' => [$inactive->id]],
            ['expires_at' => now()->toIso8601String()],
            ['starts_at' => now()->addHours(3)->toIso8601String()],
            ['expires_at' => '2026-11-01 15:00:00'],
            ['starts_at' => '2026-11-01T13:00:00.500Z'],
            ['starts_at' => 'invalid'],
        ] as $invalid) {
            $this->createShare($invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('document_shares', 0);
        $this->assertDatabaseCount('document_share_recipients', 0);
        $this->assertDatabaseMissing('audit_events', ['action' => 'share.created']);
        $this->createShare(['starts_at' => '2026-11-01T09:00:00-03:00', 'expires_at' => '2026-11-01T11:00:00-03:00'])
            ->assertCreated()->assertJsonPath('data.state', 'active');
    }

    public function test_new_shares_require_permission_and_scope_and_archival_preserves_existing_links_only(): void
    {
        $token = $this->createShare()->assertCreated()->json('token');
        $reader = $this->user('Leitor', $this->receivable);
        foreach ([$reader, $this->other] as $user) {
            $this->actingAs($user)->postJson('/api/v1/versions/'.$this->version->id.'/shares', [
                'recipient_ids' => [$this->recipient->id], 'expires_at' => now()->addHour()->toIso8601String(),
            ])->assertForbidden();
        }
        $this->document->archived_at = now();
        $this->document->archived_by = $this->creator->id;
        $this->document->save();
        $this->createShare()->assertForbidden();
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$token)->assertOk();
    }

    public function test_received_and_management_lists_are_isolated_paginated_and_do_not_reveal_tokens(): void
    {
        $first = $this->createShare()->assertCreated();
        $this->createShare(['recipient_ids' => [$this->other->id]])->assertCreated();
        $this->actingAs($this->recipient)->getJson('/api/v1/shares/received?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first->json('data.id'))
            ->assertJsonMissingPath('data.0.recipients')->assertJsonMissingPath('data.0.token');
        $this->getJson('/api/v1/shares/received?per_page=101')->assertUnprocessable();
        $this->actingAs($this->creator)->getJson('/api/v1/versions/'.$this->version->id.'/shares')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.token_hash');
        $colleague = $this->user('Colaborador', $this->receivable);
        $this->actingAs($colleague)->getJson('/api/v1/versions/'.$this->version->id.'/shares')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->manager)->getJson('/api/v1/versions/'.$this->version->id.'/shares')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_recipient_search_is_limited_to_active_directory_fields_and_sharing_permission(): void
    {
        $inactive = $this->user('Leitor');
        $inactive->is_active = false;
        $inactive->save();
        $this->actingAs($this->creator)->getJson('/api/v1/share-recipients?search='.urlencode($this->recipient->email))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->recipient->id)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.profile_id');
        $this->getJson('/api/v1/share-recipients?search='.urlencode($inactive->email))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->recipient)->getJson('/api/v1/share-recipients')->assertForbidden();
    }

    public function test_links_for_the_same_version_are_independent_and_missing_files_do_not_audit_successful_downloads(): void
    {
        $first = $this->createShare()->assertCreated();
        $second = $this->createShare()->assertCreated();
        $this->assertNotSame($first->json('token'), $second->json('token'));
        $this->postJson('/api/v1/shares/'.$first->json('data.id').'/revoke')->assertOk();
        $this->actingAs($this->recipient)->getJson('/api/v1/shared/'.$second->json('token'))->assertOk();
        Storage::disk('local')->delete($this->version->storage_path);
        $this->getJson('/api/v1/shared/'.$second->json('token').'/download')->assertNotFound();
        $this->assertDatabaseMissing('audit_events', ['document_share_id' => $second->json('data.id'), 'action' => 'share.downloaded']);
    }
}
