<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

/**
 * Gestiona exclusivamente cuentas de personal (administrador/mantenimiento).
 * Los chofers se crean y editan desde el módulo de Chofers (App\Livewire\Drivers).
 */
class UserForm extends Form
{
    public const STAFF_ROLES = ['administrador', 'mantenimiento'];

    public ?User $editing = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public ?string $phone = null;
    public bool $is_active = true;
    public string $role = 'administrador';

    public function rules(): array
    {
        $userId = $this->editing?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'password' => [$this->editing ? 'nullable' : 'required', 'string', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
            'role' => ['required', Rule::in(self::STAFF_ROLES)],
        ];
    }

    public function setUser(User $user): void
    {
        $this->editing = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->phone = $user->phone;
        $this->is_active = $user->is_active;
        $this->role = $user->getRoleNames()->first() ?? 'administrador';
    }

    public function save(): User
    {
        $validated = $this->validate();

        $user = $this->editing ?? new User();
        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'is_active' => $validated['is_active'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();
        $user->syncRoles([$validated['role']]);

        $this->reset();

        return $user;
    }
}
