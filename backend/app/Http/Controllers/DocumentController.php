<?php

namespace App\Http\Controllers;

use App\Actions\AddDocumentVersion;
use App\Actions\CreateDocument;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Rules\AllowedDocumentFile;
use App\Services\DocumentAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentAccess $access): LengthAwarePaginator
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'document_type_id' => ['nullable', 'integer'],
            'producing_unit_id' => ['nullable', 'integer'],
            'archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'production_date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'production_date_to' => ['nullable', 'date', 'date_format:Y-m-d', ...($request->filled('production_date_from') ? ['after_or_equal:production_date_from'] : [])],
        ]);
        $query = $access->query($request->user())->with('type', 'producingUnit', 'latestVersion');
        $query->when($filters['search'] ?? null, fn ($q, $search) => $q->where('title', 'ilike', '%'.$search.'%'));
        foreach (['document_type_id', 'producing_unit_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $value) => $q->where($field, $value));
        }
        if (isset($filters['production_date_from'])) {
            $query->where('production_date', '>=', $filters['production_date_from']);
        }
        if (isset($filters['production_date_to'])) {
            $query->where('production_date', '<=', $filters['production_date_to']);
        }
        if ($request->boolean('archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        return $query->orderByDesc('id')->paginate(20)->withQueryString();
    }

    public function store(StoreDocumentRequest $request, CreateDocument $create)
    {
        $document = $create->execute($request->safe()->except('file'), $request->file('file'), $request->user());

        return response()->json(['data' => $document], 201);
    }

    public function show(Document $document)
    {
        Gate::authorize('view', $document);

        return response()->json(['data' => $document->load('type', 'producingUnit', 'versions')]);
    }

    public function addVersion(Request $request, Document $document, AddDocumentVersion $add)
    {
        Gate::authorize('addVersion', $document);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('ged.max_upload_kb'), new AllowedDocumentFile],
            'change_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $version = $add->execute($document, $request->file('file'), $request->user(), $data['change_note'] ?? null);

        return response()->json(['data' => $version], 201);
    }

    public function download(Request $request, DocumentVersion $version)
    {
        Gate::authorize('download', $version->document);
        abort_unless(Storage::disk($version->storage_disk)->exists($version->storage_path), 404);
        AuditEvent::create([
            'actor_id' => $request->user()->id, 'action' => 'document.downloaded',
            'subject_type' => 'document_version', 'subject_id' => $version->id, 'document_id' => $version->document_id,
        ]);

        return Storage::disk($version->storage_disk)->download($version->storage_path, $version->original_filename, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
