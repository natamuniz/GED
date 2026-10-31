<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Administration
{
    public function mutate(User $actor, string $permission, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $operation) {
            DB::select('SELECT pg_advisory_xact_lock(71932, 1)');
            $actor = $actor->fresh();
            abort_unless($actor?->hasPermission($permission), 403);
            $hadAdministrator = $this->hasAdministrator();
            $result = $operation($actor);
            if ($hadAdministrator && ! $this->hasAdministrator()) {
                throw ValidationException::withMessages([
                    'administration' => 'A operação removeria o último administrador ativo com gestão de usuários e perfis.',
                ]);
            }

            return $result;
        });
    }

    private function hasAdministrator(): bool
    {
        return User::where('is_active', true)->with('profile.permissions', 'permissionOverrides')->get()->contains(
            fn (User $user) => $user->hasPermission('users.manage') && $user->hasPermission('profiles.manage')
        );
    }

    public function audit(User $actor, string $action, Model $subject, array $before, array $after): void
    {
        AuditEvent::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => Str::snake(class_basename($subject)),
            'subject_id' => $subject->getKey(),
            'changes' => ['before' => $before, 'after' => $after],
        ]);
    }

    public function userSnapshot(User $user): array
    {
        return [
            ...$user->only('name', 'email', 'profile_id', 'is_active'),
            'unit_ids' => $user->units()->orderBy('organizational_units.id')->pluck('organizational_units.id')->all(),
            'permission_overrides' => $user->permissionOverrides()->orderBy('permissions.id')->get()
                ->map(fn ($permission) => ['permission_id' => $permission->id, 'effect' => $permission->pivot->effect])->all(),
        ];
    }
}
