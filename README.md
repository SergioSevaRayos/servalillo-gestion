# Gestión Servalillo

Gestión de tareas y rutas de reparto para flota de camiones cisterna.

- `server/` — Laravel 13 (API + web de gestión + web operativa del chofer)
- `mobile/` — App Flutter de tracking GPS (Bloque 10)
- `docs/` — Decisiones de arquitectura y modelo de datos

## Arranque local (server)

Requisitos: Docker.

```bash
cd server
cp .env.example .env          # ya incluido en el repo para local
./vendor/bin/sail up -d       # alias de docker compose
./vendor/bin/sail artisan migrate --seed
npm install && npm run dev    # Vite en el host (Node 22)
```

- App: http://localhost:8000
- Mailpit (emails de prueba): http://localhost:8026
- Reverb (websockets): puerto 8080
- PostgreSQL: puerto 5433 en el host

### Usuarios de prueba (contraseña `password`)

| Rol | Email |
|---|---|
| Administrador | `admin@servalillo.test` |
| Mantenimiento | `soporte@servalillo.test` |
| Chofer | `pedro@servalillo.test` (y lucia@, carlos@, nadia@) |

### Tests

```bash
./vendor/bin/sail artisan test
```

Ver [docs/](docs/) para el modelo de datos y el estado de cada bloque.
