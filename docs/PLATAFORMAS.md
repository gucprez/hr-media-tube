# HR Media Sports – alcance multiplataforma

**Estado:** propuesta + primera entrega Android (demo de interfaz). Nada de lo descrito como "propuesto" está validado todavía.

## Qué demuestra y qué NO demuestra esta entrega
- **Demuestra:** una interfaz de demostración **solo Android** (Kotlin + Jetpack Compose): su distribución, su adaptación a tamaños y escalas de texto, y las reglas del marcador, con pruebas unitarias y capturas simuladas.
- **NO demuestra compatibilidad con Windows, macOS ni Chrome/PWA.** Jetpack Compose para Android no corre en esas plataformas tal cual: la interfaz de escritorio y web hay que construirla con otra tecnología (o portarla) y validarla. Nada de esa interfaz se ha ejecutado ni probado fuera de Android.
- **NO incluye** cámaras, OCR/reconocimiento de pizarra, transmisión, grabación ni repeticiones: están marcadas como no disponibles en la propia interfaz.
- La integración de esas funciones con los motores audiovisuales de cada plataforma se decide con las pruebas T1–T8 (`PRUEBAS-TECNICAS.md`, todas pendientes) y se trabaja en una entrega independiente (rama `claude/camara-remota-prueba-tecnica`, prueba técnica Android Cámara remota → Laptop Estudio).

## Plataformas objetivo
Android (celular y tablet) · Windows · macOS · Web (Chrome y PWA, incluida evaluación en Chromebook).

## Decisión pendiente: Flutter vs. Kotlin/Compose
El cambio de alcance propone **Flutter** como base compartida; la tarea de esta entrega pide explícitamente **Kotlin + Jetpack Compose** para la pantalla Estudio. Ambas instrucciones se respetaron así:

- La pantalla Estudio se implementó en Kotlin/Compose (`android/`), como se pidió.
- **No** se migró nada a Flutter ni se inició un proyecto Flutter: la propuesta debe validarse primero (ver `MATRIZ-FUNCIONES.md`, pruebas T1–T8).
- Las capas 2–5 se mantienen detrás de interfaces pequeñas para que el cambio de motor de UI no obligue a reescribir las reglas ni el protocolo.

Criterio para decidir tras las pruebas: si WebRTC, captura de cámara y composición no son viables con Flutter en Windows/macOS/Web sin código nativo por plataforma, se evalúa una alternativa (p. ej. Compose Multiplatform para escritorio + cliente web separado). Ese análisis es parte de la validación y no se adelanta aquí.

## Separación en capas

| # | Capa | Responsabilidad | Dónde está hoy | Portable entre plataformas |
|---|------|-----------------|----------------|----------------------------|
| 1 | Interfaz adaptable | Móvil / tablet / escritorio; componentes, navegación | `android/.../ui` (Compose) | No: se rehace por framework de UI |
| 2 | Reglas y estado del partido | Marcador, reloj, cuarto, posesión, validaciones | `android/.../domain` (Kotlin puro) | Sí en la JVM; reimplementar o compartir (KMP) si se cambia de lenguaje |
| 3 | Protocolo entre dispositivos | Mensajes de pizarra versionados, estado de enlace | Especificación: `protocolo-pizarra.md`; estado de enlace y antigüedad: `android/.../protocol` | La especificación sí (JSON); el código por plataforma |
| 4 | Reconocimiento de pizarra | Leer el marcador físico con la cámara | **No implementado.** Interfaz `ScoreboardSource`; solo `DemoScoreboardSource` | Depende del motor de visión elegido (por evaluar) |
| 5 | Captura, composición, codificación, grabación y transmisión | Cámaras, mezcla, encoder, archivo, salida | **No implementado.** Interfaz `MediaEngine`; solo `DemoMediaEngine` (todas las capacidades en `false`) | Muy dependiente de la plataforma |

Regla: la UI solo habilita una función si `MediaEngine.capabilities` / `ScoreboardSource` la declara. En el modo demostración todo está declarado como no disponible.

## Colaboración entre dispositivos (objetivo, no implementado)
Un Android podría enviar cámara (WebRTC) y/o datos de pizarra (protocolo v1) a un estudio en Windows, macOS, Android o web. **Qué combinaciones funcionan realmente se decide solo con la matriz verificada.**

## Servicios puente
No se presupone ningún servidor de pago. Opciones a evaluar, de menor a mayor costo:

| Opción | Para qué | Instalación | Costo |
|--------|----------|-------------|-------|
| Solo red local (sin servidor) | Cámara y pizarra entre dispositivos en la misma Wi‑Fi; señalización por código QR / código de emparejamiento / mDNS | Ninguna | $0 |
| STUN público | Descubrir dirección pública para conexiones fuera de la LAN | Configuración de ICE; hay servidores STUN públicos gratuitos sin garantía de servicio | $0, sin SLA |
| Servidor de señalización propio (WebSocket mínimo) | Emparejar dispositivos fuera de la LAN | Un proceso pequeño; puede correr en la PC del estudio | $0 si se aloja en el propio estudio |
| TURN (p. ej. coturn, código abierto) | Conexiones que STUN no logra (NAT estrictos, datos móviles) | Servidor con IP pública | Costo de hosting + tráfico; **depende del proveedor y no se estimó** |
| Puente WebRTC→RTMP/SRT (p. ej. MediaMTX o FFmpeg, código abierto) | Navegador no puede emitir RTMP directamente | Proceso local en el estudio | $0 de licencia; CPU local |

Los costos de TURN y de cualquier alojamiento no se calcularon: dependen del proveedor y del volumen de video.

## Código abierto
- Licencia del proyecto: **Apache-2.0** (archivo `LICENSE`). Se eligió por ser la misma de todas las dependencias incorporadas y por incluir concesión de patentes; si el titular prefiere otra (MIT, GPL‑3.0), debe cambiarse antes de aceptar contribuciones externas.
- Revisión de dependencias: `LICENCIAS.md`.
