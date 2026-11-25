<?php

namespace App\Livewire\Forms;

use Livewire\Form;

/**
 * Wi-Fi opcional que se mete en el QR de alta (docs/24). No se guarda en ningún sitio: solo
 * viaja dentro del QR que se muestra en pantalla.
 */
class DeviceProvisioningForm extends Form
{
    public string $wifi_ssid = '';

    public string $wifi_password = '';

    public function rules(): array
    {
        return [
            'wifi_ssid' => ['nullable', 'string', 'max:32'],
            'wifi_password' => ['nullable', 'string', 'min:8', 'max:63'],
        ];
    }

    public function messages(): array
    {
        return [
            'wifi_ssid.max' => 'El nombre de la Wi-Fi no puede pasar de 32 caracteres.',
            'wifi_password.min' => 'La contraseña de una Wi-Fi WPA tiene al menos 8 caracteres.',
            'wifi_password.max' => 'La contraseña de la Wi-Fi no puede pasar de 63 caracteres.',
        ];
    }
}
