// Service worker mínimo: solo existe para que Android/Chrome considere la web
// "instalable" (icono en el escritorio, ventana sin barra de navegador). No
// cachea nada — cada petición sigue yendo a la red, igual que sin él, para no
// arriesgar servir contenido de Livewire/GPS desactualizado.
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Sin respondWith(): el navegador hace la petición normal a la red.
});
