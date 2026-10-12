# Arquitectura

## 1. Multiempresa (single database, `company_id`)

* Toda tabla operacional tiene `company_id` (FK a `companies`).
* Trait `App\Models\Concerns\BelongsToCompany`: **global scope** que filtra por la empresa activa y rellena `company_id` al crear.
* `App\Support\CurrentCompany` guarda la empresa activa de la petición. La fija el middleware `ResolveCompany`:
  * usuario normal → su empresa (no editable);
  * super administrador → empresa elegida en sesión (`/plataforma/empresas` → *Entrar*).
* `ResolveCompany` se ejecuta **antes** de `SubstituteBindings` (prioridad de middleware) para que `{user}`, `{product}`… también queden aislados. Hay un test que lo garantiza.
* Usuario autenticado sin empresa activa ⇒ el scope devuelve **0 filas** (fail-closed).
* Validaciones `exists`/`unique` siempre acotan por `company_id`.
* Alta de empresas: `CompanyProvisioner` (roles, sucursal, almacén, numeraciones, administrador) en una transacción.

## 2. Roles y permisos

* Catálogo único en `config/permissions.php` (`products.view`, `inventory.dispatch`, …) → tabla `permissions` (`php artisan permissions:sync`).
* Los **roles** pertenecen a la empresa (copias de plantillas editables). El acceso real se evalúa **por permiso**: `User::hasPermission()`.
* **Overrides por usuario** (`permission_user.granted`): otorgar o denegar sobre lo que da el rol.
* `Gate::before` → `@can('products.edit')`, `$user->can()`, `Gate::allows()` funcionan con cualquier permiso con punto.
* Backend: middleware `permission:clave[,clave2]` en cada ruta; ocultar botones es solo cosmético.
* Anti-escalada: un administrador solo puede delegar permisos que él mismo posee.
* Rol **Solo consulta**: todos los `*.view` + reportes; ninguna escritura.

## 3. Inventario (Fase 2+, esquema ya creado)

* Unidad base interna por producto (`quantity_base`, decimal 14,3). `units_per_box` convierte cajas↔unidades:
  `5 cajas + 8 rollos` (36/caja) = `188` base. La UI muestra `5 cajas + 8 unidades`.
* **Las existencias nunca se editan directamente**: `inventory_stocks` solo cambia mediante `inventory_movements` + `inventory_movement_items` (con `delta_base` firmado y `balance_after_base`), siempre en transacción con `lockForUpdate`.
* Tipos: `entry, exit, adjustment, container, maintenance, return, receipt, void`. Anular = movimiento inverso (`reversal_of_id`), nunca borrar.
* Stock negativo bloqueado salvo permiso `inventory.negative` + configuración de la empresa.
* Estados: `AGOTADO` (0), `CRÍTICO` (≤ mínimo × `critical_ratio`), `STOCK BAJO` (≤ mínimo), `NORMAL`.

## 4. Combustible (Fase 4)

`fuel_tanks.current_gallons` es la reserva. Recepción: `reserva_después = reserva_antes + galones`. Despacho: `galones = lectura_final − lectura_inicial`, `reserva_después = reserva_antes − galones`. Se guarda el snapshot de reserva en cada registro; se anula con trazabilidad.

## 5. Archivos

Fotos y documentos en disco (`storage/app/public`); en BD solo la ruta (`attachments`, `product_images`, `vehicles.photo_path`). Compresión en el navegador antes de subir y re-codificación en servidor (Fase 2).

## 6. Auditoría

`App\Services\Audit::log()` / `::changes()` → `audit_logs` (usuario, fecha, IP, user-agent, acción, registro afectado, valores anterior/nuevo). Modelo inmutable. Las contraseñas nunca se registran.

## 7. Seguridad

Hash bcrypt, CSRF, validación backend, Eloquent (consultas parametrizadas), rate limiting de login (5/min por usuario+IP), regeneración de sesión, cabeceras de seguridad, soft deletes en entidades maestras, cuentas/empresas desactivadas expulsadas en cada petición.

## 8. Numeración

`document_sequences` por empresa y tipo (`container`, `ticket_materials`, `ticket_fuel`, `fuel_receipt`, `movement`) con prefijo y relleno; `DocumentNumbering::next()` usa `lockForUpdate` y no puede retroceder.

## 9. Esquema de base de datos

| Migración | Tablas |
|-----------|--------|
| `0001_01_01_000000` | companies, branches, warehouses, document_sequences, permissions, roles, permission_role, users, permission_user, password_reset_tokens, sessions |
| `2026_01_01_000100` | categories, units, products, product_images |
| `…000200` | drivers, vehicle_types, vehicles, containers, inventory_stocks, inventory_movements, inventory_movement_items, container_materials |
| `…000300` | fuel_tanks, fuel_receipts, fuel_dispatches |
| `…000400` | inspection_checklist_items, vehicle_inspections, inspection_items, vehicle_incidents, maintenance_records, maintenance_parts, returnable_movements, tire_returns |
| `…000500` | tickets, ticket_items, attachments, notifications, audit_logs |

Todas con FK e índices; compatibles con MySQL/MariaDB y SQLite (tests).
