# Transmitir video del PC a dos Android TV por WiFi

Este proyecto añade dos piezas al repositorio (independientes del servicio
FastAPI existente) para transmitir video desde tu PC a **dos televisores
Android TV** por WiFi, con reconexión automática si la señal se corta:

- **`android-tv-receiver/`** — App Android TV (APK) que recibe el stream y
  lo muestra a pantalla completa.
- **`pc-streamer/`** — Scripts para el PC: levantan un servidor RTSP local
  y transmiten la pantalla del PC hacia él.

## Cómo funciona (arquitectura)

```
[ PC ]                                    [ TV 1 ]
 ffmpeg (captura pantalla) --RTSP/TCP-->  App Android TV
        |                     \
        v                      \--RTSP/TCP--> [ TV 2 ]
   MediaMTX (servidor RTSP local, puerto 8554)
```

El PC codifica el video **una sola vez** con ffmpeg y lo publica en un
servidor RTSP local (MediaMTX). Cada TV se conecta a ese servidor de forma
independiente, así que puedes tener las dos encendidas o solo una, sin que
una TV dependa de la otra.

Se usa **RTSP sobre TCP** (no UDP) a propósito: en WiFi doméstico es
mucho más resistente a microcortes y pérdida de paquetes que UDP, que es
lo que normalmente causa "cuadros verdes" o congelamientos en apps de
streaming caseras.

## 1. Preparar el PC

Requisitos: [ffmpeg](https://ffmpeg.org/download.html) y Python 3 instalados
y en el PATH.

**Windows** (PowerShell, dentro de `pc-streamer/`):
```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned   # solo la primera vez
.\start-windows.ps1
```

**Linux**:
```bash
cd pc-streamer
./start-linux.sh
```

El script descarga MediaMTX automáticamente la primera vez, levanta el
servidor RTSP, empieza a capturar tu pantalla y anuncia la IP del PC por la
red para que las TV se autoconfiguren. Puedes ajustar bitrate/fps con
variables (`BITRATE`, `FPS` en Linux; `-Bitrate`, `-Fps` en Windows).

> macOS: usa el mismo `start-linux.sh` como base cambiando `-f x11grab` por
> `-f avfoundation -i "1:0"` (captura de pantalla + audio de macOS).

## 2. Instalar la app en las dos TV

El proyecto Android está en `android-tv-receiver/` y ya se compiló y
verificó en este entorno (`./gradlew assembleDebug` — build exitoso).
Para generar el APK tú mismo:

```bash
cd android-tv-receiver
./gradlew assembleDebug
# APK resultante en: app/build/outputs/apk/debug/app-debug.apk
```

También puedes abrir la carpeta `android-tv-receiver/` directamente en
Android Studio y pulsar "Run" o "Build APK(s)".

Para instalarlo en cada TV:
- Con **ADB** (recomendado): `adb connect <ip-de-la-tv>` y luego
  `adb install app-debug.apk`.
- O copiando el APK a una memoria USB y abriéndolo desde un explorador de
  archivos en la TV (activa antes "Orígenes desconocidos" en Ajustes).

Repite la instalación en las dos TV — es el mismo APK para ambas.

## 3. Configurar cada TV

Al abrir la app por primera vez:
- Si el router no bloquea el broadcast, la IP del PC aparece sola
  ("Servidor encontrado") — solo pulsa **Conectar**.
- Si no aparece (algunos routers activan "aislamiento de clientes" en
  WiFi), escribe la IP del PC a mano con el control remoto. La IP del PC
  se imprime en la consola al arrancar el script (`start-windows.ps1` /
  `start-linux.sh`).

La app guarda la configuración: la próxima vez conecta directo, sin pasar
por esta pantalla. Para cambiar de servidor más adelante, mantén pulsado
**MENÚ** en el control remoto mientras el video está en pantalla.

Si la señal WiFi se corta, la app lo detecta sola y muestra
"Reconectando…", reintentando cada pocos segundos hasta recuperar la
imagen — no hace falta reiniciar la app ni la TV.

## 4. Recomendaciones para que no se corte la señal

- Usa la banda **5GHz** del router en el PC y en ambas TV (menos
  interferencia que 2.4GHz, aunque de menor alcance — si hay mucha
  distancia, prueba 2.4GHz con un canal poco usado).
- Si es posible, ubica el router centrado entre el PC y las dos TV, o usa
  un access point / mesh adicional cerca de las TV.
- Evita bitrates demasiado altos para tu WiFi: como referencia,
  `-Bitrate 6M`/`BITRATE=6M` (6 Mbps) funciona bien en la mayoría de redes
  domésticas a 1080p30. Si notas cortes, bájalo a `3M`o `4M`.
- Reserva la IP del PC en el router (DHCP reservation) para que no cambie
  y siempre puedas reconectar con la misma dirección.
- Si el router tiene control de **QoS**, prioriza el tráfico del PC y de
  las TV.

## Notas

- Todo el tráfico va por tu red WiFi local (RTSP sin cifrar) — pensado
  para uso doméstico dentro de tu propia red, no para exponerlo a
  Internet.
- El componente FastAPI existente en la raíz del repo (`main.py`) no se
  modificó; este streaming es un proyecto independiente dentro del mismo
  repositorio.
