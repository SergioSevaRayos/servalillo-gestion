import Sortable from 'sortablejs';
import Chart from 'chart.js/auto';
import SignaturePad from 'signature_pad';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import * as THREE from 'three';

function applyThemeClass(value) {
    const isDark = value === 'dark'
        || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.dataset.theme = value;
}

/*
| Enlace a Google Maps para navegar unas paradas (equivalente JS de App\Support\GoogleMaps).
| Prioriza las pendientes; sin `origin` → Google usa el GPS del móvil. Máx. 9 waypoints.
*/
function googleMapsDirectionsUrl(stops) {
    const list = Array.isArray(stops) ? stops : [];
    const pending = list.filter((s) => s && s.status === 'pending');
    const use = (pending.length ? pending : list)
        .filter((s) => s && typeof s.lat === 'number' && typeof s.lng === 'number');

    if (! use.length) return '';

    const fmt = (s) => `${(+s.lat).toFixed(6)},${(+s.lng).toFixed(6)}`;
    const base = 'https://www.google.com/maps/dir/?api=1&travelmode=driving';
    const destination = fmt(use[use.length - 1]);
    const waypoints = use.slice(0, -1).slice(0, 9).map(fmt);

    return `${base}&destination=${destination}`
        + (waypoints.length ? `&waypoints=${waypoints.join('%7C')}` : '');
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
            // Una parada cerrada es un muro: además de no poder cogerla, tampoco se aparta
            // para "hacer hueco" a otra. Sin esto SortableJS la anima desplazándose y, aunque
            // el servidor la devuelva a su sitio, se veía el salto. onMove:false cancela el
            // reordenado cuando el vecino afectado es una tarjeta no arrastrable.
            onMove(evt) {
                return evt.related?.dataset.draggable !== 'false';
            },
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

    /*
    | FLIP: cuando el chofer sube/baja una parada (Today::moveStop), Livewire reordena los nodos
    | `[data-stop-row]` (wire:key estable). Antes del commit se guarda la posición de cada fila;
    | tras aplicarse la respuesta se anima el salto de la posición vieja a la nueva.
    */
    Livewire.hook('commit', ({ component, succeed }) => {
        const rows = component.el.querySelectorAll('[data-stop-row]');
        if (rows.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const before = new Map();
        rows.forEach((row) => before.set(row, row.getBoundingClientRect().top));

        succeed(() => requestAnimationFrame(() => {
            component.el.querySelectorAll('[data-stop-row]').forEach((row) => {
                const delta = (before.get(row) ?? 0) - row.getBoundingClientRect().top;
                if (! before.has(row) || Math.abs(delta) < 1) {
                    return;
                }

                row.style.transition = 'none';
                row.style.transform = `translateY(${delta}px)`;

                requestAnimationFrame(() => {
                    row.style.transition = 'transform 260ms cubic-bezier(0.2, 0, 0.2, 1)';
                    row.style.transform = '';
                });

                row.addEventListener('transitionend', () => {
                    row.style.transition = '';
                    row.style.transform = '';
                }, { once: true });
            });
        }));
    });

    /*
    | La web del chofer vive abierta horas en el móvil (wire:poll.15s), con el teléfono
    | bloqueándose y desbloqueándose sin parar. Si la sesión/CSRF caduca mientras estaba de
    | fondo, cada petición del poll falla en silencio (419/401) y el chofer se queda viendo
    | datos congelados sin ningún aviso — "no cuadra con lo que ve oficina". En vez de reintentar
    | contra una sesión que ya no vale, recarga la página entera: reautentica (redirige a login
    | si hace falta) y vuelve a traer el estado real desde cero.
    */
    Livewire.hook('request', ({ fail }) => {
        fail(({ status }) => {
            if (status === 419 || status === 401) {
                window.location.reload();
            }
        });
    });
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
                // Solo ordena los días entre sí; mantener bajo (el modal es z-50, el nav z-30).
                el.style.zIndex = String(Math.max(0, 10 - Math.round(a)));
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

    /*
    | "Ver recorrido" (Bloque 13): mapa Leaflet con las paradas de una ruta y su trazado.
    | El componente Livewire (Board / Chofer\Today) emite el evento `open-route-map` con
    | { stops, meta, skipped }; aquí abrimos el <x-modal name="route-map"> y, cuando el
    | contenedor ya tiene tamaño (estaba display:none), montamos/actualizamos el mapa.
    */
    Alpine.data('routeMap', () => ({
        map: null,
        layer: null,
        summary: '',
        skippedNote: '',
        approachNote: '',
        vehicleNote: '',
        mapsUrl: '',

        open(detail) {
            this.$dispatch('open-modal', 'route-map');
            this.mapsUrl = googleMapsDirectionsUrl(detail && detail.stops);
            this._whenVisible(() => this.render(detail || {}));
        },

        _whenVisible(cb, tries = 90) {
            const el = this.$refs.map;
            if (el && el.offsetParent !== null && el.clientWidth > 0) return cb();
            if (tries <= 0) return;
            requestAnimationFrame(() => this._whenVisible(cb, tries - 1));
        },

        render(detail) {
            const stops = Array.isArray(detail.stops) ? detail.stops : [];
            const meta = detail.meta || null;

            if (! this.map) {
                // setPrefix(false) quita el "Leaflet |" del pie (la librería no lo exige, es
                // puramente decorativo) y el `attribution` del tileLayer de abajo se dejó abreviado
                // ("Tiles © Esri" en vez del listado completo de proveedores) — el pie ocupaba varias
                // líneas y tapaba el mapa en tarjetas pequeñas. El crédito a Esri SÍ hay que dejarlo
                // (aunque sea corto): es condición de su uso gratuito, como con cualquier proveedor
                // de mosaicos sin API key — quitarlo del todo es la misma clase de problema que ya
                // nos bloqueó con OpenStreetMap y CARTO.
                this.map = L.map(this.$refs.map, { scrollWheelZoom: true });
                this.map.attributionControl.setPrefix(false);
                // Esri World Street Map, NI tile.openstreetmap.org NI CARTO: ambos empezaron a
                // bloquear/marcar de agua los mosaicos sin API key (ver el comentario largo en
                // CLAUDE.md, sección "Ver recorrido" → Gotchas Leaflet). Los tiles REST públicos de
                // ArcGIS Online sí siguen sirviendo sin key para este volumen de uso — comprobado con
                // una descarga real de un tile sobre Alicante antes de fijar esta URL. OJO: el orden
                // de la plantilla de Esri es z/y/x (al revés que OSM/CARTO/la mayoría), por eso el
                // template lleva {y} antes que {x}.
                L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
                    maxZoom: 19,
                    attribution: 'Tiles &copy; Esri',
                }).addTo(this.map);
                this.layer = L.layerGroup().addTo(this.map);
            }

            this.layer.clearLayers();

            const points = stops.map((s) => [s.lat, s.lng]);
            const line = meta && Array.isArray(meta.line) && meta.line.length > 1 ? meta.line : points;

            if (line.length > 1) {
                L.polyline(line, { color: '#0d9488', weight: 4, opacity: 0.85 }).addTo(this.layer);
            }

            stops.forEach((s) => {
                L.marker([s.lat, s.lng], { icon: this._pin(s) })
                    .addTo(this.layer)
                    .bindPopup(() => {
                        const el = document.createElement('div');
                        el.textContent = `${s.n}. ${s.name}`;
                        return el;
                    });
            });

            const bounds = points.slice();

            const v = detail.vehicle || null;
            this.approachNote = '';
            this.vehicleNote = '';
            if (v && typeof v.lat === 'number' && typeof v.lng === 'number') {
                const vpos = [v.lat, v.lng];
                const speedBit = typeof v.speed_kmh === 'number' ? ` · ${v.speed_kmh} km/h` : '';
                this.vehicleNote = `🚚 Camión: ${v.age || 'última señal'}${speedBit}`;

                // Trazado "cómo llegar" del camión a la primera parada (línea ámbar discontinua).
                const approach = v.approach || null;
                const approachLine = approach && Array.isArray(approach.line) && approach.line.length > 1
                    ? approach.line
                    : (v.next_stop ? [vpos, [stops.find((s) => s.n === v.next_stop.n)?.lat, stops.find((s) => s.n === v.next_stop.n)?.lng]] : null);

                if (approachLine && approachLine.every((p) => Array.isArray(p) && typeof p[0] === 'number')) {
                    L.polyline(approachLine, { color: '#f59e0b', weight: 4, opacity: 0.9, dashArray: '2 8', lineCap: 'round' }).addTo(this.layer);
                    approachLine.forEach((p) => bounds.push(p));
                }

                if (approach && approach.distance_m) {
                    this.approachNote = `Del camión a la parada ${v.next_stop ? v.next_stop.n : 1}: ~${(approach.distance_m / 1000).toFixed(1)} km · ~${Math.round((approach.duration_s || 0) / 60)} min`;
                } else if (v.next_stop) {
                    this.approachNote = `El camión va hacia la parada ${v.next_stop.n} (${v.next_stop.name}).`;
                }

                if (typeof v.accuracy_m === 'number' && v.accuracy_m > 0) {
                    L.circle(vpos, { radius: v.accuracy_m, color: '#f59e0b', weight: 1, fillColor: '#f59e0b', fillOpacity: 0.12 }).addTo(this.layer);
                }
                L.marker(vpos, {
                    icon: L.divIcon({
                        className: '',
                        html: '<span class="route-map-vehicle">🚚</span>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15],
                        popupAnchor: [0, -15],
                    }),
                    zIndexOffset: 1000,
                }).addTo(this.layer).bindPopup(() => {
                    const el = document.createElement('div');
                    el.textContent = this.vehicleNote;
                    return el;
                });
                bounds.push(vpos);
            }

            // invalidateSize() PRIMERO: fitBounds() calcula el zoom a partir del tamaño
            // actual del contenedor, y si el modal acaba de pasar de display:none a
            // visible, Leaflet aún tiene en caché el tamaño de antes (0 o el de la última
            // vez) — fitBounds con ese tamaño da un zoom mucho más alejado del real (se
            // veía la Península entera en vez de la zona de la ruta).
            this.map.invalidateSize();

            if (bounds.length === 1) {
                this.map.setView(bounds[0], 15);
            } else if (bounds.length > 1) {
                this.map.fitBounds(L.latLngBounds(bounds).pad(0.15));
            } else {
                this.map.setView([28.46, -16.25], 10); // Tenerife, sin paradas ubicadas
            }

            this.summary = meta && meta.distance_m
                ? `~${(meta.distance_m / 1000).toFixed(1)} km · ~${Math.round((meta.duration_s || 0) / 60)} min`
                : '';

            const skipped = detail.skipped || 0;
            this.skippedNote = skipped === 1
                ? '1 parada sin ubicación no se muestra.'
                : (skipped > 1 ? `${skipped} paradas sin ubicación no se muestran.` : '');
        },

        _pin(s) {
            // Verde más vivo para "completada" (antes #059669, emerald-600, se confundía a golpe
            // de vista con "pendiente") + check y nº de parada juntos ("píldora", ver
            // .route-map-pin--completed): de un vistazo en el mapa se ve CUÁL parada se cerró, no
            // solo que alguna lo está — un check solo no distinguía la 1 de la 4.
            const colors = { pending: '#0d9488', completed: '#10b981', failed: '#e11d48', skipped: '#64748b' };
            const isCompleted = s.status === 'completed';
            const label = isCompleted ? `&check;&nbsp;${s.n}` : s.n;
            const width = isCompleted ? 34 : 26;
            return L.divIcon({
                className: '',
                html: `<span class="route-map-pin${isCompleted ? ' route-map-pin--completed' : ''}" style="background:${colors[s.status] || '#0d9488'}">${label}</span>`,
                iconSize: [width, 26],
                iconAnchor: [width / 2, 13],
                popupAnchor: [0, -13],
            });
        },

        destroy() {
            this.map?.remove();
            this.map = null;
        },
    }));

    /*
    | "Localizar dispositivo" (Bloque 11): mapa Leaflet con la última ubicación conocida de
    | un dispositivo tracker + rastro de posiciones recientes. Maintenance\Devices::locate()
    | emite `open-device-map` con { label, driver, last, trail }.
    */
    Alpine.data('deviceMap', () => ({
        map: null,
        layer: null,
        title: '',
        subtitle: '',
        age: '',
        detail: '',

        open(payload) {
            this.$dispatch('open-modal', 'device-map');
            this._whenVisible(() => this.render(payload || {}));
        },

        _whenVisible(cb, tries = 90) {
            const el = this.$refs.map;
            if (el && el.offsetParent !== null && el.clientWidth > 0) return cb();
            if (tries <= 0) return;
            requestAnimationFrame(() => this._whenVisible(cb, tries - 1));
        },

        render(payload) {
            const last = payload.last || null;
            const trail = Array.isArray(payload.trail) ? payload.trail : [];

            this.title = payload.label || 'Dispositivo';
            this.subtitle = payload.driver ? `Chofer: ${payload.driver}` : 'Sin chofer asignado';

            if (! last) {
                this.age = '';
                this.detail = 'Sin ubicación.';
                return;
            }

            if (! this.map) {
                // setPrefix(false) quita el "Leaflet |" del pie (la librería no lo exige, es
                // puramente decorativo) y el `attribution` del tileLayer de abajo se dejó abreviado
                // ("Tiles © Esri" en vez del listado completo de proveedores) — el pie ocupaba varias
                // líneas y tapaba el mapa en tarjetas pequeñas. El crédito a Esri SÍ hay que dejarlo
                // (aunque sea corto): es condición de su uso gratuito, como con cualquier proveedor
                // de mosaicos sin API key — quitarlo del todo es la misma clase de problema que ya
                // nos bloqueó con OpenStreetMap y CARTO.
                this.map = L.map(this.$refs.map, { scrollWheelZoom: true });
                this.map.attributionControl.setPrefix(false);
                // Esri World Street Map, NI tile.openstreetmap.org NI CARTO: ambos empezaron a
                // bloquear/marcar de agua los mosaicos sin API key (ver el comentario largo en
                // CLAUDE.md, sección "Ver recorrido" → Gotchas Leaflet). Los tiles REST públicos de
                // ArcGIS Online sí siguen sirviendo sin key para este volumen de uso — comprobado con
                // una descarga real de un tile sobre Alicante antes de fijar esta URL. OJO: el orden
                // de la plantilla de Esri es z/y/x (al revés que OSM/CARTO/la mayoría), por eso el
                // template lleva {y} antes que {x}.
                L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
                    maxZoom: 19,
                    attribution: 'Tiles &copy; Esri',
                }).addTo(this.map);
                this.layer = L.layerGroup().addTo(this.map);
            }

            this.layer.clearLayers();

            if (trail.length > 1) {
                L.polyline(trail, { color: '#0d9488', weight: 3, opacity: 0.6, dashArray: '4 6' }).addTo(this.layer);
            }

            const pos = [last.lat, last.lng];

            if (typeof last.accuracy_m === 'number' && last.accuracy_m > 0) {
                L.circle(pos, {
                    radius: last.accuracy_m,
                    color: '#0d9488',
                    weight: 1,
                    fillColor: '#0d9488',
                    fillOpacity: 0.12,
                }).addTo(this.layer);
            }

            L.marker(pos, {
                icon: L.divIcon({
                    className: '',
                    html: '<span class="route-map-pin" style="background:#0d9488">•</span>',
                    iconSize: [26, 26],
                    iconAnchor: [13, 13],
                }),
            }).addTo(this.layer);

            this.map.invalidateSize();
            this.map.setView(pos, 16);

            this.age = this._age(last.recorded_at);

            const bits = [];
            if (typeof last.accuracy_m === 'number') bits.push(`precisión ±${Math.round(last.accuracy_m)} m`);
            if (typeof last.speed_mps === 'number' && last.speed_mps >= 0) {
                bits.push(`${Math.round(last.speed_mps * 3.6)} km/h`);
            }
            if (typeof last.battery_level === 'number') bits.push(`batería ${last.battery_level}%`);
            bits.push(`${last.lat.toFixed(6)}, ${last.lng.toFixed(6)}`);
            this.detail = bits.join(' · ');
        },

        _age(iso) {
            if (! iso) return '';
            const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
            if (secs < 60) return `hace ${secs} s`;
            if (secs < 3600) return `hace ${Math.round(secs / 60)} min`;
            if (secs < 86400) return `hace ${Math.round(secs / 3600)} h`;
            return `hace ${Math.round(secs / 86400)} d`;
        },

        destroy() {
            this.map?.remove();
            this.map = null;
        },
    }));

    /*
    | Resumen para copiar y mandar por WhatsApp al registrar un pre-cliente (pendiente de
    | valoración). El componente Livewire (Clients\Index) emite `open-prospect-summary` con
    | { text }.
    */
    Alpine.data('prospectSummary', () => ({
        text: '',
        copied: false,

        open(detail) {
            this.$dispatch('open-modal', 'prospect-summary');
            this.text = (detail && detail.text) || '';
            this.copied = false;
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.text);
            } catch {
                // Sin permiso/API de portapapeles (http no seguro, navegador antiguo…): selecciona
                // el texto para que el usuario pueda copiarlo a mano con Ctrl/Cmd+C.
                this.$refs.text?.select();
            }
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 2000);
        },
    }));

    /*
    | Selector de fecha propio (<x-ui.date-input>). El calendario nativo del navegador no se
    | puede estilar, así que se sustituye por este popover con los tokens del sistema. Se integra
    | con Livewire vía x-modelable + wire:model; `value` es la fecha ISO ('YYYY-MM-DD') o ''.
    | El panel se teletransporta a <body> para que no lo recorte el overflow de un modal.
    */
    Alpine.data('datePicker', ({ initial, min, max, model }) => ({
        value: initial || '',
        model,
        open: false,
        viewYear: 2000,
        viewMonth: 0,
        min: min || null,
        max: max || null,
        panelStyle: '',
        weekdays: ['L', 'M', 'X', 'J', 'V', 'S', 'D'],
        _onDoc: null,
        _onReflow: null,

        init() {
            // El valor real lo tiene el servidor (wire:model); lo leemos al montar.
            if (this.model && this.$wire) {
                const v = this.$wire.get(this.model);
                if (v) {
                    this.value = v;
                }
            }

            this.syncView();
            this.$watch('value', () => {
                if (! this.open) {
                    this.syncView();
                }
            });
        },

        destroy() {
            this._teardownListeners();
        },

        iso(d) {
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        },

        syncView() {
            const base = this.value ? new Date(this.value + 'T00:00:00') : new Date();
            this.viewYear = base.getFullYear();
            this.viewMonth = base.getMonth();
        },

        get displayValue() {
            if (! this.value) {
                return '';
            }

            return new Date(this.value + 'T00:00:00')
                .toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
        },

        get monthLabel() {
            const label = new Date(this.viewYear, this.viewMonth, 1)
                .toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });

            return label.charAt(0).toUpperCase() + label.slice(1);
        },

        get weeks() {
            const first = new Date(this.viewYear, this.viewMonth, 1);
            const offset = (first.getDay() + 6) % 7; // ISO: lunes primero
            const start = new Date(this.viewYear, this.viewMonth, 1 - offset);
            const todayIso = this.iso(new Date());
            const weeks = [];

            for (let w = 0; w < 6; w++) {
                const days = [];

                for (let d = 0; d < 7; d++) {
                    const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + w * 7 + d);
                    const iso = this.iso(day);

                    days.push({
                        iso,
                        label: day.getDate(),
                        inMonth: day.getMonth() === this.viewMonth,
                        isToday: iso === todayIso,
                        disabled: (this.min && iso < this.min) || (this.max && iso > this.max),
                    });
                }

                weeks.push(days);
            }

            return weeks;
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.syncView();
            this.open = true;
            this.$nextTick(() => {
                this.position();

                this._onDoc = (e) => {
                    if (! this.$refs.panel?.contains(e.target) && ! this.$refs.trigger?.contains(e.target)) {
                        this.close();
                    }
                };
                this._onReflow = () => this.position();

                // setTimeout: que el propio clic de apertura no lo cierre al instante.
                setTimeout(() => document.addEventListener('click', this._onDoc), 0);
                window.addEventListener('scroll', this._onReflow, true);
                window.addEventListener('resize', this._onReflow);
            });
        },

        close() {
            this.open = false;
            this._teardownListeners();
        },

        _teardownListeners() {
            if (this._onDoc) {
                document.removeEventListener('click', this._onDoc);
                this._onDoc = null;
            }
            if (this._onReflow) {
                window.removeEventListener('scroll', this._onReflow, true);
                window.removeEventListener('resize', this._onReflow);
                this._onReflow = null;
            }
        },

        position() {
            const r = this.$refs.trigger.getBoundingClientRect();
            const panel = this.$refs.panel;
            const h = panel.offsetHeight || 320;
            const w = panel.offsetWidth || 280;
            const spaceBelow = window.innerHeight - r.bottom;
            const top = (spaceBelow > h + 12 || r.top < h + 12) ? r.bottom + 4 : r.top - h - 4;
            const left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));

            this.panelStyle = `position:fixed;top:${Math.round(top)}px;left:${Math.round(left)}px;`;
        },

        prevMonth() {
            const d = new Date(this.viewYear, this.viewMonth - 1, 1);
            this.viewYear = d.getFullYear();
            this.viewMonth = d.getMonth();
        },

        nextMonth() {
            const d = new Date(this.viewYear, this.viewMonth + 1, 1);
            this.viewYear = d.getFullYear();
            this.viewMonth = d.getMonth();
        },

        pick(day) {
            if (day.disabled) {
                return;
            }

            this.value = day.iso;
            this.close();
        },

        clear() {
            this.value = '';
            this.close();
        },

        goToday() {
            const t = this.iso(new Date());

            if ((this.min && t < this.min) || (this.max && t > this.max)) {
                this.syncView();

                return;
            }

            this.value = t;
            this.close();
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

/*
| Visual 3D del depósito (panel /depositos): puerto directo del componente Vue
| TankVisual3D.vue del propio dashboard SGRA (mismo Three.js, mismas proporciones/
| decoración) a Alpine + vanilla Three.js, para que "sea la misma animación" que ya
| ve el usuario en el dashboard SGRA. Mismo criterio que statsCharts/maintenanceCharts:
| el <div wire:ignore> evita que el morph de Livewire toque el <canvas> que crea
| Three.js; el componente Livewire emite `tanks-updated` en cada wire:poll y aquí cada
| tarjeta busca su propio depósito por id y reconstruye la escena con los datos nuevos
| — sin recrear el renderer/WebGL context en cada poll (más barato, sin parpadeo).
|
| El tema claro/oscuro se fija UNA vez al montar (no se re-observa el toggle en
| caliente) — igual decisión que statsCharts con los colores de los ejes: cambiar de
| tema mientras se mira esta tarjeta es un caso raro, y añadir un watcher solo para
| eso no compensa la complejidad extra.
*/
window.tank3d = function (initial) {
    return {
        _cleanup: null,

        init() {
            let tank = initial;
            const container = this.$refs.container;

            let renderer, scene, camera, tankGroup, resizeObserver, animId;
            let disposables = [];
            let buoyGroup = null;

            const isDark = document.documentElement.classList.contains('dark');
            const palette = isDark
                ? { shell: 0x3b82f6, shellOpacity: 0.32, edges: 0x60a5fa }
                : { shell: 0xbfdbfe, shellOpacity: 0.22, edges: 0x93c5fd };

            // La profundidad real de un aljibe suele ser pequeña frente a su superficie
            // (depósitos anchos y poco profundos) — se exagera visualmente para que el
            // volumen de agua se note, sin alterar el % de llenado real.
            const DEPTH_EXAGGERATION = 1.7;
            const PERSON_HEIGHT_CM = 170;
            const BUOY_DIAMETER_CM = 60; // diámetro real típico de un flotador salvavidas
            const BUOY_EXAGGERATION = 2.5;
            const LIFE_RING_SEGMENTS = 8;
            // Vista elevada en 3/4 (ni de perfil puro ni cenital pura).
            const ELEVATION = Math.PI / 4.3;
            const AZIMUTH = Math.PI / 5;

            function dims() {
                if (tank.forma === 'cilindrico') {
                    const diam = tank.diametro_cm > 0 ? tank.diametro_cm : 100;
                    const prof = tank.profundidad_cm > 0 ? tank.profundidad_cm : 100;
                    return { w: diam, d: diam, h: prof };
                }
                const largo = tank.largo_cm > 0 ? tank.largo_cm : 100;
                const ancho = tank.ancho_cm > 0 ? tank.ancho_cm : 100;
                const prof = tank.profundidad_cm > 0 ? tank.profundidad_cm : 100;
                return { w: largo, d: ancho, h: prof };
            }

            function clearGroup() {
                if (!tankGroup) return;
                for (const obj of [...tankGroup.children]) tankGroup.remove(obj);
                for (const d of disposables) d.dispose();
                disposables = [];
                buoyGroup = null;
            }

            function buildPerson(scale, tankW, groundY) {
                const mat = new THREE.MeshPhysicalMaterial({ color: 0xf59e0b, roughness: 0.55, metalness: 0.05 });
                const H = PERSON_HEIGHT_CM * scale;
                const headR = H * 0.11;
                const capRadius = H * 0.16;
                const bodyTotal = H - headR * 2;
                const capLength = Math.max(0.01, bodyTotal - capRadius * 2);

                const group = new THREE.Group();
                const bodyGeo = new THREE.CapsuleGeometry(capRadius, capLength, 4, 12);
                const body = new THREE.Mesh(bodyGeo, mat);
                body.position.y = bodyTotal / 2;
                group.add(body);

                const headGeo = new THREE.SphereGeometry(headR, 16, 16);
                const head = new THREE.Mesh(headGeo, mat);
                head.position.y = bodyTotal + headR;
                group.add(head);

                disposables.push(bodyGeo, headGeo, mat);

                group.position.set(-(tankW / 2 + capRadius + H * 0.12), groundY, 0);
                return group;
            }

            // Flotador salvavidas clásico flotando plano sobre la superficie del agua —
            // detalle decorativo, sin función informativa. El bobbing se anima en
            // updateBuoy() (animate()).
            function buildBuoy(scale, waterSurfaceY, offsetX, offsetZ) {
                const outerR = (BUOY_DIAMETER_CM * BUOY_EXAGGERATION / 2) * scale;
                const tubeR = outerR * 0.18;
                const ringR = outerR - tubeR;
                const arcAngle = (Math.PI * 2) / LIFE_RING_SEGMENTS;
                const gap = arcAngle * 0.08;

                const group = new THREE.Group();
                const redMat = new THREE.MeshPhysicalMaterial({ color: 0xdc2626, roughness: 0.4, metalness: 0.05, clearcoat: 0.3 });
                const whiteMat = new THREE.MeshPhysicalMaterial({ color: 0xf8fafc, roughness: 0.4, metalness: 0.05, clearcoat: 0.3 });
                disposables.push(redMat, whiteMat);

                for (let i = 0; i < LIFE_RING_SEGMENTS; i++) {
                    const geo = new THREE.TorusGeometry(ringR, tubeR, 10, 8, arcAngle - gap);
                    geo.rotateZ(i * arcAngle);
                    geo.rotateX(Math.PI / 2);
                    disposables.push(geo);
                    const mesh = new THREE.Mesh(geo, i % 2 === 0 ? redMat : whiteMat);
                    group.add(mesh);
                }

                group.position.set(offsetX, waterSurfaceY, offsetZ);
                group.userData.baseY = waterSurfaceY;
                group.userData.bobAmp = outerR * 0.22;
                return group;
            }

            function buildTank() {
                clearGroup();

                const { w, d, h } = dims();
                const maxDim = Math.max(w, d, h);
                const scale = 2.1 / maxDim;
                const W = w * scale, D = d * scale, H = h * scale * DEPTH_EXAGGERATION;
                const isCyl = tank.forma === 'cilindrico';

                const shellGeo = isCyl
                    ? new THREE.CylinderGeometry(W / 2, W / 2, H, 40, 1, true)
                    : new THREE.BoxGeometry(W, H, D);
                const shellMat = new THREE.MeshPhysicalMaterial({
                    color: palette.shell, transparent: true, opacity: palette.shellOpacity,
                    roughness: 0.05, metalness: 0, side: THREE.DoubleSide,
                    clearcoat: 0.6, clearcoatRoughness: 0.2,
                });
                const shell = new THREE.Mesh(shellGeo, shellMat);
                tankGroup.add(shell);
                disposables.push(shellGeo, shellMat);

                const edgesGeo = new THREE.EdgesGeometry(isCyl
                    ? new THREE.CylinderGeometry(W / 2, W / 2, H, 40, 1, false)
                    : shellGeo);
                const edgesMat = new THREE.LineBasicMaterial({ color: palette.edges, transparent: true, opacity: 0.6 });
                tankGroup.add(new THREE.LineSegments(edgesGeo, edgesMat));
                disposables.push(edgesGeo, edgesMat);

                const pct = Math.max(0, Math.min(100, tank.fill_pct || 0));
                // Al 100% el agua llegaría exactamente a la altura del depósito: la cara
                // superior del agua y la del contenedor quedan coplanarias y parpadean
                // (z-fighting) — se deja un margen mínimo para que nunca coincidan.
                const waterH = Math.max(Math.min(H * (pct / 100), H * 0.996), 0.001);
                const waterGeo = isCyl
                    ? new THREE.CylinderGeometry(W / 2 * 0.97, W / 2 * 0.97, waterH, 40)
                    : new THREE.BoxGeometry(W * 0.97, waterH, D * 0.97);
                const waterMat = new THREE.MeshPhysicalMaterial({
                    color: 0x2563eb, transparent: true, opacity: 0.88,
                    roughness: 0.1, metalness: 0.05, clearcoat: 0.4,
                });
                const water = new THREE.Mesh(waterGeo, waterMat);
                water.position.y = -H / 2 + waterH / 2;
                tankGroup.add(water);
                disposables.push(waterGeo, waterMat);

                const waterSurfaceY = -H / 2 + waterH;

                if (pct > 1) {
                    const surfGeo = isCyl
                        ? new THREE.CircleGeometry(W / 2 * 0.97, 40)
                        : new THREE.PlaneGeometry(W * 0.97, D * 0.97);
                    const surfMat = new THREE.MeshPhysicalMaterial({
                        color: 0x60a5fa, transparent: true, opacity: 0.55,
                        roughness: 0.05, metalness: 0, side: THREE.DoubleSide,
                    });
                    const surf = new THREE.Mesh(surfGeo, surfMat);
                    surf.rotation.x = -Math.PI / 2;
                    surf.position.y = waterSurfaceY;
                    tankGroup.add(surf);
                    disposables.push(surfGeo, surfMat);
                }

                // Flotador solo con agua suficiente para que "flotar" tenga sentido visual.
                if (pct > 3) {
                    const offsetX = W * 0.16;
                    const offsetZ = D * 0.12;
                    buoyGroup = buildBuoy(scale, waterSurfaceY, offsetX, offsetZ);
                    tankGroup.add(buoyGroup);
                }

                tankGroup.add(buildPerson(scale, W, -H / 2));
            }

            function fitCamera() {
                if (!tankGroup || !tankGroup.children.length) return;
                const box = new THREE.Box3().setFromObject(tankGroup);
                const sphere = new THREE.Sphere();
                box.getBoundingSphere(sphere);
                const { center, radius } = sphere;

                const vHalf = THREE.MathUtils.degToRad(camera.fov) / 2;
                const hHalf = Math.atan(Math.tan(vHalf) * camera.aspect);
                const limitingHalf = Math.min(vHalf, hHalf);
                const distance = 1.35 * (radius / Math.sin(limitingHalf));

                camera.position.set(
                    center.x + distance * Math.cos(ELEVATION) * Math.sin(AZIMUTH),
                    center.y + distance * Math.sin(ELEVATION),
                    center.z + distance * Math.cos(ELEVATION) * Math.cos(AZIMUTH),
                );
                camera.lookAt(center);
                camera.updateProjectionMatrix();
            }

            function setSize() {
                if (!renderer || !container) return;
                const w = container.clientWidth;
                const h = container.clientHeight;
                if (!w || !h) return;
                renderer.setSize(w, h);
                camera.aspect = w / h;
                camera.updateProjectionMatrix();
                fitCamera();
            }

            function updateBuoy(now) {
                if (!buoyGroup) return;
                const t = now * 0.0015;
                const lift = (Math.sin(t) + 1) / 2 * buoyGroup.userData.bobAmp;
                buoyGroup.position.y = buoyGroup.userData.baseY + lift;
                buoyGroup.rotation.z = Math.sin(t * 0.7) * 0.08;
                buoyGroup.rotation.x = Math.cos(t * 0.9) * 0.05;
            }

            function animate() {
                animId = requestAnimationFrame(animate);
                if (tankGroup) tankGroup.rotation.y += 0.0035;
                updateBuoy(performance.now());
                renderer.render(scene, camera);
            }

            scene = new THREE.Scene();
            camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
            camera.position.set(0, 1, 4.6);

            renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            container.appendChild(renderer.domElement);

            scene.add(new THREE.AmbientLight(0xffffff, 0.8));
            const dir1 = new THREE.DirectionalLight(0xffffff, 0.9);
            dir1.position.set(3, 5, 4);
            scene.add(dir1);
            const dir2 = new THREE.DirectionalLight(0xbfdbfe, 0.4);
            dir2.position.set(-3, -2, -3);
            scene.add(dir2);

            tankGroup = new THREE.Group();
            scene.add(tankGroup);

            buildTank();
            setSize();
            animate();

            resizeObserver = new ResizeObserver(setSize);
            resizeObserver.observe(container);

            const onUpdate = (e) => {
                const updated = (e.detail.tanks || []).find((t) => t.id === tank.id);
                if (!updated) return;
                tank = { ...tank, ...updated };
                buildTank();
            };
            window.addEventListener('tanks-updated', onUpdate);

            this._cleanup = () => {
                if (animId) cancelAnimationFrame(animId);
                if (resizeObserver) resizeObserver.disconnect();
                window.removeEventListener('tanks-updated', onUpdate);
                clearGroup();
                if (renderer) {
                    renderer.dispose();
                    renderer.domElement.remove();
                }
            };
        },

        destroy() {
            this._cleanup?.();
        },
    };
};
