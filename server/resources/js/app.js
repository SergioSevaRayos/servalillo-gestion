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
    | Selector de día del chofer (Bloque 7): carrusel tipo "coverflow". El día en foco va en
    | el centro, a tamaño real; los vecinos se ven cada vez más pequeños, girados y difuminados
    | (planos de fondo). Se mueve arrastrando la tira, girando la rueda del ratón encima, con las
    | flechas o tocando un día. El desplazamiento durante el gesto es puramente cliente (`offset`
    | fraccional, animación CSS); al soltar se redondea al día más cercano y se avisa al servidor
    | una sola vez con `selectDay(fecha)` (que recarga la ruta y actualiza la URL). El servidor
    | sigue siendo la fuente de verdad de `date`: si cambia por fuera ("Ir a hoy"), el carrusel
    | se recoloca.
    */
    const isoOf = (d) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const addDays = (iso, n) => {
        const d = new Date(`${iso}T00:00:00`);
        d.setDate(d.getDate() + n);
        return d;
    };

    Alpine.data('dayCarousel', ({ initial, today }) => ({
        RANGE: 4,          // días renderizados a cada lado del foco
        STEP_DRAG: 60,     // px de arrastre por día
        LETTERS: ['D', 'L', 'M', 'X', 'J', 'V', 'S'],
        todayIso: today,
        center: initial,   // fecha en foco (fuente de verdad visual)
        offset: 0,         // desplazamiento fraccional durante el gesto
        dragging: false,
        _lastX: 0,
        _moved: false,
        _gestureEndAt: 0,
        _commitTimer: null,

        init() {
            this.$wire.$watch('date', (v) => {
                if (v && v !== this.center) {
                    this.center = v;
                    this.offset = 0;
                }
            });
        },

        days() {
            const out = [];
            for (let i = -this.RANGE; i <= this.RANGE; i++) {
                const d = addDays(this.center, i);
                out.push({ i, iso: isoOf(d), day: d.getDate(), dow: d.getDay() });
            }
            return out;
        },

        focusIndex() {
            return Math.round(this.offset);
        },

        style(i) {
            const p = i - this.offset;
            const a = Math.abs(p);
            const x = p * 58 - Math.sign(p) * Math.min(a, 3) * 6;
            const scale = Math.max(0.5, 1 - a * 0.17);
            const rot = Math.max(-38, Math.min(38, -p * 18));
            const opacity = a > this.RANGE - 0.5 ? 0 : Math.max(0.12, 1 - a * 0.3);
            return `transform: translateX(${x}px) translateZ(${-a * 42}px) rotateY(${rot}deg) scale(${scale}); opacity:${opacity}; z-index:${100 - Math.round(a * 10)};`;
        },

        scheduleCommit() {
            clearTimeout(this._commitTimer);
            this._commitTimer = setTimeout(() => this.commit(), 200);
        },

        commit() {
            const step = Math.round(this.offset);
            this.offset = 0;
            if (step !== 0) {
                this.center = isoOf(addDays(this.center, step));
                this.$wire.selectDay(this.center);
            }
        },

        nudge(dir) {
            this.offset += dir;
            this.scheduleCommit();
        },

        tap(d) {
            // Ignora el click sintético que el navegador dispara justo al soltar un arrastre.
            if (Date.now() - this._gestureEndAt < 350) return;
            if (d.i === this.focusIndex()) return;
            this.offset = d.i;
            this.scheduleCommit();
        },

        onWheel(e) {
            const raw = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
            if (! raw) return;
            e.preventDefault();
            this.offset += raw / 90;
            this.offset = Math.max(-this.RANGE, Math.min(this.RANGE, this.offset));
            this.scheduleCommit();
        },

        onPointerDown(e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            clearTimeout(this._commitTimer);
            this.dragging = true;
            this._moved = false;
            this._lastX = e.clientX;
            try { this.$el.setPointerCapture(e.pointerId); } catch { /* pointer sin id */ }
        },

        onPointerMove(e) {
            if (! this.dragging) return;
            const dx = e.clientX - this._lastX;
            this._lastX = e.clientX;
            if (Math.abs(dx) > 0) this._moved = true;
            // arrastrar a la izquierda (dx < 0) trae los días siguientes al foco
            this.offset -= dx / this.STEP_DRAG;
            this.offset = Math.max(-this.RANGE, Math.min(this.RANGE, this.offset));
        },

        onPointerUp(e) {
            if (! this.dragging) return;
            this.dragging = false;
            try { this.$el.releasePointerCapture(e.pointerId); } catch { /* noop */ }
            if (this._moved) this._gestureEndAt = Date.now();
            this._moved = false;
            this.scheduleCommit();
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
