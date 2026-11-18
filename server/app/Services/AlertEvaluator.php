<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\GpsPosition;
use App\Models\RouteDay;
use App\Models\User;
use App\Notifications\FleetAlert;
use App\Support\TrackingWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Único punto que evalúa los avisos de flota (/avisos). Se ejecuta cada minuto (comando
 * `avisos:evaluar`). Por cada tipo activo calcula qué "sujetos" cumplen la condición y
 * RECONCILIA contra `alert_events`: avisa una sola vez al abrirse y otra al resolverse, nunca
 * en cada pasada (mismo problema y misma solución que `unplanned_stops.notified_at`).
 */
class AlertEvaluator
{
    public const LAST_RUN_KEY = 'alerts:last_run';

    /**
     * Evalúa solo si hace falta y sin solaparse: lock NO bloqueante + sello de la última pasada.
     * Lo usan el planificador (cada minuto, `$minAgeSeconds = 0`) y la propia pantalla /avisos
     * (recálculo perezoso, ~45 s): así los avisos se actualizan solos aunque el planificador no
     * esté corriendo (p. ej. en local) sin que nadie pulse nada — mismo patrón que el tiempo de
     * permanencia en parada (`dwell_recalculated_at`).
     *
     * @return array<string, array{opened: int, resolved: int}>|null null = se saltó (reciente o en curso)
     */
    public function runGuarded(int $minAgeSeconds = 0): ?array
    {
        $last = Cache::get(self::LAST_RUN_KEY);
        if ($minAgeSeconds > 0 && $last && now()->diffInSeconds(Carbon::createFromTimestamp($last), true) < $minAgeSeconds) {
            return null;
        }

        $lock = Cache::lock('alerts:evaluate', 55);
        if (! $lock->get()) {
            return null;
        }

        try {
            $summary = $this->run();
            Cache::forever(self::LAST_RUN_KEY, now()->timestamp);

            return $summary;
        } finally {
            $lock->release();
        }
    }

    /** Momento de la última evaluación (para mostrarlo en pantalla), o null si nunca se ha hecho. */
    public function lastRunAt(): ?Carbon
    {
        $ts = Cache::get(self::LAST_RUN_KEY);

        return $ts ? Carbon::createFromTimestamp($ts) : null;
    }

    /** @return array<string, array{opened: int, resolved: int}> Resumen por tipo (para el comando/tests). */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $rules = AlertRule::ensureDefaults();
        $summary = [];

        foreach (AlertType::cases() as $type) {
            $rule = $rules->get($type->value);

            // Interruptor apagado: se cierran los avisos abiertos de ese tipo sin molestar a nadie.
            $found = $rule->enabled ? $this->detect($type, $rule, $now) : collect();

            // null = "no aplica ahora" (p. ej. fuera de la ventana de tracking): no se toca nada.
            if ($found === null) {
                continue;
            }

            $summary[$type->value] = $this->reconcile($type, $found, notify: $rule->enabled, now: $now);
        }

