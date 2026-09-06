<?php

namespace App\Support;

use App\Models\ErrorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
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

    public static function record(Throwable $e): void
    {
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
                'message' => \Illuminate\Support\Str::limit($e->getMessage(), 2000),
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
