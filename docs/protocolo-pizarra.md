# Protocolo de pizarra v1 (borrador)

Estado: **especificación propuesta, sin implementar ni probar**. El modelo en memoria vive en `android/.../protocol/ScoreboardProtocol.kt`; no existe aún serialización ni transporte.

## Transporte (a validar en prueba T3)
Mensajes JSON UTF‑8, uno por mensaje, sobre cualquiera de:
- WebRTC DataChannel (mismo canal ICE/DTLS que el video), o
- WebSocket en red local.
El protocolo no depende del transporte.

## Versionado
- `v`: entero de versión **mayor** (obligatorio). Un receptor que no soporta `v` responde `error` con `code: "unsupported_version"` y descarta el mensaje.
- Cambios compatibles (campos nuevos opcionales) no suben `v`; el receptor **ignora campos desconocidos**.
- Cambios incompatibles suben `v`.

## Mensajes

### `hello` (al conectar, ambos sentidos)
```json
{ "v": 1, "type": "hello", "session": "b3f1…", "role": "scoreboard-reader", "app": "hr-media-sports/0.1.0", "supports": [1] }
```

### `scoreboard` (lectura)
```json
{
  "v": 1,
  "type": "scoreboard",
  "session": "b3f1…",
  "seq": 1204,
  "capturedAt": 1731176431482,
  "sentAt": 1731176431530,
  "match": { "home": 48, "away": 45, "clockSeconds": 204, "period": 3, "possessionSeconds": 18 },
  "confidence": 0.97,
  "origin": "recognition"
}
```

| Campo | Tipo | Regla |
|-------|------|-------|
| `seq` | entero ≥ 0 | Creciente por sesión; un receptor descarta `seq` menor o igual al último aceptado (detecta duplicados/desorden) |
| `capturedAt` | epoch ms (reloj del emisor) | Instante de la imagen leída |
| `sentAt` | epoch ms (reloj del emisor) | Instante de envío |
| `match.home` / `away` | 0–199 | Fuera de rango → mensaje descartado y contado como inválido |
| `match.clockSeconds` | 0–5999 | |
| `match.period` | 1–8 | 5–8 = prórrogas |
| `match.possessionSeconds` | 0–24 | |
| `confidence` | 0–1 o `null` | Confianza global; `null` si el lector no la calcula |
| `origin` | `recognition` \| `manual` | Una corrección hecha en el celular lector se marca `manual` |

Reservado para v1.x (aditivo): confianza por campo (`match.confidence.{home,away,clockSeconds,…}`), `clockRunning`.

### `heartbeat` (1 por segundo, ambos sentidos)
```json
{ "v": 1, "type": "heartbeat", "session": "b3f1…", "sentAt": 1731176432530, "echo": 1731176431530 }
```
`echo` devuelve el `sentAt` recibido para estimar RTT y desfase de relojes.

### `error`
```json
{ "v": 1, "type": "error", "code": "unsupported_version", "detail": "…" }
```

## Estado de conexión (lo que muestra la interfaz)
- **No conectado**: sin canal abierto.
- **Conectando**: canal en negociación o `hello` pendiente.
- **Conectado**: canal abierto y `hello` intercambiado.
- **Sin datos recientes**: conectado, pero la antigüedad de la última lectura supera `STALE_AFTER_MILLIS` (3 000 ms). Umbral inicial a calibrar en T3.
- La antigüedad se calcula con el reloj **local de recepción** (`receivedAt`), no con relojes de dos dispositivos. `capturedAt`/`sentAt` sirven para latencia, usando el desfase estimado con `heartbeat`.
- Si faltan 3 heartbeats seguidos, el enlace pasa a **No conectado**.

## Seguridad (mínimo para v1)
- DTLS (WebRTC) cifra el canal; en WebSocket local debe usarse `wss` o un código de emparejamiento de un solo uso mostrado en el estudio.
- Mensajes sin `session` válida se descartan.
- Los datos de pizarra son informativos: la corrección manual en el estudio siempre tiene prioridad visible (`origin = Manual` en la UI).
