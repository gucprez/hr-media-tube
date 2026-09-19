# Transmitir video/llamadas del PC a dos Android TV por WiFi

Este proyecto añade dos piezas al repositorio (independientes del servicio
FastAPI existente) para mostrar contenido de tu PC en **dos televisores
Android TV simultáneamente, mostrando ambas lo mismo**, mientras tu laptop
sigue usando su propia pantalla para otra cosa.

- **`android-tv-receiver/`** — App Android TV (APK) que recibe el stream y
  lo muestra a pantalla completa, con reconexión automática.
- **`pc-streamer/`** — Scripts para el PC: levantan un servidor RTSP local
  y transmiten una región específica de la pantalla hacia él.

## Cómo funciona (arquitectura)

```
[ Laptop/PC ]
  Pantalla real  -> sigue siendo tuya, uso normal, no se toca.
  Monitor virtual (pantalla extra) -> lo que pones ahí es lo que ven las TV.
        |
        v  ffmpeg captura SOLO esa región --RTSP/TCP-->  MediaMTX (puerto 8554)
                                                             |         \
                                                             v          v
                                                          [ TV 1 ]   [ TV 2 ]
                                                          (mismo stream, mismo contenido)
```

El PC codifica el video **una sola vez** y lo publica en un servidor RTSP
local (MediaMTX). Las dos TV se conectan a ese mismo stream, por eso ven
exactamente lo mismo. Tu pantalla real de laptop no se transmite ni se ve
afectada — solo se transmite el "monitor virtual" extra.

Se usa **RTSP sobre TCP** (no UDP) porque en WiFi doméstico es mucho más
resistente a microcortes y pérdida de paquetes que UDP.

## 1. Crear el "monitor virtual" en Windows

Para que puedas mandar contenido a las TV sin tocar tu pantalla real,
Windows necesita ver una pantalla extra (aunque no exista físicamente).

1. Instala una herramienta de "monitor virtual / dummy plug para Windows"
   (hay varias gratuitas, búscalas en internet — instalan un controlador
   liviano y firmado que añade una pantalla extra en Configuración de
   pantalla, sin necesidad de un monitor físico).
2. Ve a **Configuración > Sistema > Pantalla** y confirma que aparece la
   pantalla nueva. Ponla en modo "Extender" y ubícala donde prefieras (por
   ejemplo, a la derecha de tu laptop) con la resolución que quieras usar
   en las TV (recomendado: 1920x1080).
3. Desde `pc-streamer/`, corre:
   ```powershell
   .\list-monitors.ps1
   ```
   Esto imprime el `OffsetX`, `OffsetY`, `Width` y `Height` de cada
   pantalla — anota los del monitor nuevo (el que no dice "principal").
4. Arrastra a esa pantalla nueva lo que quieras que vean las TV: el
   reproductor de video, la ventana de la videollamada, lo que sea.

> Si tu PC ya tiene físicamente un segundo monitor/salida HDMI libre que no
> usas, puedes usar esa salida en vez de un monitor virtual — el resto del
> proceso es idéntico, solo cambia de dónde sale la imagen.

## 2. Preparar el PC (transmitir)

Requisitos: [ffmpeg](https://ffmpeg.org/download.html) y Python 3 instalados
y en el PATH.

**Windows** (PowerShell, dentro de `pc-streamer/`):
```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned   # solo la primera vez
.\start-windows.ps1 -OffsetX 1920 -OffsetY 0 -Width 1920 -Height 1080
```

**Linux** (con una pantalla extendida vía xrandr):
```bash
cd pc-streamer
./start-linux.sh --offset-x 1920 --offset-y 0 --width 1920 --height 1080
```

Usa los valores de `OffsetX/OffsetY/Width/Height` que anotaste con
`list-monitors.ps1` (Windows) o `xrandr --query` (Linux).

Si no tienes un dispositivo de audio virtual instalado (para capturar el
sonido del sistema), agrega `-NoAudio` (Windows) o `--no-audio` (Linux)
para transmitir solo video.

## 3. Elegir modo: llamadas o película

Ambos scripts aceptan un modo que ajusta la codificación en el PC:

- **LowLatency** (por defecto) — casi tiempo real, pensado para
  videollamadas. `.\start-windows.ps1 ... -Mode LowLatency`
- **Stable** — más buffer/GOP más largo, prioriza que no se corte sobre la
  inmediatez. `.\start-windows.ps1 ... -Mode Stable` / `MODE=stable ./start-linux.sh ...`

**Este modo debe combinarse con el mismo modo elegido en la app de la TV**
(ver siguiente sección) — el modo del PC ajusta cómo se codifica el video
(keyframes más frecuentes), y el modo de la TV ajusta cuánto buffer usa el
reproductor antes de mostrar imagen. Usa **LowLatency + "Llamadas"** juntos,
o **Stable + "Película"** juntos.

## 4. Instalar la app en las dos TV

El proyecto Android está en `android-tv-receiver/` y ya se compiló y
verificó en este entorno (`./gradlew assembleDebug` — build exitoso).

```bash
cd android-tv-receiver
./gradlew assembleDebug
# APK resultante en: app/build/outputs/apk/debug/app-debug.apk
```

También puedes abrir la carpeta en Android Studio y pulsar "Run" o
"Build APK(s)".

Para instalarlo en cada TV:
- Con **ADB**: `adb connect <ip-de-la-tv>` y luego `adb install app-debug.apk`.
- O copiando el APK a una memoria USB y abriéndolo desde un explorador de
  archivos en la TV (activa antes "Orígenes desconocidos" en Ajustes).

Es el mismo APK para las dos TV.

## 5. Configurar cada TV

Al abrir la app por primera vez, en **ambas TV**:
- Escribe la **misma IP, puerto y nombre de stream** en las dos (o espera a
  que se autodetecte por broadcast UDP) — así ambas muestran exactamente lo
  mismo.
- Elige el modo: **"Llamadas (tiempo real)"** o **"Película (más
  estable)"**, según lo que vayas a usar (debe coincidir con el `-Mode` que
  usaste en el script del PC).
- Pulsa **Conectar**.

La app guarda la configuración: la próxima vez conecta directo. Para
cambiar de servidor o de modo más adelante, mantén pulsado **MENÚ** en el
control remoto mientras el video está en pantalla.

Si la señal WiFi se corta, la app lo detecta sola y muestra
"Reconectando…", reintentando cada pocos segundos hasta recuperar la
imagen — no hace falta reiniciar la app ni la TV.

## 6. Recomendaciones para que no se corte la señal

- Usa la banda **5GHz** del router en el PC y en ambas TV.
- Si es posible, ubica el router centrado entre el PC y las dos TV.
- Bitrate de referencia: `-Bitrate 6M` (6 Mbps) funciona bien en la mayoría
  de redes domésticas a 1080p30. Si notas cortes, bájalo a `3M` o `4M`
  (`.\start-windows.ps1 ... -Bitrate 4M`).
- En **modo llamadas**, el WiFi necesita ser bastante estable porque hay
  poco margen de buffer — si notas tartamudeos frecuentes, cambia
  temporalmente a modo Estable/Película.
- Reserva la IP del PC en el router (DHCP reservation) para que no cambie.
- Si el router tiene **QoS**, prioriza el tráfico del PC y de las TV.

## Notas

- Todo el tráfico va por tu red WiFi local (RTSP sin cifrar) — pensado
  para uso doméstico dentro de tu propia red, no para exponerlo a
  Internet.
- El componente FastAPI existente en la raíz del repo (`main.py`) no se
  modificó; este streaming es un proyecto independiente dentro del mismo
  repositorio.
