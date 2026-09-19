#!/usr/bin/env bash
#
# Transmite la pantalla de este PC (Linux, X11) a las TVs Android por WiFi.
#
# Qué hace:
#   1. Descarga MediaMTX (servidor RTSP) si no está ya en esta carpeta.
#   2. Lo arranca escuchando en el puerto 8554.
#   3. Arranca ffmpeg capturando la pantalla (x11grab) y el audio (pulse),
#      y lo publica hacia MediaMTX por RTSP/TCP.
#   4. Arranca el anunciador UDP para que las TV encuentren el PC solas.
#
# Requisitos: ffmpeg y python3 instalados.
#
# Uso:
#   ./start-linux.sh
#   BITRATE=6M FPS=30 ./start-linux.sh

set -euo pipefail
cd "$(dirname "$0")"

BITRATE="${BITRATE:-6M}"
FPS="${FPS:-30}"
STREAM_PATH="${STREAM_PATH:-pc}"
RTSP_PORT="${RTSP_PORT:-8554}"
SCREEN="${DISPLAY:-:0.0}"

if [ ! -f "./mediamtx" ]; then
    echo "Descargando MediaMTX..."
    curl -sSL -o mediamtx.tar.gz \
        "https://github.com/bluenviron/mediamtx/releases/latest/download/mediamtx_linux_amd64.tar.gz"
    tar -xzf mediamtx.tar.gz mediamtx
    rm mediamtx.tar.gz
    chmod +x mediamtx
fi

echo "Iniciando servidor RTSP (MediaMTX)..."
./mediamtx mediamtx.yml &
MEDIAMTX_PID=$!
sleep 2

echo "Iniciando anuncio automático en la red..."
python3 discovery_broadcaster.py --port "$RTSP_PORT" --path "$STREAM_PATH" &
DISCOVERY_PID=$!

cleanup() {
    echo "Deteniendo servicios..."
    kill "$MEDIAMTX_PID" "$DISCOVERY_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

RTSP_URL="rtsp://127.0.0.1:${RTSP_PORT}/${STREAM_PATH}"

echo "Iniciando captura de pantalla y transmisión (ffmpeg). Ctrl+C para detener."
ffmpeg -f x11grab -framerate "$FPS" -i "$SCREEN" \
    -f pulse -i default \
    -c:v libx264 -preset veryfast -tune zerolatency \
    -b:v "$BITRATE" -g "$((FPS * 2))" \
    -c:a aac -b:a 128k \
    -rtsp_transport tcp \
    -f rtsp "$RTSP_URL"
