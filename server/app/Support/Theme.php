<?php

namespace App\Support;

/**
 * Resuelve el tema del lado del servidor para que `wire:navigate` no lo pierda.
 *
 * Livewire sincroniza los atributos de <html> entre la página actual y la que acaba de
 * cargar por AJAX; si la clase "dark" solo la pusiera el JS de tiempo de ejecución (nunca
 * en el HTML servido), esa sincronización la borraría en cada navegación SPA. Al renderizar
 * la clase aquí, la propia petición de wire:navigate (que sí manda la cookie) ya la incluye.
 */
class Theme
{
    /** 'dark' o cadena vacía — listo para usar directamente en class="{{ ... }}". */
    public static function htmlClass(): string
    {
        return static::isDark() ? 'dark' : '';
    }

    /**
     * Solo se puede resolver aquí "light"/"dark" explícitos. Con "system" (o sin cookie
     * todavía) no hay forma de saber en el servidor el esquema del sistema operativo: ese
     * caso lo decide el script anti-FOUC del cliente en el primer pintado.
     */
    public static function isDark(): bool
    {
        return request()?->cookie('theme') === 'dark';
    }
}
