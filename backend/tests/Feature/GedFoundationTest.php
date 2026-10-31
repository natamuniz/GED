<?php

namespace Tests\Feature;

use App\Actions\AddDocumentVersion;
use App\Jobs\VerifyDocumentIntegrity;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GedFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $profile = 'Colaborador', ?OrganizationalUnit $unit = null): User
    {
        $user = User::factory()->create(['profile_id' => Profile::where('name', $profile)->value('id')]);
        if ($unit) {
            $user->units()->attach($unit);
        }

        return $user;
    }

    private function unit(string $code, ?OrganizationalUnit $parent = null): OrganizationalUnit
    {
        return OrganizationalUnit::create(['name' => $code, 'code' => $code, 'parent_id' => $parent?->id]);
    }

    private function document(User $creator, ?OrganizationalUnit $unit): Document
    {
        $document = new Document([
            'title' => 'Contrato', 'document_type_id' => DocumentType::first()->id,
            'production_date' => '2026-10-31', 'producing_unit_id' => $unit?->id,
        ]);
        $document->created_by = $creator->id;
        $document->updated_by = $creator->id;
        $document->save();

        return $document;
    }

    public function test_permissions_allow_overrides_and_denial_takes_precedence(): void
    {
        $user = $this->user();
        $share = Permission::where('key', 'shares.create')->first();
        $archive = Permission::where('key', 'documents.archive')->first();
        $this->assertTrue($user->hasPermission('shares.create'));
        $this->assertFalse($user->hasPermission('documents.archive'));
        $user->permissionOverrides()->attach($share, ['effect' => 'deny']);
        $user->permissionOverrides()->attach($archive, ['effect' => 'allow']);
        $this->assertFalse($user->hasPermission('shares.create'));
        $this->assertTrue($user->hasPermission('documents.archive'));
        $user->profile_id = null;
        $user->save();
        $this->assertTrue($user->fresh()->hasPermission('documents.archive'));
        $user->is_active = false;
        $user->save();
        $this->assertFalse($user->hasPermission('documents.archive'));
    }

    public function test_tests_use_the_dedicated_postgresql_database(): void
    {
        $database = DB::selectOne('SELECT current_database() AS name');
        $this->assertSame('ged_test', $database->name);
    }

    public function test_hierarchy_access_descends_but_does_not_ascend_or_cross_siblings(): void
    {
        $finance = $this->unit('FIN');
        $payable = $this->unit('CP', $finance);
        $receivable = $this->unit('CR', $finance);
        $manager = $this->user('Gestor', $finance);
        $employee = $this->user('Colaborador', $payable);
        $own = $this->document($manager, $payable);
        $sibling = $this->document($manager, $receivable);
        $parent = $this->document($manager, $finance);
        $general = $this->document($manager, null);
        $this->assertTrue(Gate::forUser($manager)->allows('view', $sibling));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $own));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $general));
        $this->assertFalse(Gate::forUser($employee)->allows('view', $sibling));
        $this->assertFalse(Gate::forUser($employee)->allows('view', $parent));
        $this->actingAs($employee)->getJson('/api/v1/documents/'.$sibling->id)->assertForbidden();
    }

    public function test_hierarchy_cannot_contain_cycles(): void
    {
        $parent = $this->unit('FIN');
        $child = $this->unit('CP', $parent);
        $parent->parent_id = $child->id;
        $this->expectException(ValidationException::class);
        $parent->save();
    }

    public function test_upload_creates_logical_document_version_hash_and_audit_without_exposing_storage_path(): void
    {
        Storage::fake('local');
        $unit = $this->unit('CP');
        $user = $this->user('Colaborador', $unit);
        $content = "%PDF-1.4\nGED test document\n%%EOF";
        $response = $this->actingAs($user)->post('/api/v1/documents', [
            'title' => 'Contrato teste', 'document_type_id' => DocumentType::first()->id,
            'producing_unit_id' => $unit->id, 'production_date' => '2026-10-31',
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', $content),
        ], ['Accept' => 'application/json'])->assertCreated();
        $response->assertJsonPath('data.latest_version.sha256', hash('sha256', $content));
        $this->assertArrayNotHasKey('storage_path', $response->json('data.latest_version'));
        $document = Document::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($document->latestVersion->storage_path);
        $this->assertDatabaseHas('audit_events', ['document_id' => $document->id, 'action' => 'document.created']);

        $this->actingAs($user)->post('/api/v1/documents/'.$document->id.'/versions', [
            'file' => UploadedFile::fake()->createWithContent('novo.pdf', $content.' updated'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.version_number', 2);
        $this->assertEquals(2, $document->versions()->count());
    }

    public function test_reader_cannot_upload_and_collaborator_cannot_create_general_document(): void
    {
        Storage::fake('local');
        $unit = $this->unit('CP');
        $data = [
            'title' => 'Teste', 'document_type_id' => DocumentType::first()->id,
            'production_date' => '2026-10-31', 'producing_unit_id' => $unit->id,
            'file' => UploadedFile::fake()->createWithContent('arquivo.pdf', '%PDF-1.4 test'),
        ];
        $this->actingAs($this->user('Leitor', $unit))->post('/api/v1/documents', $data, ['Accept' => 'application/json'])->assertForbidden();
        unset($data['producing_unit_id']);
        $this->actingAs($this->user('Colaborador', $unit))->post('/api/v1/documents', $data, ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_dashboard_counts_all_versions_and_deduplicates_overlapping_unit_access(): void
    {
        $parent = $this->unit('FIN');
        $child = $this->unit('CP', $parent);
        $other = $this->unit('RH');
        $user = $this->user('Gestor', $parent);
        $user->units()->attach($child);
        $visible = $this->document($user, $child);
        $general = $this->document($user, null);
        $hidden = $this->document($user, $other);
        foreach ([[$visible, 1, 200], [$visible, 2, 300], [$general, 1, 100], [$hidden, 1, 999]] as [$document, $number, $size]) {
            $document->versions()->create([
                'version_number' => $number, 'storage_path' => $document->id.'/'.$number,
                'original_filename' => 'file.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf',
                'size_bytes' => $size, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id,
            ]);
        }
        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.total_documents', 2)->assertJsonPath('data.storage_bytes', 600);
    }

    public function test_integrity_distinguishes_match_mismatch_and_read_error(): void
    {
        Storage::fake('local');
        $unit = $this->unit('FIN');
        $user = $this->user('Colaborador', $unit);
        $document = $this->document($user, $unit);
        $version = app(AddDocumentVersion::class)->execute(
            $document, UploadedFile::fake()->createWithContent('test.pdf', '%PDF-1.4 original'), $user,
        );
        $job = new VerifyDocumentIntegrity($version->id, $user->id);
        $job->handle();
        $this->assertDatabaseHas('integrity_checks', ['result' => 'match', 'document_version_id' => $version->id]);
        Storage::disk('local')->put($version->storage_path, 'changed');
        $job->handle();
        $this->assertDatabaseHas('integrity_checks', ['result' => 'mismatch', 'document_version_id' => $version->id]);
        Storage::disk('local')->delete($version->storage_path);
        $job->handle();
        $this->assertDatabaseHas('integrity_checks', ['result' => 'error', 'document_version_id' => $version->id]);
    }

    public function test_seeders_preserve_customized_profiles_and_do_not_create_default_accounts(): void
    {
        $profile = Profile::where('name', 'Colaborador')->first();
        $profile->permissions()->detach();
        $this->seed(DatabaseSeeder::class);
        $this->assertEquals(0, $profile->permissions()->count());
        $this->assertDatabaseCount('profiles', 4);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_api_requires_authentication_and_blocks_inactive_users(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $user = $this->user('Administrador');
        $user->is_active = false;
        $user->save();
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_login_and_logout_use_session_and_reject_inactive_accounts(): void
    {
        $user = $this->user('Administrador');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $user->is_active = false;
        $user->save();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
    }
}
