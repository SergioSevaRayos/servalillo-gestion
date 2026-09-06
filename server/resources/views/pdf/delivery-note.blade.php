<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 40px; }
        * { box-sizing: border-box; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.45;
        }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; color: #0f766e; }
        .header .meta { margin-top: 2px; color: #64748b; font-size: 10px; }
        .doc-number { float: right; text-align: right; }
        .doc-number .n { font-size: 16px; font-weight: bold; }
        .doc-number .d { color: #64748b; font-size: 10px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin: 20px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        .kv td { padding: 3px 0; vertical-align: top; }
        .kv td:first-child { width: 130px; color: #64748b; }
        .items { margin-top: 4px; border: 1px solid #e2e8f0; }
        .items th { background: #f1f5f9; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; color: #475569; }
        .items td { padding: 6px 8px; border-top: 1px solid #e2e8f0; }
        .items td.num { text-align: right; }
        .tote { margin-top: 8px; text-align: right; font-size: 13px; }
        .tote strong { color: #0f766e; }
        .sign { margin-top: 36px; }
        .sign .box { border: 1px solid #cbd5e1; height: 120px; width: 260px; padding: 6px; }
        .sign img { max-height: 100px; max-width: 246px; }
        .sign .who { margin-top: 6px; font-size: 10px; color: #64748b; }
        .foot { position: fixed; bottom: -16px; left: 0; right: 0; text-align: center; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $c = $note->customer_snapshot ?? [];
        $route = $stop->route;
    @endphp

    <div class="header">
        <div class="doc-number">
            <div class="n">{{ $note->number }}</div>
            <div class="d">{{ $note->issued_at->format('d/m/Y H:i') }}</div>
        </div>
        <h1>{{ config('app.name') }}</h1>
        <div class="meta">Albarán de entrega</div>
    </div>

    <h2>Cliente</h2>
    <table class="kv">
        <tr><td>Nombre</td><td>{{ $c['name'] ?? '—' }}</td></tr>
        @if (!empty($c['tax_id']))<tr><td>CIF / NIF</td><td>{{ $c['tax_id'] }}</td></tr>@endif
        <tr><td>Dirección</td><td>{{ $c['address'] ?? '—' }}</td></tr>
        @if (!empty($c['contact_name']) || !empty($c['contact_phone']))
            <tr><td>Contacto</td><td>{{ trim(($c['contact_name'] ?? '').' '.($c['contact_phone'] ? '· '.$c['contact_phone'] : '')) ?: '—' }}</td></tr>
        @endif
    </table>

    <h2>Entrega</h2>
    <table class="kv">
        @if ($route)
            <tr><td>Ruta</td><td>{{ $route->code }} · {{ optional($route->route_date)->format('d/m/Y') }}</td></tr>
            <tr><td>Camión / chofer</td><td>{{ $route->truck?->code ?? '—' }}{{ $route->driver?->user?->name ? ' · '.$route->driver->user->name : '' }}</td></tr>
        @endif
        @if ($note->odometer_reading)<tr><td>Contador (km)</td><td>{{ number_format($note->odometer_reading, 0, ',', '.') }}</td></tr>@endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Concepto</th>
                <th style="text-align:right; width:110px;">Cantidad</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ $stop->deliveryType?->name ?? 'Entrega' }}
                    @if ($stop->deliveryType && !empty($stop->data))
                        <br><span style="color:#64748b; font-size:10px;">
                            @foreach ($stop->deliveryType->fields() as $field)
                                @php $v = $stop->data[$field['key']] ?? null; @endphp
                                @if ($v !== null && $v !== '')
                                    {{ $field['label'] }}: {{ is_bool($v) ? ($v ? 'Sí' : 'No') : $v }}{{ !$loop->last ? ' · ' : '' }}
                                @endif
                            @endforeach
                        </span>
                    @endif
                </td>
                <td class="num">{{ $note->delivered_quantity !== null ? number_format($note->delivered_quantity, 0, ',', '.').' L' : '—' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="tote">Total entregado: <strong>{{ $note->delivered_quantity !== null ? number_format($note->delivered_quantity, 0, ',', '.').' L' : '—' }}</strong></div>

    <div class="sign">
        <h2>Conforme (firma del cliente)</h2>
        <div class="box">
            @if ($signature)<img src="{{ $signature }}" alt="firma">@endif
        </div>
        <div class="who">{{ $note->signer_name ?: '—' }}</div>
    </div>

    <div class="foot">{{ config('app.name') }} · {{ $note->number }} · generado el {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
