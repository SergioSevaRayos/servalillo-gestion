<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /** Cada cuánto se actualiza como mucho `last_seen_at` (evita escribir en cada petición). */
    private const SEEN_THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            if (! $user->is_active) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->withErrors(['email' => 'Tu cuenta está desactivada. Contacta con administración.']);
            }

            if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subSeconds(self::SEEN_THROTTLE_SECONDS))) {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
