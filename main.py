import json
import re
from datetime import date, datetime
from pathlib import Path

from fastapi import FastAPI, Form, Request
from fastapi.responses import (
    FileResponse,
    JSONResponse,
    PlainTextResponse,
    RedirectResponse,
)
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from data.schedule import SCHEDULE
from data.verses import verse_of_day
from data.messages import MESSAGES
from data.news import NEWS
from data.activities import ACTIVITIES
from data.hosts import HOSTS
from data.icons import ICONS

BASE_DIR = Path(__file__).resolve().parent
DATA_STORE_DIR = BASE_DIR / "data" / "store"
DATA_STORE_DIR.mkdir(parents=True, exist_ok=True)
SUBSCRIBERS_FILE = DATA_STORE_DIR / "subscribers.json"
TESTIMONIES_FILE = DATA_STORE_DIR / "testimonios.json"
PRAYER_REQUESTS_FILE = DATA_STORE_DIR / "oraciones.json"

SITE_NAME = "Faro de Luz Radio"
SITE_URL = "https://farodeluzradio.com"
SITE_TAGLINE = "Radio que ilumina tu camino"
SITE_DESCRIPTION = (
    "Faro de Luz Radio es la radio cristiana de nuestra iglesia en Puerto "
    "Plata: música, palabra, adoración y esperanza en vivo, además de "
    "programación semanal, transmisiones y actividades."
)

# TODO: reemplazar por los enlaces reales de redes sociales de la iglesia.
# Se buscaron cuentas públicas y no fue posible confirmar con certeza
# cuáles pertenecen a esta iglesia (hay varias "Faro de Luz" distintas).
SOCIAL_LINKS = {
    "facebook": "",
    "instagram": "",
    "youtube": "",
    "tiktok": "",
    "whatsapp": "",
}

# El stream detectado en el sitio actual (Shoutcast) responde 404 — no hay
# una transmisión en vivo funcional en este momento. Actualiza esta URL con
# el stream real (.mp3/.aac o servidor Icecast/Shoutcast vigente) en cuanto
# la iglesia lo confirme.
STREAM_AUDIO_URL = ""
STREAM_IS_LIVE = bool(STREAM_AUDIO_URL)

# TODO: confirmar teléfono, WhatsApp y correo reales de la iglesia.
# La dirección sí fue provista y se usa tal cual.
CONTACT = {
    "phone": "",
    "whatsapp": "",
    "email": "",
    "address": "Calle Principal #9, Padre Granero, Puerto Plata, República Dominicana",
}

# TODO: enlace real al canal/transmisión de YouTube de la iglesia.
YOUTUBE_LIVE_URL = ""

app = FastAPI(title=SITE_NAME)
app.mount("/static", StaticFiles(directory=BASE_DIR / "static"), name="static")
templates = Jinja2Templates(directory=BASE_DIR / "templates")


def base_context(request: Request, **extra) -> dict:
    context = {
        "request": request,
        "site_name": SITE_NAME,
        "site_url": SITE_URL,
        "site_tagline": SITE_TAGLINE,
        "site_description": SITE_DESCRIPTION,
        "social": SOCIAL_LINKS,
        "social_urls": [v for v in SOCIAL_LINKS.values() if v],
        "stream_audio_url": STREAM_AUDIO_URL,
        "stream_is_live": STREAM_IS_LIVE,
        "youtube_live_url": YOUTUBE_LIVE_URL,
        "contact": CONTACT,
        "current_year": datetime.now().year,
        "path": request.url.path,
        "icons": ICONS,
    }
    context.update(extra)
    return context


@app.get("/", response_class=None)
def home(request: Request):
    verse = verse_of_day(date.today().timetuple().tm_yday)
    return templates.TemplateResponse(
        request,
        "index.html",
        base_context(
            request,
            title=f"{SITE_NAME} | Radio Cristiana en Puerto Plata",
            description=SITE_DESCRIPTION,
            og_image="/static/img/og-image.svg",
            schedule=SCHEDULE[:3],
            verse=verse,
            activities=ACTIVITIES[:3],
            hosts=HOSTS[:6],
            canonical=f"{SITE_URL}/",
        ),
    )


