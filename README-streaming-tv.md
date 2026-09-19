# Transmitir video/llamadas del PC a dos Android TV por WiFi

Dos piezas (independientes del servicio FastAPI existente en este repo):

- **`android-tv-receiver/`** — App Android TV (APK) que recibe el stream y
  lo muestra a pantalla completa, con reconexión automática.
- **`pc-streamer/`** — El emisor para tu PC.

## Uso rápido (Windows, sin instalar nada manualmente)

1. Abre `HRMediaTubeSender.exe` con **clic derecho > "Ejecutar como
   administrador"** (así puede abrir el puerto en el Firewall de Windows
   él solo — ver sección de problemas más abajo si no lo haces así). Se
   abre una página en tu navegador con un botón.
   - **La primera vez** descarga solo (ffmpeg + el servidor de video) —
     necesita internet unos minutos. Después de eso queda guardado en la
     carpeta `bin/` junto al `.exe` y no se vuelve a descargar; puedes
     usarlo sin internet (solo en tu red WiFi local).
2. En la página: elige **"Todo mi escritorio"** (siempre funciona) o una
   ventana específica, el modo (**Llamadas** o **Película**) y la calidad,
   y pulsa **Iniciar transmisión**.
3. La página te muestra la IP de tu PC — instala el APK en ambas TV (ver
   abajo) y en cada una escribe esa IP (o espera a que aparezca sola) y
   pulsa Conectar. Usa **la misma IP y el mismo modo en las dos TV** para
   que muestren lo mismo.

Eso es todo — no hace falta instalar ffmpeg, Python, ni ningún driver de
monitor virtual por separado; el programa ya trae todo lo que necesita.

## Actualizaciones

`HRMediaTubeSender.exe` revisa solo, cada vez que lo abres (y cada 30
minutos mientras está abierto), si hay una versión más nueva publicada en
este repositorio. Si la hay, aparece un aviso arriba de la página con un
botón **"Actualizar ahora"** — lo descarga, se reinicia solo con la nueva
versión, y listo, sin que tengas que volver a bajar el archivo a mano.

Esto funciona **a partir de esta versión**: si ya tienes una copia anterior
sin esta función, esa una vez sí necesitas volver a descargarla manualmente
— después de eso, las siguientes ya se instalan solas.

> Nota sobre "compartir una ventana": si eliges una ventana específica en
> vez de todo el escritorio, ten en cuenta que si esa ventana cambia de
> título (algunas apps de videollamada agregan el nombre del contacto,
> por ejemplo), la captura puede perderla. Si eso pasa, usa "Todo mi
> escritorio", que siempre es confiable.

## Instalar la app en las dos TV

El proyecto Android está en `android-tv-receiver/` y ya se compiló y
verificó en este entorno (`./gradlew assembleDebug` — build exitoso). Te
entrego el `.apk` ya compilado aparte — instala el mismo archivo en ambas
TV:

- Con **ADB**: `adb connect <ip-de-la-tv>` y luego `adb install app-debug.apk`.
- O copiando el APK a una memoria USB y abriéndolo desde un explorador de
  archivos en la TV (activa antes "Orígenes desconocidos" en Ajustes).

Si prefieres compilarlo tú mismo:
```bash
cd android-tv-receiver
./gradlew assembleDebug
# APK resultante en: app/build/outputs/apk/debug/app-debug.apk
```

## Configurar cada TV

Al abrir la app por primera vez, en **ambas TV**:
- Escribe la **misma IP, puerto y nombre de stream** en las dos (o espera a
  que se autodetecte por broadcast UDP) — así ambas muestran exactamente lo
  mismo.
- Elige el modo: **"Llamadas (tiempo real)"** o **"Película (más
  estable)"** — debe coincidir con el modo elegido en el programa del PC.
- Pulsa **Conectar**.

La app guarda la configuración: la próxima vez conecta directo. Para
cambiar de servidor o de modo más adelante, mantén pulsado **MENÚ** en el
control remoto mientras el video está en pantalla.

Si la señal WiFi se corta, la app lo detecta sola y muestra
"Reconectando…", reintentando cada pocos segundos hasta recuperar la
imagen.

## Cómo funciona por dentro

