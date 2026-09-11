@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1.5'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$width = match ($width) {
    '48' => 'w-48',
    default => $width,
};
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @close.stop="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            {{-- glass (borde/sombra elevada) + bg-white/slate-900 OPACO encima: un panel flotante
                 traslúcido dejaba ver el contenido de detrás (nav, texto de la página) a su
                 través — mismo criterio que <x-modal> (ver CLAUDE.md, sistema de diseño). --}}
            class="absolute z-50 mt-2 {{ $width }} rounded-xl {{ $alignmentClasses }} glass bg-white dark:bg-slate-900"
            style="display: none;"
            @click="open = false">
        <div class="rounded-xl {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
