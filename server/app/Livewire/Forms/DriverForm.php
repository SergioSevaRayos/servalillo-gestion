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
    public bool $is_active = true;

    public function rules(): array
    {
        $userId = $this->editing?->user_id;
        $driverId = $this->editing?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'password' => [$this->editing ? 'nullable' : 'required', 'string', Password::defaults()],
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('drivers', 'employee_code')->ignore($driverId)],
            'license_number' => ['nullable', 'string', 'max:50'],
            'license_expiry' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
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
        $this->is_active = $driver->is_active;
    }

    public function save(): Driver
    {
        $validated = $this->validate();

        $driver = DB::transaction(function () use ($validated) {
            $user = $this->editing?->user ?? new User();
            $user->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'is_active' => $validated['is_active'],
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
