@props(['title' => 'Sin resultados', 'description' => null])

<div class="flex flex-col items-center justify-center gap-1 py-12 text-center">
    <p class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ $title }}</p>
    @if ($description)
        <p class="text-sm text-slate-400 dark:text-slate-500">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
