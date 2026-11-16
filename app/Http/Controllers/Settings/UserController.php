<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'active', 'created_at']),
            'roles' => UserRole::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:120', 'unique:users'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', Password::min(8)],
        ]);

        User::create($data + ['active' => true, 'email_verified_at' => now()]);

        return back()->with('success', 'Usuario creado.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'active' => ['boolean'],
            'password' => ['nullable', Password::min(8)],
        ]);

        if ($user->is($request->user()) && ($data['role'] !== UserRole::Admin->value || ! ($data['active'] ?? true))) {
            return back()->with('error', 'No puede quitarse el rol de administrador ni desactivarse a sí mismo.');
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return back()->with('success', 'Usuario actualizado.');
    }
}
