<?php

namespace App\Models;

use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso concreto (un camión sin señal, una jornada sin cerrar…). Abierto mientras se da la
 * condición. Dato derivado del evaluador: no se audita (mismo criterio que `unplanned_stops`).
 */
class AlertEvent extends Model
{
    protected $fillable = [
        'rule_type', 'subject_type', 'subject_id', 'subject_label', 'message', 'url',
        'opened_at', 'resolved_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => AlertType::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
