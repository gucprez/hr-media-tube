# HR Media System — Gestión de almacén, operaciones, combustible y flotilla

Plataforma web **multiempresa (SaaS)** desarrollada por **HR Media System**. Primer cliente: **Carmen Cargo Express**.

Laravel 12 · PHP 8.2+ · MySQL/MariaDB · Blade + Tailwind CSS 4 + Alpine.js · Vite

## Estado del proyecto

| Fase | Contenido | Estado |
|------|-----------|--------|
| 1 | Arquitectura, esquema BD completo, autenticación, multiempresa, roles/permisos, layout, configuración, auditoría | ✅ Hecha |
| 2 | Productos, fotos, categorías, inventario, cajas/unidades, entradas/salidas/ajustes, alertas, búsqueda global, dashboard real, reportes de almacén | ✅ Hecha — ver [`docs/FASE2.md`](docs/FASE2.md) |
| 3 | Furgones, materiales, tickets, impresión 1/2/4 por hoja | ✅ Hecha |
| 4 | Gasoil (recepciones, despachos, reserva) y vehículos | ✅ Hecha |
| 5 | Inspecciones, averías, mantenimiento, gomas, retornables | ✅ Hecha |
| 6 | Reportes (35), Excel, PDF, auditoría | ✅ Hecha |
| 7 | PWA/móvil, seguridad (CSP), optimización, kit de despliegue | ✅ Hecha — ver [`docs/FASES_3_A_7.md`](docs/FASES_3_A_7.md) |


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
php artisan test                        # 196 tests (SQLite en memoria por defecto)
DB_CONNECTION=mysql DB_DATABASE=… php artisan test   # también pasan contra MariaDB/MySQL
php artisan inventory:verify            # el stock de cada producto = suma de su libro de movimientos
php artisan inventory:concurrency-check # estrés con procesos paralelos (solo MySQL/MariaDB)
```

Cubren autenticación, rate limiting, **aislamiento multiempresa** (incluido route-model-binding y archivos), permisos en backend (rol «Solo consulta» incluido), conversión cajas/unidades, motor de inventario (entradas, salidas, ajustes, stock negativo, integridad), alertas, reportes por rango de fechas, búsqueda y escape XSS.

## Documentación

- [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md) — multiempresa, permisos, inventario, esquema de BD.
- [`docs/FASE2.md`](docs/FASE2.md) — lo que funciona en Fase 2, decisiones técnicas, contrato de datos de reportes y revisión de seguridad.
- [`docs/FASES_3_A_7.md`](docs/FASES_3_A_7.md) — qué hace cada fase, decisiones y pendientes.
- [`docs/DESPLIEGUE_VPS.md`](docs/DESPLIEGUE_VPS.md) — instalación en el VPS paso a paso (sin tocar lo existente), cron, respaldos y prueba de humo.

> `legacy/` contiene el placeholder anterior del repositorio (FastAPI); no forma parte del sistema.
