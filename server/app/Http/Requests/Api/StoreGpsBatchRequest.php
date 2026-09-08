<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreGpsBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tokenCan('gps:ingest') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'positions' => ['required', 'array', 'min:1', 'max:500'],
            'positions.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.recorded_at' => ['required', 'date'],
            'positions.*.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'positions.*.speed_mps' => ['nullable', 'numeric', 'min:0'],
            'positions.*.heading_deg' => ['nullable', 'numeric', 'between:0,360'],
            'positions.*.battery_level' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
