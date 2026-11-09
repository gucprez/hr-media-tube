"""Faro de Luz Radio — sitio público + panel de administración.

Ejecutar:  uvicorn faro.app:app --reload
Variables de entorno: ver faro/README.md
"""
import re
import secrets
from datetime import date, datetime
from pathlib import Path
from zoneinfo import ZoneInfo

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import FileResponse, HTMLResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from markupsafe import Markup, escape

from faro import biblia, db, seguridad

BASE = Path(__file__).resolve().parent
MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
         "septiembre", "octubre", "noviembre", "diciembre"]
MAX_IMAGEN = 5 * 1024 * 1024

app = FastAPI(title="Faro de Luz Radio", docs_url=None, redoc_url=None, openapi_url=None)
db.iniciar()
app.mount("/static", StaticFiles(directory=BASE / "static"), name="static")

plantillas = Jinja2Templates(directory=BASE / "templates")


def hoy() -> str:
    return datetime.now(ZoneInfo("America/Santo_Domingo")).date().isoformat()


def fecha_larga(valor: str) -> str:
    try:
        d = date.fromisoformat(valor)
    except ValueError:
        return valor
    return f"{d.day} de {MESES[d.month - 1]} de {d.year}"


def parrafos(texto: str) -> Markup:
    bloques = [b.strip() for b in re.split(r"\n\s*\n", texto or "") if b.strip()]
    return Markup("".join(f"<p>{escape(b).replace(chr(10), Markup('<br>'))}</p>" for b in bloques))


plantillas.env.filters["fecha_larga"] = fecha_larga
plantillas.env.filters["parrafos"] = parrafos


@app.middleware("http")
async def cabeceras(request: Request, call_next):
    r = await call_next(request)
    r.headers.setdefault("X-Content-Type-Options", "nosniff")
    r.headers.setdefault("Referrer-Policy", "strict-origin-when-cross-origin")
    r.headers.setdefault("X-Frame-Options", "SAMEORIGIN")
    if request.url.path.startswith("/admin"):
        r.headers["Cache-Control"] = "no-store"
    return r


def render(request: Request, nombre: str, estado: int = 200, **ctx):
    return plantillas.TemplateResponse(request, nombre, ctx, status_code=estado)


# ----------------------------------------------------------------- sitio público
def _datos_publicos() -> dict:
    with db.conexion() as c:
        return {
            "aj": db.ajustes(),
            "horarios": [dict(r, dia_nombre=db.DIAS[r["dia"]]) for r in c.execute("SELECT * FROM horarios ORDER BY dia")],
            "versiculos": [dict(r) for r in c.execute("SELECT * FROM versiculos ORDER BY id")],
        }


@app.get("/uploads/{nombre}")
def subida(nombre: str):
    ruta = db.data_dir() / "uploads" / nombre
    if not re.fullmatch(r"[0-9a-f]{24}\.(jpg|png|webp)", nombre) or not ruta.is_file():
        raise HTTPException(404)
    return FileResponse(ruta, headers={"Cache-Control": "public, max-age=86400"})


@app.get("/", response_class=HTMLResponse)
def inicio(request: Request):
    d = _datos_publicos()
    with db.conexion() as c:
        d["noticias"] = [dict(r) for r in c.execute(
            "SELECT * FROM noticias WHERE publicada=1 ORDER BY fecha DESC, id DESC LIMIT 3")]
        d["mensajes"] = [dict(r) for r in c.execute(
            "SELECT * FROM mensajes WHERE publicado=1 ORDER BY fecha DESC, id DESC LIMIT 6")]
    return render(request, "inicio.html", **d)


@app.get("/noticias", response_class=HTMLResponse)
def noticias(request: Request):
    with db.conexion() as c:
        filas = [dict(r) for r in c.execute("SELECT * FROM noticias WHERE publicada=1 ORDER BY fecha DESC, id DESC")]
    return render(request, "noticias.html", aj=db.ajustes(), noticias=filas)