@app.get("/radio")
def radio(request: Request):
    return templates.TemplateResponse(
        request,
        "radio.html",
        base_context(
            request,
            title=f"Radio en vivo — {SITE_NAME}",
            description=(
                "Escucha Faro de Luz Radio en vivo: música, palabra, "
                "adoración y esperanza para cada hogar."
            ),
            canonical=f"{SITE_URL}/radio",
        ),
    )


@app.get("/en-vivo")
def en_vivo(request: Request):
    return templates.TemplateResponse(
        request,
        "en-vivo.html",
        base_context(
            request,
            title=f"Transmisiones en vivo — {SITE_NAME}",
            description=(
                "Sigue las transmisiones en vivo de cultos y eventos "
                "especiales de Faro de Luz, y escucha la radio en vivo."
            ),
            canonical=f"{SITE_URL}/en-vivo",
            messages=MESSAGES,
        ),
    )


@app.get("/programacion")
def programacion(request: Request):
    return templates.TemplateResponse(
        request,
        "programacion.html",
        base_context(
            request,
            title=f"Programación semanal — {SITE_NAME}",
            description=(
                "Consulta la programación semanal de Faro de Luz Radio: "
                "cultos, vigilias, estudios bíblicos y actividades para "
                "toda la familia, todos los días de la semana."
            ),
            schedule=SCHEDULE,
            canonical=f"{SITE_URL}/programacion",
        ),
    )


@app.get("/mensajes")
def mensajes(request: Request):
    return templates.TemplateResponse(
        request,
        "mensajes.html",
        base_context(
            request,
            title=f"Mensajes y Prédicas — {SITE_NAME}",
            description=(
                "Escucha y comparte los mensajes bíblicos y prédicas del "
                "pastor de Faro de Luz Radio."
            ),
            canonical=f"{SITE_URL}/mensajes",
            messages=MESSAGES,
        ),
    )


@app.get("/la-biblia")
def la_biblia(request: Request):
    verse = verse_of_day(date.today().timetuple().tm_yday)
    return templates.TemplateResponse(
        request,
        "biblia.html",
        base_context(
            request,
            title=f"La Biblia y Versículo del Día — {SITE_NAME}",
            description=(
                "Lee el versículo del día y descubre por qué estudiar la "
                "Biblia transforma vidas, con Faro de Luz Radio."
            ),
            verse=verse,
            canonical=f"{SITE_URL}/la-biblia",
        ),
    )


@app.get("/noticias")
def noticias(request: Request):
    return templates.TemplateResponse(
        request,
        "noticias.html",
        base_context(
            request,
            title=f"Noticias — {SITE_NAME}",
            description="Últimas noticias, novedades y anuncios de Faro de Luz Radio y nuestra iglesia.",
            canonical=f"{SITE_URL}/noticias",
            news=NEWS,
        ),
    )


@app.get("/actividades")
def actividades(request: Request):
    return templates.TemplateResponse(
        request,
        "actividades.html",
        base_context(
            request,
            title=f"Actividades — {SITE_NAME}",
            description="Actividades y eventos de Faro de Luz: jóvenes, damas, estudios bíblicos y más.",
            canonical=f"{SITE_URL}/actividades",
            activities=ACTIVITIES,
        ),
    )


@app.get("/iglesia")
def iglesia(request: Request):
    return templates.TemplateResponse(
        request,
        "iglesia.html",
        base_context(
            request,
            title=f"Nuestra Iglesia — {SITE_NAME}",
            description="Conoce Faro de Luz, la iglesia detrás de la radio en Puerto Plata.",
            canonical=f"{SITE_URL}/iglesia",
        ),
    )


