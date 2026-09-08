<?php

namespace App\Providers;

use App\Policies\AuditPolicy;
use App\Support\DeliveryChannels\DeliveryChannelManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use OwenIt\Auditing\Models\Audit;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeliveryChannelManager::class);
    }

    public function boot(): void
    {
        // Mantenimiento = superusuario técnico. Se sigue auditando cada acción.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('mantenimiento') ? true : null;
        });

        // La entidad Audit vive en el paquete: registramos su policy a mano.
        Gate::policy(Audit::class, AuditPolicy::class);

        // Política de contraseñas por defecto en toda la app.
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Enrolamiento de la APK tracker (Bloque 10): limitado por IP.
        RateLimiter::for('device-register', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
