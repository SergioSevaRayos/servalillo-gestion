<?php

namespace App\Livewire\Forms;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

class DriverForm extends Form
{
    public ?Driver $editing = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $employee_code = '';

    public ?string $license_number = null;

    public ?string $license_expiry = null;

    public ?string $phone = null;

    public ?string $dni = null;

    public bool $is_active = true;

    // Fichaje (Bloque 18): el chofer siempre ficha (App\Models\User::canPunchAttendance()),
    // así que puede necesitar su propia zona si aparca fuera de la nave.
    public string $attendance_mode = 'base';

    public ?string $attendance_latitude = null;

    public ?string $attendance_longitude = null;

    public ?string $attendance_radius_meters = null;

    public function rules(): array
    {
        $userId = $this->editing?->user_id;
        $driverId = $this->editing?->id;
        $remote = $this->attendance_mode === 'remote';

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'password' => [$this->editing ? 'nullable' : 'required', 'string', Password::defaults()],
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('drivers', 'employee_code')->ignore($driverId)],
            'license_number' => ['nullable', 'string', 'max:50'],
            'license_expiry' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'dni' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'attendance_mode' => ['required', Rule::in(['base', 'remote'])],
            'attendance_latitude' => [$remote ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'attendance_longitude' => [$remote ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'attendance_radius_meters' => ['nullable', 'integer', 'min:10'],
        ];
    }

    public function setDriver(Driver $driver): void
    {
        $this->editing = $driver;
        $this->name = $driver->user->name;
        $this->email = $driver->user->email;
        $this->password = '';
        $this->employee_code = $driver->employee_code;
        $this->license_number = $driver->license_number;
        $this->license_expiry = $driver->license_expiry?->toDateString();
        $this->phone = $driver->phone;
        $this->dni = $driver->user->dni;
        $this->is_active = $driver->is_active;
        $this->attendance_mode = $driver->user->attendance_mode;
        $this->attendance_latitude = $driver->user->attendance_latitude !== null ? (string) $driver->user->attendance_latitude : null;
        $this->attendance_longitude = $driver->user->attendance_longitude !== null ? (string) $driver->user->attendance_longitude : null;
        $this->attendance_radius_meters = $driver->user->attendance_radius_meters !== null ? (string) $driver->user->attendance_radius_meters : null;
    }

    public function save(): Driver
    {
        $validated = $this->validate();
        $remote = $validated['attendance_mode'] === 'remote';

        $driver = DB::transaction(function () use ($validated, $remote) {
            $user = $this->editing?->user ?? new User;
            $user->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
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

            if (! $this->editing) {
                $user->assignRole('chofer');
            }

            $driver = $this->editing ?? new Driver(['user_id' => $user->id]);
            $driver->fill([
                'employee_code' => $validated['employee_code'],
                'license_number' => $validated['license_number'],
                'license_expiry' => $validated['license_expiry'],
                'phone' => $validated['phone'],
                'is_active' => $validated['is_active'],
            ]);
            $driver->save();

            return $driver;
        });

        $this->reset();

        return $driver;
    }
}
