<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 36px; }
        * { box-sizing: border-box; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1e293b;
            line-height: 1.4;
        }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 18px; color: #0f766e; }
        .header .meta { margin-top: 2px; color: #64748b; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; color: #475569; border-bottom: 1px solid #e2e8f0; }
        td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
        td.num { text-align: right; }
        .badge { font-size: 8px; color: #b45309; }
        .foot { position: fixed; bottom: -14px; left: 0; right: 0; text-align: center; color: #94a3b8; font-size: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ __('Registro de jornada') }}</h1>
        <div class="meta">
            {{ config('servalillo.company.name') }}
            @if (config('servalillo.company.tax_id'))
                · CIF {{ config('servalillo.company.tax_id') }}
            @endif
            · {{ __('Generado el :date', ['date' => now()->format('d/m/Y H:i')]) }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('Persona') }}</th>
                <th>{{ __('DNI') }}</th>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Entrada') }}</th>
                <th>{{ __('Coord. entrada') }}</th>
                <th>{{ __('Salida') }}</th>
                <th>{{ __('Coord. salida') }}</th>
                <th class="num">{{ __('Horas') }}</th>
                <th>{{ __('Corregido') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['dni'] ?? '—' }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['in_at'] ?? '—' }}</td>
                    <td>{{ $row['in_coords'] ?? '—' }}</td>
                    <td>{{ $row['out_at'] ?? '—' }}</td>
                    <td>{{ $row['out_coords'] ?? '—' }}</td>
                    <td class="num">{{ $row['hours'] }}</td>
                    <td>@if ($row['corrected'])<span class="badge">{{ __('Sí') }}</span>@else {{ __('No') }} @endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">{{ config('app.name') }} — {{ __('Registro de jornada (RD-ley 8/2019)') }}</div>
</body>
</html>
