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

    public ?string $dni = null;

    public bool $is_active = true;

    public string $role = 'administrador';

    // Fichaje (Bloque 18): solo aplica a administrador — mantenimiento no ficha
    // (App\Models\User::canPunchAttendance()).
    public string $attendance_mode = 'base';

    public ?string $attendance_latitude = null;

    public ?string $attendance_longitude = null;

    public ?string $attendance_radius_meters = null;

    public function rules(): array
    {
        $userId = $this->editing?->id;
        $remote = $this->attendance_mode === 'remote';

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'password' => [$this->editing ? 'nullable' : 'required', 'string', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:30'],
            'dni' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'role' => ['required', Rule::in(self::STAFF_ROLES)],
            'attendance_mode' => ['required', Rule::in(['base', 'remote'])],
            'attendance_latitude' => [$remote ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'attendance_longitude' => [$remote ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'attendance_radius_meters' => ['nullable', 'integer', 'min:10'],
        ];
    }

    public function setUser(User $user): void
    {
        $this->editing = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->phone = $user->phone;
        $this->dni = $user->dni;
        $this->is_active = $user->is_active;
        $this->role = $user->getRoleNames()->first() ?? 'administrador';
        $this->attendance_mode = $user->attendance_mode;
        $this->attendance_latitude = $user->attendance_latitude !== null ? (string) $user->attendance_latitude : null;
        $this->attendance_longitude = $user->attendance_longitude !== null ? (string) $user->attendance_longitude : null;
        $this->attendance_radius_meters = $user->attendance_radius_meters !== null ? (string) $user->attendance_radius_meters : null;
    }

    public function save(): User
    {
        $validated = $this->validate();
        $remote = $validated['attendance_mode'] === 'remote';

        $user = $this->editing ?? new User;
        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'dni' => $validated['dni'],
            'is_active' => $validated['is_active'],
            'attendance_mode' => $validated['attendance_mode'],
            'attendance_latitude' => $remote ? $validated['attendance_latitude'] : null,
            'attendance_longitude' => $remote ? $validated['attendance_longitude'] : null,
            'attendance_radius_meters' => $remote ? ($validated['attendance_radius_meters'] ?: null) : null,
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