@app.get("/noticias/{nid}", response_class=HTMLResponse)
def noticia(request: Request, nid: int):
    with db.conexion() as c:
        fila = c.execute("SELECT * FROM noticias WHERE id=? AND publicada=1", (nid,)).fetchone()
    if not fila:
        raise HTTPException(404)
    return render(request, "noticia.html", aj=db.ajustes(), n=dict(fila))


@app.get("/mensajes", response_class=HTMLResponse)
def mensajes(request: Request):
    with db.conexion() as c:
        filas = [dict(r) for r in c.execute("SELECT * FROM mensajes WHERE publicado=1 ORDER BY fecha DESC, id DESC")]
    return render(request, "mensajes.html", aj=db.ajustes(), mensajes=filas)


@app.get("/biblia", response_class=HTMLResponse)
def biblia_indice(request: Request, q: str = ""):
    q = q.strip()[:80]
    return render(request, "biblia.html", aj=db.ajustes(), libros=biblia.indice(), q=q,
                  resultados=biblia.buscar(q) if q else None)


@app.get("/biblia/{slug}/{cap}", response_class=HTMLResponse)
def biblia_capitulo(request: Request, slug: str, cap: int):
    info = biblia.libro_info(slug)
    versos = biblia.capitulo(slug, cap) if info else None
    if versos is None:
        raise HTTPException(404)
    n = info["capitulos"]
    idx = biblia.indice()
    pos = idx.index(info)
    ant = (slug, cap - 1) if cap > 1 else ((idx[pos - 1]["slug"], idx[pos - 1]["capitulos"]) if pos else None)
    sig = (slug, cap + 1) if cap < n else ((idx[pos + 1]["slug"], 1) if pos < len(idx) - 1 else None)
    return render(request, "biblia_cap.html", aj=db.ajustes(), info=info, cap=cap, versos=versos,
                  ant=ant, sig=sig, nombres={x["slug"]: x["nombre"] for x in idx})


@app.exception_handler(404)
async def no_encontrada(request: Request, exc):
    return render(request, "404.html", 404, aj=db.ajustes())


# ----------------------------------------------------------------- panel de administración
def _contexto_admin(request: Request, nonce: str, **extra) -> dict:
    return {"csrf": seguridad.token_csrf(nonce), "mensaje": request.query_params.get("ok"), **extra}


async def _form(request: Request, nonce: str):
    await seguridad.verificar_csrf(request, nonce)
    return await request.form()


def _ip(request: Request) -> str:
    return (request.headers.get("x-forwarded-for", "").split(",")[0].strip()
            or (request.client.host if request.client else "?"))


@app.get("/admin/login", response_class=HTMLResponse)
def login_form(request: Request):
    return render(request, "admin/login.html", configurado=seguridad.admin_configurado(), error=None)


@app.post("/admin/login")
async def login(request: Request):
    if not seguridad.admin_configurado():
        return render(request, "admin/login.html", 503, configurado=False, error=None)
    ip = _ip(request)
    if not seguridad.limitar_login(ip):
        return render(request, "admin/login.html", 429, configurado=True,
                      error="Demasiados intentos. Espera unos minutos.")
    form = await request.form()
    if not seguridad.clave_correcta(str(form.get("clave", ""))):
        seguridad.registrar_fallo(ip)
        return render(request, "admin/login.html", 401, configurado=True, error="Contraseña incorrecta.")
    r = RedirectResponse("/admin", status_code=303)
    r.set_cookie(seguridad.COOKIE, seguridad.crear_sesion(), max_age=seguridad.DURACION, httponly=True,
                 samesite="strict", secure=seguridad.cookies_seguras())
    return r


@app.post("/admin/salir")
async def salir(request: Request):
    nonce = seguridad.requiere_admin(request)
    await seguridad.verificar_csrf(request, nonce)
    r = RedirectResponse("/admin/login", status_code=303)
    r.delete_cookie(seguridad.COOKIE)
    return r


