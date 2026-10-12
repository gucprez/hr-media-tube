# Fases 3 a 7 — qué se construyó y cómo verificarlo

Todas las pruebas: `php artisan test` (SQLite en memoria; también pasan contra MariaDB 10.11 con `DB_CONNECTION=mysql …`).

## Fase 3 — Furgones y tickets
Furgón con materiales, chofer, vehículo y conduce; flujo abierto → en proceso → cerrado (descuenta del inventario en **una sola transacción** con bloqueo) o anulado (reversa en el libro, con motivo). Tickets de materiales y de gasoil con numeración correlativa, impresión **1, 2 o 4 por hoja carta**, reducción automática si hay muchas líneas, marca **REIMPRESIÓN**, PDF, anulación sin borrado. Anulación genérica de movimientos con reverso contable.

## Fase 4 — Gasoil y vehículos
Tanque con **libro de gasoil** (recepciones por lectura de medidor, despachos por lectura o galones directos, ajustes, anulaciones), reserva siempre = suma del libro (`fuel:verify`, `fuel:concurrency-check`). Ficha de vehículo con kilometraje, rendimiento km/galón, línea de tiempo y foto. Gasoil de un furgón se despacha al cerrarlo.

## Fase 5 — Inspecciones, averías, mantenimiento, gomas, retornables
- **Inspección mensual** con checklist configurable de 18 puntos (los cambios solo afectan inspecciones futuras), fotos, próxima inspección y creación automática de avería.
- **Averías** con prioridad, historial de estados y sincronización del estado del vehículo (con avería / en mantenimiento / operativo).
- **Mantenimiento**: reparación o preventivo; las piezas salen del inventario (libro), cierran la avería; anular devuelve las piezas y reabre la avería.
- **Gomas**: entrega de gomas nuevas a camiones con seguimiento de la goma usada pendiente (recibir con condición, o exonerar con motivo).
- **Retornables** (tóner, etc.): entregado → usado → retornado / recargable / desechado, con saldo pendiente por persona o vehículo.
- Dashboard con tarjetas reales de flotilla y alertas.

## Fase 6 — Centro de reportes
- Marco común (`App\Services\Reports`): `Period` (diario, semanal, mensual, trimestral, semestral, anual o rango), `ReportResult` (columnas tipadas, totales, resumen, notas), `ReportRegistry` y un conjunto de definiciones por sección.
- **35 reportes** en las 8 secciones (almacén, gasoil, furgones, vehículos, mecánica, gomas, retornables, auditoría), todos calculados desde el **libro de movimientos / historial**, no solo del stock actual.
- Cada reporte: pantalla con filtros, **Excel real** (celdas numéricas y de fecha con formato, fila de totales, encabezado de empresa, protección contra inyección de fórmulas), **PDF** (logo real, horizontal si hay muchas columnas, firmas Preparado/Revisado/Aprobado) e impresión.
- Exportar requiere `reports.export` y queda registrado en auditoría (`report.export`). Cada sección exige su propio permiso; auditoría exige `reports.audit.view` (permiso sensible).
- «Comparación entre furgones» marca **Exceso** cuando un furgón consume >25 % sobre el promedio del producto.
- Las pantallas de Fase 2 (inventario actual y movimiento) conservan su diseño y ahora ofrecen Excel/PDF.

## Fase 7 — PWA, seguridad, optimización, despliegue
- **PWA instalable**: `manifest.webmanifest`, íconos 192/512/maskable, service worker (`public/sw.js`) con página sin conexión. Solo se guardan estáticos públicos (`/build`, `/icons`); las páginas y los datos **nunca** se guardan.
- **CSP estricta** (`config/security.php`): se eliminaron todos los manejadores `on*=""` y `<script>` en línea (una prueba lo verifica). `script-src` incluye `'unsafe-eval'` porque Alpine.js evalúa sus expresiones.
- **N+1**: `Model::preventLazyLoading()` activo en desarrollo/pruebas (en producción solo registra). El barrido encontró y corrigió dos casos reales (dashboard y línea de tiempo del vehículo).
- Límites de peticiones en búsqueda y exportaciones; login ya limitado.
- Kit de despliegue en `deploy/` (Nginx nuevo, pool PHP-FPM propio, `.env` de producción, `deploy.sh`, `smoke-test.sh`, cron, respaldos) — ver `docs/DESPLIEGUE_VPS.md`.

## Cambios en pruebas de fases anteriores (intencionales)
- Fase 2: el panel dejó de mostrar «Próximamente / Próximos módulos / Furgones del mes» porque esos módulos ya son reales (tarjetas con cifras reales).
- Fase 2: en el centro de reportes, los reportes de otras secciones dejaron de ser «Fase N» y ahora están disponibles.

## Pendiente / decisiones del dueño
- **Logo**: nunca se recibió el logo real de Carmen Cargo Express; se sube desde *Configuración → Empresa* y se usa automáticamente en reportes, tickets y PDF. Los íconos de la app instalable son genéricos («HR») y se pueden reemplazar en `public/icons/`.
- **Dominio**: confirmar `hr.deportesxtras.com` vs `hr.deportesxtra.com` y crear el registro DNS A → `54.39.98.84`.
- Ejecutar el despliegue en el VPS lo hace el dueño (o lo autoriza expresamente): el sistema no se instaló ni se tocó ningún servidor.
