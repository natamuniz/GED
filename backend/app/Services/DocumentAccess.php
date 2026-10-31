<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DocumentAccess
{
    public function __construct(private UnitAccess $units) {}

    public function query(User $user): Builder
    {
        $query = Document::query();
        if (! $user->hasPermission('documents.view')) {
            return $query->whereRaw('1 = 0');
        }
        if (! $user->hasPermission('access.global')) {
            $ids = $this->units->ids($user);
            $query->where(fn (Builder $q) => $q->whereNull('producing_unit_id')->orWhereIn('producing_unit_id', $ids));
        }

        return $query;
    }
}
