<?php

namespace App\Livewire\Attendance;

use App\Models\User;
use App\Services\AttendanceStatsService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bloque 18 (fichaje): tabla comparativa de horas/días trabajados por persona en un
 * periodo, con detalle día a día por persona (modal). Es otra pestaña del mismo panel
 * que Manage (mismo permiso `attendance.manage`, sin permiso nuevo) — ver
 * resources/views/components/attendance/tabs.blade.php.
 */
#[Layout('layouts.app')]
class Totals extends Component
{
    public const PERIODS = ['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes', 'year' => 'Año'];

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $anchor = '';

    public ?int $detailUserId = null;

    public function mount(): void
    {
        abort_unless(config('servalillo.attendance.enabled'), 404);
        // El middleware de ruta (`permission:attendance.manage`) no se ejecuta en
        // Livewire::test(), así que se repite aquí — mismo patrón que Manage.
        abort_unless(auth()->user()->can('attendance.manage'), 403);

        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = 'month';
        }

        if ($this->anchor === '' || ! $this->isValidDate($this->anchor)) {
            $this->anchor = today()->toDateString();
        }
    }

    private function isValidDate(string $value): bool
    {
        return (bool) Carbon::createFromFormat('Y-m-d', $value);
    }

    /** @return array{from: string, to: string} */
    private function resolvedRange(): array
    {
        $range = app(AttendanceStatsService::class)->rangeFor($this->period, Carbon::parse($this->anchor));

        return ['from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()];
    }

    #[Computed]
    public function range(): array
    {
        return $this->resolvedRange();
    }

    #[Computed]
    public function rows(): array
    {
        $range = $this->resolvedRange();

        return app(AttendanceStatsService::class)->comparisonTable($range['from'], $range['to']);
    }

    public function updatedPeriod(): void
    {
        unset($this->range, $this->rows);
    }

    public function updatedAnchor(): void
    {
        unset($this->range, $this->rows);
    }

    public function viewDetail(int $userId): void
    {
        $this->detailUserId = $userId;
        $this->dispatch('open-modal', 'attendance-totals-detail');
    }

    #[Computed]
    public function detailUser(): ?User
    {
        return $this->detailUserId ? User::find($this->detailUserId) : null;
    }

    #[Computed]
    public function detailRows(): array
    {
        if ($this->detailUserId === null) {
            return [];
        }

        $range = $this->resolvedRange();

        return app(AttendanceStatsService::class)->dailyBreakdownWithHours($this->detailUserId, $range['from'], $range['to']);
    }

    public function render()
    {
        return view('livewire.attendance.totals');
    }
}
