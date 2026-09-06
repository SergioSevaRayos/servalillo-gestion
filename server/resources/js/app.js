import Sortable from 'sortablejs';
import Chart from 'chart.js/auto';

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

/*
| Panel estadístico (Bloque 5). Los <canvas> viven dentro de un bloque wire:ignore para que
| el morph de Livewire no los toque al cambiar de rango; en su lugar, el componente Livewire
| emite `stats-updated` con los datasets nuevos y aquí solo hacemos chart.update().
|
| Colores: se eligen tonos que funcionan igual en claro y oscuro (slate-400 semitransparente
| para ejes/rejilla), así no hace falta reconstruir los charts al cambiar de tema.
*/
Chart.defaults.font.family = 'inherit';
Chart.defaults.color = 'rgba(100, 116, 139, 0.9)';
Chart.defaults.borderColor = 'rgba(148, 163, 184, 0.18)';

const STATS_PALETTE = ['#0d9488', '#f43f5e', '#f59e0b', '#3b82f6', '#8b5cf6', '#64748b', '#14b8a6'];
const TEAL = '#0d9488';
const ROSE = '#f43f5e';

window.statsCharts = function (initial) {
    return {
        // OJO: los objetos Chart NO se guardan en `this` — Alpine haría reactivo (Proxy) todo el
        // árbol interno de Chart.js, que tiene referencias circulares -> "Maximum call stack size
        // exceeded". Se quedan en variables locales del closure de init(); solo exponemos un
        // _cleanup (una función, que Alpine no recorre) para el teardown.
        _cleanup: null,

        init() {
            const charts = {};

            charts.daily = new Chart(this.$refs.daily, {
                type: 'line',
                data: {
                    labels: initial.daily.labels,
                    datasets: [
                        { label: 'Completadas', data: initial.daily.completed, borderColor: TEAL, backgroundColor: 'rgba(13,148,136,0.12)', fill: true, tension: 0.3, pointRadius: 0 },
                        { label: 'Falladas', data: initial.daily.failed, borderColor: ROSE, backgroundColor: 'rgba(244,63,94,0.12)', fill: true, tension: 0.3, pointRadius: 0 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom' } },
                },
            });

            charts.routeStatus = new Chart(this.$refs.routeStatus, {
                type: 'doughnut',
                data: { labels: initial.routeStatus.labels, datasets: [{ data: initial.routeStatus.data, backgroundColor: STATS_PALETTE, borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } },
            });

            charts.volume = new Chart(this.$refs.volume, {
                type: 'doughnut',
                data: { labels: initial.volumeByType.labels, datasets: [{ data: initial.volumeByType.delivered, backgroundColor: STATS_PALETTE, borderWidth: 0 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => `${c.label}: ${c.parsed.toLocaleString('es-ES')} L` } } },
                },
            });

            charts.driver = new Chart(this.$refs.driver, {
                type: 'bar',
                data: {
                    labels: initial.byDriver.labels,
                    datasets: [
                        { label: 'Completadas', data: initial.byDriver.completed, backgroundColor: TEAL },
                        { label: 'Falladas', data: initial.byDriver.failed, backgroundColor: ROSE },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom' } },
                },
            });

            const onUpdate = (e) => {
                const d = e.detail.charts;

                charts.daily.data.labels = d.daily.labels;
                charts.daily.data.datasets[0].data = d.daily.completed;
                charts.daily.data.datasets[1].data = d.daily.failed;
                charts.daily.update();

                charts.routeStatus.data.labels = d.routeStatus.labels;
                charts.routeStatus.data.datasets[0].data = d.routeStatus.data;
                charts.routeStatus.update();

                charts.volume.data.labels = d.volumeByType.labels;
                charts.volume.data.datasets[0].data = d.volumeByType.delivered;
                charts.volume.update();

                charts.driver.data.labels = d.byDriver.labels;
                charts.driver.data.datasets[0].data = d.byDriver.completed;
                charts.driver.data.datasets[1].data = d.byDriver.failed;
                charts.driver.update();
            };

            window.addEventListener('stats-updated', onUpdate);

            this._cleanup = () => {
                window.removeEventListener('stats-updated', onUpdate);
                Object.values(charts).forEach((c) => c.destroy());
            };
        },

        destroy() {
            this._cleanup?.();
        },
    };
};
