<?php

namespace App\Services;

use App\Models\DeliveryNote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renderiza el PDF de un albarán (dompdf, PHP puro — el contenedor no trae navegador headless)
 * y lo guarda en el disco `r2` (en local: storage/app/private/r2).
 */
class DeliveryNotePdfRenderer
{
    public function render(DeliveryNote $note): string
    {
        $note->loadMissing('routeStop.deliveryType', 'routeStop.route.truck', 'routeStop.route.driver.user');

        $signature = null;
        if ($note->signature_path && Storage::disk('r2')->exists($note->signature_path)) {
            $signature = 'data:image/png;base64,'.base64_encode(Storage::disk('r2')->get($note->signature_path));
        }

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'note' => $note,
            'stop' => $note->routeStop,
            'signature' => $signature,
        ])->setPaper('a4');

        $path = 'albaranes/'.$note->number.'.pdf';
        Storage::disk('r2')->put($path, $pdf->output());

        return $path;
    }
}
