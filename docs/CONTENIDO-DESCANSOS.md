# Módulo de contenido para descansos, medio tiempo y tiempos muertos

**Entrega 1 (esta): interfaz, biblioteca y listas guardadas.** No hay motor audiovisual: nada de este módulo se reproduce, suena ni sale en la emisión o la grabación. Donde falta el motor, la interfaz lo dice («DEMO · NO SE EMITE», «secuencia de demostración»).
El módulo es independiente del reconocimiento de pizarra: vive en `android/.../content` y no comparte estado con él.

## Qué es real y qué es demostración
| Función | Estado en la entrega 1 |
|---------|------------------------|
| Importar imágenes y videos con el selector del sistema | **Implementado**, sin probar en un dispositivo real |
| Biblioteca con miniatura, nombre, tipo y duración | **Implementado** (miniaturas pequeñas, sin cargar el archivo completo); sin probar en dispositivo |
| Validar compatibilidad antes de usar un archivo (tipo, lectura, duración, decodificador disponible, hardware o software) | **Implementado**; depende de cada teléfono; sin probar en dispositivo |
| Listas mezclando imágenes y videos; reordenar y quitar (nunca borra archivos) | **Implementado y probado** (lógica) |
| Duración de imágenes, repetir lista, retorno automático opcional, placa de espera configurable | **Implementado y probado** (lógica) |
| Plantillas Medio tiempo, Tiempo muerto, Volvemos en breve | **Implementado y probado** |
| Mostrar u ocultar marcador en cada contenido | Se guarda por elemento; **no se dibuja** (falta composición) |
| Logos y franjas de patrocinadores sobre la cámara | Solo se configuran; **no se dibujan** |
| Guardado de biblioteca y listas | **Implementado** (archivo privado de la app); probado el formato, no en dispositivo |
| Reproducción de video y audio en la salida | **No existe** (entrega 2) |

## Operación
1. **DESCANSO** abre el panel de contenidos. Abrirlo **no cambia** lo que está al aire.
2. Elegir una lista → **Preparar en Vista previa**. La salida sigue igual y la vista previa **no envía audio**.
3. **Poner al aire** inicia la lista **desde el comienzo**.
4. Mientras está al aire se muestran el elemento actual, el tiempo restante y el siguiente, con controles reproducir/pausar, siguiente y detener.
5. **Volver al partido** (botón destacado) regresa a la cámara elegida, detiene el anuncio y restaura el audio. La cámara de retorno es la que estaba al aire al empezar el descanso, o la elegida en la pestaña Audio.
6. Si la lista termina: placa de espera configurable, o retorno automático a la cámara si se activó. «Detener» deja la placa de espera; no vuelve a cámara.
7. Si un archivo falla (incompatible, no encontrado, quitado, o error al reproducir): placa de respaldo durante 5 s, aviso al operador y la lista continúa. La aplicación no se bloquea.

## Audio (reglas implementadas como estado; nada suena todavía)
- Los videos podrán aportar audio (ganancia del anuncio = 100 % mientras está al aire).
- Audio del partido durante el anuncio: **Mantener**, **Reducir** (al 20 %, nunca sube uno más bajo) o **Silenciar**.
- La vista previa nunca aporta audio a la salida.
- Al volver al partido se restaura exactamente la mezcla anterior, aunque se haya cambiado de lista en medio.

## Pruebas (automáticas, sin dispositivo)
`ContentLogicTest` y `ContentFlowTest` (33 pruebas): interrupción del anuncio a mitad de un video, retorno a la cámara y restauración del audio, archivos incompatibles / no encontrados / quitados / que fallan en reproducción, listas con imágenes y videos, repetición, retorno automático, pausa/siguiente/detener, guardado y recarga, datos dañados. Capturas: `docs/capturas/40…53`.

**Sin probar en un Android real:** el selector de archivos, los permisos persistentes de lectura, la validación de decodificadores, las miniaturas y el guardado en disco.

## Entrega 2 (pendiente): reproducción real en la salida
Un reproductor visible en pantalla **no demuestra** que su contenido salga en la emisión: la entrega 2 debe integrar la reproducción en la composición de video y audio que se graba y transmite, y comprobarlo en el archivo grabado y en la señal recibida. Requisitos de rendimiento a cumplir entonces:
- Solo archivos locales; ninguna descarga durante el partido.
- Decodificación por hardware cuando el dispositivo la ofrezca (el dato ya se registra al importar).
- No cargar videos completos en memoria (lectura por flujo).
- Limitar la precarga al siguiente elemento y liberar los reproductores que no se usen.
- No reproducir a la vez el mismo video en Vista previa y Al aire si no hace falta (la vista previa puede usar el cuadro fijo).
- Validar de nuevo la compatibilidad justo antes de salir al aire.
El secuenciador (`BreakPlayer`) ya está preparado: hoy lo mueve un reloj; en la entrega 2 lo moverán los eventos del motor (fin de video, error).
