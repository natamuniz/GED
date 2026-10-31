<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    protected $fillable = [
        'title', 'description', 'document_type_id', 'producing_unit_id',
        'production_date', 'author', 'interested_party', 'reference_number',
    ];

    protected function casts(): array
    {
        return ['production_date' => 'date:Y-m-d', 'archived_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function producingUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'producing_unit_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version_number', 'max');
    }
}
