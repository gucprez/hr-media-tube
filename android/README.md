# HR Media Sports – app Android (demo de interfaz)

Pantalla **Estudio** en Kotlin + Jetpack Compose. **Es un modo demostración**: no hay cámaras, cámaras remotas, grabación, transmisión ni repeticiones reales. La interfaz lo indica con una franja ámbar permanente, una etiqueta DEMO y mensajes en los controles no disponibles. Contexto multiplataforma: [`../docs/PLATAFORMAS.md`](../docs/PLATAFORMAS.md).

## Modos
Al abrir la app eliges el rol del dispositivo (el botón Atrás vuelve a esta pantalla):
- **Cámara remota** (horizontal): **funcional, en validación** (no probada en un teléfono real). Escanea el QR del estudio de la laptop (`studio-server/README.md`), espera la aceptación y envía video por WebRTC a 720p/30 con codificación por hardware si el chip la ofrece. Pide permisos de cámara y micrófono **solo al usarlos**. Controles: lente (trasera/frontal), zoom, enfoque (tocar la vista previa), exposición y micrófono. Muestra conexión, calidad (según fps, pérdida, RTT y limitación del codificador) y un aviso **AL AIRE** cuando el estudio la usa en la salida. Si se corta la conexión, reintenta sola con su token y el estudio no duplica la fuente. También permite pegar el enlace de vinculación (útil en emuladores). La pantalla se mantiene encendida y la app debe quedar en primer plano. «Lente» significa trasera/frontal: no se seleccionan lentes físicas (gran angular, tele) de un mismo lado.
- **Estudio** (horizontal): ver abajo.
- **Lector de pizarra** (celular vertical): marco de cámara con las cinco zonas (Local, Tiempo, Visita, Cuarto, Posesión). **Ajustar zonas** permite mover (arrastrando) y redimensionar (− / +) la zona seleccionada, y **Guardar plantilla** la guarda en el dispositivo y se recupera al reabrir. Lo demás es demo: no hay cámara, reconocimiento ni conexión con el estudio. Los «datos detectados» son valores de ejemplo rotulados y **Iniciar envío** aparece como no disponible.

## Qué funciona en el Estudio (demo)
- Seleccionar una fuente (Cámara 1, Cámara 2, Descanso, Resultado) la prepara en **Vista previa** (borde naranja).
- **Poner al aire** envía la fuente de Vista previa a **Al aire** (borde rojo). Es solo estado de interfaz: no hay salida de video.
- Panel de marcador con datos de **ejemplo** (no hay pizarra conectada) y **corrección manual** operativa.
- Navegación: Estudio, Cámaras, Gráficos, Repeticiones y Ajustes. Las cuatro últimas muestran el estado real de esta versión (sin cámaras conectadas, sin búfer de repeticiones, sin preferencias editables).
- Grabar, Transmitir y «Guardar últimos 20 s» aparecen como **no disponibles** y explican por qué al pulsarlos.
- Cámaras: placeholders rotulados «Sin video». «Descanso» y «Resultado» son gráficos dibujados por la app.

## Requisitos
- JDK 17 o superior (probado con 21)
- Android SDK con `platforms;android-35` y `build-tools` (o Android Studio Ladybug o más reciente)

## Generar la APK
```bash
cd android
echo "sdk.dir=/ruta/a/Android/Sdk" > local.properties   # Android Studio lo crea solo
./gradlew assembleDebug
```
La APK queda en `android/app/build/outputs/apk/debug/app-debug.apk`. Instalar en un dispositivo con depuración USB:
```bash
adb install -r app/build/outputs/apk/debug/app-debug.apk
```
En Android Studio: abrir la carpeta `android/` → *Build ▸ Build APK(s)*.

La APK de debug está firmada con la clave de depuración y sirve solo para pruebas. Una APK de publicación requiere tu propio keystore (no lo subas al repositorio).

## Pruebas
```bash
./gradlew testDebugUnitTest      # app (incluye el cliente de enlace contra el servidor real en la JVM)
./gradlew :core-link:test :studio-server:test
```

## Estructura
| Paquete | Capa |
|---------|------|
| `domain` | Reglas y estado del partido (Kotlin puro) |
| `protocol` | Estado de enlace y reglas de antigüedad del protocolo de pizarra v1 ([especificación](../docs/protocolo-pizarra.md)) |
| `engine` | Interfaces `MediaEngine` y `ScoreboardSource` + implementaciones demo |
| `data` | Persistencia local (plantilla de zonas en SharedPreferences) |
| `camera` | Rol Cámara remota: cliente del enlace, captura CameraX→WebRTC, escáner de QR, telemetría |
| `ui` | Compose: tema, componentes, pantallas (`StudioViewModel`, `ReaderViewModel`, `AppViewModel`) |

El logotipo está definido una sola vez (`HrLogo`) y se muestra solo desde la barra de marca común (`HrBrandBar`), por lo que es idéntico en todas las pantallas. Los controles táctiles miden al menos 48 dp (`HrDimens.MinTouch`).

## Adaptación a distintos tamaños (Estudio)
El layout se decide con el espacio real del área de contenido (sin barras del sistema ni navegación) y con la escala de texto, no con dimensiones fijas:
- **Ancho**: monitores Vista previa y Al aire siempre arriba; fuentes compactas debajo; bandeja de repeticiones desplegable (abierta, su lista reemplaza la fila de fuentes y los monitores no se achican); marcador compacto a la derecha.
- **Bajo** (p. ej. celular horizontal): prioriza Al aire; si no caben ambos monitores, pestañas PREVIA / AL AIRE; repeticiones y marcador completo se abren en paneles aparte (con corrección manual).
- Controles táctiles ≥ 48 dp en todos los casos; el logotipo y los gráficos de los monitores no escalan con la fuente.
- Etiquetas: **PREVIA** (naranja) = fuente preparada; **AL AIRE** (rojo) = fuente en la salida.
- Marcador: «DATOS DEMO» en modo demostración, «—» si nunca se recibieron datos, y si se pierde una conexión se conservan los últimos datos con «DESACTUALIZADO».

Capturas verificadas (generadas con Robolectric, no con un dispositivo): [`../docs/capturas/`](../docs/capturas). Para regenerarlas:
```bash
./gradlew testDebugUnitTest -Pscreenshots --rerun --tests '*ScreenshotTest*'   # salida en app/build/screenshots
```
(requiere red para descargar los jars de Robolectric, o la variable `ROBOLECTRIC_DEPS` con una carpeta local).

## Limitaciones conocidas
- La orientación depende del modo: horizontal en Estudio, vertical en Lector.
- El arrastre de zonas no se ha probado en un dispositivo táctil.
- La escala de texto del sistema se limita al 130 % en esta interfaz: por encima, en ventanas pequeñas ya no caben texto y monitores a la vez. En 640×360 dp con 130 % la columna derecha necesita desplazarse.
- Los paneles específicos (repeticiones y marcador en ventana baja) y los diálogos no están incluidos en las capturas; no se revisaron visualmente.
- Con ventanas intermedias (p. ej. 853×480 dp) los monitores quedan centrados con espacio libre arriba y abajo.
- Textos en español dentro del código (sin `strings.xml` traducible todavía).
- El diseño no se ha comprobado visualmente en un dispositivo ni emulador (ver nota de entrega).
