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

CRUISE_EXCURSIONS = [
    {
        "name": "27 Charcos de Damajagua",
        "description": "Salta, resbala y nada en las cascadas naturales de Damajagua, a solo 20 minutos de Amber Cove.",
        "image": "damajagua.jpg",
    },
    {
        "name": "Teleférico y Pico Isabel de Torres",
        "description": "Sube en teleférico hasta la cima del Pico Isabel de Torres y disfruta la mejor vista panorámica de Puerto Plata.",
        "image": "teleferico.jpg",
    },
    {
        "name": "Fortaleza San Felipe y Malecón",
        "description": "Recorre la fortaleza colonial del siglo XVI y el histórico Malecón, a pocos minutos del puerto.",
        "image": "fortaleza.jpg",
    },
]

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
            "cruise_excursions": CRUISE_EXCURSIONS,
            "cruise_faqs": CRUISE_FAQS,
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