        return $summary;
    }

    /** @return Collection<string, array{subject_type: string, subject_id: int, label: string, message: string, url: string}>|null */
    private function detect(AlertType $type, AlertRule $rule, Carbon $now): ?Collection
    {
        return match ($type) {
            AlertType::GpsSilence => $this->gpsSilence((int) $rule->threshold, $now),
            AlertType::LowBattery => $this->lowBattery((int) $rule->threshold, $now),
            AlertType::Speeding => $this->speeding((int) $rule->threshold, $now),
            AlertType::ShiftNotStarted => $this->shiftNotStarted((string) $rule->time_of_day, $now),
            AlertType::ShiftNotClosed => $this->shiftNotClosed((string) $rule->time_of_day, $now),
        };
    }

    // --- Detectores -----------------------------------------------------------------------

    /** Dispositivos de choferes con ruta operativa hoy (los únicos de los que se espera señal). */
    private function devicesOnRoute(Carbon $now): Collection
    {
        $driverIds = RouteDay::query()
            ->forDate($now->toDateString())
            ->operational()
            ->pluck('driver_id');

        return Device::query()
            ->with('driver.user')
            ->where('is_active', true)
            ->whereIn('driver_id', $driverIds)
            ->get();
    }

    private function gpsSilence(int $minutes, Carbon $now): ?Collection
    {
        if (! TrackingWindow::isActive($now)) {
            return null; // el tracking está pausado: nadie "pierde" señal.
        }

        $limit = $now->copy()->subMinutes($minutes);
        $found = collect();

        foreach ($this->devicesOnRoute($now) as $device) {
            if ($device->last_seen_at !== null && $device->last_seen_at->gte($limit)) {
                continue;
            }

            $name = $this->deviceName($device);
            $since = $device->last_seen_at
                ? 'desde hace '.(int) $device->last_seen_at->diffInMinutes($now, true).' min (última a las '.$device->last_seen_at->format('H:i').')'
                : 'desde que se le asignó la ruta: no ha enviado ninguna posición';

            $found->put("device:{$device->id}", $this->item('device', $device->id, $name, "{$name}: sin señal GPS {$since}.", route('fleet.map')));
        }

        return $found;
    }

    private function lowBattery(int $percent, Carbon $now): ?Collection
    {
        if (! TrackingWindow::isActive($now)) {
            return null;
        }

        $found = collect();

        foreach ($this->devicesOnRoute($now) as $device) {
            $last = $this->latestPositions($device, 1)->first();

            // Solo con una lectura reciente: si no llega nada, el aviso de "sin señal" ya cubre el caso.
            if (! $last || $last->battery_level === null || $last->recorded_at->lt($now->copy()->subMinutes(30))) {
                continue;
            }

            if ($last->battery_level < $percent) {
                $name = $this->deviceName($device);
                $found->put("device:{$device->id}", $this->item('device', $device->id, $name, "{$name}: batería al {$last->battery_level} %.", route('fleet.map')));
            }
        }

        return $found;
    }

    private function speeding(int $kmh, Carbon $now): ?Collection
    {
        if (! TrackingWindow::isActive($now)) {
            return null;
        }

        $found = collect();
        $recent = $now->copy()->subMinutes(10);

        foreach ($this->devicesOnRoute($now) as $device) {
            $last = $this->latestPositions($device, 2);

            // Dos lecturas seguidas por encima del límite y recientes: un pico suelto no cuenta.
            $over = $last->count() === 2 && $last->every(
                fn (GpsPosition $p) => $p->speed_mps !== null
                    && $p->speed_mps * 3.6 > $kmh
                    && $p->recorded_at->gte($recent),
            );

            if ($over) {
                $name = $this->deviceName($device);
                $speed = (int) round($last->first()->speed_mps * 3.6);
                $found->put("device:{$device->id}", $this->item('device', $device->id, $name, "{$name}: circula a {$speed} km/h (límite {$kmh}).", route('fleet.map')));
            }
        }

        return $found;
    }

    private function shiftNotStarted(string $time, Carbon $now): Collection
    {
        if ($now->format('H:i') < $time) {
            return collect(); // todavía no es la hora
        }

        $found = collect();

        RouteDay::query()
            ->with('driver.user', 'truck')
            ->forDate($now->toDateString())
            ->where('status', RouteStatus::Published)
            ->whereNull('liter_meter_start')
            ->whereHas('stops', fn ($q) => $q->where('status', RouteStopStatus::Pending))
            ->get()
            ->each(function (RouteDay $day) use ($found, $time) {
                $label = $this->routeLabel($day);
                $found->put("route_day:{$day->id}", $this->item('route_day', $day->id, $label, "{$label}: la jornada no ha empezado y eran las {$time}.", route('routes.board', ['date' => $day->route_date->toDateString()])));
            });

        return $found;
    }

    private function shiftNotClosed(string $time, Carbon $now): Collection
    {
        $found = collect();

        RouteDay::query()
            ->with('driver.user', 'truck')
            ->where('status', RouteStatus::InProgress)
            ->where(function ($q) use ($now, $time) {
                // Un día anterior sin cerrar siempre cuenta; el de hoy, solo pasada la hora.
                $q->where('route_date', '<', $now->toDateString());
                if ($now->format('H:i') >= $time) {
                    $q->orWhere('route_date', $now->toDateString());
                }
            })
            ->get()
            ->each(function (RouteDay $day) use ($found, $now) {
                $label = $this->routeLabel($day);
                $when = $day->route_date->isSameDay($now) ? 'hoy' : 'el '.$day->route_date->format('d/m');
                $found->put("route_day:{$day->id}", $this->item('route_day', $day->id, $label, "{$label}: la jornada de {$when} sigue en curso sin terminar.", route('routes.board', ['date' => $day->route_date->toDateString()])));
            });

        return $found;
    }

    // --- Reconciliación y notificación ----------------------------------------------------

    /** @return array{opened: int, resolved: int} */
    private function reconcile(AlertType $type, Collection $found, bool $notify, Carbon $now): array
    {
        $open = AlertEvent::query()->open()->where('rule_type', $type)->get()
            ->keyBy(fn (AlertEvent $e) => "{$e->subject_type}:{$e->subject_id}");

        $opened = 0;
        $resolved = 0;

        foreach ($found as $key => $item) {
            if ($open->has($key)) {
                continue; // ya estaba abierto y avisado: no se repite
            }

            $event = AlertEvent::create([
                'rule_type' => $type,
                'subject_type' => $item['subject_type'],
                'subject_id' => $item['subject_id'],
                'subject_label' => $item['label'],
                'message' => $item['message'],
                'url' => $item['url'],
                'opened_at' => $now,
            ]);
            $opened++;

            if ($notify) {
                $this->send(new FleetAlert('opened', $type, $item['label'], $item['message'], $item['url']));
                $event->update(['notified_at' => $now]);
            }
        }

        foreach ($open as $key => $event) {
            if ($found->has($key)) {
                continue;
            }

            $event->update(['resolved_at' => $now]);
            $resolved++;

            // "Resuelto" solo si en su día se avisó (un aviso apagado en caliente se cierra en silencio).
            if ($notify && $event->notified_at) {
                $this->send(new FleetAlert('resolved', $type, $event->subject_label, $event->message, $event->url));
            }
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /** Mismos destinatarios que RouteChangeNotifier: administrador y mantenimiento (User::isManager()). */
    private function send(FleetAlert $notification): void
    {
        Notification::send(
            User::role(['administrador', 'mantenimiento'])->where('is_active', true)->get(),
            $notification,
        );
    }

    // --- Utilidades -----------------------------------------------------------------------

    /** @return Collection<int, GpsPosition> Las N últimas posiciones (índice device_id, recorded_at). */
    private function latestPositions(Device $device, int $limit): Collection
    {
        return GpsPosition::query()
            ->where('device_id', $device->id)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get(['speed_mps', 'battery_level', 'recorded_at']);
    }

    private function deviceName(Device $device): string
    {
        return $device->driver?->user?->name ?? $device->label;
    }

    private function routeLabel(RouteDay $day): string
    {
        return trim(($day->truck?->code ? $day->truck->code.' · ' : '').($day->driver?->user?->name ?? 'Sin chofer'));
    }

    /** @return array{subject_type: string, subject_id: int, label: string, message: string, url: string} */
    private function item(string $subjectType, int $subjectId, string $label, string $message, string $url): array
    {
        return ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'label' => $label, 'message' => $message, 'url' => $url];
    }
}
