#!/usr/bin/env bash
#
# Transmite SOLO una región de tu pantalla (Linux, X11) a las dos TV Android
# por WiFi. Pensado para apuntar a un monitor virtual/extendido añadido con
# xrandr, para no tocar lo que muestra tu pantalla real.
#
# Antes de usarlo:
#   1. Agrega una pantalla extendida virtual con xrandr (o usa un segundo
#      monitor HDMI real si lo tienes) y ubícala, por ejemplo, a la derecha
#      de tu pantalla principal.
#   2. Averigua su posición y tamaño con: xrandr --query
#   3. Arrastra a esa pantalla la ventana que quieras que vean las TV.
#
# Requisitos: ffmpeg y python3 instalados.
#
# Uso:
#   ./start-linux.sh --offset-x 1920 --offset-y 0 --width 1920 --height 1080
#   MODE=stable ./start-linux.sh --offset-x 1920 --offset-y 0 --width 1920 --height 1080
#   ./start-linux.sh --offset-x 1920 --offset-y 0 --width 1920 --height 1080 --no-audio

set -euo pipefail
cd "$(dirname "$0")"

BITRATE="${BITRATE:-6M}"
FPS="${FPS:-30}"
STREAM_PATH="${STREAM_PATH:-pc}"
RTSP_PORT="${RTSP_PORT:-8554}"
DISPLAY_NAME="${DISPLAY:-:0.0}"
MODE="${MODE:-lowlatency}"

OFFSET_X=0
OFFSET_Y=0
WIDTH=1920
HEIGHT=1080
NO_AUDIO=0

while [ $# -gt 0 ]; do
    case "$1" in
        --offset-x) OFFSET_X="$2"; shift 2 ;;
        --offset-y) OFFSET_Y="$2"; shift 2 ;;
        --width) WIDTH="$2"; shift 2 ;;
        --height) HEIGHT="$2"; shift 2 ;;
        --no-audio) NO_AUDIO=1; shift ;;
        *) echo "Argumento no reconocido: $1"; exit 1 ;;
    esac
done

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

if [ "$MODE" = "stable" ]; then
    PRESET="veryfast"
    GOP=$((FPS * 4))
else
    PRESET="ultrafast"
    GOP=$FPS
fi

RTSP_URL="rtsp://127.0.0.1:${RTSP_PORT}/${STREAM_PATH}"
CAPTURE_GEOMETRY="${WIDTH}x${HEIGHT}+${OFFSET_X},${OFFSET_Y}"

echo "Modo: $MODE (preset=$PRESET, gop=$GOP)"
echo "Capturando región: $CAPTURE_GEOMETRY de la pantalla $DISPLAY_NAME"
echo "Iniciando captura y transmisión (ffmpeg). Ctrl+C para detener."

AUDIO_ARGS=()
AUDIO_ENCODE_ARGS=()
if [ "$NO_AUDIO" -eq 0 ]; then
    AUDIO_ARGS=(-f pulse -i default)
    AUDIO_ENCODE_ARGS=(-c:a aac -b:a 128k)
fi

ffmpeg -f x11grab -framerate "$FPS" -video_size "${WIDTH}x${HEIGHT}" \
    -i "${DISPLAY_NAME}+${OFFSET_X},${OFFSET_Y}" \
    "${AUDIO_ARGS[@]}" \
    -c:v libx264 -preset "$PRESET" -tune zerolatency -bf 0 \
    -b:v "$BITRATE" -g "$GOP" \
    "${AUDIO_ENCODE_ARGS[@]}" \
    -rtsp_transport tcp \
    -f rtsp "$RTSP_URL"
