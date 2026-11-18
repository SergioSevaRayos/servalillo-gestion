{{-- Raíz única siempre presente (aunque esté vacía) para que el poll siga vivo. --}}
<span wire:poll.20s>
    @if ($count > 0)
        <span class="rounded-full {{ $color }} px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">{{ $count }}</span>
    @endif
</span>
