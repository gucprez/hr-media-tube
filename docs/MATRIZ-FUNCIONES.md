# Matriz de funciones verificadas por plataforma

Leyenda:
- ✅ **Verificado** con evidencia (prueba ejecutada en dispositivo real, registrada abajo).
- 🔧 **Compila**: el código compila y pasa pruebas unitarias, pero no se ha probado en un dispositivo.
- ⬜ **No verificado**: no existe implementación ni prueba.
- ❌ **Impedimento confirmado**: probado y no viable (con motivo).

**Hoy no hay ninguna función ✅ ni ❌.** Esta entrega no incluyó pruebas en dispositivos: el entorno de desarrollo no tiene teléfonos, tabletas, Windows, macOS ni Chromebook.

| Función | Android móvil | Android tablet | Windows | macOS | Chrome / PWA | Chromebook |
|---------|---------------|----------------|---------|-------|--------------|------------|
| Interfaz Estudio (demo, datos de ejemplo) | 🔧 | 🔧 | ⬜ | ⬜ | ⬜ | ⬜ |
| Reglas y estado del partido (`MatchState`) | 🔧 | 🔧 | ⬜ | ⬜ | ⬜ | ⬜ |
| Recepción de datos de pizarra (protocolo v1) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Envío de datos de pizarra | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Reconocimiento de pizarra (cámara) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Cámara enviada por WebRTC (emisor) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Cámara recibida por WebRTC (estudio) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Composición de video con marcador integrado | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Grabación a archivo | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Transmisión (RTMP/SRT/WHIP) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Grabación y transmisión simultáneas | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Búfer de repeticiones (últimos 20 s) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |

## Pruebas técnicas requeridas (todas pendientes)
Procedimiento detallado: [`PRUEBAS-TECNICAS.md`](PRUEBAS-TECNICAS.md).

Deben ejecutarse **antes** de construir las funciones completas. Cada una produce un registro con dispositivo, versión de SO, medición y veredicto, que actualiza la matriz.

| ID | Prueba | Criterio de aceptación propuesto (a confirmar con el responsable) | Estado |
|----|--------|-------------------------------------------------------------------|--------|
| T1 | Cámara Android → Windows y → macOS por WebRTC | 1080p30 durante 30 min sin cortes; latencia vidrio a vidrio medida y registrada en LAN | Pendiente |
| T2 | Cámara Android → estudio web (Chrome) y → Android | Igual que T1 | Pendiente |
| T3 | Datos de pizarra entre plataformas (protocolo v1) | Sin pérdida de `seq` en 30 min; antigüedad p95 < 500 ms en LAN; recuperación tras cortar Wi‑Fi | Pendiente |
| T4 | Video con marcador integrado en la salida | Marcador dibujado en el cuadro codificado, con desfase marcador↔imagen medido | Pendiente |
| T5 | Grabación y transmisión simultáneas | 30 min sin pérdida de cuadros apreciable; medir CPU, temperatura y batería | Pendiente |
| T6 | Chrome: captura, composición, grabación y emisión | Documentar límites reales (resolución, FPS, pestaña en segundo plano, RTMP) | Pendiente |
| T7 | Búfer de repeticiones de 20 s | Guardar clip reproducible de 20 s ±1 s sin interrumpir la grabación/emisión | Pendiente |
| T8 | Integración con motores audiovisuales en Flutter (Windows, macOS, Web) | ¿Se logra T1–T5 sin código nativo propio por plataforma? Documentar impedimentos | Pendiente |

## Hipótesis y riesgos conocidos (SIN verificar)
Son puntos a comprobar en las pruebas, no hallazgos:

1. **Chrome no emite RTMP directamente**: se espera que haga falta un puente (WebRTC/WHIP → RTMP/SRT) o un servicio que acepte WebRTC.
2. **Grabar con `MediaRecorder` en Chrome**: los fragmentos pueden no ser reproducibles por sí solos; podría complicar el búfer de repeticiones (T7). Alternativas: segmentos cortos o WebCodecs.
3. **Pestañas en segundo plano** en Chrome pueden limitar temporizadores y captura; relevante en Chromebook y PWA.
4. **Android**: capturar cámara con la pantalla apagada o la app en segundo plano suele requerir servicio en primer plano y notificación visible; consumo de batería y temperatura sostenidos son un riesgo para T1/T5.
5. **Codificación doble** (grabar + transmitir) puede exceder el hardware gama baja; evaluar codificar una vez y derivar ambas salidas.
6. **Conexión fuera de la LAN** puede requerir TURN (costo de servidor); dentro de la LAN no.
7. **Licencias de códecs** (H.264/AAC) según plataforma: ver `LICENCIAS.md`.

## Registro de resultados
_(vacío: aún no se ha ejecutado ninguna prueba)_
