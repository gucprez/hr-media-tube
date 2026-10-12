# Fase 2 — Inventario real

Estado: **terminada y verificada** (102 tests en SQLite y en MariaDB 10.11, más prueba de concurrencia con procesos reales).

## 1. Qué quedó funcionando

| Área | Funcionalidad |
|------|---------------|
| Catálogos | Categorías y unidades de medida (CRUD con permisos, soft delete, no se borra lo que está en uso). |
| Productos | SKU/código, código de barras, nombre, descripción, categoría, unidad base, tipo de manejo (unidades / cajas / cajas + unidades), unidades por caja, nombre del empaque, mínimo y tipo de mínimo (cajas/unidades), ubicación (almacén · pasillo · estante · libre), retornable (goma/tóner/otro), activo. |
| Fotografías | Principal + adicionales (hasta 8). Cámara del celular (`capture`), galería o archivo. Compresión en el navegador (≈180 KB → 15 KB en la prueba) y re-codificación en servidor (JPEG ≤1600 px, miniatura 400 px, EXIF corregido y eliminado). Se pueden marcar como principal o eliminar. |
| Ficha del producto | Foto/galería, existencia en cajas + unidades y total, mínimo, estado, ubicación, existencia por almacén, entradas del mes, salidas del mes, consumo 30 días y últimos movimientos. |
| Existencias | Pantalla *Inventario* con filtros (búsqueda, almacén, categoría, estado). Tarjetas en móvil, tabla en escritorio. |
| Entradas | Documento `ENT-000001…` con **varios productos**, fecha, almacén, proveedor, conduce, observaciones y fotos/PDF adjuntos. |
| Salidas | Documento `SAL-000001…` con varios productos, destino, tipo de destino (otro/empleado), motivo, observaciones y adjuntos. Stock negativo solo con autorización especial. |
| Ajustes | Documento `AJU-000001…` por **conteo físico**: el sistema calcula la diferencia y la registra como movimiento. Motivo y observación obligatorios. |
| Alertas | DISPONIBLE · STOCK BAJO · CRÍTICO · AGOTADO, con mínimo propio por producto. En dashboard, listados, ficha y reportes. |
| Movimientos | Libro histórico con filtros (texto, producto, tipo, almacén, usuario, fechas) y detalle imprimible de cada documento. |
| Búsqueda global | Barra superior con resultados en vivo: productos (nombre, SKU, código de barras, descripción) y categorías. Arquitectura de *proveedores* lista para furgones, vehículos y tickets. |
| Dashboard | Datos reales: productos, unidades en existencia, stock bajo/crítico, agotados, entradas y salidas del mes, alertas y movimientos recientes. Módulos futuros marcados «Próximamente» sin cifras inventadas. |
| Centro de reportes | Menú **Reportes** con las 8 secciones (Almacén, Gasoil, Furgones, Vehículos, Mecánica/Mantenimiento, Gomas, Retornables, Auditoría), cada una con su permiso. Disponibles hoy: *Inventario actual* (imprimible), *Movimiento de inventario* (inicial + entradas − salidas ± ajustes = final por rango), *Stock bajo / crítico / agotados*, *Entradas*, *Salidas*, *Historial*. El resto se muestra como «Fase N» (no son botones muertos). |
| Auditoría | `product.created`, `updated` (antes/después), `product.deactivated`, `product.deleted`, `inventory.entry`, `inventory.exit`, `inventory.adjustment` (con líneas, antes y después). |
| Comandos | `inventory:verify` (stock = suma del libro; programado a diario), `inventory:concurrency-check` (estrés con procesos paralelos), `permissions:sync`. |

## 2. Decisiones técnicas importantes

