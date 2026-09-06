<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Services\DeliveryNotePdfRenderer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryNoteController extends Controller
{
    public function pdf(DeliveryNote $note, DeliveryNotePdfRenderer $renderer): StreamedResponse
    {
        $this->authorize('view', $note);

        // Si el PDF aún no existe (job en cola o físico sin generar), se genera al vuelo.
        $path = $note->pdf_path && Storage::disk('r2')->exists($note->pdf_path)
            ? $note->pdf_path
            : $renderer->render($note);

        if (! $note->pdf_path) {
            $note->forceFill(['pdf_path' => $path])->save();
        }

        return Storage::disk('r2')->download($path, $note->number.'.pdf');
    }
}