@app.get("/admin", response_class=HTMLResponse)
def panel(request: Request):
    nonce = seguridad.requiere_admin(request)
    with db.conexion() as c:
        cuentas = {t: c.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0] for t in ("noticias", "mensajes", "versiculos")}
    return render(request, "admin/panel.html", cuentas=cuentas, **_contexto_admin(request, nonce))


# --- noticias y mensajes: misma mecánica, distintos campos
ENTIDADES = {
    "noticias": {
        "titulo": "Noticias", "singular": "noticia", "estado": "publicada", "ver": "/noticias",
        "campos": [
            ("titulo", "Título", "text", True),
            ("fecha", "Fecha", "date", True),
            ("resumen", "Resumen (una o dos frases)", "area_corta", False),
            ("cuerpo", "Texto completo", "area", False),
            ("imagen", "Foto (opcional, JPG, PNG o WebP, máx. 5 MB)", "imagen", False),
            ("publicada", "Publicada (visible en la web)", "check", False),
        ],
    },
    "mensajes": {
        "titulo": "Mensajes del pastor", "singular": "mensaje", "estado": "publicado", "ver": "/mensajes",
        "campos": [
            ("titulo", "Título", "text", True),
            ("fecha", "Fecha", "date", True),
            ("youtube", "Enlace de YouTube del video (opcional)", "text", False),
            ("descripcion", "Descripción", "area", False),
            ("publicado", "Publicado (visible en la web)", "check", False),
        ],
    },
}


def _entidad(nombre: str) -> dict:
    if nombre not in ENTIDADES:
        raise HTTPException(404)
    return ENTIDADES[nombre]


def id_youtube(texto: str) -> str | None:
    t = texto.strip()
    if re.fullmatch(r"[A-Za-z0-9_-]{11}", t):
        return t
    m = re.match(r"^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)"
                 r"([A-Za-z0-9_-]{11})(?:[&?/#].*)?$", t)
    return m.group(1) if m else None


def guardar_imagen(datos: bytes) -> str:
    if len(datos) > MAX_IMAGEN:
        raise ValueError("La imagen supera 5 MB.")
    if datos[:3] == b"\xff\xd8\xff":
        ext = "jpg"
    elif datos[:8] == b"\x89PNG\r\n\x1a\n":
        ext = "png"
    elif datos[:4] == b"RIFF" and datos[8:12] == b"WEBP":
        ext = "webp"
    else:
        raise ValueError("La foto debe ser JPG, PNG o WebP.")
    nombre = f"{secrets.token_hex(12)}.{ext}"
    (db.data_dir() / "uploads" / nombre).write_bytes(datos)
    return nombre


def borrar_imagen(nombre: str) -> None:
    if nombre and re.fullmatch(r"[0-9a-f]{24}\.(jpg|png|webp)", nombre):
        (db.data_dir() / "uploads" / nombre).unlink(missing_ok=True)


@app.get("/admin/{ent}", response_class=HTMLResponse)
def lista(request: Request, ent: str):
    if ent in ("horarios", "versiculos", "ajustes"):
        return _otras(request, ent)
    nonce = seguridad.requiere_admin(request)
    e = _entidad(ent)
    with db.conexion() as c:
        filas = [dict(r) for r in c.execute(f"SELECT * FROM {ent} ORDER BY fecha DESC, id DESC")]
    return render(request, "admin/lista.html", ent=ent, e=e, filas=filas, **_contexto_admin(request, nonce))


def _formulario(request, nonce, ent, e, fila, errores, estado=200):
    return render(request, "admin/form.html", estado, ent=ent, e=e, fila=fila, errores=errores,
                  **_contexto_admin(request, nonce))


@app.get("/admin/{ent}/nueva", response_class=HTMLResponse)
@app.get("/admin/{ent}/nuevo", response_class=HTMLResponse)
def nuevo(request: Request, ent: str):
    nonce = seguridad.requiere_admin(request)
    e = _entidad(ent)
    return _formulario(request, nonce, ent, e, {"fecha": hoy(), e["estado"]: 1}, [])


