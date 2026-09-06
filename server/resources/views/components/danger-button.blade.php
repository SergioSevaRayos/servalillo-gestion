<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-soft transition duration-150 ease-out hover:bg-rose-700 active:bg-rose-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:focus-visible:ring-offset-slate-950']) }}>
    {{ $slot }}
</button>
