# Manual de uso (2026-11-20)

Dos manuales en Markdown renderizados dentro de la app, con capturas generadas por script.

## Qué hay
- **Contenido**: `server/resources/manuales/chofer.md` y `administracion.md` (español, escritos para el usuario final, no técnicos). Las imágenes se referencian como `/images/manual/<manual>/NN-nombre.png`.
- **Visor**: `App\Livewire\Manual\Show` (`/manual/{type?}`, ruta `manual`, solo `auth`). `Str::markdown()` con `html_input => strip` y `allow_unsafe_links => false`; ancla por cada `##` y índice. Estilos `.manual` en `resources/css/app.css` (no hay plugin typography).
- **Permisos**: el chofer solo abre `chofer` (el otro → 403); administrador y mantenimiento (`isManager()`) ven los dos, con selector arriba y `administracion` por defecto. No hay permiso nuevo.
- **Menú**: "Manual de uso" en el grupo "Sistema" (gestión) y en el grupo único del chofer. Una sola definición (`$groups` de `livewire/layout/navigation`) alimenta menú lateral y móvil.
- Test: `tests/Feature/ManualTest.php`.

## Capturas (`npm run manual:capturas`)
Script `server/scripts/manual-screenshots.mjs` (`puppeteer-core` + `/usr/bin/google-chrome`, solo en el HOST con el stack levantado). Entra con `admin@` y `pedro@` (seed), tema claro (cookie `theme=light`), tours desactivados (`localStorage tour:*`), y guarda en `server/public/images/manual/`. Las del chofer simulan móvil (420 px, ×2) y los modales de parada se recortan al panel.
- **Efecto secundario**: para llegar al modal de parada **inicia la jornada de hoy de Pedro** desde la UI. Si ya estaba iniciada falla el primer paso: antes de lanzar, déjala en `published` (`started_at` y `liter_meter_start` a null) y, después, restaura lo que necesites.
- Necesita datos: Pedro con al menos 1 parada hoy, y semana planificada (`RecurringRouteService` / `RecurringStopService`).
- Cuando cambie una pantalla: relanzar el script y revisar los PNG; el texto de los `.md` hay que mantenerlo a mano.
- `/fichar` no se captura: el fichaje está visible solo para mantenimiento (`docs/22`), el chofer recibe 404.

## Pendiente
Producción: los PNG van en git (`public/images/manual`), llegan a la VPS con el `git pull`, no con el rsync de `public/build`.
