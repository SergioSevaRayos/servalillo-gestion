<?php

namespace App\Support;

use App\Models\ErrorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorLogger
{
    /** Excepciones "esperadas" que no son errores de sistema. */
    private const IGNORED = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
    ];

    /**
     * ¿Es una llamada a `/livewire/update` fabricada a mano (escáner/bot), no un fallo nuestro?
     *
     * Dos firmas reales vistas en producción: `_startUpload` sobre un componente sin
     * `WithFileUploads` (ninguno de la app sube ficheros) y un método inexistente con prefijo
     * `_` (p. ej. `_xNonExist`, un sondeo clásico). Los métodos de la app nunca empiezan por
     * `_` — los internos de Livewire sí, y no pueden llegar aquí como "no encontrado".
     * Un `wire:click` mal escrito (sin `_`) SIGUE registrándose: ese sí es un bug nuestro.
     */
    public static function isForgedLivewireCall(Throwable $e): bool
    {
        if ($e instanceof MissingFileUploadsTraitException) {
            return true;
        }

        return $e instanceof MethodNotFoundException
            && preg_match('/\[_[^\]]*\]/', $e->getMessage()) === 1;
    }

    public static function record(Throwable $e): void
    {
        if (self::isForgedLivewireCall($e)) {
            return;
        }

        foreach (self::IGNORED as $ignored) {
            if ($e instanceof $ignored) {
                return;
            }
        }

        // 4xx del cliente tampoco se registran como error de sistema.
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return;
        }

        try {
            ErrorLog::create([
                'level' => 'error',
                'message' => Str::limit($e->getMessage(), 2000),
                'exception_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'context' => [
                    'trace' => collect($e->getTrace())->take(15)->map(fn ($f) => [
                        'file' => $f['file'] ?? null,
                        'line' => $f['line'] ?? null,
                        'function' => $f['function'] ?? null,
                    ])->all(),
                ],
                'user_id' => auth()->id(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Nunca dejar que el logging rompa la petición (ej. BD caída).
        }
    }
}
