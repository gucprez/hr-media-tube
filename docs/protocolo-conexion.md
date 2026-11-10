# Protocolo de conexión local v1 (estudio ↔ dispositivos)

Estado: **implementado** en `android/core-link` (compartido), `android/studio-server` (laptop) y la app Android (rol «Cámara remota»). Probado con sockets reales entre la app (cliente OkHttp) y el servidor, y entre el servidor y un cliente JavaScript independiente (`studio-server/e2e`). **No** probado entre un teléfono real y una laptop real: ver `MATRIZ-FUNCIONES.md`.

Alcance de la primera versión: misma red local, sin cuentas ni nube. El pizarra (lector) envía datos, no video continuo: sus mensajes siguen `protocolo-pizarra.md`.

## Piezas
| Pieza | Qué hace |
|-------|----------|
| Estudio (laptop) | Servidor local (puerto 8765 por defecto) + interfaz web en `http://localhost:8765`. Hace de **señalización** y valida la vinculación. El video lo recibe el navegador por WebRTC. |
| Dispositivo | App Android. Se conecta por WebSocket (`ws://IP:8765/ws/device`) y envía su video por WebRTC directamente al navegador del estudio. |
| QR | Solo **configura y autoriza**: dónde está el estudio y un secreto temporal. No transporta video ni datos de partido. |

## QR de vinculación
`hrms://pair?v=1&h=<IP>&p=<puerto>&s=<id de sesión>&k=<secreto base64url, 16 bytes>&e=<vencimiento, epoch s>`

- El secreto es aleatorio (128 bits), **vence a los 5 minutos** y se regenera con «Crear estudio».
- El secreto nunca viaja por la red: solo se usa para firmar (HMAC-SHA256).

## Apretón de manos (autenticación mutua)
1. Servidor → dispositivo: `challenge {sessionId, nonceS}`.
2. Dispositivo → servidor: `hello {deviceId, name, role, auth, nonceD, proof}` con
   `proof = HMAC(clave, "dev|auth|sessionId|nonceS|nonceD|deviceId")`.
   - `auth = "pairing"`: `clave` = secreto del QR (primer vínculo).
   - `auth = "token"`: `clave` = token de reconexión (ver abajo).
3. Servidor → dispositivo: `hello_ok {proof, state}` con `proof = HMAC(clave, "srv|sessionId|nonceS|nonceD|deviceId")`. **El dispositivo verifica esta prueba**: así sabe que habla con el estudio y no con un impostor.
4. Clave de sesión: `HMAC(clave, "sess|sessionId|nonceS|nonceD")`.

Errores (`hello_error {code}`): `no_studio` (reintentable), `bad_proof`, `pairing_expired`, `device_exists`, `locked`, `unknown_device`, `unsupported_version`, `bad_role`, `bad_device`, `bad_auth`.

## Mensajes de sesión (firmados)
Después del apretón, cada mensaje va en un sobre `{seq, msg, mac}` con
`mac = HMAC(claveSesión, dirección|seq|msg)`, dirección `d2s` (dispositivo→servidor) o `s2d`.
`seq` crece estrictamente en cada sentido: se descartan repeticiones, reordenamientos y mensajes alterados (incluidas las ofertas SDP, que contienen la huella DTLS del video).

| Mensaje | Sentido | Uso |
|---------|---------|-----|
| `accepted` / `rejected{reason}` | estudio → dispositivo | Resultado de la aceptación del operador |
| `offer{sdp}` / `answer{sdp}` / `ice{…}` | ambos | Señalización WebRTC |
| `onair{active}` | estudio → cámara | La fuente está (o dejó de estar) AL AIRE |
| `telemetry{…}` | cámara → estudio | Cada ~2 s: resolución, fps, tasa, codificador (hardware/software), RTT, pérdida, limitación, batería, temperatura, estado térmico, contador de carga, corriente, lente, zoom, audio |
| `bye` | dispositivo → estudio | Cierre ordenado |

## Aceptación y reconexión
- Un dispositivo vinculado con el QR queda **pendiente** hasta que el operador pulsa «Aceptar». Mientras está pendiente, el estudio **ignora** sus ofertas y telemetría.
- Al aceptar, ambos lados **derivan** el token `HMAC(secreto, "token|deviceId")`. No se transmite. El dispositivo lo guarda.
- **Reconexión sin duplicar fuentes**: el `deviceId` es estable. Con `auth="token"` el estudio reemplaza la conexión anterior de ese `deviceId` (cierra la vieja) y reutiliza su entrada; la fuente no se duplica, conserva su rol (PREVIA/AL AIRE) y el estudio vuelve a enviar `onair`. El dispositivo reintenta con espera creciente (0,5 → 8 s). Tras reconectar crea una oferta WebRTC nueva y el estudio descarta la conexión de media anterior.
- Con el QR no se puede suplantar a un dispositivo ya aceptado (`device_exists`).
- «Desconectar» en el estudio olvida el dispositivo: debe vincularse de nuevo. «Crear estudio» genera credencial nueva y olvida todos.
- Después de 10 pruebas inválidas con el QR, la credencial se bloquea (`locked`).

## Límite de la primera versión
El estudio acepta **una sola cámara** (`--max-cameras 1`) hasta que se mida el comportamiento de una (720p/30, latencia, consumo, temperatura, estabilidad). Ver `PRUEBAS-TECNICAS.md`.

## Modelo de seguridad y límites (léelo)
Protege contra: espías pasivos en la Wi-Fi (el secreto no viaja), alteración de señalización (MAC), repeticiones, suplantación del estudio (prueba del servidor), suplantación de un dispositivo aceptado, fuerza bruta (bloqueo), y páginas web ajenas que intenten controlar el estudio (la interfaz y su API solo aceptan la propia laptop, comprueban `Host` y `Origin`).

**No** protege contra: alguien que fotografíe el QR dentro de sus 5 minutos (podrá solicitar vincularse, pero el operador debe aceptarlo); la señalización va en `ws://` sin cifrar (el contenido no es secreto: SDP/ICE, y está autenticado). El video viaja cifrado por DTLS-SRTP (WebRTC). El nombre del dispositivo y la telemetría se ven en claro en la red local.
La app declara `usesCleartextTraffic` porque una red doméstica no tiene certificados; el control de acceso lo hace el HMAC, no TLS.

## Interoperabilidad
Las fórmulas de arriba son la especificación. Dos implementaciones independientes la cumplen: Kotlin (app y servidor, módulo `core-link`) y JavaScript/WebCrypto (`studio-server/e2e/e2e.cjs`).
