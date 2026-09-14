import os

from fastapi import FastAPI, Form
from fastapi.responses import HTMLResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware
from starlette.requests import Request

import db
from admin import router as admin_router
from sections import AIRBNB_ZONES

app = FastAPI(title="AR Excursiones")

app.add_middleware(
    SessionMiddleware,
    secret_key=os.environ.get("SECRET_KEY", "ar-excursiones-change-this-secret-key"),
)

app.mount("/static", StaticFiles(directory="static"), name="static")
templates = Jinja2Templates(directory="templates")

app.include_router(admin_router)

WHATSAPP_NUMBER = "18298171402"  # +1 829 817 1402
AGENCY_NAME = "AR Excursiones"
INSTAGRAM_URL = "https://www.instagram.com/arialdysrexcursiones"
FACEBOOK_URL = "https://www.facebook.com/share/19Ad9MJNq3/"
ADDRESS = "Calle 4 #13, Puerto Plata 57001, República Dominicana"
MAPS_URL = "https://maps.app.goo.gl/WpcoZxjqS932nNia9"
MAPS_EMBED_URL = (
    "https://www.google.com/maps/embed?origin=mfe&pb=!1m2!2m1!1s"
    "Calle+4+%2313%2C+Puerto+Plata+57001%2C+Dominican+Republic"
)

CRUISE_FAQS = [
    {
        "q": "¿Recogen en el muelle de Amber Cove o Taíno Bay?",
        "a": "Sí, coordinamos el punto de encuentro directamente en la terminal de cruceros de Puerto Plata (Amber Cove o Taíno Bay) para que no pierdas tiempo bajando del barco.",
    },
    {
        "q": "¿La excursión regresa a tiempo para abordar el barco?",
        "a": "Sí. Todos nuestros tours para pasajeros de crucero están diseñados con horarios de regreso garantizados antes del zarpe.",
    },
    {
        "q": "¿Qué debo llevar a la excursión?",
        "a": "Traje de baño, toalla, protector solar biodegradable, calzado cómodo y efectivo en dólares para gastos personales.",
    },
    {
        "q": "¿Puedo reservar el mismo día que llega mi crucero?",
        "a": "Te recomendamos reservar con anticipación por WhatsApp, pero si hay disponibilidad coordinamos tours el mismo día de tu llegada.",
    },
]


@app.on_event("startup")
def on_startup():
    db.init_db()


@app.get("/", response_class=HTMLResponse)
def home(request: Request):
    airbnb_listings = db.list_items("airbnb", active_only=True)
    airbnb_zones = [z for z in AIRBNB_ZONES if any(a.get("zone") == z for a in airbnb_listings)]
    return templates.TemplateResponse(
        request,
        "index.html",
        {
            "agency_name": AGENCY_NAME,
            "whatsapp_number": WHATSAPP_NUMBER,
            "instagram_url": INSTAGRAM_URL,
            "facebook_url": FACEBOOK_URL,
            "address": ADDRESS,
            "maps_url": MAPS_URL,
            "maps_embed_url": MAPS_EMBED_URL,
            "destinations": db.list_items("destinos", active_only=True),
            "cruise_excursions": db.list_items("cruceros", active_only=True),
            "cruise_faqs": CRUISE_FAQS,
            "offers": db.list_items("ofertas", active_only=True),
            "news": db.list_items("noticias", active_only=True),
            "airbnb_listings": airbnb_listings,
            "airbnb_zones": airbnb_zones,
        },
    )


@app.get("/noticias/{slug}", response_class=HTMLResponse)
def noticia_detail(request: Request, slug: str):
    noticia = db.get_item_by("noticias", "slug", slug)
    if not noticia:
        return HTMLResponse("Noticia no encontrada", status_code=404)
    return templates.TemplateResponse(
        request,
        "noticia.html",
        {
            "agency_name": AGENCY_NAME,
            "whatsapp_number": WHATSAPP_NUMBER,
            "instagram_url": INSTAGRAM_URL,
            "facebook_url": FACEBOOK_URL,
            "noticia": noticia,
        },
    )


@app.post("/contacto")
def contacto(
    nombre: str = Form(...),
    telefono: str = Form(...),
    destino: str = Form(""),
    mensaje: str = Form(""),
):
    return {
        "status": "ok",
        "mensaje": f"Gracias {nombre}, hemos recibido tu solicitud. Te contactaremos pronto.",
    }
