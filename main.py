from fastapi import FastAPI, Form
from fastapi.responses import HTMLResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.requests import Request

app = FastAPI(title="AR Excursiones")

app.mount("/static", StaticFiles(directory="static"), name="static")
templates = Jinja2Templates(directory="templates")

WHATSAPP_NUMBER = "18298171402"  # +1 829 817 1402
AGENCY_NAME = "AR Excursiones"
INSTAGRAM_URL = "https://www.instagram.com/arialdysrexcursiones"
FACEBOOK_URL = "https://www.facebook.com/share/19Ad9MJNq3/"

DESTINATIONS = [
    {
        "slug": "medellin",
        "name": "Medellín",
        "country": "Colombia",
        "tagline": "La ciudad de la eterna primavera",
        "description": "Comuna 13, Guatapé, El Peñol, tours de café y la mejor vida nocturna paisa.",
        "image": "medellin.jpg",
    },
    {
        "slug": "bogota",
        "name": "Bogotá",
        "country": "Colombia",
        "tagline": "Historia, cultura y montaña",
        "description": "Monserrate, La Candelaria, Museo del Oro y los mejores planes en la capital.",
        "image": "bogota.jpg",
    },
    {
        "slug": "peru",
        "name": "Perú",
        "country": "Perú",
        "tagline": "Machu Picchu te espera",
        "description": "Cusco, Valle Sagrado, Machu Picchu y la magia de los Andes.",
        "image": "peru.jpg",
    },
    {
        "slug": "mexico",
        "name": "México",
        "country": "México",
        "tagline": "Cultura, playas y sabor",
        "description": "Chichén Itzá, Cancún, Ciudad de México y mucho más.",
        "image": "mexico.jpg",
    },
    {
        "slug": "usa",
        "name": "Estados Unidos",
        "country": "USA",
        "tagline": "Grandes ciudades, grandes experiencias",
        "description": "Nueva York, Miami, Orlando y los destinos favoritos en USA.",
        "image": "usa.jpg",
    },
    {
        "slug": "republica-dominicana",
        "name": "República Dominicana",
        "country": "Puerto Plata y otras ciudades",
        "tagline": "El Caribe en su máxima expresión",
        "description": "Puerto Plata, Santo Domingo, Punta Cana, Samaná y las mejores playas del país.",
        "image": "rd.jpg",
    },
]


@app.get("/", response_class=HTMLResponse)
def home(request: Request):
    return templates.TemplateResponse(
        request,
        "index.html",
        {
            "agency_name": AGENCY_NAME,
            "whatsapp_number": WHATSAPP_NUMBER,
            "instagram_url": INSTAGRAM_URL,
            "facebook_url": FACEBOOK_URL,
            "destinations": DESTINATIONS,
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
