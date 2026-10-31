<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\Administration;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = User::with('profile', 'units');
        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return UserResource::collection($query->orderBy('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('profile.permissions', 'units', 'permissionOverrides'));
    }

    public function store(UserRequest $request, Administration $administration): UserResource
    {
        return $this->save($request, $administration);
    }

    public function update(UserRequest $request, User $user, Administration $administration): UserResource
    {
        $resource = $this->save($request, $administration, $user);
        if ($request->has('password') && $user->id === $request->user()->id) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $resource;
    }

    private function save(UserRequest $request, Administration $administration, ?User $target = null): UserResource
    {
        $user = $administration->mutate($request->user(), 'users.manage', function (User $actor) use ($request, $administration, $target) {
            $data = Validator::make($request->all(), $request->rules())->validate();
            if (array_key_exists('profile_id', $data) || array_key_exists('permission_overrides', $data)) {
                abort_unless($actor->hasPermission('profiles.manage'), 403);
            }
            $user = $target ? User::findOrFail($target->id) : new User;
            $before = $target ? $administration->userSnapshot($user) : [];
            $user->fill(collect($data)->only('name', 'email', 'password')->all());
            if (array_key_exists('profile_id', $data)) {
                $user->profile_id = $data['profile_id'];
            }
            if (array_key_exists('is_active', $data)) {
                $user->is_active = $data['is_active'];
            }
            $user->save();
            if (array_key_exists('unit_ids', $data)) {
                $user->units()->sync($data['unit_ids']);
            }
            if (array_key_exists('permission_overrides', $data)) {
                $overrides = [];
                foreach ($data['permission_overrides'] as $override) {
                    $overrides[$override['permission_id']] = ['effect' => $override['effect']];
                }
                $user->permissionOverrides()->sync($overrides);
            }
            if ($target && (array_key_exists('password', $data) || ! $user->is_active)) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $after = $administration->userSnapshot($user);
            if ($target && array_key_exists('password', $data)) {
                $after['password_changed'] = true;
            }
            $administration->audit($actor, $target ? 'user.updated' : 'user.created', $user, $before, $after);

            return $user;
        });

        return new UserResource($user->load('profile.permissions', 'units', 'permissionOverrides'));
    }
}
