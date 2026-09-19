<#
    Transmite SOLO una región de tu pantalla de Windows (pensado para ser el
    monitor virtual "dummy" que agregaste como pantalla extra) a las dos TV
    Android por WiFi, sin tocar lo que muestra la pantalla real de tu PC.

    Qué hace:
      1. Descarga MediaMTX (servidor RTSP) si no está ya en esta carpeta.
      2. Lo arranca escuchando en el puerto 8554.
      3. Arranca ffmpeg capturando SOLO el rectángulo que le indiques
         (-OffsetX/-OffsetY/-Width/-Height) y lo publica hacia MediaMTX por
         RTSP/TCP (más resistente a cortes de WiFi que UDP).
      4. Arranca el anunciador UDP para que las TV encuentren el PC solas.
         Las dos TV que apunten a la misma IP/puerto/stream verán exactamente
         lo mismo (mismo stream, mismo servidor).

    Antes de usarlo:
      1. Instala un "monitor virtual" (dummy plug) en Windows, para que tenga
         una pantalla extra donde poner lo que quieras mostrar en las TV sin
         afectar tu pantalla real. Busca en internet una herramienta tipo
         "monitor virtual dummy plug para Windows" (hay varias gratuitas).
      2. En Configuración de pantalla de Windows, ubica ese monitor nuevo
         (posición y resolución, ej. a la derecha de tu laptop).
      3. Corre .\list-monitors.ps1 para ver el OffsetX/OffsetY/Width/Height
         exacto de ese monitor nuevo.
      4. Arrastra a ese monitor la ventana de lo que quieras que vean las TV
         (un video, una videollamada, un reproductor, lo que sea).

    Requisitos previos en el PC:
      - ffmpeg instalado y en el PATH (https://ffmpeg.org/download.html).
      - Python 3 instalado y en el PATH (para el anunciador).
      - Para capturar audio del sistema, un dispositivo de audio virtual tipo
        "virtual-audio-capturer" o VB-Audio Virtual Cable. Si no tienes uno,
        usa -NoAudio para transmitir solo video.
      - Ejecutar en PowerShell: si da error de permisos, ejecutar antes
        una sola vez:  Set-ExecutionPolicy -Scope CurrentUser RemoteSigned

    Uso:
      .\list-monitors.ps1
      .\start-windows.ps1 -OffsetX 1920 -OffsetY 0 -Width 1920 -Height 1080
      .\start-windows.ps1 -OffsetX 1920 -OffsetY 0 -Width 1920 -Height 1080 -Mode Stable
      .\start-windows.ps1 -OffsetX 1920 -OffsetY 0 -Width 1920 -Height 1080 -NoAudio
#>

param(
    [int]$OffsetX = 0,
    [int]$OffsetY = 0,
    [int]$Width = 1920,
    [int]$Height = 1080,
    [ValidateSet("LowLatency", "Stable")]
    [string]$Mode = "LowLatency",
    [string]$Bitrate = "6M",
    [int]$Fps = 30,
    [string]$StreamPath = "pc",
    [int]$RtspPort = 8554,
    [switch]$NoAudio
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

$mediamtxExe = Join-Path $here "mediamtx.exe"
if (-not (Test-Path $mediamtxExe)) {
    Write-Host "Descargando MediaMTX..."
    $zipUrl = "https://github.com/bluenviron/mediamtx/releases/latest/download/mediamtx_windows_amd64.zip"
    $zipPath = Join-Path $here "mediamtx.zip"
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath
    Expand-Archive -Path $zipPath -DestinationPath $here -Force
    Remove-Item $zipPath
}

Write-Host "Iniciando servidor RTSP (MediaMTX)..."
$mediamtxProcess = Start-Process -FilePath $mediamtxExe -ArgumentList "mediamtx.yml" -PassThru -WindowStyle Minimized
Start-Sleep -Seconds 2

Write-Host "Iniciando anuncio automático en la red (para que la TV encuentre el PC solo)..."
$discoveryProcess = Start-Process -FilePath "python" `
    -ArgumentList "discovery_broadcaster.py --port $RtspPort --path $StreamPath" `
    -PassThru -WindowStyle Minimized

# GOP corto en modo llamadas: se recupera más rápido de un corte, a costa de
# un poco más de bitrate. Preset más rápido = menos retraso de codificación.
if ($Mode -eq "LowLatency") {
    $preset = "ultrafast"
    $gop = [int]$Fps  # 1 segundo entre keyframes
} else {
    $preset = "veryfast"
    $gop = $Fps * 4   # 4 segundos: menos overhead, prioriza estabilidad
}

Write-Host "Modo: $Mode (preset=$preset, gop=$gop)"
Write-Host "Capturando región: offset ($OffsetX,$OffsetY) tamaño ${Width}x${Height}"
Write-Host "Iniciando captura y transmisión (ffmpeg). Presiona Ctrl+C para detener todo."

$rtspUrl = "rtsp://127.0.0.1:$RtspPort/$StreamPath"

$videoArgs = @(
    "-f", "gdigrab",
    "-framerate", $Fps,
    "-offset_x", $OffsetX,
    "-offset_y", $OffsetY,
    "-video_size", "${Width}x${Height}",
    "-i", "desktop"
)

$audioArgs = @()
$audioEncodeArgs = @()
if (-not $NoAudio) {
    $audioArgs = @("-f", "dshow", "-i", "audio=virtual-audio-capturer")
    $audioEncodeArgs = @("-c:a", "aac", "-b:a", "128k")
}

$encodeArgs = @(
    "-c:v", "libx264", "-preset", $preset, "-tune", "zerolatency",
    "-bf", "0",
    "-b:v", $Bitrate, "-g", $gop
)

$outputArgs = @("-rtsp_transport", "tcp", "-f", "rtsp", $rtspUrl)

try {
    & ffmpeg @videoArgs @audioArgs @encodeArgs @audioEncodeArgs @outputArgs
}
finally {
    Write-Host "Deteniendo servicios..."
    Stop-Process -Id $mediamtxProcess.Id -ErrorAction SilentlyContinue
    Stop-Process -Id $discoveryProcess.Id -ErrorAction SilentlyContinue
}
