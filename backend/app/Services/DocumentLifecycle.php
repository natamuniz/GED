<?php

namespace App\Services;

use App\Http\Requests\MoveDocumentRequest;
use App\Http\Requests\UpdateDocumentMetadataRequest;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DocumentLifecycle
{
    public function updateMetadata(UpdateDocumentMetadataRequest $request, Document $document): Document
    {
        return $this->mutate($request->user(), $document, 'document.metadata-updated', function (User $actor, Document $locked) use ($request) {
            Gate::forUser($actor)->authorize('update', $locked);
            $data = Validator::make($request->all(), $request->rules())->validate();
            $data = array_intersect_key($data, array_flip(UpdateDocumentMetadataRequest::FIELDS));
            if ($data === []) {
                throw ValidationException::withMessages(['metadata' => 'Informe ao menos um metadado para atualizar.']);
            }
            if (isset($data['document_type_id']) && (int) $data['document_type_id'] !== $locked->document_type_id
                && ! DocumentType::whereKey($data['document_type_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['document_type_id' => 'Selecione um tipo documental ativo.']);
            }
            $locked->fill($data);
        });
    }

    public function move(MoveDocumentRequest $request, Document $document): Document
    {
        return $this->mutate($request->user(), $document, 'document.unit-changed', function (User $actor, Document $locked) use ($request) {
            $data = Validator::make($request->all(), $request->rules())->validate();
            $destination = $data['producing_unit_id'] === null ? null : (int) $data['producing_unit_id'];
            Gate::forUser($actor)->authorize('move', [$locked, $destination]);
            $locked->producing_unit_id = $destination;
        });
    }

    public function archive(User $actor, Document $document): Document
    {
        return $this->mutate($actor, $document, 'document.archived', function (User $actor, Document $locked) {
            Gate::forUser($actor)->authorize('archive', $locked);
            if ($locked->archived_at === null) {
                $locked->archived_at = now();
                $locked->archived_by = $actor->id;
            }
        });
    }

    public function restore(User $actor, Document $document): Document
    {
        return $this->mutate($actor, $document, 'document.restored', function (User $actor, Document $locked) {
            Gate::forUser($actor)->authorize('restore', $locked);
            $locked->archived_at = null;
            $locked->archived_by = null;
        });
    }

    private function mutate(User $actor, Document $document, string $action, Closure $operation): Document
    {
        return DB::transaction(function () use ($actor, $document, $action, $operation) {
            DB::select('SELECT pg_advisory_xact_lock(71932, 1)');
            $actor = $actor->fresh();
            abort_unless($actor?->is_active, 403);
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $operation($actor, $locked);
            if ($locked->isDirty()) {
                $locked->updated_by = $actor->id;
                $locked->save();
                AuditEvent::create([
                    'actor_id' => $actor->id, 'action' => $action,
                    'subject_type' => 'document', 'subject_id' => $locked->id, 'document_id' => $locked->id,
                    'changes' => ['before' => $before, 'after' => $this->snapshot($locked)],
                ]);
            }

            return $locked->load('type', 'producingUnit', 'latestVersion');
        });
    }

    private function snapshot(Document $document): array
    {
        return [
            ...$document->only(...UpdateDocumentMetadataRequest::FIELDS),
            'production_date' => $document->production_date->format('Y-m-d'),
            'producing_unit_id' => $document->producing_unit_id,
            'archived_at' => $document->archived_at?->toIso8601String(),
            'archived_by' => $document->archived_by,
            'updated_by' => $document->updated_by,
        ];
    }
}
