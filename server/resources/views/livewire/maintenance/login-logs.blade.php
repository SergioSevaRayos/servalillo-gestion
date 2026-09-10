<div>
    <x-maintenance.tabs />

    @if ($this->onlineUsers->isNotEmpty())
        <div wire:poll.30s class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-500/20 dark:bg-emerald-500/10">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                {{ __('Conectados ahora') }} ({{ $this->onlineUsers->count() }})
            </p>
            <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-emerald-800 dark:text-emerald-200">
                @foreach ($this->onlineUsers as $u)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ $u->name }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Buscar por nombre o email…') }}"
                class="block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:max-w-xs sm:text-sm"
            />
            <div class="w-40"><x-ui.date-input name="from" wire:model.live="from" placeholder="{{ __('Desde') }}" /></div>
            <div class="w-40"><x-ui.date-input name="to" wire:model.live="to" placeholder="{{ __('Hasta') }}" /></div>
            <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
        </div>

        @if ($oldCount > 0)
            <x-ui.button
                variant="secondary" size="sm"
                wire:click="purgeOld"
                wire:confirm="{{ __('¿Eliminar :n accesos de más de :d días?', ['n' => $oldCount, 'd' => config('servalillo.login_log_retention_days')]) }}"
            >{{ __('Purgar > :d días (:n)', ['d' => config('servalillo.login_log_retention_days'), 'n' => $oldCount]) }}</x-ui.button>
        @endif
    </div>

    @if (count($selected) > 0)
        <div class="mb-3 flex items-center justify-between rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 dark:border-primary-500/20 dark:bg-primary-500/10">
            <p class="text-sm text-primary-800 dark:text-primary-200">{{ __(':n seleccionados', ['n' => count($selected)]) }}</p>
            <x-ui.button
                variant="ghost" size="sm"
                wire:click="deleteSelected"
                wire:confirm="{{ __('¿Eliminar :n accesos seleccionados?', ['n' => count($selected)]) }}"
                class="!text-rose-600 hover:!bg-rose-100 dark:!text-rose-400 dark:hover:!bg-rose-500/10"
            >{{ __('Eliminar seleccionados') }}</x-ui.button>
        </div>
    @endif

    <x-ui.table>
        <thead>
            <tr>
                <th class="w-10"><input type="checkbox" wire:click="toggleSelectAll" class="rounded border-slate-300 text-primary-600 shadow-soft-sm focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" /></th>
                <th>{{ __('Fecha') }}</th>
                <th>{{ __('Usuario') }}</th>
                <th>{{ __('Rol') }}</th>
                <th>{{ __('IP') }}</th>
                <th>{{ __('Navegador / dispositivo') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logins as $login)
                <tr wire:key="login-{{ $login->id }}">
                    <td><input type="checkbox" wire:model="selected" value="{{ $login->id }}" class="rounded border-slate-300 text-primary-600 shadow-soft-sm focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" /></td>
                    <td data-label="{{ __('Fecha') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $login->logged_in_at->format('d/m/Y H:i') }}</td>
                    <td data-label="{{ __('Usuario') }}" class="font-medium text-slate-800 dark:text-slate-100">
                        {{ $login->user?->name ?? __('Cuenta eliminada') }}
                        @if ($login->user)
                            <span class="block text-xs font-normal text-slate-400">{{ $login->user->email }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('Rol') }}">
                        @if ($login->user)
                            <x-ui.badge variant="primary">{{ ucfirst($login->user->getRoleNames()->first() ?? '—') }}</x-ui.badge>
                        @else
                            —
                        @endif
                    </td>
                    <td data-label="{{ __('IP') }}" class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $login->ip ?? '—' }}</td>
                    <td data-label="{{ __('Navegador / dispositivo') }}" class="max-w-xs truncate text-xs text-slate-500 dark:text-slate-400" title="{{ $login->user_agent }}">
                        {{ $login->user_agent ?? '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state title="{{ __('Sin accesos registrados') }}" description="{{ __('Todavía no hay ningún login con estos filtros.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $logins->links() }}</div>
</div>
