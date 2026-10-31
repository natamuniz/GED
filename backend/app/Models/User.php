<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationalUnit::class)->withPivot('created_at');
    }

    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission_overrides')->withPivot('effect')->withTimestamps();
    }

    public function hasPermission(string $key): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $override = $this->relationLoaded('permissionOverrides')
            ? $this->permissionOverrides->firstWhere('key', $key)
            : $this->permissionOverrides()->where('key', $key)->first();
        if ($override) {
            return $override->pivot->effect === 'allow';
        }

        $profile = $this->profile;
        if ($profile?->relationLoaded('permissions')) {
            return $profile->permissions->contains('key', $key);
        }

        return $profile?->permissions()->where('key', $key)->exists() ?? false;
    }
}
