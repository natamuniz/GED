<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class OrganizationalUnit extends Model
{
    protected $fillable = ['name', 'code', 'parent_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $unit) {
            // Administrative moves must serialize concurrent hierarchy changes.
            $seen = $unit->exists ? [$unit->id => true] : [];
            $parentId = $unit->parent_id;
            while ($parentId !== null) {
                if (isset($seen[$parentId])) {
                    throw ValidationException::withMessages(['parent_id' => 'A hierarquia não pode conter ciclos.']);
                }
                $seen[$parentId] = true;
                $parentId = static::findOrFail($parentId)->parent_id;
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
