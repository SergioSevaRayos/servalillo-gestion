<?php

namespace App\Providers;

use App\Models\CompanySetting;
use App\Policies\AuditPolicy;
use App\Support\DeliveryChannels\DeliveryChannelManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // Tras nginx + Let's Encrypt: forzamos https en las URLs generadas (enlaces de PDF
        // de albarán, correos de recuperación…) por si falta la cabecera X-Forwarded-Proto.
        // Condicionado a que APP_URL sea https: durante el arranque en producción todavía
        // por IP (sin dominio/TLS) los assets se sirven por http y no deben romperse.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

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

        // Enrolamiento de la APK tracker (Bloque 10): limitado por IP. Margen holgado porque
        // durante el alta un mismo técnico puede re-enrolar varias veces seguidas.
        RateLimiter::for('device-register', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        $this->applyCompanySettings();
    }

    /**
     * Ajustes editables desde /mantenimiento/ajustes (Bloque 18, 2026-09-13) que pisan el
     * .env sin necesidad de desplegar: ubicación de la base y umbral de parada no
     * programada. Envuelto en try/catch en vez de `Schema::hasTable()` (una consulta
     * extra por petición) — solo falla si `company_settings` todavía no existe (antes de
     * correr las migraciones por primera vez), caso en el que simplemente se deja el .env.
     */
    private function applyCompanySettings(): void
    {
        try {
            $setting = CompanySetting::query()->first();
        } catch (QueryException) {
            return;
        }

        if ($setting === null) {
            return;
        }

        if ($setting->base_latitude !== null && $setting->base_longitude !== null) {
            config([
                'servalillo.base.latitude' => (float) $setting->base_latitude,
                'servalillo.base.longitude' => (float) $setting->base_longitude,
            ]);
        }

        if ($setting->unplanned_stop_minutes !== null) {
            config(['servalillo.dwell.unplanned_stop_min_seconds' => $setting->unplanned_stop_minutes * 60]);
        }
    }
}
