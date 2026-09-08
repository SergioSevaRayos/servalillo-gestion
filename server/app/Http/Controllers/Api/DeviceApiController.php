<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterDeviceRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class DeviceApiController extends Controller
{
    /**
     * Enrolamiento de la APK tracker: valida el secreto compartido, registra/actualiza el
     * dispositivo y emite un token Sanctum con habilidad `gps:ingest`. El dispositivo queda
     * SIN chofer asignado — eso lo hace el servicio técnico desde el panel de Mantenimiento.
     */
    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        $secret = (string) config('servalillo.device.enrolment_secret');

        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->string('secret')),
            403,
            'Secreto de enrolamiento no válido.',
        );

        $device = Device::firstOrNew(['install_identifier' => $request->string('install_identifier')->value()]);

        $device->fill([
            'platform' => $request->string('platform')->value() ?: ($device->platform ?: 'android'),
            'app_version' => $request->string('app_version')->value() ?: null,
            'label' => $device->label ?: 'Tracker '.substr($device->install_identifier, -6),
            'is_active' => true,
            'last_seen_at' => now(),
        ])->save();

        // Un re-enrolamiento invalida el token anterior (móvil reinstalado, etc.).
        $device->tokens()->delete();
        $token = $device->createToken('tracker', ['gps:ingest'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'device_id' => $device->id,
            'tracking' => $device->trackingConfig(),
        ], 201);
    }
}
