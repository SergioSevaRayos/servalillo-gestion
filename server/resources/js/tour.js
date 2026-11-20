import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/*
| Tours guiados (Driver.js). Cada tour = lista de pasos con `data-tour="x"` como ancla (más estable
| que las clases de Tailwind). Los pasos cuyo elemento no existe en ese momento se omiten solos
| (p. ej. "Empezar jornada" cuando la jornada ya está iniciada).
*/
const TOURS = {
    chofer: [
        { el: 'dia', title: 'Elige el día', text: 'Desliza o toca para ver la ruta de otro día. «Hoy» te devuelve al día actual.' },
        { el: 'jornada', title: 'Tu jornada', text: 'Empieza la jornada anotando la lectura del contador de litros. Hasta entonces no podrás operar las paradas. Al terminar, vuelve aquí para cerrarla.' },
        { el: 'navegar', title: 'Navegar la ruta', text: 'Abre Google Maps con todas tus paradas pendientes, en el orden actual.' },
        { el: 'acciones', title: 'Herramientas de la ruta', text: 'Ver el recorrido en el mapa, reorganizar las paradas automáticamente o gestionarlas a mano (mover o quitar).' },
        { el: 'parada', title: 'Tus paradas', text: 'Toca una parada para cerrarla: entregada, fallida o cancelada. También puedes reprogramarla para otro día.' },
        { el: 'anadir', title: 'Cliente que ha llamado', text: 'Si un cliente te llama, añádelo aquí como parada nueva al final de la ruta.' },
    ],
    // Modal de cierre de parada. Se adapta a la variante abierta (Entregada / Fallida / Cancelada,
    // albarán por email o en mano…): solo salen los pasos cuyos campos están en pantalla ahora mismo.
    parada: [
        { el: 'parada-cliente', title: 'Datos del cliente', text: 'Toca el teléfono para llamarle y «Cómo llegar» para abrir Google Maps hasta su puerta.' },
        { el: 'parada-pedido', title: 'Lo que ha pedido', text: 'Cantidad y tipo de suministro previstos para este cliente.' },
        { el: 'parada-resultado', title: '¿Cómo ha ido?', text: 'Entregada: has descargado. Fallida: no has podido (cliente ausente, sin acceso…). Cancelada: el cliente la anula. El formulario cambia según lo que elijas; después pulsa «?» para ver la guía de esa opción.' },
        { el: 'parada-albaran', title: 'Cómo se entrega el albarán', text: 'Por email: el cliente firma en tu pantalla y se le envía por correo. En mano: le das la copia impresa y firma el papel.' },
        { el: 'parada-litros', title: 'Litros entregados', text: 'Pon los litros realmente descargados. Viene rellenado con lo pedido: corrígelo si ha sido otra cantidad.' },
        { el: 'parada-dni', title: 'DNI / CIF del cliente', text: 'Hace falta para el albarán y la factura. Si el cliente ya lo tenía guardado sale solo; si no, escríbelo y se guarda para la próxima vez.' },
        { el: 'parada-factura', title: 'Factura', text: 'Márcalo si el cliente quiere factura. Administración la prepara y se la envía; en ese caso el DNI/CIF es obligatorio.' },
        { el: 'parada-contador', title: 'Contador de litros', text: 'Déjalo en «Pasa por el contador» salvo que esos litros no salgan de la cisterna (por ejemplo, un repostaje aparte). Si no pasan, púlsalo para que no se descuenten al cuadrar la jornada.' },
        { el: 'parada-campos', title: 'Datos del reparto', text: 'Campos propios de este tipo de reparto. Rellena los que pida; los obligatorios avisan si falta alguno.' },
        { el: 'parada-firma', title: 'Firma y destinatario', text: 'Datos del albarán según el canal elegido.' },
        { el: 'parada-motivo', title: 'Motivo', text: 'Explica brevemente lo ocurrido. Es obligatorio y lo verá la oficina.' },
        { el: 'parada-reprogramar', title: 'Reprogramar (opcional)', text: 'Si hay que volver otro día, elige la fecha: se crea una parada nueva ese día en tu ruta. Si lo dejas vacío, la parada solo se cierra.' },
        { el: 'parada-guardar', title: 'Guardar', text: 'Cuando termines, pulsa «Guardar». Si te equivocas, abre la parada otra vez y usa «Reabrir parada».' },
    ],
};

let active = null;
let watchdog = null;

function seenKey(name) {
    return `tour:${name}`;
}

function markSeen(name) {
    try { localStorage.setItem(seenKey(name), '1'); } catch (e) { /* sin storage: no pasa nada */ }
}

function hasSeen(name) {
    try { return localStorage.getItem(seenKey(name)) === '1'; } catch (e) { return false; }
}

window.startTour = function (name) {
    const defs = TOURS[name];
    if (! defs) return;

    active?.destroy();

    const steps = defs
        .filter((s) => document.querySelector(`[data-tour="${s.el}"]`))
        .map((s) => {
            const node = document.querySelector(`[data-tour="${s.el}"]`);

            // La vista puede personalizar título/texto según la variante (data-tour-title / data-tour-text).
            return {
                element: node,
                popover: {
                    title: node.dataset.tourTitle || s.title,
                    description: node.dataset.tourText || s.text,
                },
            };
        });

    if (! steps.length) return;

    active = driver({
        showProgress: true,
        allowClose: true,
        overlayOpacity: 0.6,
        stagePadding: 6,
        stageRadius: 12,
        nextBtnText: 'Siguiente',
        prevBtnText: 'Anterior',
        doneBtnText: 'Entendido',
        progressText: '{{current}} de {{total}}',
        popoverClass: 'servalillo-tour',
        steps,
        onDestroyed: () => {
            markSeen(name);
            active = null;
            clearInterval(watchdog);
        },
    });

    active.drive();

    // El modal puede cerrarse (Cancelar, Escape, guardar) con el tour abierto: si el elemento del
    // paso deja de estar visible se cierra el tour, para no dejar el overlay colgado.
    clearInterval(watchdog);
    watchdog = setInterval(() => {
        const el = active?.getActiveElement?.();
        if (el && (! el.isConnected || el.getClientRects().length === 0)) active.destroy();
    }, 500);
};

function autoStart() {
    const root = document.querySelector('[data-tour-auto]');
    if (! root) return;

    const name = root.dataset.tourAuto;
    if (! TOURS[name] || hasSeen(name)) return;

    // Margen para que el carrusel de días (Alpine) termine de montarse.
    setTimeout(() => window.startTour(name), 600);
}

// Primera vez que el chofer abre una parada: arranca la guía del modal (queda marcada como vista).
window.addEventListener('open-modal', (e) => {
    if (e.detail != 'stop-action' || hasSeen('parada')) return;

    setTimeout(() => window.startTour('parada'), 700);
});

document.addEventListener('DOMContentLoaded', autoStart);
document.addEventListener('livewire:navigated', autoStart);

// wire:poll re-renderiza la pantalla: recolocar el resaltado si hay un tour abierto.
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('morphed', () => active?.refresh());
});
