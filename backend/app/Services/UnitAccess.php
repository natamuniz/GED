<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnitAccess
{
    /** @return list<int> */
    public function ids(User $user): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE accessible_units AS (
                SELECT organizational_units.id FROM organizational_units
                JOIN organizational_unit_user ON organizational_unit_user.organizational_unit_id = organizational_units.id
                WHERE organizational_unit_user.user_id = ?
                UNION
                SELECT child.id FROM organizational_units child
                JOIN accessible_units parent ON child.parent_id = parent.id
            ) SELECT id FROM accessible_units
            SQL, [$user->id]);

        return array_map(fn ($row) => (int) $row->id, $rows);
    }

    public function contains(User $user, ?int $unitId): bool
    {
        return $unitId === null || $user->hasPermission('access.global') || in_array($unitId, $this->ids($user), true);
    }
}
