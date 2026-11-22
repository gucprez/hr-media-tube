# Guía: Estudio + Pizarra + Cámara (2 o 3 teléfonos)

> **Estado:** versión nueva, **no probada todavía en teléfonos reales**. Haz la prueba del final
> en un horario sin partido antes de usarla en el torneo. Las pruebas automáticas (sockets reales,
> lectura de números sintéticos, flujos) no sustituyen la prueba con cámara, wifi y calor reales.

## Qué hace cada teléfono
| Modo | Teléfono sugerido | Qué hace |
|---|---|---|
| **ESTUDIO** | S22+ en trípode filmando la cancha | Pantalla de producción: Vista previa / Al aire, Cámara 1 (su propia cámara), Cámara 2 (remota), Descanso, Resultado. Marcador y logo dentro del video. Transmite a YouTube y Facebook a la vez y graba una copia. |
| **PIZARRA** | S21+ mirando el marcador del gimnasio | Manda puntos, reloj y cuarto al Estudio: **a mano** (botones grandes) o **automático con la cámara** (lee números LED de 7 segmentos). |
| **CÁMARA** | Un teléfono extra (opcional) | Solo filma y manda la imagen al Estudio como Cámara 2. |
| **TRANSMISIÓN RÁPIDA** | Un solo teléfono | Sin Estudio: cámara + marcador a mano (lo de la versión anterior). |

Todos deben estar en **la misma red wifi** (la del gimnasio, un router propio, o el punto de acceso de
un teléfono que no sea el Estudio). Internet solo lo necesita el Estudio.

## Preparación (una vez)
1. Instala `HRMediaSports-Estudio-0.4.0.apk` en todos los teléfonos.
2. En el **Estudio**: botón ⚙ (Transmisión) → claves de YouTube y Facebook, nombres cortos y colores de
   los equipos, logo (PNG transparente), calidad 720p → GUARDAR. Las claves no salen del teléfono.
3. YouTube: activa la emisión en directo en YouTube Studio (la primera vez tarda hasta 24 h).

## Conectar los teléfonos
1. En el Estudio toca el botón **QR** (Conectar). Aparece un código QR, la IP y un código de 16 letras.
2. En la Pizarra o la Cámara elige su modo y apunta al QR. Si no lo lee: "escribir los datos".
3. Queda guardado: si se corta el wifi o se reinicia la app, **se reconectan solos**.
4. "Nuevo código" en el Estudio desconecta a todos (tendrán que escanear de nuevo).

## Pizarra
- **A MANO:** +1 +2 +3 −1 por equipo, arrancar/parar reloj, ajustar ±1 s, cuarto ◀ ▶|, minutos 8/10/12.
- **CÁMARA (AUTO):** teléfono fijo frente al marcador → AJUSTAR ZONAS → arrastra cada recuadro sobre
  su número (Local, Visita, Tiempo, Cuarto; Posesión opcional) → LISTO.
  - Solo envía un número si lo lee igual 3 veces seguidas; un salto de más de 4 puntos necesita 8.
  - Si el marcador se tapa o no se lee, **no envía nada** y el Estudio lo marca "DESACTUALIZADO".
  - Funciona con marcadores de **números de 7 segmentos** (LED o LCD). Con otro tipo de marcador usa A MANO
    y mándanos una foto para adaptarlo.
- En el Estudio siempre puedes usar **Corrección manual** si algo no cuadra.

## Durante el partido (Estudio)
- La Cámara 1 sale al aire al abrir. Toca una fuente → queda en **PREVIA** (naranja) → **PONER AL AIRE** (rojo).
- **Descanso / Resultado:** gráficos de pantalla completa con el marcador actual; la cámara sigue lista debajo.
- **Cámara 2:** la Vista previa muestra una imagen por segundo de esa cámara. Si se corta estando al aire
  más de 3 s, **el Estudio vuelve solo a la Cámara 1** y avisa.
- Botón de marcador (🟩) oculta o muestra el marcador del video.
- **TRANSMITIR** inicia YouTube y Facebook; tocarlo de nuevo pide confirmación para finalizar. **GRABAR** graba aunque no se transmita.
- Sección **Cámaras**: estado de cada teléfono (fps, batería, temperatura) y de cada destino.

## Calor y batería
- 720p 30 fps con codificador por hardware. Si Android avisa calor, el Estudio baja la calidad solo.
- Todos los teléfonos enchufados, sin funda, fuera del sol, en trípode. La Pizarra automática analiza ~4 imágenes/s (poco consumo).
- Cámara remota: botón AHORRO (pantalla al mínimo, sigue enviando).

## Prueba antes del torneo (30 minutos)
1. YouTube en "No listado", Facebook en "Solo yo".
2. Conecta Pizarra (prueba A MANO y AUTO) y Cámara 2 por QR.
3. Transmite: alterna Cámara 1 / Cámara 2 / Descanso / Resultado; mueve el marcador.
4. Apaga el wifi de la Cámara 2 15 s estando al aire → debe volver sola a la Cámara 1; al volver el wifi, se reconecta.
5. Apaga el wifi de la Pizarra 10 s → el Estudio muestra DESACTUALIZADO; al volver se actualiza solo.
6. Revisa desde otro teléfono: imagen, sonido, marcador, retraso. Anota temperaturas y batería a los 30 min.
7. Revisa la grabación en Galería › Películas › HR Media Sports.
