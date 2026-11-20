/*
| Genera las capturas del Manual de uso (public/images/manual/**) con el Chrome del sistema.
|
| Uso (en el HOST, con el stack levantado y datos de la semana planificados):
|     npm run manual:capturas
|
| - Tema claro, tours desactivados y datos del seed local (ficticios).
| - Las capturas del chofer simulan un móvil. Para llegar al modal de parada se INICIA la jornada de
|   hoy de Pedro desde la propia UI: el script no la deshace (ver docs/23-manual-de-uso.md).
| - Variables opcionales: APP_URL, CHROME_PATH, MANUAL_PASSWORD.
*/
import puppeteer from 'puppeteer-core';
import { mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'public/images/manual');
const BASE = process.env.APP_URL ?? 'http://localhost:8000';
const CHROME = process.env.CHROME_PATH ?? '/usr/bin/google-chrome';
const PASSWORD = process.env.MANUAL_PASSWORD ?? 'password';

const DESKTOP = { width: 1280, height: 800, deviceScaleFactor: 1 };
const MOBILE = { width: 420, height: 900, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const MOBILE_TALL = { ...MOBILE, height: 1800 };

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function newSession(browser, email, viewport) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    await page.setViewport(viewport);
    const host = new URL(BASE).hostname;

    // Tema claro (lo lee el script anti-FOUC) y tours ya "vistos".
    await page.setCookie({ name: 'theme', value: 'light', domain: host, path: '/' });
    await page.evaluateOnNewDocument(() => {
        try {
            localStorage.setItem('tour:chofer', '1');
            localStorage.setItem('tour:parada', '1');
        } catch (e) { /* noop */ }
    });

    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' });
    await page.type('#email', email);
    await page.type('#password', PASSWORD);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.keyboard.press('Enter'),
    ]);

    return page;
}

async function shot(page, folder, name, { fullPage = false, wait = 900, modal = false } = {}) {
    await sleep(wait);
    mkdirSync(join(OUT, folder), { recursive: true });
    const file = join(OUT, folder, `${name}.png`);

    // Con `modal`, se recorta al panel del modal abierto (sin el hueco vacío de debajo).
    let clip;
    if (modal) {
        clip = await page.evaluate(() => {
            const panel = [...document.querySelectorAll('.fixed.inset-0 .p-6')].find((e) => e.offsetParent !== null);
            if (! panel) return null;
            const r = panel.getBoundingClientRect();
            const pad = 24;
            return { x: 0, y: 0, width: window.innerWidth, height: Math.min(window.innerHeight, Math.ceil(r.bottom + pad)) };
        });
    }

    await page.screenshot({ path: file, fullPage, ...(clip ? { clip } : {}) });
    console.log(`  ✓ ${folder}/${name}.png`);
}

async function go(page, path, wait = 1200) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
    await sleep(wait);
}

/** Pulsa el primer botón/enlace visible cuyo texto contiene `text`. */
async function clickText(page, text, scope = 'button, a') {
    const ok = await page.evaluate((text, scope) => {
        const el = [...document.querySelectorAll(scope)]
            .find((e) => e.offsetParent !== null && e.textContent.replace(/\s+/g, ' ').includes(text));
        if (! el) return false;
        el.click();
        return true;
    }, text, scope);
    if (! ok) throw new Error(`No se encontró «${text}»`);
    await sleep(900);
}

async function admin(browser) {
    console.log('Administración');
    const page = await newSession(browser, 'admin@servalillo.test', DESKTOP);
    const dir = 'administracion';
    const pages = [
        ['01-panel', '/dashboard'],
        ['02-rutas-tablero', '/rutas'],
        ['03-rutas-listado', '/rutas/listado'],
        ['04-clientes', '/clientes'],
        ['05-albaranes', '/albaranes'],
        ['06-facturas', '/facturas'],
        ['07-avisos', '/avisos'],
        ['08-mapa', '/mapa'],
        ['09-chofers', '/chofers'],
        ['10-camiones', '/camiones'],
        ['11-usuarios', '/usuarios'],
        ['12-soporte', '/soporte'],
        ['13-depositos', '/depositos'],
    ];
    for (const [name, path] of pages) {
        try {
            await go(page, path, name === '08-mapa' ? 4000 : 1500);
            await shot(page, dir, name);
        } catch (e) {
            console.warn(`  ✗ ${name}: ${e.message}`);
        }
    }

    // Ficha de un cliente.
    try {
        await go(page, '/clientes');
        const href = await page.evaluate(() => document.querySelector('a[href*="/clientes/"]')?.getAttribute('href'));
        if (href) { await go(page, href); await shot(page, dir, '14-cliente-ficha'); }
    } catch (e) { console.warn(`  ✗ ficha cliente: ${e.message}`); }

    await page.browserContext().close();
}

async function chofer(browser) {
    console.log('Chofer (móvil)');
    const page = await newSession(browser, 'pedro@servalillo.test', MOBILE);
    const dir = 'chofer';

    await go(page, '/chofer/ruta');
    await shot(page, dir, '01-mi-ruta-sin-empezar', { fullPage: true });

    await clickText(page, 'Empezar jornada');
    await shot(page, dir, '02-empezar-jornada');
    await clickText(page, 'Empezar', '[role="dialog"] button, form button[type="submit"]');
    await sleep(1500);
    await go(page, '/chofer/ruta');
    await shot(page, dir, '03-mi-ruta-en-curso', { fullPage: true });

    // Modal de parada, en cada variante (viewport alto para que quepa entero).
    await page.setViewport(MOBILE_TALL);
    await page.click('[data-tour="parada"] button');
    await sleep(1500);
    await shot(page, dir, '04-parada-entregada-email', { modal: true });

    await clickText(page, 'Entrega en mano');
    await shot(page, dir, '05-parada-entregada-en-mano', { modal: true });

    await clickText(page, 'Fallida');
    await shot(page, dir, '06-parada-fallida', { modal: true });

    await clickText(page, 'Cancelada');
    await shot(page, dir, '07-parada-cancelada', { modal: true });

    await page.keyboard.press('Escape');
    await page.setViewport(MOBILE);
    await sleep(500);
    try {
        await clickText(page, 'Añadir cliente');
        await shot(page, dir, '08-anadir-cliente');
        await page.keyboard.press('Escape');
    } catch (e) { console.warn(`  ✗ añadir cliente: ${e.message}`); }

    await page.browserContext().close();
}

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--lang=es-ES'],
});

try {
    await admin(browser);
    await chofer(browser);
} finally {
    await browser.close();
}
