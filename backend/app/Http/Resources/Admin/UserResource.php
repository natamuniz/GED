<?php

namespace App\Http\Resources\Admin;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'profile_id' => $this->profile_id,
            'profile' => $this->whenLoaded('profile'),
            'units' => $this->whenLoaded('units'),
            'permission_overrides' => $this->whenLoaded('permissionOverrides', fn () => $this->permissionOverrides
                ->map(fn ($permission) => ['permission_id' => $permission->id, 'key' => $permission->key, 'effect' => $permission->pivot->effect])),
            'effective_permissions' => $this->when($this->relationLoaded('permissionOverrides'), fn () => Permission::all()
                ->filter(fn ($permission) => $this->resource->hasPermission($permission->key))->pluck('key')->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
