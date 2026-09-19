# HR Media Tube Sender (Windows)

Programa único para Windows: se abre, descarga sus propias dependencias
(ffmpeg y MediaMTX) la primera vez, y muestra una página local con un botón
para empezar a transmitir a las TV. No requiere instalar nada manualmente.

## Compilar (desde Linux/Mac/Windows, con Go instalado)

```bash
cd pc-streamer/sender-app
GOOS=windows GOARCH=amd64 go build -ldflags "-H=windowsgui" -o HRMediaTubeSender.exe .
```

`-H=windowsgui` evita que se abra una ventana de consola negra al ejecutar
el programa.

## Cómo funciona

- Al abrirse, levanta un servidor web local en `http://127.0.0.1:5757` y
  abre esa página en el navegador por defecto automáticamente.
- La primera vez, descarga `ffmpeg.exe` y `mediamtx.exe` desde sus
  repositorios oficiales de GitHub (`bluenviron/mediamtx` y
  `BtbN/FFmpeg-Builds`) a una carpeta `bin/` junto al `.exe` — **esto
  necesita internet la primera vez**; después queda guardado y no se
  vuelve a descargar.
- Desde la página puedes elegir compartir todo el escritorio o una ventana
  específica, el modo (llamadas/película) y el bitrate, y arrancar/detener
  la transmisión con un botón. Detecta solo el mismo protocolo de
  autodescubrimiento UDP que ya usa la app Android (`HRMEDIATUBE|ip|puerto|ruta`
  por broadcast al puerto 40404), así que las TV existentes no necesitan
  ningún cambio.

## Archivos

- `main.go` — servidor HTTP + orquestación.
- `webui.go` — la página HTML/JS que se ve en el navegador.
- `capture.go` — arranca/detiene MediaMTX y ffmpeg, detecta audio del sistema.
- `setup.go` — descarga y extrae ffmpeg/MediaMTX la primera vez.
- `winenum.go` — lista las ventanas abiertas (API de Windows) para el selector.
- `discovery.go` — anuncia la IP del PC por broadcast UDP.
- `netutil.go` — IP local y espera a que el puerto RTSP esté listo.
- `state.go` — estado compartido de la app.
