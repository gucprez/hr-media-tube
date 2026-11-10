# Capturas de la interfaz (revisión visual)

**Qué son:** renders del Estudio y del Lector generados con Robolectric (gráficos nativos) en tamaños, densidades y escalas de texto simulados. **No son un dispositivo real**: no validan cámara, red, temperatura, consumo, arrastre táctil, barras del sistema ni rendimiento. Ver `../PRUEBAS-TECNICAS.md`.
Regenerar: `cd android && ./gradlew testDebugUnitTest -Pscreenshots --rerun --tests '*ScreenshotTest*'`.

Convención: «dp» = tamaño lógico de la ventana; «px @NNN dpi» = pantalla en píxeles con esa densidad (los píxeles no equivalen a dp: 960×540 px a 240 dpi son 640×360 dp).

## Revisión visual (qué se miró en cada una)
Criterios: Al aire visible · controles ≥ 48 dp · sin textos partidos, cortados por letras ni superpuestos · PREVIA y AL AIRE distinguibles · «Datos demo» donde corresponde. Un elemento cortado en el borde inferior de una zona desplazable es intencional: el borde se atenúa para indicar que hay más contenido.

| Archivo | Qué muestra | Resultado de la revisión |
|---------|-------------|--------------------------|
| `01-960x540dp` | Estudio, ventana ancha | OK |
| `02-1280x720dp`, `03-1920x1080dp` | Estudio ancho, tamaños mayores | OK |
| `04-960x540px-240dpi-640x360dp` | Celular horizontal | OK: todo cabe; Al aire visible |
| `05-1280x720px-240dpi-853x480dp` | **Tamaño intermedio 853×480 dp** | OK: ambos monitores, fuentes bajo ellos y marcador completo; el botón «Repeticiones» queda al borde (se desplaza) |
| `06-1920x1080px-240dpi-1280x720dp` | 1080p a 240 dpi | OK |
| `07-celular-731x411dp-420dpi` | Celular grande a 420 dpi | OK |
| `08`/`09-960x540dp-texto-1.3/2.0` | Texto 130 % y 200 % en 960×540 | OK: pasa a distribución baja, riel de iconos |
| `10`/`11-640x360dp-texto-1.3/2.0` | **Ventana pequeña con texto al 200 %** (y 130 %) | OK: Al aire visible, controles ≥ 48 dp; las fuentes y lo secundario se desplazan; monitor pequeño |
| `12-960x540dp-bandeja-abierta`, `28-1280x720dp-bandeja-abierta` | **Panel de repeticiones abierto** (bandeja, ventana ancha) | OK: los monitores siguen visibles; la lista reemplaza a las fuentes |
| `21`/`26-640x360dp-panel-repeticiones[-texto-2.0]` | **Panel de repeticiones abierto** (ventana baja) | OK; a 200 % el botón «Guardar» se desplaza (borde atenuado) |
| `22`/`27-640x360dp-panel-marcador[-texto-2.0]` | **Marcador en ventana baja** | OK; a 200 % se desplaza (borde atenuado) |
| `23-960x540dp-dialogo-correccion` | **Diálogo de corrección** (ancho) | OK: todos los campos y botones visibles |
| `24-640x360dp-dialogo-correccion` | **Diálogo de corrección** (bajo) | OK: el campo de tiempo está en la primera fila. *Corregido en esta revisión: antes quedaba fuera de la vista* |
| `25-640x360dp-dialogo-correccion-texto-2.0` | **Diálogo de corrección al 200 %** | OK: botones siempre visibles; Periodo y Posesión se desplazan |
| `13-640x360dp-pestana-previa` | Pestaña PREVIA activa | OK |
| `14`…`16` | Corrección manual, sin datos nunca, conexión perdida | OK: «—», «DESACTUALIZADO», «CORREGIDO A MANO» |
| `29-853x480dp-texto-2.0`, `30-640x360dp-texto-1.5`, `31-1280x720dp-texto-2.0`, `32-…-sin-salida` | Más combinaciones de tamaño y escala | OK |
| `17`/`18-lector-360x780dp[-texto-1.3]` | Lector de pizarra vertical | OK |

## Módulo de contenido para descansos (capturas 40–53)
| Archivo | Qué muestra | Resultado de la revisión |
|---------|-------------|--------------------------|
| `40`/`41-…-descansos-listas` | Panel de contenidos, pestaña Listas (ancho y bajo) | OK |
| `42-…-descansos-editor` | Editor de una lista: reordenar, quitar, marcador por elemento | OK |
| `43`…`45` | Biblioteca, Audio y Patrocinadores | OK |
| `46-…-descanso-preparado` | Lista preparada en Vista previa; la cámara sigue al aire | OK |
| `47`, `49`, `53-…-descanso-al-aire` | Descanso al aire con la barra de control y «Volver al partido» (960×540, 853×480, 1280×720) | OK. *Corregido en esta revisión: la barra aplastaba los monitores* |
| `48-640x360dp-descanso-al-aire` | Lo mismo en celular horizontal | OK. *Corregido: «Volver al partido» quedaba fuera de pantalla* |
| `50-…-texto-2.0` | Descanso al aire con texto al 200 % | OK con límites: se muestra solo Al aire (sin pestañas) y la información se reduce a una línea |
| `51`, `52` | Placa de respaldo (archivo que falla) y placa de espera | OK |

Las miniaturas de esas capturas son marcadores: los archivos de ejemplo no existen. En el teléfono se muestran la imagen o el primer cuadro del video.

## Limitaciones de esta revisión
- Robolectric no reproduce multitáctil, barras del sistema, teclado en pantalla ni recorte de pantalla.
- El **arrastre táctil** de zonas no se ve en una captura estática: pendiente en dispositivo real (UI-1).
- La escala de texto se aplicó con la configuración real del sistema simulado (`fontScale`), incluidos los diálogos.