1. **Unidad base única.** `inventory_stocks.quantity_base` y `delta_base` están en unidad base (decimal 14,3). `QuantityConverter` es el *único* sitio que convierte cajas↔unidades y formatea («5 cajas + 8 rollos», «4 cajas + 1 rollo»). Ni controladores ni vistas ni JavaScript repiten esa lógica: el navegador pide una *vista previa* al servidor (`/inventario/api/vista-previa`).
2. **Libro de movimientos (ledger).** Un documento (`inventory_movements`) tiene N líneas (`inventory_movement_items`). **Cada línea guarda su propio contexto histórico**: empresa, sucursal, almacén, tipo (`entry`, `exit`, `adjustment_in`, `adjustment_out`; preparados `container`, `maintenance`, `return`, `void`), fecha, producto (con SKU y nombre en el momento), unidades por caja, cajas, unidades, `delta_base` con signo, `stock_before_base` y `balance_after_base`. El usuario, referencia, motivo y observaciones están en el encabezado.
3. **El stock no se puede editar.** `StockService` es la única vía; cualquier `->save()/update()/delete()` Eloquent sobre `InventoryStock` lanza excepción (hay test). Líneas y encabezados del libro son inmutables (no se editan ni se borran). Las correcciones son movimientos nuevos.
4. **Concurrencia.** Transacción + `SELECT … FOR UPDATE` de las filas de stock en orden de `product_id` (evita *deadlocks*) + `UPDATE … SET q = q + Δ WHERE q + Δ >= 0` como segunda barrera. Verificado con `inventory:concurrency-check`: 20 procesos simultáneos sobre 10 unidades → exactamente 3 despachos de 3 unidades aceptados, stock final 1, libro consistente (3 corridas).
5. **Stock negativo.** Solo si la empresa lo habilita (Configuración) **y** el usuario tiene `inventory.negative` **y** marca la casilla en la salida. Queda `negative_override` + `authorized_by` en el documento. `inventory.negative` y `reports.audit.view` son *permisos sensibles*: ningún comodín (`inventory.*`, `reports.*`) los concede; solo el administrador (`*`) o una concesión explícita.
6. **Reportes históricos, no del stock actual.** `InventoryLedger::periodSummary()` calcula inicial/entradas/salidas/ajustes/otros/final desde las líneas del libro (con `occurred_at`, `type` y `warehouse_id` desnormalizados e indexados). Ejemplo verificado: inicial 250 + entradas 144 − salidas 180 − ajustes 2 = **final 212**. `stockAt(producto, fecha)` reconstruye la existencia en cualquier fecha.
7. **Fechas retroactivas.** Se permite registrar con fecha pasada (hasta 2 años; nunca futura) para ponerse al día con papeles. Los reportes usan `occurred_at`; los campos *antes/después* de cada línea reflejan el orden de **registro**.
8. **Estados de alerta.** AGOTADO = 0; CRÍTICO ≤ mínimo × `critical_ratio` (0.5 por defecto, configurable por empresa); STOCK BAJO ≤ mínimo; DISPONIBLE el resto. El mínimo se guarda en la unidad elegida (cajas/unidades) y en base (`min_stock_base`, calculado en servidor). El estado se evalúa sobre el **total de todos los almacenes**; si se filtra por almacén, sobre las existencias de ese almacén. El filtro SQL y la lógica PHP están probados para dar el mismo resultado.
9. **Archivos privados.** Fotos y adjuntos van al disco `local` (privado, `storage/app/private`), **nunca por URL pública**; se sirven con `FileController` (permiso + alcance de empresa; otra empresa recibe 404). Se desactivó `serve` del disco local. Solo el logo de la empresa usa el disco público.
10. **Documentos con prefijo.** `ENT-`, `SAL-`, `AJU-` (relleno de 6) por empresa, editables en Configuración → Numeración. Una transacción fallida no consume número.
11. **Gasoil.** No es un producto del inventario: vive en la reserva de combustible (`fuel_tanks`, Fase 4) para no duplicar el control. Por eso no se sembró «Gasoil» como producto demo.
12. **Productos con historial** no se eliminan con existencia; la eliminación es *soft delete* y el historial conserva SKU/nombre originales.
13. **Validado contra MariaDB 10.11.** Detectó que `audit_logs.description` (`varchar(255)`) era corta para documentos con muchos productos (SQLite no lo advierte); se amplió a `text`.

