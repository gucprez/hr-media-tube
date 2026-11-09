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
