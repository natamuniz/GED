<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AuditAccess
{
    public function __construct(private DocumentAccess $documents) {}

    public function read(User $actor, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation) {
            DB::select('SELECT pg_advisory_xact_lock_shared(71932, 1)');
            $actor = $actor->fresh();
            abort_unless($actor?->is_active, 403);
            $actor->load('profile.permissions', 'permissionOverrides');
            abort_unless($actor->hasPermission('audit.view') || $actor->hasPermission('audit.view-global'), 403);

            return $operation($actor);
        });
    }

    public function query(User $actor): Builder
    {
        abort_unless($actor->hasPermission('audit.view') || $actor->hasPermission('audit.view-global'), 403);
        $documents = $this->documents->query($actor)->select('documents.id');
        $global = $actor->hasPermission('audit.view-global') && $actor->hasPermission('access.global');

        return AuditEvent::query()->where(function (Builder $query) use ($documents, $global) {
            $query->whereIn('document_id', $documents);
            if ($global) {
                $query->orWhereNull('document_id');
            }
        })->with(['actor:id,name', 'document:id,title,producing_unit_id,archived_at', 'document.producingUnit:id,name,code']);
    }

    public function filter(Builder $query, array $filters): Builder
    {
        foreach (['action', 'actor_id', 'document_id', 'document_share_id', 'subject_type', 'subject_id'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['producing_unit_id'])) {
            $query->whereHas('document', fn (Builder $document) => $document->where('producing_unit_id', $filters['producing_unit_id']));
        }
        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['date_from'], 'UTC')->startOfDay()->format('Y-m-d H:i:sP'));
        }
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['date_to'], 'UTC')->startOfDay()->addDay()->format('Y-m-d H:i:sP'));
        }

        return $query;
    }
}
