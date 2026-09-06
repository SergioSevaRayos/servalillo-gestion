{{-- Se aplica el tema ANTES de pintar la página, para evitar parpadeo (FOUC). --}}
<script>
    (function () {
        try {
            var match = document.cookie.match(/(?:^|; )theme=([^;]*)/);
            var theme = match ? decodeURIComponent(match[1]) : @json(auth()->user()?->theme_preference?->value ?? 'system');
            var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.dataset.theme = theme;
        } catch (e) {}
    })();
</script>