## 3. Contrato de datos para los reportes de la Fase 6

| Sección | Se alimenta de | Estado de datos |
|---------|----------------|-----------------|
| Almacén | `inventory_movement_items` (ledger), `inventory_stocks`, `products`, `categories` | ✅ implementado |
| Furgones | `containers`, `container_materials` + líneas del libro tipo `container` (con `container_id` del encabezado) | esquema listo (Fase 3 escribe) |
| Gasoil | `fuel_receipts`, `fuel_dispatches` (guardan `reserve_before/after`, lecturas, vehículo, usuario) | esquema listo (Fase 4) |
| Vehículos | `vehicles`, `fuel_dispatches`, `inventory_movements.vehicle_id`, `maintenance_*` | esquema listo |
| Mecánica | `vehicle_inspections`, `inspection_items`, `vehicle_incidents`, `maintenance_records`, `maintenance_parts` (+ `movement_id`) | esquema listo (Fase 5) |
| Gomas | `tire_returns` (entrega, kilometraje, estado de la usada, recibido por), líneas `return` | esquema listo |
| Retornables | `returnable_movements` (estado, vehículo, usuario, fecha) | esquema listo |
| Auditoría | `audit_logs` (usuario, IP, acción, antes/después) | ✅ implementado |

Regla: ningún reporte importante se calcula solo con `inventory_stocks`; todos parten del libro o de tablas históricas con fecha.

## 4. Revisión de seguridad multiempresa (Fase 2)

* Todos los modelos nuevos (`Product`, `Category`, `Unit`, `ProductImage`, `Attachment`, `InventoryStock`, `InventoryMovement`, `InventoryMovementItem`) usan `BelongsToCompany` (alcance global + `company_id` automático).
* Route-model-binding ya corre con la empresa fijada (`{product}`, `{movement}`, `{image}`, `{attachment}` de otra empresa → 404).
* Validaciones `exists()` siempre con `where company_id` (categoría, unidad, almacén, producto, almacén de la entrada/salida/ajuste).
* `StockService` vuelve a comprobar producto y almacén por el alcance de empresa (aunque se salte el formulario).
* SQL crudo revisado: solo constantes, valores enlazados (`?`) o enteros casteados; las subconsultas de stock cuelgan de `products` ya filtrado por empresa.
* Archivos: servidos solo por controlador autorizado; nombre original truncado; imágenes re-codificadas; los PDF validados por tipo MIME real.
* XSS: `@js()`/`Js::from` en los `confirm()` (un nombre de producto como `x');alert(1);//` queda inerte; hay test), `x-text` en el cliente.
* Tests dedicados (`InventoryTenancyTest`, 14 comprobaciones): productos, stock, movimientos, documentos, reportes, dashboard, búsqueda, lookup/preview, archivos y numeración, todos aislados.
* **Pendiente para Fase 3:** `container_id` y `vehicle_id` del encabezado de movimientos aún no se usan; cuando se conecten deben validarse con `exists` por empresa (la regla ya está documentada).

## 5. Qué queda para la Fase 3

* Furgones/operaciones: alta, estados (abierto / en proceso / cerrado / anulado), chofer, vehículo, conduce, materiales.
* Al cerrar un furgón: descontar materiales con movimientos tipo `container` (reutilizando `StockService`) y conservar el historial.
* Consumo por furgón y comparación entre furgones.
* Tickets: ticket de materiales y de gasoil, numeración correlativa, anulación con motivo (sin borrar), impresión 1/2/4 por hoja carta, PDF y reimpresión.
* Salidas con destino «Furgón» (selector real) y búsqueda global de furgones/tickets.
* Reportes de furgones en el Centro de reportes.
* **Logo real de Carmen Cargo Express:** no se recibió ningún archivo; súbalo en *Configuración → Empresa*. Aparece automáticamente en el menú, reportes, documentos y (Fase 3) tickets.