@app.get("/admin/{ent}/{eid}", response_class=HTMLResponse)
def editar(request: Request, ent: str, eid: int):
    nonce = seguridad.requiere_admin(request)
    e = _entidad(ent)
    with db.conexion() as c:
        fila = c.execute(f"SELECT * FROM {ent} WHERE id=?", (eid,)).fetchone()
    if not fila:
        raise HTTPException(404)
    return _formulario(request, nonce, ent, e, dict(fila), [])


async def _guardar(request: Request, ent: str, eid: int | None):
    nonce = seguridad.requiere_admin(request)
    e = _entidad(ent)
    form = await _form(request, nonce)
    errores, valores = [], {}
    with db.conexion() as c:
        previa = dict(c.execute(f"SELECT * FROM {ent} WHERE id=?", (eid,)).fetchone() or {}) if eid else {}
    for campo, etiqueta, tipo, obligatorio in e["campos"]:
        if tipo == "check":
            valores[campo] = 1 if form.get(campo) else 0
            continue
        if tipo == "imagen":
            valores[campo] = previa.get(campo, "")
            continue
        v = str(form.get(campo, "")).strip()
        if obligatorio and not v:
            errores.append(f"«{etiqueta}» es obligatorio.")
        if campo == "fecha" and v:
            try:
                date.fromisoformat(v)
            except ValueError:
                errores.append("La fecha no es válida.")
        if campo == "youtube" and v:
            yid = id_youtube(v)
            if yid:
                v = yid
            else:
                errores.append("El enlace de YouTube no es válido.")
        valores[campo] = v
    nueva_imagen = None
    if any(c[2] == "imagen" for c in e["campos"]):
        archivo = form.get("imagen_archivo")
        if archivo is not None and getattr(archivo, "filename", ""):
            try:
                nueva_imagen = guardar_imagen(await archivo.read())
            except ValueError as ex:
                errores.append(str(ex))
        if not errores:
            if nueva_imagen:
                borrar_imagen(previa.get("imagen", ""))
                valores["imagen"] = nueva_imagen
            elif form.get("quitar_imagen"):
                borrar_imagen(previa.get("imagen", ""))
                valores["imagen"] = ""
    if errores:
        return _formulario(request, nonce, ent, e, {**previa, **valores, "id": eid}, errores, 422)
    cols = list(valores)
    with db.conexion() as c:
        if eid:
            c.execute(f"UPDATE {ent} SET " + ",".join(f"{k}=?" for k in cols) + " WHERE id=?",
                      [*valores.values(), eid])
        else:
            c.execute(f"INSERT INTO {ent}({','.join(cols)}) VALUES({','.join('?' * len(cols))})",
                      list(valores.values()))
    return RedirectResponse(f"/admin/{ent}?ok=Guardado", status_code=303)


@app.post("/admin/{ent}/nueva")
@app.post("/admin/{ent}/nuevo")
async def crear(request: Request, ent: str):
    return await _guardar(request, ent, None)


@app.post("/admin/{ent}/{eid}/borrar")
async def borrar(request: Request, ent: str, eid: int):
    nonce = seguridad.requiere_admin(request)
    _entidad(ent)
    await seguridad.verificar_csrf(request, nonce)
    with db.conexion() as c:
        fila = c.execute(f"SELECT * FROM {ent} WHERE id=?", (eid,)).fetchone()
        if fila:
            if "imagen" in fila:
                borrar_imagen(fila["imagen"])
            c.execute(f"DELETE FROM {ent} WHERE id=?", (eid,))
    return RedirectResponse(f"/admin/{ent}?ok=Eliminado", status_code=303)


@app.post("/admin/{ent}/{eid}")
async def actualizar(request: Request, ent: str, eid: int):
    return await _guardar(request, ent, eid)


