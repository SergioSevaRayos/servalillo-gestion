<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Jobs\ProcessDeliveryNote;
use App\Models\DeliveryNote;
use App\Models\RouteStop;
use App\Support\DeliveryChannels\DeliveryChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea y (re)procesa albaranes. La generación del PDF y la entrega por el canal ocurren
 * en `ProcessDeliveryNote` (cola); este servicio solo prepara el registro y lo encola.
 */
class DeliveryNoteService
{
    public function __construct(private DeliveryChannelManager $channels) {}

    /**
     * @param  array{channel: string, recipient_email?: ?string, signer_name?: ?string, signature?: ?string}  $input
     */
    public function createForStop(RouteStop $stop, array $input, ?int $userId = null): DeliveryNote
    {
        if ($stop->deliveryNote()->exists()) {
            return $stop->deliveryNote;
        }

        $channel = $this->channels->get($input['channel']);

        $note = DB::transaction(function () use ($stop, $input, $channel, $userId) {
            $note = new DeliveryNote([
                'route_stop_id' => $stop->id,
                'number' => $this->nextNumber(),
                'issued_at' => now(),
                'customer_snapshot' => [
                    'name' => $stop->customer_name,
                    'tax_id' => $stop->customer_tax_id,
                    'address' => $stop->address,
                    'contact_name' => $stop->contact_name,
                    'contact_phone' => $stop->contact_phone,
                ],
                'delivered_quantity' => $stop->delivered_quantity,
                'odometer_reading' => $stop->route?->odometerReadings
                    ->firstWhere('kind.value', 'end')?->value,
                'signer_name' => $input['signer_name'] ?? null,
                'signature_path' => ! empty($input['signature'])
                    ? $this->storeSignature($input['signature'])
                    : null,
                'delivery_channel' => $channel->key(),
                'recipient_email' => $input['recipient_email'] ?? null,
                'status' => DeliveryNoteStatus::Queued,
                'created_by' => $userId,
            ]);
            $note->save();

            return $note;
        });

        ProcessDeliveryNote::dispatch($note);

        return $note;
    }

    /** Vuelve a generar el PDF y a entregar un albarán ya existente. */
    public function reprocess(DeliveryNote $note): void
    {
        $note->forceFill([
            'status' => DeliveryNoteStatus::Queued,
            'failure_reason' => null,
        ])->save();

        ProcessDeliveryNote::dispatch($note);
    }

    /** Reglas de validación del formulario de entrega para un canal concreto. */
    public function rulesForChannel(?string $channelKey): array
    {
        if (! $channelKey || ! $this->channels->exists($channelKey)) {
            return [];
        }

        return $this->channels->get($channelKey)->validationRules();
    }

    /** ¿El canal captura la firma en el teléfono? (email sí, entrega en mano no). */
    public function channelRequiresSignature(?string $channelKey): bool
    {
        return $channelKey
            && $this->channels->exists($channelKey)
            && $this->channels->get($channelKey)->requiresSignature();
    }

    private function nextNumber(): string
    {
        $prefix = 'ALB-'.now()->year.'-';

        $last = DeliveryNote::where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    /** Guarda la firma (data URL PNG) en el disco r2 y devuelve la ruta. */
    private function storeSignature(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,#', $dataUrl)) {
            throw ValidationException::withMessages(['signature' => 'La firma debe ser una imagen PNG.']);
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        // Cabecera mágica real de un PNG — nunca fiarse solo del prefijo del data URL.
        if ($binary === false || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['signature' => 'La firma no es un PNG válido.']);
        }

        $path = 'firmas/'.Str::ulid()->toString().'.png';
        Storage::disk('r2')->put($path, $binary);

        return $path;
    }
}
