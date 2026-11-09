# HR Media Sports – app Android (demo de interfaz)

Pantalla **Estudio** en Kotlin + Jetpack Compose. **Es un modo demostración**: no hay cámaras, cámaras remotas, grabación, transmisión ni repeticiones reales. La interfaz lo indica con una franja ámbar permanente, una etiqueta DEMO y mensajes en los controles no disponibles. Contexto multiplataforma: [`../docs/PLATAFORMAS.md`](../docs/PLATAFORMAS.md).

## Modos
Al abrir la app eliges el rol del dispositivo (el botón Atrás vuelve a esta pantalla):
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
./gradlew testDebugUnitTest
```

## Estructura
| Paquete | Capa |
|---------|------|
| `domain` | Reglas y estado del partido (Kotlin puro) |
| `protocol` | Estado de enlace y reglas de antigüedad del protocolo de pizarra v1 ([especificación](../docs/protocolo-pizarra.md)) |
| `engine` | Interfaces `MediaEngine` y `ScoreboardSource` + implementaciones demo |
| `data` | Persistencia local (plantilla de zonas en SharedPreferences) |
| `ui` | Compose: tema, componentes, pantallas (`StudioViewModel`, `ReaderViewModel`, `AppViewModel`) |

El logotipo está definido una sola vez (`HrLogo`) y se muestra solo desde la barra de marca común (`HrBrandBar`), por lo que es idéntico en todas las pantallas. Los controles táctiles miden al menos 48 dp (`HrDimens.MinTouch`).

## Limitaciones conocidas
- La orientación depende del modo: horizontal en Estudio, vertical en Lector.
- El arrastre de zonas no se ha probado en un dispositivo táctil.
- Textos en español dentro del código (sin `strings.xml` traducible todavía).
- El diseño no se ha comprobado visualmente en un dispositivo ni emulador (ver nota de entrega).
