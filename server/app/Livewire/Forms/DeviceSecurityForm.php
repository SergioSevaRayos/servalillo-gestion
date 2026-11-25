<?php

namespace App\Livewire\Forms;

use App\Models\Device;
use Livewire\Form;

/**
 * Protección del terminal del chofer (docs/24): restricciones que la APK tracker aplica como
 * Device Owner y la clave para abrirla / desbloquear en el móvil.
 */
class DeviceSecurityForm extends Form
{
    public ?Device $editing = null;

    public bool $block_screen_capture = false;

    public bool $block_uninstall = false;

    public bool $block_factory_reset = false;

    public bool $block_safe_boot = false;

    // Vacía = se deja la clave que ya tenga.
    public string $pin = '';

    public string $pin_confirmation = '';

    public function rules(): array
    {
        return [
            'block_screen_capture' => ['boolean'],
            'block_uninstall' => ['boolean'],
            'block_factory_reset' => ['boolean'],
            'block_safe_boot' => ['boolean'],
            'pin' => [
                // Sin clave no se puede abrir la APK protegida: obligatoria si aún no tiene.
                $this->editing?->hasUnlockPin() ? 'nullable' : 'required',
                'digits_between:4,8',
                'confirmed',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.required' => 'Pon una clave para este terminal.',
            'pin.digits_between' => 'La clave debe tener entre 4 y 8 números.',
            'pin.confirmed' => 'Las dos claves no coinciden.',
        ];
    }

    public function validationAttributes(): array
    {
        return ['pin' => 'clave'];
    }

    public function setDevice(Device $device): void
    {
        $this->reset();
        $this->resetValidation();

        $this->editing = $device;
        foreach (array_keys(Device::POLICIES) as $column) {
            $this->{$column} = (bool) $device->{$column};
        }
    }

    public function save(): Device
    {
        $this->validate();

        $device = $this->editing;

        foreach (array_keys(Device::POLICIES) as $column) {
            $device->{$column} = $this->{$column};
        }

        if ($this->pin !== '') {
            $device->setUnlockPin($this->pin);
        }

        // Volver a proteger anula una liberación que aún no se había aplicado.
        if (in_array(true, $device->desiredPolicies(), true)) {
            $device->release_requested = false;
        }

        $device->save();

        $this->reset('pin', 'pin_confirmation');

        return $device;
    }
}
