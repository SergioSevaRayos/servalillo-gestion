# Gestión Servalillo

Gestión de tareas y rutas de reparto para flota de camiones cisterna.

- `server/` — Laravel 13 (API + web de gestión + web operativa del chofer)
- `mobile/` — App Flutter de tracking GPS (Bloque 10, aún no creado)
- `docs/` — Decisiones de arquitectura, modelo de datos y estado de los bloques

## Requisitos

- **Docker** + Docker Compose (levanta PHP, PostgreSQL, Reverb, colas y Mailpit).
- **Node 22** en el host (Vite/assets se compilan fuera del contenedor).
- No hace falta PHP ni Composer en el host: la imagen de `server/` ya trae Composer.
- El contenedor construye un usuario con UID/GID **1000**. Si tu usuario del host no es 1000,
  pon `WWWUSER` y `WWWGROUP` en `server/.env` con tu `id -u` / `id -g` antes de construir.

## Arranque en una máquina nueva

```bash
git clone git@github.com:SergioSevaRayos/servalillo-gestion.git
cd servalillo-gestion/server

cp .env.example .env

# 1. Imagen + dependencias PHP (Composer va dentro de la imagen)
docker compose build
docker compose run --rm laravel.test composer install

# 2. Levantar el stack
docker compose up -d

# 3. Clave de app + esquema + datos de ejemplo
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate --seed
docker compose exec laravel.test php artisan storage:link

# 4. Assets — OBLIGATORIO (en el host, Node 22). Sin compilarlos, todas las vistas dan 500.
npm ci
npm run build      # una vez; o `npm run dev` y déjalo corriendo mientras desarrollas
```

- App: http://localhost:8000
- Mailpit (emails de prueba): http://localhost:8026
- Reverb (websockets): puerto 8080
- PostgreSQL: puerto 5433 en el host

> La base `testing` que usan los tests se crea sola la primera vez que se inicializa el volumen
> de Postgres (`server/docker/pgsql/create-testing-database.sql`).

### Usuarios de prueba (contraseña `password`)

| Rol | Email |
|---|---|
| Administrador | `admin@servalillo.test` |
| Mantenimiento | `soporte@servalillo.test` |
| Chofer | `pedro@servalillo.test` (y `lucia@`, `carlos@`, `nadia@`) |

### Comandos habituales (desde `server/`)

```bash
docker compose exec laravel.test php artisan test              # suite Pest
docker compose exec laravel.test php artisan migrate:fresh --seed
docker compose exec laravel.test ./vendor/bin/pint             # formateo PHP
```

Ver [docs/](docs/) para el modelo de datos y el estado de cada bloque, y `CLAUDE.md` para las
convenciones del proyecto.

## Ramas

- `develop` — trabajo del día a día.
- `test` — integración/QA; recibe merges de `develop`.
- `main` — estable; recibe merges de `test` con el visto bueno.
