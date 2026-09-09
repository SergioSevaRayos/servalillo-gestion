<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Servalillo · Banco de pruebas del tracker</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 16px 14px 40px;
    font: 15px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #0f172a; color: #e2e8f0;
  }
  h1 { font-size: 17px; margin: 0 0 4px; }
  p.sub { margin: 0 0 18px; color: #94a3b8; font-size: 13px; }
  .card {
    background: #1e293b; border: 1px solid #334155; border-radius: 14px;
    padding: 14px; margin-bottom: 14px;
  }
  label { display: block; font-size: 12px; color: #94a3b8; margin: 10px 0 4px; }
  label:first-child { margin-top: 0; }
  input {
    width: 100%; padding: 10px 12px; border-radius: 10px;
    border: 1px solid #475569; background: #0f172a; color: #e2e8f0; font-size: 15px;
  }
  button {
    appearance: none; border: 0; border-radius: 10px; padding: 12px 14px;
    font-size: 15px; font-weight: 600; color: #fff; background: #0d7d8c;
    width: 100%; margin-top: 10px; cursor: pointer;
  }
  button.secondary { background: #475569; }
  button.danger { background: #b91c1c; }
  button:disabled { opacity: .5; }
  .row { display: flex; gap: 8px; }
  .row > * { flex: 1; }
  dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 0; font-size: 13px; }
  dt { color: #94a3b8; }
  dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
  dd.ok { color: #4ade80; }
  dd.bad { color: #f87171; }
  #log {
    background: #0f172a; border: 1px solid #334155; border-radius: 10px;
    padding: 10px; font: 12px/1.5 ui-monospace, Menlo, monospace;
    height: 220px; overflow-y: auto; white-space: pre-wrap; word-break: break-word;
  }
  #log .t { color: #64748b; }
  #log .e { color: #f87171; }
  #log .s { color: #4ade80; }
</style>
</head>
<body>
<h1>Servalillo · Banco de pruebas del tracker</h1>
<p class="sub">Simula el APK desde el navegador para validar el pipeline GPS (Bloque&nbsp;10/11). El
navegador solo envía posición con la pestaña abierta y la pantalla encendida — no es un sustituto del
APK para uso real, solo para probar el servidor.</p>

<div class="card">
  <label for="secret">Secreto de enrolamiento (DEVICE_ENROLMENT_SECRET)</label>
  <input id="secret" type="password" autocomplete="off" placeholder="el del .env del servidor">
  <button id="btnEnrol">Enrolar / Re-enrolar</button>
  <p style="margin:8px 0 0;font-size:12px;color:#64748b">La etiqueta del dispositivo se pone luego en
    Mantenimiento&nbsp;→&nbsp;Dispositivos.</p>
</div>

<div class="card">
  <dl>
    <dt>Install ID</dt><dd id="sInstall">—</dd>
    <dt>Enrolado</dt><dd id="sDevice">no</dd>
    <dt>Chofer asignado</dt><dd id="sDriver">—</dd>
    <dt>Dispositivo activo</dt><dd id="sActive">—</dd>
    <dt>Intervalo (s)</dt><dd id="sInterval">—</dd>
    <dt>En cola</dt><dd id="sQueue">0</dd>
    <dt>Última enviada</dt><dd id="sSent">nunca</dd>
    <dt>Aceptadas (total)</dt><dd id="sAccepted">0</dd>
  </dl>
  <div class="row">
    <button id="btnStatus" class="secondary">Consultar estado</button>
    <button id="btnToggle">Iniciar envío</button>
  </div>
  <button id="btnOnce" class="secondary">Enviar una posición ahora</button>
</div>

<div class="card">
  <label for="interval">Intervalo de envío (segundos, mín. 5)</label>
  <input id="interval" type="number" min="5" value="15">
  <button id="btnClear" class="danger">Borrar enrolamiento local</button>
</div>

<div id="log"></div>

<script>
const $ = id => document.getElementById(id);
const LS = {
  get: k => { try { return localStorage.getItem('tt_' + k); } catch (e) { return null; } },
  set: (k, v) => { try { localStorage.setItem('tt_' + k, v); } catch (e) {} },
  del: k => { try { localStorage.removeItem('tt_' + k); } catch (e) {} },
};

let sending = false;
let timer = null;
const queue = [];
let acceptedTotal = 0;

function log(msg, cls) {
  const el = $('log');
  const line = document.createElement('div');
  const now = new Date().toLocaleTimeString('es-ES');
  line.innerHTML = '<span class="t">' + now + '</span> ' + msg;
  if (cls) line.className = cls;
  el.appendChild(line);
  while (el.children.length > 120) el.removeChild(el.firstChild);
  el.scrollTop = el.scrollHeight;
}

function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

function installId() {
  let id = LS.get('install');
  if (!id) { id = uuid(); LS.set('install', id); }
  return id;
}

function refresh() {
  $('sInstall').textContent = installId().slice(0, 8) + '…';
  const dev = LS.get('device');
  $('sDevice').textContent = dev ? ('#' + dev) : 'no';
  $('sDevice').className = dev ? 'ok' : 'bad';
  $('sInterval').textContent = LS.get('interval') || $('interval').value;
  $('sQueue').textContent = queue.length;
  $('sAccepted').textContent = acceptedTotal;
  $('secret').value = LS.get('secret') || '';
  $('btnToggle').textContent = sending ? 'Detener envío' : 'Iniciar envío';
  $('btnToggle').className = sending ? 'danger' : '';
}

async function api(path, opts) {
  const res = await fetch('/api/' + path, {
    ...opts,
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...(opts?.headers || {}) },
  });
  let body = null;
  try { body = await res.json(); } catch (e) {}
  return { status: res.status, ok: res.ok, body };
}

async function enrol() {
  const secret = $('secret').value.trim();
  if (!secret) { log('Falta el secreto de enrolamiento.', 'e'); return; }
  LS.set('secret', secret);
  log('Enrolando…');
  try {
    const r = await api('device/register', {
      method: 'POST',
      body: JSON.stringify({
        install_identifier: installId(),
        secret,
        platform: 'android',
        app_version: 'web-test-1.0',
      }),
    });
    if (r.status === 201) {
      LS.set('token', r.body.token);
      LS.set('device', r.body.device_id);
      if (r.body.tracking?.ping_interval_seconds) {
        LS.set('interval', r.body.tracking.ping_interval_seconds);
        $('interval').value = Math.max(5, r.body.tracking.ping_interval_seconds);
      }
      log('Enrolado. Dispositivo #' + r.body.device_id + '. Aparece ya en Mantenimiento → Dispositivos.', 's');
      await status();
    } else if (r.status === 403) {
      log('403 · Secreto de enrolamiento no válido.', 'e');
    } else if (r.status === 429) {
      log('429 · Demasiados intentos de enrolamiento (límite 10/min). Espera un minuto.', 'e');
    } else {
      log(r.status + ' · ' + JSON.stringify(r.body), 'e');
    }
  } catch (e) {
    log('Sin conexión con el servidor: ' + e.message, 'e');
  }
  refresh();
}

async function status() {
  const token = LS.get('token');
  if (!token) { log('Aún sin enrolar.', 'e'); return; }
  try {
    const r = await api('device', { headers: { 'Authorization': 'Bearer ' + token } });
    if (r.status === 200) {
      $('sDriver').textContent = r.body.driver?.name || 'sin asignar';
      $('sDriver').className = r.body.driver ? 'ok' : 'bad';
      $('sActive').textContent = r.body.is_active ? 'sí' : 'no';
      $('sActive').className = r.body.is_active ? 'ok' : 'bad';
      if (r.body.tracking?.ping_interval_seconds) {
        LS.set('interval', r.body.tracking.ping_interval_seconds);
      }
      log('Estado OK · chofer: ' + (r.body.driver?.name || 'sin asignar') +
          ' · activo: ' + r.body.is_active + ' · hora servidor: ' + r.body.server_time, 's');
    } else if (r.status === 401) {
      log('401 · Token revocado. Vuelve a enrolar.', 'e');
    } else if (r.status === 403) {
      log('403 · Token sin permiso.', 'e');
    } else {
      log(r.status + ' · ' + JSON.stringify(r.body), 'e');
    }
  } catch (e) {
    log('Sin conexión: ' + e.message, 'e');
  }
  refresh();
}

function getPosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('Este navegador no tiene geolocalización.'));
    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true, timeout: 20000, maximumAge: 5000,
    });
  });
}

