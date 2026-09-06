<x-mail::message>
# Albarán {{ $note->number }}

Adjuntamos el albarán de la entrega realizada
@php $c = $note->customer_snapshot ?? []; @endphp
@if (!empty($c['name']))a **{{ $c['name'] }}**@endif
el {{ $note->issued_at->format('d/m/Y') }}.

@if ($note->delivered_quantity !== null)
**Cantidad entregada:** {{ number_format($note->delivered_quantity, 0, ',', '.') }} L
@endif

Gracias por confiar en nosotros.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
