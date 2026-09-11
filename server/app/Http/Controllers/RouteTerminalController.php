<?php

namespace App\Http\Controllers;

use App\Models\RouteTerminal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class RouteTerminalController extends Controller
{
    /**
     * Vincula ESTE navegador a la ruta del token (deja una cookie de por vida). Sin middleware
     * `auth`: quien visita el enlace puede no tener sesión iniciada todavía en este navegador —
     * es el propio enlace, largo e improbable de adivinar, el que actúa como credencial (mismo
     * nivel de confianza que la URL firmada de verificación de email que ya usa Laravel).
     */
    public function pair(string $token): RedirectResponse
    {
        $terminal = RouteTerminal::where('token', $token)->whereNull('revoked_at')->first();

        abort_if($terminal === null, 404);

        $terminal->forceFill([
            'paired_at' => $terminal->paired_at ?? now(),
            'last_used_at' => now(),
        ])->saveQuietly();

        return redirect(Auth::check() ? route('home') : route('login'))
            ->cookie(Cookie::forever('route_terminal', $terminal->token));
    }
}
