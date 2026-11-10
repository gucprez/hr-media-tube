# Guía para ejecutar la prueba: Android Cámara remota → Laptop Estudio

Una cámara, 720p a 30 fps, en tu red local. Sin cuentas, sin internet, **sin emuladores** (LDPlayer no sirve: no tiene una cámara ni un codificador reales).
Tiempo total: unos 60 minutos. Nadie ha ejecutado todavía esta prueba; es posible que algo falle al primer intento: anótalo, eso también es un resultado.

## 1. Lo que necesitas
| | Requisito |
|---|-----------|
| Teléfono | Android real, 8.0 o superior, de **64 bits**, con 80–100 % de batería. Un trípode o apoyo. |
| Laptop | Windows o macOS, con **Java 17 o superior** y Chrome. Enchufada. |
| Red | Los dos en **la misma Wi-Fi** (mejor 5 GHz). No uses redes de invitados. |
| Para la latencia | Un segundo teléfono para tomar fotos y un cronómetro en pantalla (cualquier app o web de cronómetro con centésimas). |

¿Tienes Java? En la laptop abre una terminal y escribe `java -version`. Si no aparece 17 o más, instala «Temurin 17» (gratuito).

## 2. Los dos archivos exactos
| Archivo | Para | Huella SHA-256 |
|---------|------|----------------|
| `HRMediaSports-CamaraRemota-0.2.0.apk` (44 MB) | Teléfono | `0e4e03e755bead43793fbb8668fb8bb30b2a67f446722bd2272d55130033afd8` |
| `hr-studio-laptop.zip` (14 MB) | Laptop | `e4f94dc5ddd3038a170278910ca32aa6ce4abc6b0ef16425a6ccfba9a05aacc5` |

Son los que se entregaron por el chat junto con esta guía. El CI del PR genera otros equivalentes (mismo código, distinta firma): si instalas uno sobre el otro, Android pedirá desinstalar primero.
La app se llama «HR Media Sports» (`com.hrmediasports.studio`, versión 0.2.0-demo) y **reemplaza** a la demo anterior si la tenías instalada.

## 3. Preparar
**Laptop**
1. Descomprime `hr-studio-laptop.zip`.
2. Abre la carpeta `hr-studio/bin` y ejecuta `hr-studio.bat` (Windows) o `./hr-studio` (macOS, desde la terminal).
3. Si el sistema pregunta por el cortafuegos, permite el acceso en **redes privadas**.
4. Se abre Chrome en `http://localhost:8765`. Si no se abre, escribe esa dirección.
5. **Deja esa pestaña visible todo el tiempo**: si la minimizas o cambias de pestaña, los congelamientos dejan de medirse.

**Teléfono**
1. Copia la APK al teléfono e instálala (Android pedirá permitir «instalar apps desconocidas»).
2. Brillo al 50 %, modo ahorro de batería apagado, cierra las demás apps. Ponlo en el trípode, en horizontal.

## 4. Conectar
1. Laptop: en «Red local» elige la dirección de tu Wi-Fi y pulsa **Crear estudio**. Aparece un código QR (vale 5 minutos).
2. Teléfono: abre la app → **CÁMARA REMOTA** → **ESCANEAR QR** → permite la cámara → apunta al QR.
3. Laptop: en «Dispositivos» aparece tu teléfono → **Aceptar**.
4. Teléfono: **INICIAR ENVÍO** → permite el micrófono (o niégalo: seguirá sin audio).
5. Laptop: pulsa la tarjeta de la cámara (queda en Vista previa) → **PONER AL AIRE**. En el teléfono debe aparecer **AL AIRE**.

## 5. La prueba
**A. Corrida de 30 minutos (automática).** Desenchufa el teléfono, anota la hora y el % de batería, y no toques nada durante 30 minutos.

**B. Latencia (manual, 10 fotos).** Pon el cronómetro corriendo frente a la cámara del teléfono. Con el otro teléfono toma 10 fotos en las que se vean **a la vez** el cronómetro real y el monitor «Al aire» de la laptop. De cada foto anota los dos tiempos.

