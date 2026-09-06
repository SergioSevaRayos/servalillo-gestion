<?php

namespace App\Contracts;

use App\Models\DeliveryNote;

interface DeliveryChannel
{
    /** Clave única del canal (coincide con config/delivery.php y la columna delivery_channel). */
    public function key(): string;

    /** Etiqueta legible para la interfaz. */
    public function label(): string;

    /** ¿El canal genera y necesita un PDF adjunto? */
    public function requiresPdf(): bool;

    /**
     * Reglas de validación extra para el formulario de "completar entrega"
     * cuando se elige este canal (ej. email obligatorio).
     *
     * @return array<string, mixed>
     */
    public function validationRules(): array;

    /**
     * Entrega el albarán por este canal y devuelve el estado resultante.
     * Se ejecuta dentro de un Job en cola.
     */
    public function deliver(DeliveryNote $note): void;
}
