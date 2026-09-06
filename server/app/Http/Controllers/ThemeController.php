<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateThemeRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

class ThemeController extends Controller
{
    /**
     * Persiste la preferencia de tema: cookie para invitados (y para pintar sin parpadeo
     * en el próximo request), y además en el usuario si está autenticado.
     */
    public function update(UpdateThemeRequest $request): Response
    {
        $theme = $request->string('theme')->value();

        $request->user()?->forceFill(['theme_preference' => $theme])->saveQuietly();

        return response()->noContent()
            ->cookie(Cookie::forever('theme', $theme));
    }
}
