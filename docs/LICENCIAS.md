# Licencias de dependencias

Revisión hecha al incorporar cada dependencia. Fuente: metadatos de licencia publicados por cada proyecto; **verificar de nuevo al actualizar versiones**.

## Incorporadas (app Android, demo de interfaz)

| Dependencia | Versión | Licencia |
|-------------|---------|----------|
| Kotlin (compilador y stdlib) | 2.0.21 | Apache‑2.0 |
| Android Gradle Plugin | 8.7.3 | Apache‑2.0 |
| Jetpack Compose (BOM, ui, material3, material‑icons‑extended) | BOM 2024.12.01 | Apache‑2.0 |
| androidx.activity:activity‑compose | 1.9.3 | Apache‑2.0 |
| androidx.lifecycle (viewmodel‑compose, runtime‑compose) | 2.8.7 | Apache‑2.0 |
| kotlinx‑coroutines‑test (solo pruebas) | 1.9.0 | Apache‑2.0 |
| JUnit (solo pruebas) | 4.13.2 | EPL‑1.0 |
| Robolectric (solo pruebas, capturas) | 4.14.1 | MIT |
| androidx.compose.ui:ui-test-junit4 / ui-test-manifest (solo pruebas) | BOM 2024.12.01 | Apache‑2.0 |
| Gradle Wrapper | 8.10.2 | Apache‑2.0 |

Notas:
- Los iconos de `material-icons-extended` son de Material Design (Apache‑2.0).
- No hay fuentes ni imágenes de terceros. El logotipo se dibuja como texto en `HrLogo`; el icono del lanzador es un vector propio simplificado.
- Las pruebas (JUnit, EPL‑1.0) no se distribuyen en la APK.

## Candidatas por evaluar (NO incorporadas)
Verificar licencia **antes** de añadirlas:

| Candidata | Uso | Licencia a confirmar |
|-----------|-----|----------------------|
| Flutter SDK / flutter_webrtc | UI compartida y WebRTC | Se espera BSD‑3 / MIT: confirmar |
| libwebrtc (Google) | WebRTC nativo | BSD‑3: confirmar (y patentes de códecs) |
| MediaMTX | Puente WebRTC→RTMP/SRT | Se espera MIT: confirmar |
| FFmpeg | Codificación/puente | LGPL o GPL según compilación: **riesgo de copyleft, revisar** |
| coturn | TURN | Se espera BSD: confirmar |
| Códecs H.264/AAC | Grabación/transmisión | Pueden requerir licencias de patentes según plataforma: revisar |

## Licencia del proyecto
Apache-2.0 (archivo `LICENSE` en la raíz). Compatible con todas las dependencias incorporadas. Las candidatas con copyleft (FFmpeg compilado con GPL) exigirían revisar esta elección antes de enlazarlas.