async function batteryLevel() {
  try {
    if (navigator.getBattery) {
      const b = await navigator.getBattery();
      return Math.round(b.level * 100);
    }
  } catch (e) {}
  return null;
}

async function sampleAndQueue() {
  try {
    const pos = await getPosition();
    const c = pos.coords;
    const p = {
      lat: +c.latitude.toFixed(7),
      lng: +c.longitude.toFixed(7),
      recorded_at: new Date(pos.timestamp).toISOString(),
    };
    if (c.accuracy != null && isFinite(c.accuracy)) p.accuracy_m = +c.accuracy.toFixed(2);
    if (c.speed != null && isFinite(c.speed) && c.speed >= 0) p.speed_mps = +c.speed.toFixed(2);
    if (c.heading != null && isFinite(c.heading) && c.heading >= 0) p.heading_deg = +c.heading.toFixed(1);
    const bat = await batteryLevel();
    if (bat != null) p.battery_level = bat;
    queue.push(p);
    log('Posición ' + p.lat + ', ' + p.lng + ' (±' + (p.accuracy_m ?? '?') + ' m) → cola');
  } catch (e) {
    log('No se pudo obtener la posición: ' + e.message, 'e');
  }
  refresh();
}

async function flush() {
  const token = LS.get('token');
  if (!token) { log('Sin enrolar, no se envía.', 'e'); return; }
  if (queue.length === 0) return;
  const batch = queue.slice(0, 500);
  try {
    const r = await api('gps/batch', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ positions: batch }),
    });
    if (r.ok) {
      queue.splice(0, batch.length);
      acceptedTotal += (r.body?.accepted ?? batch.length);
      LS.set('sent', new Date().toISOString());
      $('sSent').textContent = new Date().toLocaleTimeString('es-ES');
      log('Lote enviado: ' + batch.length + ' · aceptadas ' + (r.body?.accepted ?? '?'), 's');
    } else if (r.status === 401) {
      log('401 · Token revocado — deteniendo. Vuelve a enrolar.', 'e');
      stop();
    } else if (r.status === 403) {
      log('403 · Dispositivo desactivado — deteniendo.', 'e');
      stop();
    } else if (r.status === 422) {
      log('422 · Lote rechazado, se descarta: ' + JSON.stringify(r.body?.errors || r.body), 'e');
      queue.splice(0, batch.length);
    } else {
      log(r.status + ' · se conserva la cola: ' + JSON.stringify(r.body), 'e');
    }
  } catch (e) {
    log('Sin red, la cola crece (' + queue.length + '): ' + e.message, 'e');
  }
  refresh();
}

