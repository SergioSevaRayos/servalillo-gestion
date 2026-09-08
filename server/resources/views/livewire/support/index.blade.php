@php
    $inputClass = 'block w-full rounded-lg border-slate-300 shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 sm:text-sm';
@endphp

<div>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Soporte') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Comunica fallos o necesidades de la app al equipo de mantenimiento.') }}
            </p>
        </div>
        <x-ui.button wire:click="compose">{{ __('Nueva incidencia') }}</x-ui.button>
    </div>

    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <input type="search" wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Buscar por asunto…') }}"
            class="{{ $inputClass }} sm:max-w-xs" />
        <select wire:model.live="status" class="{{ $inputClass }} sm:w-48">
            <option value="all">{{ __('Todos los estados') }}</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters">{{ __('Limpiar') }}</x-ui.button>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Lista de incidencias --}}
        <div class="lg:col-span-1">
            <x-ui.card :padded="false">
                <ul class="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto themed-scrollbar dark:divide-slate-800">
                    @forelse ($tickets as $ticket)
                        <li wire:key="ticket-{{ $ticket->id }}">
                            <button type="button" wire:click="select({{ $ticket->id }})"
                                @class([
                                    'block w-full px-4 py-3 text-start transition',
                                    'bg-primary-50 dark:bg-primary-500/10' => $selectedId === $ticket->id,
                                    'hover:bg-slate-50 dark:hover:bg-slate-800/60' => $selectedId !== $ticket->id,
                                ])>
                                <div class="flex items-start justify-between gap-2">
                                    <span class="line-clamp-2 text-sm font-medium text-slate-800 dark:text-slate-100">{{ $ticket->subject }}</span>
                                    <x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->label() }}</x-ui.badge>
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-xs text-slate-400">
                                    <span>{{ $ticket->category->label() }}</span>
                                    <span>·</span>
                                    <span>{{ ($ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}</span>
                                </div>
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-10">
                            <x-ui.empty-state title="{{ __('Sin incidencias') }}" description="{{ __('Abre una con «Nueva incidencia».') }}" />
                        </li>
                    @endforelse
                </ul>
            </x-ui.card>
            <div class="mt-3">{{ $tickets->links() }}</div>
        </div>

        {{-- Hilo de la incidencia seleccionada --}}
        <div class="lg:col-span-2">
            @if ($this->selected)
                @php $t = $this->selected; @endphp
                <x-ui.card>
                    <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-4 dark:border-slate-800">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $t->subject }}</h2>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                <x-ui.badge :variant="$t->status->badgeVariant()">{{ $t->status->label() }}</x-ui.badge>
                                <span>{{ $t->category->label() }}</span>
                                <span>·</span>
                                <span>{{ __('Abierta el') }} {{ $t->created_at->format('d/m/Y') }}</span>
                            </div>
                        </div>
                        @can('delete', $t)
                            <x-ui.button variant="ghost" size="sm"
                                wire:click="deleteTicket({{ $t->id }})"
                                wire:confirm="{{ __('¿Eliminar esta incidencia?') }}"
                                class="!text-rose-600 hover:!bg-rose-50 dark:!text-rose-400 dark:hover:!bg-rose-500/10">
                                {{ __('Eliminar') }}
                            </x-ui.button>
                        @endcan
                    </div>

                    <div class="mt-4 space-y-4">
                        {{-- Primer mensaje (del creador) --}}
                        <div class="flex justify-end">
                            <div class="max-w-[85%] rounded-2xl rounded-br-sm bg-primary-50 px-4 py-2.5 dark:bg-primary-500/15">
                                <p class="text-xs font-semibold text-primary-700 dark:text-primary-300">{{ $t->creator?->name ?? __('Tú') }}</p>
                                @if ($editingReplyId === 0)
                                    <form wire:submit="saveEdit" class="mt-1.5 w-72 max-w-full">
                                        <x-ui.textarea name="editBody" wire:model="editBody" rows="3" />
                                        <div class="mt-1.5 flex gap-2">
                                            <x-ui.button type="submit" size="sm">{{ __('Guardar') }}</x-ui.button>
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEdit">{{ __('Cancelar') }}</x-ui.button>
                                        </div>
                                    </form>
                                @else
                                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $t->body }}</p>
                                    <div class="mt-1 flex items-center gap-2 text-[0.7rem] text-slate-400">
                                        <span>{{ $t->created_at->format('d/m/Y H:i') }}</span>
                                        @can('update', $t)
                                            <button type="button" wire:click="startEdit" class="hover:text-slate-600 dark:hover:text-slate-200">{{ __('Editar') }}</button>
                                        @endcan
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Respuestas --}}
                        @foreach ($t->replies as $r)
                            @php $mine = $r->user_id === auth()->id(); @endphp
                            <div wire:key="reply-{{ $r->id }}" class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                                <div @class([
                                    'max-w-[85%] rounded-2xl px-4 py-2.5',
                                    'rounded-br-sm bg-primary-50 dark:bg-primary-500/15' => $mine,
                                    'rounded-bl-sm bg-slate-100 dark:bg-slate-800' => ! $mine,
                                ])>
                                    <p class="text-xs font-semibold {{ $mine ? 'text-primary-700 dark:text-primary-300' : 'text-slate-600 dark:text-slate-300' }}">
                                        {{ $r->author?->name ?? __('Mantenimiento') }}
                                    </p>
                                    @if ($editingReplyId === $r->id)
                                        <form wire:submit="saveEdit" class="mt-1.5 w-72 max-w-full">
                                            <x-ui.textarea name="editBody" wire:model="editBody" rows="3" />
                                            <div class="mt-1.5 flex gap-2">
                                                <x-ui.button type="submit" size="sm">{{ __('Guardar') }}</x-ui.button>
                                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEdit">{{ __('Cancelar') }}</x-ui.button>
                                            </div>
                                        </form>
                                    @else
                                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $r->body }}</p>
                                        <div class="mt-1 flex items-center gap-2 text-[0.7rem] text-slate-400">
                                            <span>{{ $r->created_at->format('d/m/Y H:i') }}</span>
                                            @if ($mine)
                                                @can('update', $t)
                                                    <button type="button" wire:click="startEdit({{ $r->id }})" class="hover:text-slate-600 dark:hover:text-slate-200">{{ __('Editar') }}</button>
                                                    <button type="button" wire:click="deleteReply({{ $r->id }})" wire:confirm="{{ __('¿Eliminar este mensaje?') }}" class="hover:text-rose-500">{{ __('Eliminar') }}</button>
                                                @endcan
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form wire:submit="reply" class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <x-ui.textarea name="replyForm.body" wire:model="replyForm.body" rows="3"
                            placeholder="{{ __('Escribe una respuesta…') }}" />
                        <div class="mt-2 flex justify-end">
                            <x-ui.button type="submit">{{ __('Responder') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card>
                    <x-ui.empty-state title="{{ __('Selecciona una incidencia') }}"
                        description="{{ __('Elige una de la lista para ver el hilo, o abre una nueva.') }}" />
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- Modal: nueva incidencia --}}
    <x-modal name="ticket-form" max-width="lg">
        <form wire:submit="save" class="p-5">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Nueva incidencia') }}</h3>

            <div class="mt-4 space-y-4">
                <x-ui.input name="form.subject" wire:model="form.subject" :label="__('Asunto')" />

                <x-ui.select name="form.category" wire:model="form.category" :label="__('Tipo')"
                    :placeholder="__('Elige un tipo…')">
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.textarea name="form.body" wire:model="form.body" rows="5" :label="__('Descripción')"
                    :help="__('Cuenta qué falla o qué necesitas, con el máximo detalle posible.')" />
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Enviar') }}</x-ui.button>
            </div>
        </form>
    </x-modal>
</div>
