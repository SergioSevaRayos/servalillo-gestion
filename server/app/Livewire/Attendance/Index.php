<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Services\AttendanceExportService;
use App\Services\AttendanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bloque 18 (fichaje): página personal de fichaje — administrador y chofer (mantenimiento
 * no ficha, ver App\Models\User::canPunchAttendance()). El botón de fichar nunca se gatea;
 * ver y exportar el propio histórico tampoco lleva ningún permiso ni casilla adicional —
 * es un derecho del trabajador (RD-ley 8/2019), ver docs/05-fichaje.md.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(config('servalillo.attendance.enabled'), 404);
        abort_unless(auth()->user()->canPunchAttendance(), 403);
    }

    #[Computed]
    public function today(): ?Attendance
    {
        return Attendance::where('user_id', auth()->id())->where('date', today()->toDateString())->first();
    }

    #[Computed]
    public function history(): Collection
    {
        return Attendance::where('user_id', auth()->id())
            ->where('date', '>=', today()->subDays(30))
            ->orderByDesc('date')
            ->get();
    }

    public function punchIn(?float $lat = null, ?float $lng = null): void
    {
        app(AttendanceService::class)->punchIn(auth()->user(), $lat, $lng);

        unset($this->today, $this->history);
        $this->dispatch('toast', message: 'Entrada fichada.', variant: 'success');
    }

    public function punchOut(?float $lat = null, ?float $lng = null): void
    {
        app(AttendanceService::class)->punchOut(auth()->user(), $lat, $lng);

        unset($this->today, $this->history);
        $this->dispatch('toast', message: 'Salida fichada.', variant: 'success');
    }

    /** Exporta TODO el histórico propio, no solo los últimos 30 días que se muestran. */
    private function ownAttendances(): Collection
    {
        return Attendance::where('user_id', auth()->id())->orderBy('date')->get();
    }

    public function exportPdf()
    {
        $rows = app(AttendanceExportService::class)->toPdfRows($this->ownAttendances());
        $pdf = Pdf::loadView('pdf.attendance-report', ['rows' => $rows])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print $pdf->output(),
            'mis-horas.pdf',
        );
    }

    public function exportXml()
    {
        $xml = app(AttendanceExportService::class)->toLegalXml($this->ownAttendances());

        return response()->streamDownload(
            fn () => print $xml,
            'mis-horas.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function render()
    {
        return view('livewire.attendance.index');
    }
}
