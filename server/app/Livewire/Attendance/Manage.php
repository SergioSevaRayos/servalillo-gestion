<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\AttendanceExportService;
use App\Services\AttendanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bloque 18 (fichaje): panel de gestión — administrador y mantenimiento (permiso
 * `attendance.manage`, ver RolePermissionSeeder). Vive en el grupo de "Gestión", NO bajo
 * /mantenimiento (ese prefijo es exclusivo del rol mantenimiento).
 */
#[Layout('layouts.app')]
class Manage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $month = '';

    // Corregir un fichaje existente
    public ?int $correctingId = null;

    public string $correct_in_at = '';

    public string $correct_out_at = '';

    public string $correct_reason = '';

    // Registrar un fichaje olvidado (día sin ninguna fila)
    public ?int $create_user_id = null;

    public string $create_date = '';

    public string $create_in_at = '';

    public string $create_out_at = '';

    public string $create_reason = '';

    // Ver el historial de correcciones de una fila
    public ?int $viewingCorrectionsId = null;

    public function mount(): void
    {
        abort_unless(config('servalillo.attendance.enabled'), 404);
        // El middleware de ruta (`permission:attendance.manage`) no se ejecuta en
        // Livewire::test(), así que se repite aquí — mismo patrón que Dashboard\Index.
        abort_unless(auth()->user()->can('attendance.manage'), 403);
    }

    private function currentMonth(): string
    {
        return $this->month !== '' ? $this->month : today()->format('Y-m');
    }

    private function monthQuery()
    {
        $month = $this->currentMonth();

        return Attendance::query()
            ->whereYear('date', substr($month, 0, 4))
            ->whereMonth('date', substr($month, 5, 2))
            ->when($this->search !== '', fn ($q) => $q->whereHas(
                'user', fn ($q2) => $q2->where('name', 'like', '%'.$this->search.'%')
            ));
    }

    #[Computed]
    public function attendances(): LengthAwarePaginator
    {
        return $this->monthQuery()->with('user')->orderByDesc('date')->paginate(20);
    }

    /** Personas que fichan (administrador + chofer) — para los selectores del panel. */
    #[Computed]
    public function eligibleUsers(): Collection
    {
        return User::query()->get()->filter->canPunchAttendance()->sortBy('name')->values();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingMonth(): void
    {
        $this->resetPage();
    }

    public function openCorrect(int $attendanceId): void
    {
        $attendance = Attendance::findOrFail($attendanceId);

        $this->correctingId = $attendance->id;
        $this->correct_in_at = $attendance->in_at?->format('Y-m-d\TH:i') ?? '';
        $this->correct_out_at = $attendance->out_at?->format('Y-m-d\TH:i') ?? '';
        $this->correct_reason = '';
        $this->dispatch('open-modal', 'attendance-correct');
    }

    /** Atajo de "Corregir": prefija la salida a ahora mismo para cerrar una jornada abierta. El motivo sigue siendo obligatorio. */
    public function openCloseNow(int $attendanceId): void
    {
        $this->openCorrect($attendanceId);
        $this->correct_out_at = now()->format('Y-m-d\TH:i');
    }

    /** Atajo de "Corregir": vacía la salida para reabrir una jornada cerrada por error. El motivo sigue siendo obligatorio. */
    public function openReopen(int $attendanceId): void
    {
        $this->openCorrect($attendanceId);
        $this->correct_out_at = '';
    }

    public function saveCorrect(): void
    {
        $validated = $this->validate([
            'correct_in_at' => ['required', 'date'],
            'correct_out_at' => ['nullable', 'date', 'after:correct_in_at'],
            'correct_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $attendance = Attendance::findOrFail($this->correctingId);

        app(AttendanceService::class)->correct(
            $attendance,
            ['in_at' => $validated['correct_in_at'], 'out_at' => $validated['correct_out_at'] ?: null],
            $validated['correct_reason'],
            auth()->user(),
        );

        unset($this->attendances);
        $this->correctingId = null;
        $this->dispatch('close-modal', 'attendance-correct');
        $this->dispatch('toast', message: 'Fichaje corregido.', variant: 'success');
    }

    public function openCreate(): void
    {
        $this->create_user_id = null;
        $this->create_date = today()->toDateString();
        $this->create_in_at = '';
        $this->create_out_at = '';
        $this->create_reason = '';
        $this->dispatch('open-modal', 'attendance-create');
    }

    public function saveCreate(): void
    {
        $validated = $this->validate([
            'create_user_id' => ['required', 'exists:users,id'],
            'create_date' => ['required', 'date'],
            'create_in_at' => ['nullable', 'date'],
            'create_out_at' => ['nullable', 'date', 'after:create_in_at'],
            'create_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $target = User::findOrFail($validated['create_user_id']);

        app(AttendanceService::class)->createManual(
            $target,
            $validated['create_date'],
            $validated['create_in_at'] ?: null,
            $validated['create_out_at'] ?: null,
            $validated['create_reason'],
            auth()->user(),
        );

        unset($this->attendances);
        $this->dispatch('close-modal', 'attendance-create');
        $this->dispatch('toast', message: 'Fichaje registrado.', variant: 'success');
    }

    /** "Desde dónde han fichado": abre un mapa de solo lectura con los puntos de entrada/salida y la geovalla de la persona. */
    public function viewLocation(int $attendanceId): void
    {
        $attendance = Attendance::with('user')->findOrFail($attendanceId);
        $geofence = app(AttendanceService::class)->effectiveGeofence($attendance->user);

        $this->dispatch('open-attendance-location', ...[
            'person' => $attendance->user->name,
            'date' => $attendance->date->format('d/m/Y'),
            'geofence' => $geofence,
            'in' => $attendance->in_latitude !== null ? [
                'lat' => (float) $attendance->in_latitude,
                'lng' => (float) $attendance->in_longitude,
                'label' => 'Entrada '.$attendance->in_at?->format('H:i'),
                'outOfBounds' => $attendance->in_out_of_bounds,
            ] : null,
            'out' => $attendance->out_latitude !== null ? [
                'lat' => (float) $attendance->out_latitude,
                'lng' => (float) $attendance->out_longitude,
                'label' => 'Salida '.$attendance->out_at?->format('H:i'),
                'outOfBounds' => $attendance->out_out_of_bounds,
            ] : null,
        ]);
    }

    public function viewCorrections(int $attendanceId): void
    {
        $this->viewingCorrectionsId = $attendanceId;
        $this->dispatch('open-modal', 'attendance-corrections');
    }

    #[Computed]
    public function viewingCorrections(): Collection
    {
        if ($this->viewingCorrectionsId === null) {
            return new Collection;
        }

        return AttendanceCorrection::where('attendance_id', $this->viewingCorrectionsId)
            ->with('correctedBy')
            ->latest('id')
            ->get();
    }

    private function exportSelection(): Collection
    {
        return $this->monthQuery()->with('user')->orderBy('date')->get();
    }

    public function exportXml()
    {
        $xml = app(AttendanceExportService::class)->toLegalXml($this->exportSelection());

        return response()->streamDownload(
            fn () => print $xml,
            'registro-jornada-'.$this->currentMonth().'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function exportPdf()
    {
        $rows = app(AttendanceExportService::class)->toPdfRows($this->exportSelection());
        $pdf = Pdf::loadView('pdf.attendance-report', ['rows' => $rows])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print $pdf->output(),
            'registro-jornada-'.$this->currentMonth().'.pdf',
        );
    }

    public function render()
    {
        return view('livewire.attendance.manage');
    }
}
