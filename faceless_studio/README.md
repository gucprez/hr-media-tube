# Faceless Studio — canal de curiosidades automatizado

Generador de videos verticales (formato Shorts) 100% automático y con costo
de operación **$0**: guion escrito, voz por IA gratuita (Edge TTS, sin API
key), fondo animado generado por código, subtítulos sincronizados palabra
por palabra y miniatura, todo en un solo comando.

Lo que este código **no** puede hacer por vos: crear tu canal de YouTube,
pagar/gestionar cuentas de terceros, ni tramitar la monetización (AdSense
exige tu identidad fiscal real). Esa parte la hacés vos siguiendo la guía
de abajo; el contenido, la voz, el video y la miniatura ya están listos.

## 1. Instalación

```bash
cd faceless_studio
pip install -r requirements.txt
```

Necesita `ffmpeg`, que se instala solo (vía el paquete `imageio-ffmpeg`, no
hace falta instalarlo en el sistema). Necesita conexión a internet (Edge TTS
llama a un servicio gratuito de Microsoft para generar la voz).

## 2. Generar los videos

```bash
# Genera los 12 videos del banco inicial
python -m faceless_studio.generate

# O uno solo, por id (ver faceless_studio/scripts_data.py)
python -m faceless_studio.generate 01_pulpo_corazones
```

Cada video queda en `faceless_studio/output/<id>/`:
- `video.mp4` — el short, formato 1080x1920, listo para subir.
- `thumbnail.jpg` — miniatura.
- `metadata.txt` — título, descripción y tags, listos para copiar/pegar en
  YouTube Studio.

Cada corrida tarda 1-2 minutos por video (la mayor parte es la codificación
de ffmpeg).

## 3. Crear el canal (esto lo hacés vos)

1. Andá a [youtube.com](https://youtube.com) con tu cuenta de Google y creá
   un canal. Elegí un nombre relacionado a curiosidades/datos (ej: "Dato
   Curioso Diario", "Sabías Que...", etc. — revisá que no esté muy
   repetido).
2. Subí una foto de perfil y un banner simples (podés pedirme que te genere
   opciones con este mismo enfoque de diseño por código, sin costo).
3. En la sección "Contenido" de YouTube Studio, subí el primer short: usá
   `video.mp4`, el título y la descripción de `metadata.txt`, y marcalo
   como "Hecho para niños: No".
4. Subí `thumbnail.jpg` como miniatura personalizada (en Shorts, YouTube a
   veces la ignora en el feed pero sí se usa en el perfil del canal y en
   YouTube en TV/desktop).

## 4. Plan de publicación para lo que resta de 2026

Con constancia es más importante que el volumen. Plan sugerido desde ahora
(sept. 2026) hasta fin de año:

- **Semanas 1-2 (arranque):** subí 1 short por día usando el banco de 12
  guiones ya generado. Esto valida el formato y le da al algoritmo señal
  rápida sobre tu nicho.
- **A partir de la semana 3:** sumá guiones nuevos (ver sección 5) para
  sostener 1 short diario o cada dos días. La cadencia constante importa
  más que picos irregulares.
- **Métricas a mirar cada semana** (YouTube Studio → Analytics):
  duración media de reproducción (>70% del video es buena señal para
  Shorts), tasa de "me gusta", y de dónde viene el tráfico (feed de
  Shorts vs. búsqueda).
- **Iterar sobre lo que funciona:** cuando un video despegue, hacé 2-3
  variantes del mismo estilo/tema (mismo animal, misma época histórica,
  etc.), el algoritmo de Shorts favorece consistencia temática.

## 5. Escalar el contenido más allá de los 12 guiones iniciales

Opciones, de más simple a más automatizada:

1. **Escribir vos guiones nuevos** siguiendo el mismo formato de
   `scripts_data.py` (hook fuerte en la primera frase, 130-190 palabras,
   dato verificable). Es gratis y no depende de ninguna API.
2. **Pedirme a mí que redacte tandas nuevas** de guiones (puedo escribir
   lotes de 10-20 por vez, sin costo, como hice con los primeros 12).
3. **Conectar un LLM gratuito para generación continua**: [Groq](https://console.groq.com)
   ofrece una API gratuita (modelos abiertos tipo Llama) con límites
   generosos, sin tarjeta de crédito. Si querés este camino, decime y te
   dejo armado un script que genere guiones nuevos automáticamente a partir
   de una lista de temas.

## 6. Requisitos de monetización (YouTube Partner Program)

Para activar anuncios en Shorts necesitás, en los últimos 90 días:
- 1.000 suscriptores, **y**
- 10 millones de vistas válidas en Shorts (o, alternativamente, 4.000 horas
  de reproducción pública en contenido normal en los últimos 12 meses).

Esto lo verificás vos mismo en YouTube Studio → Monetización, y el alta de
AdSense requiere tus datos fiscales reales — nadie puede hacerlo en tu
nombre. Mientras juntás esos números, el canal también sirve como vidriera
para ingresos por: marketing de afiliados (linkeando productos relacionados
al dato del video en la descripción) o menciones patrocinadas una vez que
tengas audiencia.

## 7. Estructura del código

```
faceless_studio/
  scripts_data.py   # banco de guiones (contenido)
  tts.py            # voz por IA (Edge TTS, gratis)
  captions.py       # agrupa el timing palabra-por-palabra en subtítulos
  background.py     # fondo animado generado por código (gradiente + partículas)
  render.py         # arma el video final (ffmpeg vía pipe)
  thumbnail.py      # genera la miniatura
  generate.py       # CLI que orquesta todo
```

Todo el pipeline es independiente de la app FastAPI del resto del repo
(`main.py`) — no comparten dependencias ni se despliegan juntos.
