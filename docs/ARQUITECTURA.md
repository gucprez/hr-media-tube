# HR Global Intelligence — Arquitectura

Estado: **Fase 2, primera entrega**. Lo marcado ✅ está implementado y probado; lo demás es plan.

## Decisión de stack
| Capa | Decisión | Motivo |
|---|---|---|
| Ingesta, verificación, API | **FastAPI (Python)** ✅ | Ya existe en el repo; parsing de feeds/XML y agrupación son más simples en Python. |
| Frontend | **Next.js + TypeScript + Tailwind + MapLibre GL JS** (plan) | Pedido en el spec. Consume `/api/*`; se sirve aparte. |
| Persistencia | **SQLite** ✅ (v0) → **PostgreSQL/PostGIS** | El esquema ya separa columnas de filtro y JSON; PostGIS se adopta cuando el volumen/filtros geoespaciales lo exijan y el VPS lo permita. |
| Cola/caché | Ninguna por ahora | Redis solo si hay varios workers; no hace falta con un proceso. |
| Programación | Tareas asyncio por conector ✅ (`HRGI_INGEST=1`) | Un solo proceso, intervalos propios por fuente. |
| Despliegue | Docker Compose + proxy inverso existente (plan) | **Pendiente de auditar el VPS** (`scripts/audit-vps.sh`); no se toca nada existente. |

Si la auditoría muestra pocos recursos (<2 GB RAM), se omite PostGIS/Redis y se queda SQLite + un único proceso.

## Modelo de datos (`hrgi/models.py`)
Cada incidente guarda: id estable, categoría, país/región, coordenadas con `location_precision`
(`exact` | `approximate` | `none`), **`occurred_at` (hecho) separado de `published_at` (publicación)**,
`updated_at`, fuentes (`SourceRef` con URL original), `verification`, `confidence` y
`confidence_rationale`. `data_class` (current/historical/simulated) se calcula al servir.

## Verificación (`hrgi/verification.py`) — determinista, sin IA
- `official`: al menos una fuente oficial.
- `corroborated`: ≥2 publicadores distintos sin fuente oficial (no implica independencia editorial).
- `reported`: una sola fuente de medios.
- `retracted`: solo por acción de un administrador; se conserva al reingerir.

## Agrupación (`hrgi/dedupe.py`)
Misma categoría + ventana de 6 h + ≤50 km (o título muy similar si falta ubicación). Se fusionan fuentes
sin duplicarlas y se recalcula la verificación. Los umbrales son heurísticos y deben afinarse con datos.

## Conectores verificados (probados desde el contenedor de desarrollo)
| Conector | Cubre | Licencia / límites | Estado |
|---|---|---|---|
| USGS | Sismos M4.5+ | Dominio público | ✅ datos reales |
| GDACS (ONU/CE) | Sismos, ciclones, inundaciones, volcanes, incendios | Atribución | ✅ datos reales |
| NASA EONET | Incendios, tormentas, volcanes, inundaciones | Abierto, atribución | ✅ datos reales |
| GDELT DOC 2.0 | Noticias sobre misiles/drones, ataques aéreos, ciberataques | Atribución; **1 petición/5 s**, devuelve 429 si se excede | ⚠️ **No verificado en vivo**: desde el contenedor devolvió 429 de forma persistente (IP compartida). Un primer sondeo manual sí devolvió artículos reales y el parser sigue ese formato, pero falta confirmarlo desde el VPS. |
| ReliefWeb | Crisis humanitarias | **Exige `appname` aprobado** (403 sin él) | ⏳ solicitar appname |
| ACLED | Conflicto armado | Requiere licencia/cuenta | ⏳ pendiente de acceso |
| Alertas oficiales de defensa civil (p. ej. Israel Home Front Command, Ucrania) | Alertas aéreas | Pendiente revisar términos de reutilización de cada una | ⏳ |

**Importante:** hoy **no hay fuente oficial de alertas de misiles/drones integrada**. Esa categoría solo se
alimenta de noticias GDELT, marcadas como "reportado". No se afirma nada más.

GDELT: sus resultados son artículos, no hechos. Se registran sin coordenadas, sin hora del hecho y
sin país (el país de GDELT es el del medio, no el del suceso).

## Seguridad (implementado ✅ / plan)
✅ Admin por `Authorization: Bearer $ADMIN_TOKEN` con comparación en tiempo constante; sin token configurado, admin deshabilitado.
✅ Límite de 60 req/min/IP en la API pública (en memoria; detrás de proxy hay que fiar `X-Forwarded-For` solo del proxy propio).
✅ XML con `defusedxml`. ✅ Sin secretos en el repo.
Plan: usuarios/roles reales, HTTPS, logs, backups, monitoreo.

## API actual
`GET /api/health`, `/api/incidents` (filtros: category, country, verification, data_class, since, q, limit),
`/api/incidents/{id}`, `/api/sources` (estado de conectores) · Admin: `POST /api/admin/ingest`,
`POST /api/admin/incidents/{id}/retract`.

## Pendiente (siguientes PRs)
1. Frontend Next.js: mapa MapLibre, filtros, línea temporal, i18n es/en. 2. Países por geocodificación inversa offline (hoy `country` solo viene si la fuente lo da).
3. Analista determinista con citas. 4. Web push + resúmenes. 5. Panel admin completo. 6. Docker Compose + despliegue tras auditar el VPS.
