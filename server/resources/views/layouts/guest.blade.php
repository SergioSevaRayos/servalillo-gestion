<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ \App\Support\Theme::htmlClass() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name') }}</title>

        @include('partials.theme-init')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="relative flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-10 dark:bg-slate-950 sm:px-6">
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute -top-24 left-1/2 h-72 w-[36rem] -translate-x-1/2 rounded-full bg-primary-400/20 blur-3xl dark:bg-primary-500/10"></div>
            </div>

            <div class="absolute right-4 top-4">
                <x-ui.theme-toggle />
            </div>

            <a href="/" wire:navigate class="relative z-10 mb-6 flex items-center gap-2 text-primary-700 dark:text-primary-300">
                <x-application-logo class="h-10 w-10" />
                <span class="text-lg font-semibold tracking-tight text-slate-800 dark:text-slate-100">
                    {{ config('app.name') }}
                </span>
            </a>

            <div class="surface relative z-10 w-full max-w-md rounded-2xl p-6 sm:p-8">
                {{ $slot }}
            </div>
        </div>

        <x-ui.toast-container />
    </body>
</html>
