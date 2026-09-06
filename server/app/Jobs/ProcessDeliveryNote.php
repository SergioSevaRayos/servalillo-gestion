<?php

namespace App\Jobs;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Services\DeliveryNotePdfRenderer;
use App\Support\DeliveryChannels\DeliveryChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera el PDF del albarán y lo entrega por su canal. Se ejecuta en la cola `database`
 * (contenedor `queue`). Reintenta 3 veces; si agota, deja el albarán en estado `Failed`.
 */
class ProcessDeliveryNote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(public DeliveryNote $note) {}

    public function handle(DeliveryNotePdfRenderer $renderer, DeliveryChannelManager $channels): void
    {
        $channel = $channels->get($this->note->delivery_channel);

        $this->note->forceFill(['status' => DeliveryNoteStatus::Generating])->save();

        if ($channel->requiresPdf()) {
            $path = $renderer->render($this->note);
            $this->note->forceFill([
                'pdf_path' => $path,
                'status' => DeliveryNoteStatus::Generated,
            ])->save();
        }

        // Cada canal fija el estado final (Sent / DeliveredPhysically).
        $channel->deliver($this->note->refresh());
    }

    public function failed(Throwable $e): void
    {
        $this->note->forceFill([
            'status' => DeliveryNoteStatus::Failed,
            'failure_reason' => Str::limit($e->getMessage(), 500),
        ])->save();
    }
}
