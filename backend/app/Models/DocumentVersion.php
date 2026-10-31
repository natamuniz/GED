<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class DocumentVersion extends Model
{
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'version_number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Versões existentes são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Versões devem ser preservadas.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function integrityChecks(): HasMany
    {
        return $this->hasMany(IntegrityCheck::class);
    }
}
