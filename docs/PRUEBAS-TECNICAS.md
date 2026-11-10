# Pruebas técnicas T1–T8

**Estado de todas: PENDIENTE.** Ninguna se ha ejecutado. Requieren dispositivos físicos reales y las ejecuta una persona (o una IA con acceso al dispositivo). Hasta que haya resultados registrados en `docs/resultados/`, `MATRIZ-FUNCIONES.md` no marca nada como verificado y la interfaz no presenta ninguna de estas funciones como operativa. Seguimiento: [issue #2](https://github.com/gucprez/hr-media-tube/issues/2).

## Lo que NO sustituye a estas pruebas
| Herramienta | Qué sí aporta | Qué NO valida |
|-------------|---------------|---------------|
| Capturas con Robolectric (`docs/capturas/`) | Revisión visual del diseño en tamaños y escalas de texto simulados; se corre sin dispositivo | Cámara, codificación, temperatura, consumo, red, rendimiento real, arrastre táctil, barras del sistema/notch/gestos, comportamiento real de ventanas y teclado |
| LDPlayer u otro emulador | Ver cómo reacciona la interfaz con un tamaño de pantalla | Cámara real (su cámara virtual no es un sensor), codificador de hardware, térmica, batería, Wi-Fi real, multitáctil real |
| Pruebas unitarias | Reglas y lógica (marcador, antigüedad, plantilla de zonas) | Cualquier cosa que dependa de hardware, sistema operativo o red |

## Qué registrar en cada prueba
Fecha · dispositivos (modelo, SoC, SO y versión) · red (router, banda 2,4/5 GHz, Ethernet) · versión de la app o prototipo · mediciones · veredicto (✅ / ❌ + motivo) · evidencia (video, captura, log, CSV). Guardar en `docs/resultados/T<n>-<fecha>-<modelo>.md` y actualizar la matriz en el mismo PR. Un prototipo de prueba **no** es la función: no se muestra en la interfaz hasta que su prueba esté ✅. Si falla, se registra ❌ con motivo y se abre un issue con la alternativa.

## Latencia «vidrio a vidrio» (T1, T2, T4)
Cronómetro en pantalla frente a la cámara emisora; con una cámara externa se fotografían a la vez el cronómetro real y el monitor del receptor; la latencia es la diferencia de lecturas. 10 repeticiones; reportar mediana y p95.

---

## T1 — Cámara Android → Windows y macOS (WebRTC)
- **Objetivo:** saber si un teléfono puede enviar su cámara a una laptop Windows y a una Mac con baja latencia y estabilidad.
- **Requisitos:** 1 Android real, 1 PC Windows, 1 Mac, misma red; prototipo WebRTC mínimo (no la app completa). Teléfono con 80–100 % de batería, brillo fijo, sin otras apps.
- **Pasos:** (1) emitir 1080p a 30 fps durante 30 min hacia Windows; (2) repetir hacia macOS; (3) medir latencia vidrio a vidrio; (4) anotar batería y temperatura del teléfono al inicio y al final, desenchufado; (5) cortar y reponer el Wi-Fi 3 veces y anotar si y cuándo se recupera.
- **Resultado esperado (criterios propuestos, a confirmar):** resolución y fps sostenidos (mediana ≥ 27 fps), latencia mediana en LAN ≤ 300 ms, ≤ 3 congelamientos en 30 min, sin estado térmico severo, recuperación sola ≤ 15 s.
- **Evidencia:** CSV de estadísticas (fps, kbps, RTT, congelamientos), fotos de latencia, lecturas de batería/temperatura.
- **Estado:** PENDIENTE.

## T2 — Cámara Android → Chrome y otro Android
- **Objetivo:** comprobar el mismo envío hacia un estudio en Chrome (escritorio y Chromebook) y hacia otro Android.
- **Requisitos:** igual que T1 + un Chromebook y un segundo Android.
- **Pasos:** repetir T1 con cada receptor.
- **Resultado esperado:** igual que T1; documentar diferencias entre receptores (decodificación por hardware, límites de resolución).
- **Evidencia:** igual que T1.
- **Estado:** PENDIENTE.

## T3 — Datos de pizarra entre plataformas (protocolo v1)
- **Objetivo:** validar el envío de marcador (no video) entre dispositivos con marcas de tiempo, confianza y estado de conexión.
- **Requisitos:** dos dispositivos con el protocolo implementado en un prototipo (`protocolo-pizarra.md`).
- **Pasos:** enviar `scoreboard` a 5 Hz durante 30 min; cortar y restaurar el Wi-Fi 3 veces; mover los relojes de ambos dispositivos unos segundos.
- **Resultado esperado:** sin pérdida de `seq` fuera de los cortes, antigüedad p95 < 500 ms en LAN, el estado pasa a «sin datos recientes»/«conexión perdida» en los tiempos definidos y conserva el último dato con advertencia, recuperación tras el corte.
- **Evidencia:** log con `seq`, `capturedAt`, `receivedAt`; capturas del estado de conexión.
- **Estado:** PENDIENTE.

## T4 — Video con marcador integrado en la salida
- **Objetivo:** que el marcador quede dibujado dentro del cuadro de video codificado.
- **Requisitos:** receptor que compone video + marcador y lo codifica.
- **Pasos:** componer, codificar y guardar un segmento; comparar marcador y escena en el mismo cuadro; medir latencia vidrio a vidrio de la salida.
- **Resultado esperado:** marcador visible y legible en el archivo/emisión; desfase marcador↔imagen medido y aceptable (criterio a definir).
- **Evidencia:** cuadros extraídos del video de salida, medición de desfase.
- **Estado:** PENDIENTE.

## T5 — Grabación y transmisión simultáneas
- **Objetivo:** saber si el dispositivo estudio puede grabar a archivo y transmitir a la vez.
- **Requisitos:** estudio en Windows, macOS y Android (por separado); destino de prueba para la transmisión.
- **Pasos:** grabar y transmitir 30 min; medir CPU, temperatura y batería; verificar que el archivo se reproduce.
- **Resultado esperado:** sin pérdida apreciable de cuadros, archivo íntegro, uso de CPU/temperatura sostenible.
- **Evidencia:** archivo grabado, métricas, captura de la transmisión recibida.
- **Estado:** PENDIENTE.

## T6 — Viabilidad y límites en Chrome / PWA / Chromebook
- **Objetivo:** documentar qué puede y no puede hacer Chrome (captura, composición, grabación, emisión).
- **Requisitos:** Chrome en escritorio y Chromebook; PWA instalada.
- **Pasos:** repetir T1, T4 y T5 con las APIs del navegador; probar con la pestaña en segundo plano y con la pantalla bloqueada.
- **Resultado esperado:** lista de límites reales (resolución, fps, segundo plano, si la emisión directa es posible o requiere un puente) con evidencia.
- **Evidencia:** logs y capturas por escenario.
- **Estado:** PENDIENTE.

## T7 — Búfer de repeticiones (últimos 20 s)
- **Objetivo:** comprobar que se puede guardar un clip de los últimos 20 s sin interrumpir la grabación ni la emisión.
- **Requisitos:** prototipo con grabación continua.
- **Pasos:** pulsar «guardar últimos 20 s» en 10 momentos distintos con grabación y emisión activas.
- **Resultado esperado:** clip de 20 s ± 1 s, reproducible, sin cortes en lo demás, uso de memoria acotado.
- **Evidencia:** los 10 clips y su duración medida.
- **Estado:** PENDIENTE.

## T8 — Integración con motores audiovisuales en Flutter (Windows, macOS, Web)
- **Objetivo:** saber si T1 y T5 se logran con Flutter sin código nativo propio por plataforma.
- **Requisitos:** proyecto Flutter mínimo y los plugins candidatos (licencias a revisar antes de incorporarlos, ver `LICENCIAS.md`).
- **Pasos:** implementar T1 y T5 en Windows, macOS y Web.
- **Resultado esperado:** lista de lo que funciona, de los impedimentos y de las licencias de los plugins; decisión Flutter vs. Kotlin/Compose.
- **Evidencia:** prototipo, notas de impedimentos.
- **Estado:** PENDIENTE.

---

## Verificaciones de la interfaz que requieren un dispositivo real (PENDIENTES)
Las capturas simuladas no las cubren:
| ID | Qué comprobar en un Android real | Resultado esperado |
|----|----------------------------------|--------------------|
| UI-1 | **Arrastre táctil** de las zonas del Lector de pizarra, con dedos | La zona seleccionada sigue al dedo sin saltos y no sale del encuadre; el cambio de tamaño con − y + funciona |
| UI-2 | Barras del sistema, recorte de pantalla (notch) y gestos | Nada importante queda tapado; «Poner al aire» y los monitores siguen visibles |
| UI-3 | **Escala de texto del sistema** (Ajustes ▸ Pantalla ▸ Tamaño de fuente) al 100 %, 130 %, 200 % | Al aire visible, controles ≥ 48 dp, sin textos partidos; lo secundario se desplaza |
| UI-4 | Giro de 180° en horizontal y vertical (Lector) | El diseño se reacomoda sin perder el estado |
| UI-5 | Teclado en pantalla en el diálogo de corrección | El campo «Tiempo» y los botones quedan accesibles |

## Siguiente etapa (independiente de esta entrega)
La cámara remota y el estudio de laptop están en la rama `claude/camara-remota-prueba-tecnica` como **prueba técnica independiente**: un celular Android real en modo Cámara remota enviando video a una aplicación Estudio en una laptop real, sin LDPlayer. No forman parte del PR de la interfaz.