**C. Reconexión (automática + lo que ves).** Tres veces: apaga el Wi-Fi del teléfono 10 segundos y enciéndelo. No toques nada más. Mira si la imagen vuelve sola y si sigue habiendo **una sola** tarjeta de cámara.

**D. Observaciones (manuales).** Prueba y anota qué pasa: cambiar de lente, zoom, tocar para enfocar, exposición, silenciar el micrófono, girar el teléfono 180°, bloquear la pantalla 30 s, salir de la app 10 s, cerrar y abrir la app.

## 6. Detener y guardar
1. Laptop: pulsa **Registro CSV** (arriba a la derecha). Se descarga `registro-validacion-….csv` en tu carpeta de Descargas. **Hazlo antes de cerrar la pestaña: el registro vive solo en esa pestaña.**
2. Teléfono: **DETENER ENVÍO**. Anota la hora y el % de batería.
3. Laptop: cierra la ventana negra del estudio (o Ctrl+C).

## 7. Qué se mide solo y qué anotas tú
| Medición | Cómo | Objetivo provisional |
|----------|------|----------------------|
| Duración de la corrida | **Automática** (CSV) | 30 min |
| FPS recibidos (mediana, percentil 5, mínimo) | **Automática** | Mediana ≥ 27 |
| Resolución recibida | **Automática** | Se registra |
| Congelamientos: cuántos, **el más largo** y **tiempo total congelado** | **Automática** (pestaña visible) | Se registran |
| Tiempo de reconexión | **Automática** | ≤ 15 s |
| Codificador (hardware o software), batería, temperatura | **Automática** (las envía el teléfono) | Se registran |
| Latencia (mediana y **p95**) | **Manual**: 10 fotos | Mediana ≤ 300 ms; p95 se registra |
| ¿Volvió sola? ¿Una sola tarjeta? ¿Sigue AL AIRE? | **Manual** | Sí |
| Controles, giro, pantalla bloqueada, segundo plano | **Manual** | Se anota |
| Batería al inicio y al final | **Manual** (y automática) | Se registra |

**Congelamiento** = más de 200 ms sin un cuadro nuevo en pantalla (unos 6 cuadros a 30 fps). Una mediana de FPS buena puede esconder un corte largo: por eso se registran el congelamiento más largo y el tiempo total congelado.
Los objetivos son **provisionales**: sirven para leer esta prueba, no aprueban el producto. La segunda cámara se decide después de ver estos resultados.

## 8. Enviar los resultados
Manda por el chat (o sube a `docs/resultados/reales/`):
1. El archivo `registro-validacion-….csv`.
2. Las 10 parejas de tiempos de la latencia (o las fotos).
3. Esta hoja rellenada:
```
Teléfono (marca, modelo, Android):            Laptop (modelo, sistema, Chrome, Java):
Wi-Fi (router, 2,4 o 5 GHz):
Codificador que muestra «Calidad» en el teléfono:      ¿hardware o software?
Batería: inicio __ % a las __:__  →  fin __ % a las __:__     ¿Se calentó? 
Reconexión (3 intentos): ¿volvió sola? ¿cuántas tarjetas quedaron? ¿siguió AL AIRE?
Controles: lente / zoom / enfoque / exposición / micrófono:
Giro 180°:            Pantalla bloqueada 30 s:            App en segundo plano 10 s:
Cerrar y abrir la app (¿reconectó sin escanear?):
Algo que falló o te sorprendió:
```
Con eso se genera el informe: `python -m tools.prueba_camara.analisis <archivo.csv> --origen real`.

## Si algo no funciona
| Síntoma | Qué probar |
|---------|-----------|
| El teléfono se queda «Conectando…» | ¿Misma Wi-Fi? ¿Red de invitados? ¿Cortafuegos de la laptop? ¿Elegiste la IP correcta en «Red local»? |
| «El QR venció» | Pulsa **Crear estudio** otra vez y escanea de nuevo |
| No hay imagen o sale girada | Anótalo con una foto: es justo lo que esta prueba debe descubrir |
| La app se cierra | Anota en qué paso y el modelo del teléfono |