@app.get("/quienes-somos")
def quienes_somos_redirect():
    return RedirectResponse(url="/iglesia", status_code=301)


@app.get("/contacto")
def contacto(request: Request):
    return templates.TemplateResponse(
        request,
        "contacto.html",
        base_context(
            request,
            title=f"Contacto — {SITE_NAME}",
            description="Ponte en contacto con Faro de Luz Radio: dirección, correo y redes sociales.",
            canonical=f"{SITE_URL}/contacto",
        ),
    )


EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


def _append_json_line(path: Path, record: dict) -> None:
    records = []
    if path.exists():
        try:
            records = json.loads(path.read_text() or "[]")
        except json.JSONDecodeError:
            records = []
    records.append(record)
    path.write_text(json.dumps(records, ensure_ascii=False, indent=2))


@app.post("/suscribirse")
def suscribirse(email: str = Form(...)):
    email = email.strip().lower()
    if not EMAIL_RE.match(email):
        return JSONResponse({"ok": False, "mensaje": "Correo inválido."}, status_code=400)
    _append_json_line(
        SUBSCRIBERS_FILE,
        {"email": email, "fecha": datetime.now().isoformat()},
    )
    return JSONResponse({"ok": True, "mensaje": "¡Te has suscrito con éxito!"})


@app.post("/testimonio")
def testimonio(nombre: str = Form(...), mensaje: str = Form(...)):
    nombre = nombre.strip()[:80]
    mensaje = mensaje.strip()[:1000]
    if not nombre or not mensaje:
        return JSONResponse({"ok": False, "mensaje": "Completa tu nombre y testimonio."}, status_code=400)
    _append_json_line(
        TESTIMONIES_FILE,
        {"nombre": nombre, "mensaje": mensaje, "fecha": datetime.now().isoformat()},
    )
    return JSONResponse({"ok": True, "mensaje": "¡Gracias por compartir tu testimonio! Será revisado antes de publicarse."})


@app.post("/oracion")
def oracion(
    nombre: str = Form(...),
    mensaje: str = Form(...),
    correo: str = Form(""),
    sitio_web: str = Form(""),
):
    # Campo honeypot: los bots suelen rellenarlo, las personas nunca lo ven.
    if sitio_web.strip():
        return JSONResponse({"ok": True, "mensaje": "Gracias, oraremos por ti."})

    nombre = nombre.strip()[:80]
    mensaje = mensaje.strip()[:1000]
    correo = correo.strip()[:120]
    if not nombre or not mensaje:
        return JSONResponse({"ok": False, "mensaje": "Completa tu nombre y tu petición."}, status_code=400)
    if correo and not EMAIL_RE.match(correo):
        return JSONResponse({"ok": False, "mensaje": "Correo inválido."}, status_code=400)

    _append_json_line(
        PRAYER_REQUESTS_FILE,
        {"nombre": nombre, "correo": correo, "mensaje": mensaje, "fecha": datetime.now().isoformat()},
    )
    return JSONResponse({"ok": True, "mensaje": "Gracias por confiarnos tu petición. Oraremos por ti."})


@app.get("/robots.txt", response_class=PlainTextResponse)
def robots_txt():
    return (
        "User-agent: *\n"
        "Allow: /\n"
        f"Sitemap: {SITE_URL}/sitemap.xml\n"
    )


@app.get("/sitemap.xml")
def sitemap_xml():
    pages = [
        "", "radio", "en-vivo", "programacion", "mensajes", "la-biblia",
        "noticias", "actividades", "iglesia", "contacto",
    ]
    urls = "".join(
        f"<url><loc>{SITE_URL}/{p}</loc><changefreq>weekly</changefreq></url>"
        for p in pages
    )
    xml = (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        f"{urls}</urlset>"
    )
    return PlainTextResponse(xml, media_type="application/xml")


@app.get("/favicon.ico")
def favicon():
    return FileResponse(BASE_DIR / "static" / "img" / "favicon.svg", media_type="image/svg+xml")
