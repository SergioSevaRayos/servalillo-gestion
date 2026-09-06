import Sortable from 'sortablejs';

function applyThemeClass(value) {
    const isDark = value === 'dark'
        || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.dataset.theme = value;
}

document.addEventListener('alpine:init', () => {
    Alpine.store('theme', {
        current: document.documentElement.dataset.theme || 'system',

        set(value) {
            this.current = value;
            applyThemeClass(value);

            document.cookie = `theme=${value};path=/;max-age=31536000;samesite=lax`;

            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            fetch('/theme', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({ theme: value }),
                keepalive: true,
            }).catch(() => {
                // Sin conexión: la cookie ya quedó puesta, se sincronizará con la BD en el próximo intento.
            });
        },
    });

    // Si el usuario está en "automático" y el SO cambia de esquema, seguirlo sin recargar.
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
        if (Alpine.store('theme').current === 'system') {
            document.documentElement.classList.toggle('dark', event.matches);
        }
    });
});

/*
| wire:navigate sustituye el <body> y sincroniza los atributos del <html> con los de la
| página recién cargada — como el atributo `class="dark"` lo pone JS en tiempo de ejecución
| (nunca se renderiza en el servidor), esa sincronización lo borra en cada navegación SPA.
| El store de Alpine sí sobrevive (el runtime de JS no se recarga), así que basta con
| reaplicar la clase a partir de su valor tras cada navegación.
*/
document.addEventListener('livewire:navigated', () => {
    if (window.Alpine?.store('theme')) {
        applyThemeClass(Alpine.store('theme').current);
    }
});

/*
| Tablero Kanban de rutas (Bloque 4): cada <ul data-stop-list> es una columna. `group` compartido
| permite arrastrar tarjetas entre columnas, no solo reordenar dentro de una. El contenedor persiste
| entre renders de Livewire (mismo wire:key en los <li>), así que no hace falta destruir/recrear la
| instancia de Sortable en cada actualización — solo evitamos doble inicialización con `_sortable`.
*/
function initKanbanColumns(root) {
    root.querySelectorAll('[data-stop-list]').forEach((list) => {
        if (list._sortable) {
            return;
        }

        list._sortable = new Sortable(list, {
            group: 'route-stops',
            // "animation" es lo que produce el efecto Trello de las tarjetas vecinas
            // desplazándose para hacer hueco a la que llega. Necesita que las tarjetas NO
            // tengan su propia transición CSS de "transform" compitiendo (ver stop-card.blade.php).
            animation: 220,
            easing: 'cubic-bezier(0.2, 0, 0.2, 1)',
            // Sin esto, Sortable usa el drag-and-drop NATIVO del navegador: el "fantasma" que
            // arrastras es una foto que pinta el propio navegador (nuestro dragClass/chosenClass
            // apenas se nota) y el reflow de las tarjetas vecinas se ve a saltos, sobre todo al
            // cruzar a otra columna. forceFallback hace que Sortable dibuje el arrastre entero
            // con JS/CSS propios — ahí sí se ve el "hacer hueco" suave. fallbackOnBody evita que
            // el overflow-y-auto de la columna recorte la tarjeta mientras se mueve entre columnas.
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 3,
            ghostClass: 'opacity-40',
            chosenClass: 'stop-card-chosen',
            dragClass: 'shadow-soft-lg',
            delay: 80,
            delayOnTouchOnly: true,
            // Solo las paradas "pendientes" se pueden arrastrar (ver <x-routes.stop-card>);
            // las en curso/completadas se quedan fijas. preventOnFilter:false para que el
            // click de una tarjeta filtrada siga abriendo el modal de edición (wire:click).
            filter: '[data-draggable="false"]',
            preventOnFilter: false,
            onEnd(evt) {
                const toIds = Array.from(evt.to.children).map((el) => el.dataset.stopId);
                const fromIds = evt.from === evt.to
                    ? toIds
                    : Array.from(evt.from.children).map((el) => el.dataset.stopId);

                window.dispatchEvent(new CustomEvent('stops-reordered', {
                    detail: {
                        fromRouteId: evt.from.dataset.routeId ? parseInt(evt.from.dataset.routeId, 10) : null,
                        fromIds,
                        toRouteId: evt.to.dataset.routeId ? parseInt(evt.to.dataset.routeId, 10) : null,
                        toIds,
                    },
                }));
            },
        });
    });
}

document.addEventListener('DOMContentLoaded', () => initKanbanColumns(document));
document.addEventListener('livewire:navigated', () => initKanbanColumns(document));
document.addEventListener('livewire:init', () => {
    Livewire.hook('morph.updated', ({ el }) => initKanbanColumns(el));
});
