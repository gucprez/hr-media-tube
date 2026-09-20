import json
import re
from datetime import date, datetime
from pathlib import Path

from fastapi import FastAPI, Form, Request
from fastapi.responses import FileResponse, JSONResponse, PlainTextResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from data.schedule import SCHEDULE
from data.verses import verse_of_day
from data.messages import MESSAGES
from data.news import NEWS

BASE_DIR = Path(__file__).resolve().parent
DATA_STORE_DIR = BASE_DIR / "data" / "store"
DATA_STORE_DIR.mkdir(parents=True, exist_ok=True)
SUBSCRIBERS_FILE = DATA_STORE_DIR / "subscribers.json"
TESTIMONIES_FILE = DATA_STORE_DIR / "testimonios.json"

SITE_NAME = "Faro de Luz Radio"
SITE_URL = "https://farodeluzradio.com"
SITE_TAGLINE = "Iluminando vidas a través de la Palabra de Dios"
SITE_DESCRIPTION = (
    "Faro de Luz Radio es la radio online de nuestra iglesia: escucha en vivo "
    "predicaciones, alabanza y programación cristiana las 24 horas, y "
    "encuentra la programación semanal, mensajes y recursos bíblicos."
)

# TODO: reemplazar por los enlaces reales de redes sociales de la iglesia
SOCIAL_LINKS = {
    "facebook": "",
    "instagram": "",
    "youtube": "",
    "tiktok": "",
    "whatsapp": "",
}

# Stream real detectado en el sitio actual (Shoutcast). Confirmar con la
# iglesia si sigue vigente o si hay una nueva URL de transmisión.
STREAM_EMBED_URL = "https://radio.farodeluzradio.com/Shoutcast/index.php"
STREAM_AUDIO_URL = ""  # TODO: URL directa .mp3/.aac del stream si está disponible

# TODO: confirmar datos reales de contacto de la iglesia
CONTACT = {
    "phone": "",
    "whatsapp": "",
    "email": "",
    "address": "",
}

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
        "stream_embed_url": STREAM_EMBED_URL,
        "stream_audio_url": STREAM_AUDIO_URL,
        "contact": CONTACT,
        "current_year": datetime.now().year,
        "path": request.url.path,
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
            title=f"{SITE_NAME} — {SITE_TAGLINE}",
            description=SITE_DESCRIPTION,
            og_image="/static/img/og-image.svg",
            schedule=SCHEDULE,
            verse=verse,
            canonical=f"{SITE_URL}/",
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


@app.get("/quienes-somos")
def quienes_somos(request: Request):
    return templates.TemplateResponse(
        request,
        "quienes-somos.html",
        base_context(
            request,
            title=f"Quiénes Somos — {SITE_NAME}",
            description=(
                "Conoce la historia, misión y visión de Faro de Luz Radio, "
                "la radio online de nuestra iglesia."
            ),
            canonical=f"{SITE_URL}/quienes-somos",
        ),
    )


@app.get("/contacto")
def contacto(request: Request):
    return templates.TemplateResponse(
        request,
        "contacto.html",
        base_context(
            request,
            title=f"Contacto — {SITE_NAME}",
            description="Ponte en contacto con Faro de Luz Radio: teléfono, correo, dirección y redes sociales.",
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
        "", "programacion", "mensajes", "la-biblia", "noticias",
        "quienes-somos", "contacto",
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
