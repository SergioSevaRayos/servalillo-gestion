<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Estado de protección que reporta la APK tracker (docs/24). */
class ReportDeviceStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_owner' => ['required', 'boolean'],
            'applied' => ['present', 'array'],
            'applied.block_screen_capture' => ['sometimes', 'boolean'],
            'applied.block_uninstall' => ['sometimes', 'boolean'],
            'applied.block_factory_reset' => ['sometimes', 'boolean'],
            'applied.block_safe_boot' => ['sometimes', 'boolean'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ];
    }
}
