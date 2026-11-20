{{-- Manual de uso: Markdown renderizado + índice. Estilos en `.manual` (resources/css/app.css). --}}
<div class="mx-auto max-w-4xl">
    @if (count($this->available) > 1)
        <div class="mb-6 inline-flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900" role="tablist">
            @foreach ($this->available as $key => $label)
                <a href="{{ route('manual', $key) }}" wire:navigate role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-lg px-4 py-1.5 text-sm font-medium transition-colors',
                        'bg-primary-500/10 font-semibold text-primary-700 dark:text-primary-300' => $type === $key,
                        'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' => $type !== $key,
                    ])>{{ __('Manual de :tipo', ['tipo' => mb_strtolower($label)]) }}</a>
            @endforeach
        </div>
    @endif

    @php $manual = $this->manual; @endphp

    @if ($manual['html'] === '')
        <x-ui.card>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Este manual aún no tiene contenido.') }}</p>
        </x-ui.card>
    @else
        @if (count($manual['toc']) > 1)
            <nav class="surface mb-6 rounded-xl p-4" aria-label="{{ __('Índice') }}">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ __('Contenido') }}</p>
                <ol class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    @foreach ($manual['toc'] as $item)
                        <li><a href="#{{ $item['id'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $item['title'] }}</a></li>
                    @endforeach
                </ol>
            </nav>
        @endif

        <article class="manual surface rounded-xl p-5 sm:p-8">
            {!! $manual['html'] !!}
        </article>
    @endif
</div>
