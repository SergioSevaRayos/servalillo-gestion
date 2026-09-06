{{-- Superficie sólida de alto contraste: contenido, formularios, listados. Sin efecto cristal. --}}
@props(['padded' => true])

<div {{ $attributes->merge(['class' => 'surface rounded-xl ' . ($padded ? 'p-5' : '')]) }}>
    {{ $slot }}
</div>
