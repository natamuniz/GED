<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentProductionFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OrganizationalUnit $unit;

    private OrganizationalUnit $outsideUnit;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
        $this->seed(DatabaseSeeder::class);
        $this->unit = OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
        $this->outsideUnit = OrganizationalUnit::create(['name' => 'RH', 'code' => 'RH']);
        $this->typeId = DocumentType::first()->id;
        $this->user = User::factory()->create(['profile_id' => Profile::where('name', 'Gestor')->value('id')]);
        $this->user->units()->attach($this->unit);
        $this->actingAs($this->user);
    }

    private function document(string $date, array $attributes = [], bool $archived = false): Document
    {
        $document = new Document([
            'title' => 'Contrato', 'document_type_id' => $this->typeId,
            'producing_unit_id' => $this->unit->id, 'production_date' => $date,
            ...$attributes,
        ]);
        $document->created_by = $this->user->id;
        $document->updated_by = $this->user->id;
        if ($archived) {
            $document->archived_at = now();
            $document->archived_by = $this->user->id;
        }
        $document->save();

        return $document;
    }

    public function test_range_includes_both_boundaries_and_uses_production_date_instead_of_upload_date(): void
    {
        $this->document('2025-01-09');
        $start = $this->document('2025-01-10');
        $end = $this->document('2025-01-20');
        $this->document('2025-01-21');
        $response = $this->getJson('/api/v1/documents?production_date_from=2025-01-10&production_date_to=2025-01-20')
            ->assertOk()->assertJsonPath('total', 2);
        $this->assertSame([$end->id, $start->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotSame('2025', $start->created_at->format('Y'));
    }

    public function test_each_bound_can_be_used_alone_and_empty_bounds_are_ignored(): void
    {
        $before = $this->document('2025-01-09');
        $boundary = $this->document('2025-01-10');
        $after = $this->document('2025-01-11');
        $this->getJson('/api/v1/documents?production_date_from=2025-01-10')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $after->id);
        $this->getJson('/api/v1/documents?production_date_to=2025-01-10')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.1.id', $before->id);
        $this->getJson('/api/v1/documents?production_date_from=2025-01-10&production_date_to=2025-01-10')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $boundary->id);
        $this->getJson('/api/v1/documents?production_date_from=&production_date_to=')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_period_combines_with_search_type_unit_and_archival_filters(): void
    {
        $expected = $this->document('2025-01-15', archived: true);
        $active = $this->document('2025-01-15');
        $this->document('2024-12-31', archived: true);
        $this->document('2025-01-15', ['title' => 'Relatório'], archived: true);
        $otherType = DocumentType::where('id', '<>', $this->typeId)->first();
        $this->document('2025-01-15', ['document_type_id' => $otherType->id], archived: true);
        $this->document('2025-01-15', ['producing_unit_id' => null], archived: true);
        $query = http_build_query([
            'production_date_from' => '2025-01-01', 'production_date_to' => '2025-01-31',
            'search' => 'Contrato', 'document_type_id' => $this->typeId,
            'producing_unit_id' => $this->unit->id, 'archived' => 1,
        ]);
        $this->getJson('/api/v1/documents?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
        $this->getJson('/api/v1/documents?'.str_replace('archived=1', 'archived=0', $query))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
    }

    public function test_period_filters_never_expand_unit_scope_or_override_document_permission(): void
    {
        $own = $this->document('2025-01-15');
        $general = $this->document('2025-01-15', ['producing_unit_id' => null]);
        $this->document('2025-01-15', ['producing_unit_id' => $this->outsideUnit->id]);
        $query = 'production_date_from=2025-01-01&production_date_to=2025-01-31';
        $response = $this->getJson('/api/v1/documents?'.$query)->assertOk()->assertJsonPath('total', 2);
        $this->assertSame([$general->id, $own->id], collect($response->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/documents?'.$query.'&producing_unit_id='.$this->outsideUnit->id)->assertOk()->assertJsonCount(0, 'data');
        $permission = Permission::where('key', 'documents.view')->first();
        $this->user->permissionOverrides()->attach($permission, ['effect' => 'deny']);
        $this->getJson('/api/v1/documents?'.$query)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_dates_and_reversed_intervals_return_validation_errors(): void
    {
        foreach ([
            'production_date_from=2025-02-30',
            'production_date_to=invalid',
            'production_date_from=0000-01-01',
            'production_date_from=2025-01-20&production_date_to=2025-01-10',
            'production_date_from=2025-01-10T00:00:00Z',
            'production_date_from[]=2025-01-10',
        ] as $query) {
            $this->getJson('/api/v1/documents?'.$query)->assertUnprocessable();
        }
    }

    public function test_pagination_preserves_the_production_period(): void
    {
        $first = null;
        for ($index = 0; $index < 21; $index++) {
            $document = $this->document('2025-01-15');
            $first ??= $document;
        }
        $this->document('2025-02-01');
        $query = 'production_date_from=2025-01-01&production_date_to=2025-01-31';
        $response = $this->getJson('/api/v1/documents?'.$query)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 21);
        $this->assertStringContainsString('production_date_from=2025-01-01', $response->json('next_page_url'));
        $this->assertStringContainsString('production_date_to=2025-01-31', $response->json('next_page_url'));
        $this->getJson('/api/v1/documents?'.$query.'&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }
}
