# HR Media System — Gestión de almacén, operaciones, combustible y flotilla

Plataforma web **multiempresa (SaaS)** desarrollada por **HR Media System**. Primer cliente: **Carmen Cargo Express**.

Laravel 12 · PHP 8.2+ · MySQL/MariaDB · Blade + Tailwind CSS 4 + Alpine.js · Vite

## Estado del proyecto

| Fase | Contenido | Estado |
|------|-----------|--------|
| 1 | Arquitectura, esquema BD completo, autenticación, multiempresa, roles/permisos, layout, configuración, auditoría | ✅ Hecha |
| 2 | Productos, fotos, categorías, inventario, cajas/unidades, entradas/salidas, alertas | ⏳ |
| 3 | Furgones, materiales, tickets, impresión 4 por hoja | ⏳ |
| 4 | Gasoil (recepciones, despachos, reserva) y vehículos | ⏳ |
| 5 | Inspecciones, averías, mantenimiento, gomas, retornables | ⏳ |
| 6 | Reportes, Excel, PDF, auditoría avanzada | ⏳ |
| 7 | PWA/móvil, seguridad, optimización, despliegue | ⏳ |

Las migraciones ya contienen **el esquema completo de todas las fases** (ver `docs/ARQUITECTURA.md`).

## Instalación local (desarrollo)

```bash
composer install
cp .env.example .env && php artisan key:generate
# Desarrollo rápido con SQLite: DB_CONNECTION=sqlite y `touch database/database.sqlite`
php artisan migrate --seed                       # permisos + super administrador
php artisan db:seed --class=DemoSeeder           # (opcional) empresa demo Carmen Cargo Express
php artisan storage:link
npm install && npm run build                     # o `npm run dev`
php artisan serve
```

**Demo** (`DemoSeeder`): usuarios `carmen` (administradora), `juan` (encargado almacén), `pedro` (operador), `luis` (supervisor),
`german` (mantenimiento), `rosa` (combustible), `ana` (auditor), `mario` (solo consulta) — contraseña `Demo12345`.
**Elimine estos usuarios antes de usar en producción.**

El super administrador se crea con `SUPERADMIN_EMAIL` / `SUPERADMIN_USERNAME` / `SUPERADMIN_PASSWORD` del `.env`; si no hay contraseña se genera una aleatoria y se muestra una sola vez.

## Pruebas

```bash
php artisan test
```

Cubren autenticación, rate limiting, **aislamiento multiempresa** (incluido route-model-binding), permisos en backend, delegación segura de permisos, numeración correlativa y configuración.

## Documentación

- [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md) — multiempresa, permisos, inventario, esquema de BD.
- [`docs/DESPLIEGUE_VPS.md`](docs/DESPLIEGUE_VPS.md) — instalación en producción, permisos, cron y respaldos.

> `legacy/` contiene el placeholder anterior del repositorio (FastAPI); no forma parte del sistema.
