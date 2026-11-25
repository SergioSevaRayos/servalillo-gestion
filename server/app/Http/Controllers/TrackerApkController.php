<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\TrackerApk;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga de la APK tracker desde /dispositivos (docs/24). Solo quien gestiona dispositivos:
 * la APK lleva dentro el secreto de enrolamiento.
 */
class TrackerApkController extends Controller
{
    public function download(TrackerApk $apk): BinaryFileResponse
    {
        $this->authorize('viewAny', Device::class);

        abort_unless($apk->exists(), 404, 'Todavía no se ha publicado ninguna APK.');

        return $this->send($apk);
    }

    /**
     * Descarga para el alta por QR (docs/24): sin sesión, con enlace temporal firmado (middleware
     * `signed`). La hace el propio Android durante la pantalla de bienvenida.
     */
    public function provision(TrackerApk $apk): BinaryFileResponse
    {
        abort_unless($apk->exists(), 404);

        return $this->send($apk);
    }

    private function send(TrackerApk $apk): BinaryFileResponse
    {
        return response()->download($apk->path(), $apk->downloadName(), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-store',
        ]);
    }
}