```
[ PC ]
  HRMediaTubeSender.exe
     -> ffmpeg captura pantalla/ventana --RTSP/TCP-->  MediaMTX (puerto 8554, local)
                                                             |         \
                                                             v          v
                                                          [ TV 1 ]   [ TV 2 ]
                                                       (mismo stream, mismo contenido)
```

El PC codifica el video **una sola vez** y ambas TV se conectan al mismo
stream — por eso ven lo mismo. Se usa **RTSP sobre TCP** (no UDP) porque en
WiFi doméstico es mucho más resistente a microcortes que UDP.

## Modo llamadas vs película

- **Llamadas** — casi tiempo real (buffer ~150-300ms en la TV, keyframes
  cada 1s en el PC). Necesita WiFi razonablemente estable porque hay poco
  margen de buffer.
- **Película** — más buffer (2.5-5s en la TV, keyframes cada 4s en el PC),
  prioriza que nunca se corte sobre la inmediatez.

Ambos deben coincidir entre el programa del PC y la app de la TV.

## Recomendaciones para que no se corte la señal

- Usa la banda **5GHz** del router en el PC y en ambas TV.
- Ubica el router centrado entre el PC y las dos TV si es posible.
- Bitrate de referencia: 6 Mbps funciona bien en la mayoría de redes
  domésticas a 1080p30. Si notas cortes, bájalo a 3-4 Mbps en el programa.
- Reserva la IP del PC en el router (DHCP reservation) para que no cambie.
- Si el router tiene **QoS**, prioriza el tráfico del PC y de las TV.

## La TV se queda en "Señal perdida. Reintentando conexión..." sin parar

Esto casi siempre significa que la TV nunca llegó a alcanzar el stream del
PC. Revisa en este orden:

1. **¿El programa del PC realmente está transmitiendo?** Mira la página del
   navegador — debe decir "Transmitiendo" con una IP, no "Detenido".
2. **Firewall de Windows (la causa más común).** MediaMTX escucha bien en
   el propio PC, pero Windows bloquea por defecto las conexiones que
   llegan desde otros equipos (como la TV) a menos que exista una regla
   que lo permita. Soluciones:
   - Cierra el programa y ábrelo de nuevo con clic derecho > **"Ejecutar
     como administrador"** — así puede crear la regla de firewall él
     solo (lo verás como "Firewall: puerto abierto automáticamente" en la
     página).
   - Si sigue sin funcionar, agrégala a mano: abre PowerShell como
     Administrador y corre:
     ```powershell
     netsh advfirewall firewall add rule name="HRMediaTube RTSP" dir=in action=allow protocol=TCP localport=8554
     ```
3. **Misma red WiFi.** El PC y ambas TV deben estar en la misma red (no
   una en 5GHz "invitados" y otra en la red normal, por ejemplo).
4. **IP correcta.** Si tu PC tiene varias conexiones activas (WiFi +
   Ethernet, o una VPN), la IP que detecta el programa puede no ser la de
   tu red WiFi normal. Confirma con `ipconfig` en el PC cuál es la IP de
   tu adaptador WiFi/Ethernet real y escríbela a mano en la TV si no
   coincide con la que muestra el programa.
5. **Antivirus de terceros** (no solo el Firewall de Windows) a veces
   bloquea `ffmpeg.exe`/`mediamtx.exe` igual — si tienes uno instalado,
   revisa que no los esté bloqueando.

## Alternativa avanzada (Linux, o control manual en Windows)

Si prefieres control manual en vez del programa con interfaz gráfica,
siguen disponibles en `pc-streamer/`:
- `start-windows.ps1` / `start-linux.sh` — scripts de línea de comandos
  (requieren ffmpeg y Python instalados a mano).
- `list-monitors.ps1` — para capturar un monitor virtual específico en vez
  de todo el escritorio o una ventana.

## Notas

- Todo el tráfico va por tu red WiFi local (RTSP sin cifrar) — pensado
  para uso doméstico dentro de tu propia red, no para exponerlo a
  Internet.
- El componente FastAPI existente en la raíz del repo (`main.py`) no se
  modificó; este streaming es un proyecto independiente dentro del mismo
  repositorio.
