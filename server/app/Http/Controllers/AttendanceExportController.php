<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Services\AttendanceExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bloque 18 (fichaje): export "interoperable" preliminar (JSON) — protegido por el mismo
 * permiso `attendance.manage` que /fichajes/gestion (middleware de ruta, ver routes/web.php),
 * ningún `authorize()` extra hace falta aquí. Es una vía de descarga más, como el XML/PDF de
 * `Manage`/`Totals`, NO una integración real con la Inspección de Trabajo — esa depende de un
 * reglamento que todavía no existe (ver docs/05-fichaje.md, "Auditoría del formato de datos
 * frente a la ley"). Controlador aparte (no Livewire) porque es una respuesta HTTP pura,
 * mismo criterio que `DeliveryNoteController::pdf()`.
 */
class AttendanceExportController extends Controller
{
    public function json(Request $request, AttendanceExportService $exportService): JsonResponse
    {
        abort_unless(config('servalillo.attendance.enabled'), 404);

        $from = $request->date('from') ?? today()->startOfMonth();
        $to = $request->date('to') ?? today()->endOfMonth();

        $attendances = Attendance::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->orderBy('date')
            ->get();

        return response()->json($exportService->toInteroperableArray($attendances));
    }
}
