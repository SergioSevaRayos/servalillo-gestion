{{-- Instalable en Android/escritorio (icono, ventana standalone) — sin offline real. --}}
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1f7489">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Servalillo">
<link rel="apple-touch-icon" href="/images/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="/images/icons/icon-192.png">

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
    }
</script>
