<?php

namespace App\Livewire\Forms;

use App\Models\Device;
use App\Services\LostDeviceService;
use Livewire\Form;

/** Modo perdido de un móvil (docs/24): el mensaje que sale en su pantalla de bloqueo. */
class DeviceLostModeForm extends Form
{
    public ?Device $editing = null;

    public string $message = '';

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Escribe el mensaje que verá quien tenga el móvil.',
            'message.max' => 'El mensaje no puede pasar de 200 caracteres.',
        ];
    }

    public function setDevice(Device $device): void
    {
        $this->reset();
        $this->resetValidation();
        $this->editing = $device;
        $this->message = $device->lost_message ?: LostDeviceService::DEFAULT_MESSAGE;
    }

    /** @return int nº de sesiones web del chofer cerradas */
    public function save(): int
    {
        $this->validate();

        return app(LostDeviceService::class)->enable($this->editing, trim($this->message));
    }
}
