<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditEvent extends Model
{
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de auditoria são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Eventos de auditoria devem ser preservados.'));
    }
}
