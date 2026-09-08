@php
    $ctrl = 'rounded-lg border-slate-300 shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 lg:text-sm';
@endphp

<div>
    <x-maintenance.tabs />

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
        <input type="search" wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por asunto o solicitante…') }}"
            class="block w-full {{ $ctrl }} placeholder:text-slate-400 lg:max-w-xs" />
        <select wire:model.live="status" class="{{ $ctrl }}">
            <option value="all">{{ __('Todos los estados') }}</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="category" class="{{ $ctrl }}">
            <option value="all">{{ __('Todas las categorías') }}</option>
            @foreach ($categories as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="from" class="{{ $ctrl }}" />
        <input type="date" wire:model.live="to" class="{{ $ctrl }}" />
        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
    </div>

    <x-ui.table>
        <thead>
            <tr>
                <th>{{ __('Asunto') }}</th>
                <th>{{ __('Solicitante') }}</th>
                <th>{{ __('Categoría') }}</th>
                <th>{{ __('Estado') }}</th>
                <th>{{ __('Última actividad') }}</th>
                <th class="text-right">{{ __('Acciones') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tickets as $ticket)
                <tr wire:key="ticket-{{ $ticket->id }}">
                    <td data-label="{{ __('Asunto') }}">
                        <span class="font-medium text-slate-800 dark:text-slate-100">{{ \Illuminate\Support\Str::limit($ticket->subject, 60) }}</span>
                    </td>
                    <td data-label="{{ __('Solicitante') }}">{{ $ticket->creator?->name ?? '—' }}</td>
                    <td data-label="{{ __('Categoría') }}">{{ $ticket->category->label() }}</td>
                    <td data-label="{{ __('Estado') }}">
                        <x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->label() }}</x-ui.badge>
                    </td>
                    <td data-label="{{ __('Última actividad') }}" class="whitespace-nowrap text-slate-500 dark:text-slate-400">
                        {{ ($ticket->last_reply_at ?? $ticket->created_at)->format('d/m/Y H:i') }}
                    </td>
                    <td data-label="{{ __('Acciones') }}" class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" wire:click="show({{ $ticket->id }})">{{ __('Ver') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm"
                                wire:click="deleteTicket({{ $ticket->id }})"
                                wire:confirm="{{ __('¿Eliminar esta incidencia?') }}"
                                class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10">
                                {{ __('Eliminar') }}
                            </x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state title="{{ __('Sin incidencias') }}" description="{{ __('Ajusta los filtros o espera a que administración abra alguna.') }}" /></td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $tickets->links() }}</div>

    <x-modal name="support-thread" max-width="2xl">
        @if ($this->selected)
            @php $t = $this->selected; @endphp
            <div class="p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ $t->subject }}</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ $t->creator?->name ?? __('Sin solicitante') }} · {{ $t->category->label() }} ·
                            {{ $t->created_at->format('d/m/Y H:i') }}
                        </p>
                    </div>
                    <div>
                        <label class="sr-only" for="support-status">{{ __('Estado') }}</label>
                        <select id="support-status" wire:change="setStatus($event.target.value)" class="{{ $ctrl }}">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($t->status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-4 max-h-[50vh] space-y-4 overflow-y-auto themed-scrollbar rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                    @php $creatorId = $t->user_id; @endphp
                    <div class="flex justify-start">
                        <div class="max-w-[85%] rounded-2xl rounded-bl-sm bg-slate-100 px-4 py-2.5 dark:bg-slate-800">
                            <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $t->creator?->name ?? __('Administración') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $t->body }}</p>
                            <p class="mt-1 text-[0.7rem] text-slate-400">{{ $t->created_at->format('d/m/Y H:i') }}</p>
                        </div>
                    </div>

                    @foreach ($t->replies as $r)
                        @php $mine = $r->user_id !== $creatorId; @endphp
                        <div wire:key="reply-{{ $r->id }}" class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                            <div @class([
                                'max-w-[85%] rounded-2xl px-4 py-2.5',
                                'rounded-br-sm bg-primary-50 dark:bg-primary-500/15' => $mine,
                                'rounded-bl-sm bg-slate-100 dark:bg-slate-800' => ! $mine,
                            ])>
                                <p class="text-xs font-semibold {{ $mine ? 'text-primary-700 dark:text-primary-300' : 'text-slate-600 dark:text-slate-300' }}">
                                    {{ $r->author?->name ?? __('Usuario') }}
                                </p>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $r->body }}</p>
                                <p class="mt-1 text-[0.7rem] text-slate-400">{{ $r->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <form wire:submit="reply" class="mt-4">
                    <x-ui.textarea name="replyForm.body" wire:model="replyForm.body" rows="3"
                        placeholder="{{ __('Responder a administración…') }}" />
                    <div class="mt-3 flex justify-end gap-2">
                        <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
                        <x-ui.button type="submit">{{ __('Responder') }}</x-ui.button>
                    </div>
                </form>
            </div>
        @endif
    </x-modal>
</div>
