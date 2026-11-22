# Guía rápida: transmitir a YouTube y Facebook

> **Estado:** función nueva, **no probada todavía en un teléfono real**. Haz la prueba de 10 minutos
> (abajo) en un horario sin partido antes de usarla en el torneo. Las capturas y pruebas automáticas
> no sustituyen esa prueba (cámara, temperatura, red real).

## Qué hace
- Cámara trasera del teléfono → **YouTube y Facebook a la vez** (o Restream / cualquier servidor RTMP).
- Codifica **una sola vez** con el codificador por hardware y envía la misma señal a cada destino.
- **Marcador dentro del video** (equipos, colores, puntos, faltas, cuarto, reloj) y **logo** arriba a la derecha.
- Si un destino se cae, **reintenta solo** cada 3 s; el otro sigue al aire.
- **Baja la calidad sola** si internet se congestiona o el teléfono se calienta, en vez de cortarse.
- **Grabación de respaldo** en el teléfono (Galería › Películas › HR Media Sports).
- Sigue transmitiendo si sales de la app o llega una notificación (notificación fija "transmitiendo").

## Antes del primer partido (una sola vez)
1. Instala `HRMediaSports-Transmitir-0.3.0.apk` (permite "instalar apps desconocidas").
2. **YouTube:** YouTube Studio › Crear › Emitir en directo. La primera activación puede tardar **hasta 24 h**.
   Copia la **clave de transmisión**.
3. **Facebook:** Video en vivo (Live Producer) › Software de transmisión › usa **clave persistente** y cópiala.
4. En la app: **TRANSMITIR EN VIVO** › da permisos de cámara, micrófono y notificaciones › **CONFIGURAR**:
   activa YouTube y Facebook, pega cada clave, pon nombres cortos (máx. 4 letras) y colores, minutos por
   cuarto, elige el logo (PNG transparente) y **GUARDAR**. Las claves solo se guardan en el teléfono.

## Internet
- 720p recomendada: ~4,7 Mbps de **subida** por destino → **~9,4 Mbps para YouTube + Facebook**.
- Si no llegas: usa **Restream** (destino "Otro", un solo envío) o la calidad **720p ahorro**.
- Wifi estable mejor que datos móviles (calientan más el teléfono).

## Para que no se caliente
- Calidad **720p · 30 fps** (no 1080p). Quita la funda, evita el sol, usa trípode (no la mano).
- Botón **AHORRO**: apaga la vista previa y baja el brillo al mínimo; la transmisión sigue.
- La barra superior muestra **TEMP**: si dice "caliente", la app ya bajó la calidad; si sigue subiendo, activa AHORRO.
- Cargador de buena calidad; si tienes, ventilador clip para teléfono.

## Durante el partido
- **TRANSMITIR** inicia todos los destinos activos. Borde rojo = al aire.
- Cada destino muestra EN VIVO / RECONECTANDO / ERROR. Tócalo para detener o reconectar **solo ese**.
- Marcador: `+1 +2 +3 −1 F+`; tocar `F2 −` resta una falta; `▶ / II` reloj; `◀ / ▶|` cuarto
  (▶| reinicia reloj y faltas). Toca el reloj para ajustarlo a mano.
- **FINALIZAR** pide confirmación. El botón Atrás no corta la transmisión.

## Prueba de 10 minutos (obligatoria antes del torneo)
1. En YouTube crea una transmisión **No listada**; en Facebook publica en "Solo yo".
2. Transmite 10 min en la cancha (o con la misma red): mueve el marcador, corre el reloj, activa AHORRO,
   sal de la app y vuelve, apaga el wifi 20 s y vuelve a encenderlo (debe reconectar sola).
3. Revisa en otro teléfono: imagen, sonido, marcador, logo, retraso. Anota la temperatura que mostró.
4. Comprueba la grabación en Galería › Películas › HR Media Sports.

Si algo falla, manda captura de la pantalla de la app y el texto del error.