async function tick() {
  await sampleAndQueue();
  await flush();
}

function start() {
  if (sending) return;
  const secs = Math.max(5, parseInt($('interval').value, 10) || 15);
  LS.set('interval', secs);
  sending = true;
  refresh();
  log('▶ Envío cada ' + secs + ' s. Mantén la pantalla encendida y esta pestaña visible.', 's');
  tick();
  timer = setInterval(tick, secs * 1000);
}

function stop() {
  sending = false;
  if (timer) { clearInterval(timer); timer = null; }
  refresh();
  log('⏹ Envío detenido.');
}

$('btnEnrol').onclick = enrol;
$('btnStatus').onclick = status;
$('btnOnce').onclick = tick;
$('btnToggle').onclick = () => sending ? stop() : start();
$('btnClear').onclick = () => {
  stop();
  ['token', 'device', 'install', 'sent', 'interval'].forEach(LS.del);
  queue.length = 0; acceptedTotal = 0;
  log('Enrolamiento local borrado. La próxima vez se genera un install_identifier nuevo.');
  refresh();
};

if (LS.get('sent')) $('sSent').textContent = new Date(LS.get('sent')).toLocaleTimeString('es-ES');
if (LS.get('interval')) $('interval').value = Math.max(5, +LS.get('interval'));
refresh();
if (LS.get('token')) status();
log('Listo. Pega el secreto y pulsa "Enrolar".');
</script>
</body>
</html>
