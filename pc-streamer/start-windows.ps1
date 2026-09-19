<#
    Transmite la pantalla de este PC (Windows) a las TVs Android por WiFi.

    Qué hace:
      1. Descarga MediaMTX (servidor RTSP) si no está ya en esta carpeta.
      2. Lo arranca escuchando en el puerto 8554.
      3. Arranca ffmpeg capturando la pantalla y el audio, y lo publica
         hacia MediaMTX por RTSP/TCP (más resistente a cortes de WiFi).
      4. Arranca el anunciador UDP para que las TV encuentren el PC solas.

    Requisitos previos en el PC:
      - ffmpeg instalado y en el PATH (https://ffmpeg.org/download.html).
      - Python 3 instalado y en el PATH (para el anunciador).
      - Ejecutar en PowerShell: si da error de permisos, ejecutar antes
        una sola vez:  Set-ExecutionPolicy -Scope CurrentUser RemoteSigned

    Uso:
      .\start-windows.ps1
      .\start-windows.ps1 -Bitrate 6M -Fps 30
#>

param(
    [string]$Bitrate = "6M",
    [int]$Fps = 30,
    [string]$StreamPath = "pc",
    [int]$RtspPort = 8554
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

Write-Host "Iniciando captura de pantalla y transmisión (ffmpeg)..."
Write-Host "Presiona Ctrl+C para detener todo."

$rtspUrl = "rtsp://127.0.0.1:$RtspPort/$StreamPath"

try {
    ffmpeg -f gdigrab -framerate $Fps -i desktop `
        -f dshow -i audio="virtual-audio-capturer" `
        -c:v libx264 -preset veryfast -tune zerolatency `
        -b:v $Bitrate -g ($Fps * 2) `
        -c:a aac -b:a 128k `
        -rtsp_transport tcp `
        -f rtsp $rtspUrl
}
finally {
    Write-Host "Deteniendo servicios..."
    Stop-Process -Id $mediamtxProcess.Id -ErrorAction SilentlyContinue
    Stop-Process -Id $discoveryProcess.Id -ErrorAction SilentlyContinue
}
