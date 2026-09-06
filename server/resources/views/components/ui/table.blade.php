{{--
    Tabla responsive: en escritorio se ve como tabla normal; por debajo de "md" se convierte
    en una lista de tarjetas (sin scroll horizontal), sin JS, vía la clase .table-responsive
    (ver resources/css/app.css). Cada <td> debe llevar data-label="Cabecera" para que se
    muestre como etiqueta en la vista de tarjeta:

        <x-ui.table>
            <thead>
                <tr><th>Camión</th><th>Chofer</th><th class="text-right">Acciones</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td data-label="Camión">C-01</td>
                    <td data-label="Chofer">Pedro Ramírez</td>
                    <td data-label="Acciones" class="text-right">…</td>
                </tr>
            </tbody>
        </x-ui.table>
--}}
@props(['padded' => true])

<div {{ $attributes->merge(['class' => 'surface rounded-xl overflow-x-auto ' . ($padded ? 'p-1 md:p-0' : '')]) }}>
    <table class="table-responsive">
        {{ $slot }}
    </table>
</div>
