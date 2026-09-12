<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque 18 (fichaje): ledger de correcciones administrativas — de solo-inserción
 * (sin `updated_at`, ver UPDATED_AT abajo) y con `reason` obligatorio. Es la pieza
 * que resuelve "controlado, motivado y anotado" (ver docs/05-fichaje.md). NO es
 * Auditable a propósito: es en sí mismo un registro de auditoría, mismo criterio
 * que GpsPosition/LoginLog.
 */
#[Fillable(['attendance_id', 'corrected_by', 'old_values', 'new_values', 'reason'])]
class AttendanceCorrection extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
