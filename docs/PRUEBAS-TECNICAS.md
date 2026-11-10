# Procedimiento de pruebas técnicas T1–T8

Estas pruebas **requieren dispositivos reales** y las ejecuta una persona (o una IA con acceso al dispositivo). Hasta que se ejecuten, `MATRIZ-FUNCIONES.md` no marca nada como verificado. Seguimiento: issue «Pruebas técnicas T1–T8».

## Para cada prueba, registrar
Fecha · dispositivos (modelo, SO, versión) · red (Wi‑Fi 2,4/5 GHz, router) · versión de la app o prototipo · medición · veredicto (✅/❌ + motivo) · enlace a evidencia (video, captura, log). Pegar el registro en el issue y actualizar la matriz en el mismo PR.

## Medición de latencia (T1, T2, T4)
Método "vidrio a vidrio": poner un cronómetro de pantalla frente a la cámara emisora, fotografiar con una cámara externa la pantalla del cronómetro junto a la pantalla del estudio y restar los tiempos. Repetir 10 veces; reportar mediana y p95.

## Pruebas
| ID | Preparación mínima | Pasos | Se registra |
|----|--------------------|-------|-------------|
| T1 | Android con cámara + PC Windows + Mac, misma Wi‑Fi. Prototipo WebRTC mínimo (sin la app completa) | Emitir 1080p30 durante 30 min hacia cada receptor | Latencia, FPS recibidos, cortes, CPU/temperatura del emisor |
| T2 | Igual que T1; receptor en Chrome y en otro Android | Repetir T1 | Igual que T1 |
| T3 | Dos dispositivos con el protocolo v1 implementado en el prototipo | Enviar `scoreboard` a 5 Hz 30 min; cortar y restaurar Wi‑Fi 3 veces | Pérdidas de `seq`, antigüedad p50/p95, tiempo de recuperación |
| T4 | Receptor que compone video + marcador | Dibujar marcador sobre el cuadro y codificarlo | Captura del cuadro de salida; desfase marcador↔imagen |
| T5 | Dispositivo estudio (Windows, Mac y Android por separado) | Grabar a archivo y transmitir a un destino de prueba a la vez durante 30 min | Cuadros perdidos, CPU, temperatura, batería, archivo reproducible |
| T6 | Chrome (escritorio y Chromebook), PWA instalada | Repetir T1, T4 y T5 con las APIs del navegador; probar con la pestaña en segundo plano | Límites reales de resolución/FPS, comportamiento en segundo plano, emisión posible o no |
| T7 | Prototipo de grabación continua | Pulsar «guardar últimos 20 s» en 10 momentos distintos con grabación y emisión activas | Duración real del clip (objetivo 20 s ±1 s), reproducible, sin cortar la grabación |
| T8 | Proyecto Flutter mínimo | Implementar T1 y T5 con plugins de Flutter en Windows, macOS y Web | ¿Se logra sin código nativo propio? Lista de impedimentos y licencias de plugins |

## Reglas
- Un prototipo de prueba no es la función: no se muestra en la interfaz hasta que la prueba correspondiente esté ✅.
- Si una prueba falla, se registra ❌ con motivo y se abre un issue con la alternativa propuesta.


---

# Validación inicial: un Android real → una laptop real, 720p/30

**Estado: NO ejecutada.** Esto es el procedimiento. Hasta que alguien lo ejecute y pegue los resultados en `docs/resultados/`, la matriz no marca nada como verificado, y el estudio **no acepta más de una cámara** (`--max-cameras 1`).

## Qué sí se verificó sin dispositivos (entorno de desarrollo, Linux)
- Protocolo y vinculación: pruebas unitarias y de integración (cliente OkHttp de la app contra el servidor Ktor por sockets reales).
- Estudio completo con navegador Chromium headless y una «cámara» simulada (JavaScript, cámara falsa 1280×720): crear estudio, QR, aceptar, video WebRTC, previa/al aire, aviso AL AIRE, corte y reconexión sin duplicar, intruso rechazado, desconexión. `studio-server/e2e/e2e.cjs`.
- En ese entorno llegó 720p a ~20 fps con códec por software: **no es una medición de ningún dispositivo real** y no sirve para decidir nada sobre 30 fps.

