<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ \App\Support\Theme::htmlClass() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($header) ? Str::of($header)->toString() : config('app.name') }}</title>

        @include('partials.theme-init')
        @include('partials.pwa-meta')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen">
            <livewire:layout.navigation />

            @if (isset($header))
                <header class="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8">
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">
                        {{ $header }}
                    </h1>
                </header>
            @endif

            <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>
        </div>

        <x-ui.toast-container />
    </body>
</html>
