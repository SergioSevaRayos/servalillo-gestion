<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreGpsBatchRequest;
use App\Models\Device;
use App\Services\GpsIngestService;
use Illuminate\Http\JsonResponse;

class GpsController extends Controller
{
    public function store(StoreGpsBatchRequest $request, GpsIngestService $ingest): JsonResponse
    {
        $device = $request->user();

        // La habilidad `gps:ingest` solo se emite a dispositivos; un token de usuario nunca la
        // tiene en producción, pero blindamos el tipo por si acaso.
        abort_unless($device instanceof Device, 403);

        $accepted = $ingest->ingest($device, $request->validated('positions'));

        return response()->json(['accepted' => $accepted], 202);
    }
}
