<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::guard('web')->attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $request->user()->load('profile', 'units')]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = Permission::pluck('key')->filter(fn ($key) => $user->hasPermission($key))->values();

        return response()->json(['user' => $user->load('profile', 'units'), 'permissions' => $permissions]);
    }
}