# --- horarios, versículos y ajustes
def _otras(request: Request, ent: str):
    nonce = seguridad.requiere_admin(request)
    with db.conexion() as c:
        if ent == "horarios":
            filas = [dict(r, dia_nombre=db.DIAS[r["dia"]]) for r in c.execute("SELECT * FROM horarios ORDER BY dia")]
            return render(request, "admin/horarios.html", filas=filas, errores=[], **_contexto_admin(request, nonce))
        if ent == "versiculos":
            filas = [dict(r) for r in c.execute("SELECT * FROM versiculos ORDER BY id")]
            return render(request, "admin/versiculos.html", filas=filas, errores=[], **_contexto_admin(request, nonce))
    return render(request, "admin/ajustes.html", aj=db.ajustes(), errores=[], **_contexto_admin(request, nonce))


@app.post("/admin/horarios")
async def guardar_horarios(request: Request):
    nonce = seguridad.requiere_admin(request)
    form = await _form(request, nonce)
    with db.conexion() as c:
        for i in range(7):
            actividad = str(form.get(f"actividad_{i}", "")).strip()
            if actividad:
                c.execute("UPDATE horarios SET actividad=?, descripcion=?, hora=? WHERE dia=?",
                          (actividad[:150], str(form.get(f"descripcion_{i}", "")).strip()[:300],
                           str(form.get(f"hora_{i}", "")).strip()[:60], i))
    return RedirectResponse("/admin/horarios?ok=Guardado", status_code=303)


@app.post("/admin/versiculos")
async def agregar_versiculo(request: Request):
    nonce = seguridad.requiere_admin(request)
    form = await _form(request, nonce)
    texto, ref = str(form.get("texto", "")).strip(), str(form.get("referencia", "")).strip()
    if not texto or not ref:
        with db.conexion() as c:
            filas = [dict(r) for r in c.execute("SELECT * FROM versiculos ORDER BY id")]
        return render(request, "admin/versiculos.html", 422, filas=filas,
                      errores=["Escribe el texto y la referencia."], **_contexto_admin(request, nonce))
    with db.conexion() as c:
        c.execute("INSERT INTO versiculos(texto,referencia) VALUES(?,?)", (texto[:400], ref[:60]))
    return RedirectResponse("/admin/versiculos?ok=Guardado", status_code=303)


@app.post("/admin/quitar-versiculo/{vid}")
async def borrar_versiculo(request: Request, vid: int):
    nonce = seguridad.requiere_admin(request)
    await seguridad.verificar_csrf(request, nonce)
    with db.conexion() as c:
        c.execute("DELETE FROM versiculos WHERE id=?", (vid,))
    return RedirectResponse("/admin/versiculos?ok=Eliminado", status_code=303)


@app.post("/admin/ajustes")
async def guardar_ajustes(request: Request):
    nonce = seguridad.requiere_admin(request)
    form = await _form(request, nonce)
    nuevos = {k: str(form.get(k, "")).strip() for k in db.AJUSTES_INICIALES}
    errores = []
    if nuevos["correo"] and not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", nuevos["correo"]):
        errores.append("El correo no es válido.")
    if nuevos["radio_url"] and not re.fullmatch(r"https://[^\s\"'<>]+", nuevos["radio_url"]):
        errores.append("La dirección del reproductor debe empezar con https://")
    if nuevos["telefono"] and not re.fullmatch(r"[0-9+()\-. ]{7,25}", nuevos["telefono"]):
        errores.append("El teléfono solo puede tener números, espacios, + ( ) - .")
    if errores:
        return render(request, "admin/ajustes.html", 422, aj=nuevos, errores=errores, **_contexto_admin(request, nonce))
    with db.conexion() as c:
        c.executemany("INSERT INTO ajustes(clave,valor) VALUES(?,?) ON CONFLICT(clave) DO UPDATE SET valor=excluded.valor",
                      nuevos.items())
    return RedirectResponse("/admin/ajustes?ok=Guardado", status_code=303)
