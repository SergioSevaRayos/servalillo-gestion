<div>
    <x-maintenance.tabs />

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
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

    <x-ui.table>
        <thead>
            <tr>
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
                    <td colspan="5"><x-ui.empty-state title="{{ __('Sin accesos registrados') }}" description="{{ __('Todavía no hay ningún login con estos filtros.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $logins->links() }}</div>
</div>
