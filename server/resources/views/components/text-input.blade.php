@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 bg-white shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 sm:text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500']) }}>