## Qué NO se pudo verificar (requiere hardware)
CameraX→WebRTC en un teléfono real, codificación por hardware, orientación del video, controles de lente/zoom/enfoque/exposición, permisos, escáner de QR con cámara real, consumo, temperatura, estabilidad, el estudio en Windows y macOS.

## Equipo y condiciones
- 1 teléfono Android real (anotar modelo, SoC, versión de Android) y 1 laptop real (anotar modelo, SO, navegador y versión, Java 17+).
- Misma red Wi-Fi (anotar router y banda 2,4/5 GHz). Probar también Ethernet en la laptop si se puede.
- Teléfono con batería entre 80 % y 100 %, brillo fijo, sin otras apps abiertas, **desenchufado** (para consumo) y, en otra corrida, enchufado (para estabilidad térmica).

## Procedimiento
1. Laptop: instalar y arrancar el estudio (`studio-server/README.md`). Elegir la IP de la red correcta, **Crear estudio**.
2. Teléfono: abrir la app → **Cámara remota** → **Escanear QR** (concede cámara cuando lo pida). Laptop: **Aceptar**.
3. Teléfono: **Iniciar envío** (concede micrófono si se quiere audio). Anotar si el codificador figura como hardware o software (panel «Calidad»).
4. Laptop: seleccionar la cámara (Vista previa) y **Poner al aire**; el teléfono debe mostrar AL AIRE.
5. **Corrida de 30 minutos** a 720p/30 sin tocar nada. Dejar el estudio abierto: registra una fila cada 2 s. Al final, **Registro CSV** → guardar como `docs/resultados/T1-<fecha>-<modelo>.csv`.
6. **Latencia vidrio a vidrio** (10 repeticiones): poner un cronómetro en pantalla frente a la cámara del teléfono; con otra cámara fotografiar a la vez el cronómetro real y el monitor del estudio; restar las dos lecturas. Reportar mediana y p95.
7. **Consumo**: batería al inicio y al final (columnas `bateria_pct`, `carga_uAh`, `corriente_uA` del CSV; la corriente depende del fabricante). Reportar %/hora, y la temperatura inicial y final (`bateria_c`, `termico`).
8. **Estabilidad**, cada caso 3 veces, anotando si la cámara vuelve sola, en cuántos segundos y si queda **una sola** fuente en el estudio:
   - apagar y encender el Wi-Fi del teléfono;
   - alejarse del router hasta que se degrade y volver;
   - bloquear la pantalla del teléfono 30 s (la app debe seguir en primer plano: se espera que falle y debe quedar documentado);
   - llevar la app a segundo plano 10 s;
   - cerrar y abrir la app (debe reconectar sin escanear);
   - reiniciar el estudio (**Crear estudio**): debe pedir vincular de nuevo.
9. **Controles**: cambiar de lente, zoom, tocar para enfocar, exposición y silenciar audio; anotar si el estudio refleja el cambio y si el video cambia de orientación o se invierte al girar el teléfono 180°.

## Criterios de aceptación propuestos (el responsable debe confirmarlos)
| Métrica | Criterio |
|---------|----------|
| Resolución / fps en el estudio | 1280×720, mediana ≥ 27 fps durante 30 min |
| Latencia vidrio a vidrio (LAN) | mediana ≤ 300 ms |
| Congelamientos (`rx_congelamientos`) | ≤ 3 en 30 min |
| Temperatura | sin estado térmico ≥ 3 (`THERMAL_STATUS_SEVERE`) |
| Consumo | registrar; sin umbral hasta tener datos |
| Reconexión (Wi-Fi apagado y encendido) | vuelve sola ≤ 15 s, **una** fuente |

Solo si se cumplen, se sube `--max-cameras` y se prueba la segunda cámara repitiendo este procedimiento.

## Plantilla de resultados
`docs/resultados/T1-<fecha>-<modelo>.md`:
```
Teléfono: modelo / SoC / Android      Laptop: modelo / SO / navegador / Java
Red: router / banda / Ethernet        Codificador informado: hardware|software (nombre)
720p/30 sostenido: mediana fps / mín fps / congelamientos
Latencia vidrio a vidrio: mediana / p95 (10 muestras)
Batería: inicio % → fin %  (%/h)   Temperatura: inicio °C → fin °C   Térmico máx:
Estabilidad: caso → vuelve (s) → fuentes en el estudio
Controles: lente / zoom / enfoque / exposición / audio / orientación
Veredicto: ✅ | ❌ (motivo)      CSV adjunto:
```
