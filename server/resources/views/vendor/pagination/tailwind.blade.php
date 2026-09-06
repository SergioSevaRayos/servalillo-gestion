@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Paginación') }}" class="flex items-center justify-between gap-4">

        <div class="flex flex-1 items-center justify-between gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="inline-flex cursor-not-allowed items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-600">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-soft-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-soft-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="inline-flex cursor-not-allowed items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-600">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>

        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-4">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __('Mostrando') }}
                @if ($paginator->firstItem())
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $paginator->firstItem() }}</span>
                    {{ __('a') }}
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $paginator->lastItem() }}</span>
                @else
                    {{ $paginator->count() }}
                @endif
                {{ __('de') }}
                <span class="font-medium text-slate-700 dark:text-slate-200">{{ $paginator->total() }}</span>
                {{ __('resultados') }}
            </p>

            <div class="flex items-center gap-1">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" class="grid h-8 w-8 cursor-not-allowed place-items-center rounded-lg text-slate-300 dark:text-slate-700">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}" class="grid h-8 w-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-900/5 dark:text-slate-400 dark:hover:bg-white/10">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                    </a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-hidden="true" class="grid h-8 w-8 place-items-center text-sm text-slate-400 dark:text-slate-600">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="grid h-8 w-8 place-items-center rounded-lg bg-primary-600 text-sm font-medium text-white">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="{{ __('Ir a la página :page', ['page' => $page]) }}" class="grid h-8 w-8 place-items-center rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-900/5 dark:text-slate-300 dark:hover:bg-white/10">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}" class="grid h-8 w-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-900/5 dark:text-slate-400 dark:hover:bg-white/10">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                    </a>
                @else
                    <span aria-disabled="true" class="grid h-8 w-8 cursor-not-allowed place-items-center rounded-lg text-slate-300 dark:text-slate-700">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif
