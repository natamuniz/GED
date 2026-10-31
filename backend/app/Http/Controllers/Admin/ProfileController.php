<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListRequest;
use App\Http\Requests\Admin\ProfileRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\Administration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Profile::with('permissions')->withCount('users');
        if (isset($filters['search'])) {
            $query->where('name', 'ilike', '%'.$filters['search'].'%');
        }

        return JsonResource::collection($query->orderBy('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(Profile $profile): JsonResource
    {
        return new JsonResource($profile->load('permissions')->loadCount('users'));
    }

    public function store(ProfileRequest $request, Administration $administration): JsonResource
    {
        return $this->save($request, $administration);
    }

    public function update(ProfileRequest $request, Profile $profile, Administration $administration): JsonResource
    {
        return $this->save($request, $administration, $profile);
    }

    private function snapshot(Profile $profile): array
    {
        return [...$profile->only('name', 'description', 'is_system'),
            'permission_ids' => $profile->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all()];
    }

    private function save(ProfileRequest $request, Administration $administration, ?Profile $target = null): JsonResource
    {
        $profile = $administration->mutate($request->user(), 'profiles.manage', function (User $actor) use ($request, $administration, $target) {
            $data = Validator::make($request->all(), $request->rules())->validate();
            $profile = $target ? Profile::findOrFail($target->id) : new Profile;
            if ($profile->is_system && isset($data['name']) && $data['name'] !== $profile->name) {
                throw ValidationException::withMessages(['name' => 'Perfis de sistema não podem ser renomeados.']);
            }
            $before = $target ? $this->snapshot($profile) : [];
            $profile->fill(collect($data)->only('name', 'description')->all());
            $profile->save();
            if (array_key_exists('permission_ids', $data)) {
                $profile->permissions()->sync($data['permission_ids']);
            }
            $administration->audit($actor, $target ? 'profile.updated' : 'profile.created', $profile, $before, $this->snapshot($profile));

            return $profile;
        });

        return new JsonResource($profile->load('permissions')->loadCount('users'));
    }

    public function destroy(Request $request, Profile $profile, Administration $administration): Response
    {
        $administration->mutate($request->user(), 'profiles.manage', function (User $actor) use ($profile, $administration) {
            $profile = Profile::findOrFail($profile->id);
            abort_if($profile->is_system, 409, 'Perfis de sistema não podem ser excluídos.');
            abort_if($profile->users()->exists(), 409, 'O perfil está vinculado a usuários.');
            $before = $this->snapshot($profile);
            $profile->delete();
            $administration->audit($actor, 'profile.deleted', $profile, $before, []);
        });

        return response()->noContent();
    }
}
