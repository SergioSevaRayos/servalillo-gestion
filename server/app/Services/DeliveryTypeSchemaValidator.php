<?php

namespace App\Services;

use App\Models\DeliveryType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Valida (y normaliza) el array `data` de una parada contra el `field_schema`
 * de su tipo de reparto. Nunca se confía en lo que envía el cliente: esta clase
 * es el único punto por el que deben pasar esos datos antes de persistirse.
 */
class DeliveryTypeSchemaValidator
{
    private const ALLOWED_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>  solo las claves definidas en el schema
     *
     * @throws ValidationException
     */
    public function validate(DeliveryType $type, array $input): array
    {
        $rules = [];
        $attributes = [];
        $allowedKeys = [];

        foreach ($type->fields() as $field) {
            $key = $field['key'];
            $allowedKeys[] = $key;
            $attributes["data.$key"] = $field['label'] ?? $key;
            $rules["data.$key"] = $this->rulesForField($field);
        }

        $filtered = ['data' => collect($input)->only($allowedKeys)->all()];

        $validator = Validator::make($filtered, $rules, [], $attributes);

        return $validator->validate()['data'] ?? [];
    }

    /** Reglas de Laravel para un campo del schema. */
    private function rulesForField(array $field): array
    {
        $type = $field['type'] ?? 'text';

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'text';
        }

        $rules = [($field['required'] ?? false) ? 'required' : 'nullable'];

        $rules[] = match ($type) {
            'number' => 'numeric',
            'date' => 'date',
            'boolean' => 'boolean',
            'select' => 'string',
            default => 'string',
        };

        if ($type === 'number') {
            if (isset($field['min'])) {
                $rules[] = 'min:'.$field['min'];
            }
            if (isset($field['max'])) {
                $rules[] = 'max:'.$field['max'];
            }
        }

        if ($type === 'select' && ! empty($field['options'])) {
            $rules[] = 'in:'.implode(',', $field['options']);
        }

        if (in_array($type, ['text', 'textarea'], true)) {
            $rules[] = 'max:'.($field['max'] ?? 2000);
        }

        return $rules;
    }

    /**
     * Valida la propia definición de un schema (para el CRUD de delivery_types).
     *
     * @throws ValidationException
     */
    public function validateSchemaDefinition(array $schema): array
    {
        $seen = [];

        foreach ($schema as $i => $field) {
            $key = $field['key'] ?? null;

            if (! $key || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                throw ValidationException::withMessages([
                    "field_schema.$i.key" => 'La clave debe ser snake_case y empezar por letra.',
                ]);
            }

            if (in_array($key, $seen, true)) {
                throw ValidationException::withMessages([
                    "field_schema.$i.key" => "Clave duplicada: {$key}.",
                ]);
            }
            $seen[] = $key;

            if (! in_array($field['type'] ?? '', self::ALLOWED_TYPES, true)) {
                throw ValidationException::withMessages([
                    "field_schema.$i.type" => 'Tipo de campo no soportado.',
                ]);
            }
        }

        return $schema;
    }
}
