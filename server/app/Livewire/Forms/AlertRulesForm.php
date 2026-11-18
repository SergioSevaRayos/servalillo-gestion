<?php

namespace App\Livewire\Forms;

use App\Enums\AlertType;
use App\Models\AlertRule;
use Livewire\Form;

/**
 * Configuración de todos los avisos a la vez (una tarjeta por tipo). `items` va indexado por el
 * valor de `AlertType`: ['enabled' => bool, 'threshold' => ?int, 'time' => ?string].
 * (No se llama `rules` a la propiedad: chocaría con el método `rules()` de Livewire\Form.)
 */
class AlertRulesForm extends Form
{
    /** @var array<string, array{enabled: bool, threshold: int|string|null, time: string|null}> */
    public array $items = [];

    public function load(): void
    {
        $this->items = [];

        foreach (AlertRule::ensureDefaults() as $key => $rule) {
            $this->items[$key] = [
                'enabled' => $rule->enabled,
                'threshold' => $rule->threshold,
                'time' => $rule->time_of_day,
            ];
        }

        $this->resetErrorBag();
    }

    public function rules(): array
    {
        $rules = [];

        foreach (AlertType::cases() as $type) {
            $key = "items.{$type->value}";
            $rules["{$key}.enabled"] = ['boolean'];

            if ($type->usesTime()) {
                $rules["{$key}.time"] = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
            } else {
                [$min, $max] = $type->thresholdRange();
                $rules["{$key}.threshold"] = ['required', 'integer', "between:{$min},{$max}"];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [];

        foreach (AlertType::cases() as $type) {
            $key = "items.{$type->value}";

            if ($type->usesTime()) {
                $messages["{$key}.time.required"] = 'Indica la hora.';
                $messages["{$key}.time.regex"] = 'La hora no es válida (formato HH:MM).';
            } else {
                [$min, $max] = $type->thresholdRange();
                $messages["{$key}.threshold.required"] = 'Indica el valor.';
                $messages["{$key}.threshold.integer"] = 'Debe ser un número entero.';
                $messages["{$key}.threshold.between"] = "Debe estar entre {$min} y {$max} {$type->unit()}.";
            }
        }

        return $messages;
    }

    public function save(): void
    {
        $this->validate();

        foreach (AlertRule::ensureDefaults() as $key => $rule) {
            $item = $this->items[$key];
            $type = $rule->type;

            $rule->update([
                'enabled' => (bool) $item['enabled'],
                'threshold' => $type->usesTime() ? null : (int) $item['threshold'],
                'time_of_day' => $type->usesTime() ? $item['time'] : null,
                'updated_by' => auth()->id(),
            ]);
        }
    }
}
