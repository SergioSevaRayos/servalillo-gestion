import Sortable from 'sortablejs';
import Chart from 'chart.js/auto';
import SignaturePad from 'signature_pad';

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
| Selector de dígitos estilo "ruleta" (contador del chofer, Bloque 7). Una columna por dígito,
| scroll-snap vertical. El valor = concatenación de los dígitos centrados. Se integra con Livewire
| vía x-modelable + wire:model (ver <x-ui.digit-wheel>).
| El alto de item (44px) está fijado también en app.css (.digit-wheel__item h-11).
*/
const WHEEL_ITEM_H = 44;

/*
| Firma del cliente (albarán, Bloque 8). Canvas + signature_pad; exporta un PNG data URL a la
| propiedad Livewire indicada por wire:model (vía x-modelable). Se re-dimensiona al abrir el modal
| (un canvas con display:none tiene tamaño 0) escuchando open-modal, igual que <x-ui.digit-wheel>.
*/
document.addEventListener('alpine:init', () => {
    Alpine.data('signaturePad', ({ syncOn }) => ({
        value: '',
        pad: null,

        init() {
            const canvas = this.$refs.canvas;
            this.pad = new SignaturePad(canvas, {
                penColor: getComputedStyle(document.documentElement).getPropertyValue('--sig-ink') || '#0f172a',
            });
            this.pad.addEventListener('endStroke', () => {
                this.value = this.pad.isEmpty() ? '' : this.pad.toDataURL('image/png');
            });
            this.$nextTick(() => this.resize());
            window.addEventListener('resize', () => this.resize());
            if (syncOn) {
                window.addEventListener('open-modal', (e) => {
                    if (e.detail == syncOn) {
                        setTimeout(() => this.resize(), 80);
                    }
                });
            }
        },

        // Ajusta el buffer del canvas al tamaño real en pantalla (retina) sin perder el trazo.
        resize() {
            const canvas = this.$refs.canvas;
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const data = this.pad.toData();
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            this.pad.clear();
            if (data.length) {
                this.pad.fromData(data);
                this.value = this.pad.toDataURL('image/png');
            }
        },

        clear() {
            this.pad.clear();
            this.value = '';
        },
    }));

    Alpine.data('digitWheel', ({ count, initial, model }) => ({
        count,
        model,
        value: Number(initial) || 0,
        _lock: false,

        init() {
            // Cambios que vienen de fuera (servidor vía wire:model) reposicionan las ruletas.
            this.$watch('value', (v) => {
                if (! this._lock) {
                    this.write(v);
                }
            });
            this.$nextTick(() => this.write(this.value));
        },

        // El modal acaba de abrirse (estaba display:none). El servidor pudo fijar el valor justo antes,
        // así que lo re-leemos de Livewire y colocamos las ruletas cuando ya hay layout.
        resync() {
            const pull = () => {
                if (this.model && this.$wire) {
                    const v = Number(this.$wire.get(this.model));
                    if (! Number.isNaN(v)) {
                        this._lock = true;
                        this.value = v;
                        this.$nextTick(() => { this._lock = false; });
                    }
                }
                this.write(this.value);
            };
            requestAnimationFrame(() => setTimeout(pull, 80));
        },

        cols() {
            return Array.from(this.$refs.cols.querySelectorAll('[data-col]'));
        },

        write(n) {
            const s = String(Math.max(0, Math.floor(Number(n) || 0)))
                .padStart(this.count, '0')
                .slice(-this.count);

            this.cols().forEach((el, i) => {
                el.scrollTop = Number(s[i]) * WHEEL_ITEM_H;
            });
        },

        onScroll() {
            const str = this.cols()
                .map((el) => Math.max(0, Math.min(9, Math.round(el.scrollTop / WHEEL_ITEM_H))))
                .join('');

            const next = parseInt(str || '0', 10);

            if (next !== this.value) {
                this._lock = true;
                this.value = next;
                this.$nextTick(() => { this._lock = false; });
            }
        },
    }));

    /*
    | Selector de día del chofer (Bloque 7): carrusel tipo "coverflow" sobre scroll nativo con
    | scroll-snap horizontal (`scroll-snap-type: x mandatory` + `scroll-snap-align: center` en
    | cada día — igual mecanismo que el dial de litros de digitWheel). El navegador aporta el
    | "imán": al dejar de arrastrar/rodar, encaja solo en el día más centrado. El efecto coverflow
    | (giro/escala/opacidad según distancia al centro) lo pinta `paint()` en cada evento `scroll`.
    | Cuando el scroll se asienta, `settle()` mira qué día quedó centrado y avisa al servidor una
    | sola vez con `selectDay(fecha)`. El servidor sigue siendo la fuente de verdad de `date`: si
    | cambia por fuera ("Hoy"), el carrusel se recoloca.
    */
    const isoOf = (d) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const addDays = (iso, n) => {
        const d = new Date(`${iso}T00:00:00`);
        d.setDate(d.getDate() + n);
        return d;
    };

    Alpine.data('dayCarousel', ({ initial, today }) => ({
        RANGE: 14,         // días renderizados a cada lado del ancla
        LETTERS: ['D', 'L', 'M', 'X', 'J', 'V', 'S'],
        todayIso: today,
        center: initial,   // ancla de la lista renderizada (solo se mueve al re-anclar/recolocar)
        focusIso: initial, // día actualmente centrado
        _raf: null,
        _settleTimer: null,

        init() {
            this.$wire.$watch('date', (v) => {
                if (v && v !== this.focusIso) {
                    this.center = v;
                    this.focusIso = v;
                    this.$nextTick(() => this.recenter(false));
                }
            });
            this.$nextTick(() => requestAnimationFrame(() => this.recenter(false)));
        },

        days() {
            const out = [];
            for (let i = -this.RANGE; i <= this.RANGE; i++) {
                const d = addDays(this.center, i);
                out.push({ i, iso: isoOf(d), day: d.getDate(), dow: d.getDay() });
            }
            return out;
        },

        itemEls() {
            return Array.from(this.$refs.track.querySelectorAll('[data-day]'));
        },

        elFor(iso) {
            return this.$refs.track.querySelector(`[data-day="${CSS.escape(iso)}"]`);
        },

        nearestEl() {
            const s = this.$refs.scroller;
            const mid = s.scrollLeft + s.clientWidth / 2;
            let best = null;
            let bestD = Infinity;
            this.itemEls().forEach((el) => {
                const d = Math.abs(el.offsetLeft + el.clientWidth / 2 - mid);
                if (d < bestD) { bestD = d; best = el; }
            });
            return best;
        },

        // Coloca el día en foco en el centro sin avisar al servidor (carga inicial, "Hoy", re-anclado).
        // No hace falta silenciar settle(): al quedar centrado el propio focusIso, settle() no reenvía.
        recenter(smooth) {
            const el = this.elFor(this.focusIso);
            const s = this.$refs.scroller;
            if (! el || ! s) return;
            s.scrollTo({
                left: el.offsetLeft - (s.clientWidth - el.clientWidth) / 2,
                behavior: smooth ? 'smooth' : 'auto',
            });
            this.paint();
        },

        onScroll() {
            if (! this._raf) {
                this._raf = requestAnimationFrame(() => { this._raf = null; this.paint(); });
            }
            clearTimeout(this._settleTimer);
            this._settleTimer = setTimeout(() => this.settle(), 140);
        },

        paint() {
            const s = this.$refs.scroller;
            if (! s) return;
            const mid = s.scrollLeft + s.clientWidth / 2;
            this.itemEls().forEach((el) => {
                const p = (el.offsetLeft + el.clientWidth / 2 - mid) / el.clientWidth;
                const a = Math.min(Math.abs(p), this.RANGE);
                const scale = Math.max(0.55, 1 - a * 0.16);
                const rot = Math.max(-42, Math.min(42, -p * 20));
                el.style.transform = `perspective(600px) translateZ(${(-a * 40).toFixed(1)}px) rotateY(${rot.toFixed(1)}deg) scale(${scale.toFixed(3)})`;
                el.style.opacity = Math.max(0.12, 1 - a * 0.32).toFixed(3);
                el.style.zIndex = String(100 - Math.round(a * 10));
                el.classList.toggle('is-focus', a < 0.5);
            });
        },

        settle() {
            const best = this.nearestEl();
            if (! best) return;
            const iso = best.dataset.day;
            if (iso !== this.focusIso) {
                this.focusIso = iso;
                this.$wire.selectDay(iso);
            }
            const els = this.itemEls();
            const idx = els.indexOf(best);
            if (idx > -1 && (idx < 4 || idx > els.length - 5)) {
                this.center = iso;
                this.$nextTick(() => this.recenter(false));
            }
        },

        nudge(dir) {
            const els = this.itemEls();
            // Parte del día realmente centrado ahora (no de focusIso, que va un paso por detrás
            // hasta que el servidor responde) para que dos flechazos seguidos sumen.
            const base = this.nearestEl();
            const i = base ? els.indexOf(base) : els.findIndex((el) => el.dataset.day === this.focusIso);
            const target = els[i + dir];
            if (target) target.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        },

        tap(iso) {
            const el = this.elFor(iso);
            if (el) el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        },
    }));
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

/*
| Panel de inicio de Mantenimiento (Bloque 12). Mismo patrón que statsCharts: los <canvas>
| viven en un bloque wire:ignore; el componente Livewire emite `maint-stats-updated` con los
| datasets nuevos al cambiar de rango y aquí solo hacemos chart.update().
*/
const AMBER = '#f59e0b';
const SLATE = '#64748b';

window.maintenanceCharts = function (initial) {
    return {
        _cleanup: null,

        init() {
            const charts = {};

            charts.errors = new Chart(this.$refs.errors, {
                type: 'line',
                data: {
                    labels: initial.errorsDaily.labels,
                    datasets: [{ label: 'Errores', data: initial.errorsDaily.data, borderColor: ROSE, backgroundColor: 'rgba(244,63,94,0.12)', fill: true, tension: 0.3, pointRadius: 0 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { display: false } },
                },
            });

            charts.tickets = new Chart(this.$refs.tickets, {
                type: 'bar',
                data: {
                    labels: initial.ticketsDaily.labels,
                    datasets: [
                        { label: 'Abiertas', data: initial.ticketsDaily.opened, backgroundColor: AMBER },
                        { label: 'Resueltas', data: initial.ticketsDaily.resolved, backgroundColor: TEAL },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom' } },
                },
            });

            charts.audits = new Chart(this.$refs.audits, {
                type: 'bar',
                data: {
                    labels: initial.auditsDaily.labels,
                    datasets: [{ label: 'Cambios', data: initial.auditsDaily.data, backgroundColor: 'rgba(100,116,139,0.55)' }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { display: false } },
                },
            });

            charts.category = new Chart(this.$refs.category, {
                type: 'doughnut',
                data: {
                    labels: initial.ticketsByCategory.labels,
                    datasets: [{ data: initial.ticketsByCategory.data, backgroundColor: [ROSE, AMBER, SLATE], borderWidth: 0 }],
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } },
            });

            const onUpdate = (e) => {
                const d = e.detail.charts;

                charts.errors.data.labels = d.errorsDaily.labels;
                charts.errors.data.datasets[0].data = d.errorsDaily.data;
                charts.errors.update();

                charts.tickets.data.labels = d.ticketsDaily.labels;
                charts.tickets.data.datasets[0].data = d.ticketsDaily.opened;
                charts.tickets.data.datasets[1].data = d.ticketsDaily.resolved;
                charts.tickets.update();

                charts.audits.data.labels = d.auditsDaily.labels;
                charts.audits.data.datasets[0].data = d.auditsDaily.data;
                charts.audits.update();

                charts.category.data.datasets[0].data = d.ticketsByCategory.data;
                charts.category.update();
            };

            window.addEventListener('maint-stats-updated', onUpdate);

            this._cleanup = () => {
                window.removeEventListener('maint-stats-updated', onUpdate);
                Object.values(charts).forEach((c) => c.destroy());
            };
        },

        destroy() {
            this._cleanup?.();
        },
    };
};
