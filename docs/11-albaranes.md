# Albaranes (Bloque 8)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Albaranes (Bloque 8)
- **Motor PDF = `barryvdh/laravel-dompdf`** (PHP puro), NO `spatie/laravel-pdf` — el contenedor no
  trae navegador headless (decisión de imagen ligera). La vista `resources/views/pdf/delivery-note.
  blade.php` usa CSS plano y **`font-family: Helvetica`** (fuente interna de dompdf, 0 KB embebidos,
  cubre el español). Con `DejaVu Sans` el PDF pesaba ~860 KB por la fuente embebida; con Helvetica, ~2 KB.
- **Flujo:** al completar una parada (`StopActionForm`), si no tiene albarán, `DeliveryNoteService::
  createForStop()` crea el `delivery_note` (número `ALB-{año}-{6 dígitos}` secuencial con `lockForUpdate`,
  `customer_snapshot` congelado, firma guardada en disco `r2`) en estado `Queued` y despacha
  `ProcessDeliveryNote` (cola `database`, contenedor `queue`).
- **`ProcessDeliveryNote`** (job, 3 reintentos): `Generating` → renderiza PDF a `r2` (`Generated`) →
  `$channel->deliver($note)` fija el estado final (`Sent` / `DeliveredPhysically`). `failed()` deja
  `Failed` + `failure_reason`.
- **Canales** (`config/delivery.php`): `EmailChannel` envía `DeliveryNoteMail` con el PDF adjunto a
  `recipient_email` (Mailpit en local). `PhysicalChannel` = **entrega en mano en papel**: no envía
  nada digital, solo marca `DeliveredPhysically`. Añadir un canal = clase nueva + entrada en config.
- **Firma según canal:** el contrato tiene `requiresSignature()`. `email` → sí (el cliente firma en
  el teléfono, canvas). `physical` → **no** (el chofer lleva albaranes en papel; el cliente firma el
  papel). En `physical` el formulario del chofer oculta la firma y solo pide "Recibido por"
  (opcional). `StopActionForm::rules()` y la vista se ramifican con `channelRequiresSignature()`.
- **Firma:** canvas + `signature_pad` (`<x-ui.signature-pad>`, Alpine `signaturePad` en `app.js`).
  Igual que la ruleta: `x-modelable` + `wire:model`, y se re-dimensiona al abrir el modal (canvas
  con `display:none` mide 0). Se exporta PNG data URL; el servicio valida la **cabecera mágica real
  del PNG** (`\x89PNG…`), nunca solo el prefijo del data URL.
- **Front del chofer:** el modal de "Entregada" incluye canal + email + firmante + firma. No aparece
  si la parada ya tiene albarán.
- **Panel admin:** `/albaranes` (`delivery-notes.index`, `permission:delivery_notes.view`) — listado
  con filtros, acciones "Reprocesar" (`delivery_notes.regenerate`) y "Marcar entregado" (físicos,
  `delivery_notes.mark_delivered`). PDF: `GET /albaranes/{note}/pdf` (fuera del grupo de rol; la
  `DeliveryNotePolicy` deja al manager o al chofer dueño de la parada; genera el PDF al vuelo si aún
  no existe).

