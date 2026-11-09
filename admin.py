"""Panel de administración: login y edición de contenido del sitio."""

from fastapi import APIRouter, Depends, Request
from fastapi.responses import RedirectResponse
from fastapi.templating import Jinja2Templates

from auth import (
    client_ip,
    clear_attempts,
    current_admin,
    get_csrf_token,
    is_locked_out,
    register_failed_attempt,
    require_admin,
    verify_csrf,
)
from data import content_store as store

templates = Jinja2Templates(directory="templates")

router = APIRouter(prefix="/admin")

SECTION_LABELS = {
    "schedule": "Programación",
    "activities": "Actividades",
    "news": "Noticias",
    "messages": "Mensajes",
    "hosts": "Voces de Faro de Luz",
}

SECTION_FIELDS = {
    "schedule": [
        ("day", "Día", "text"),
        ("time", "Hora", "text"),
        ("title", "Título", "text"),
        ("description", "Descripción", "textarea"),
    ],
    "activities": [
        ("title", "Título", "text"),
        ("date", "Fecha", "text"),
        ("time", "Hora", "text"),
        ("description", "Descripción", "textarea"),
        ("image", "Imagen (URL)", "text"),
    ],
    "news": [
        ("title", "Título", "text"),
        ("date", "Fecha", "text"),
        ("excerpt", "Resumen", "textarea"),
    ],
    "messages": [
        ("title", "Título", "text"),
        ("date", "Fecha", "text"),
        ("preacher", "Predicador", "text"),
        ("youtube_id", "ID de video de YouTube", "text"),
        ("summary", "Resumen", "textarea"),
    ],
    "hosts": [
        ("name", "Nombre", "text"),
        ("program", "Programa", "text"),
        ("schedule", "Horario", "text"),
        ("photo", "Foto (URL)", "text"),
    ],
}

# Columnas que se muestran en la tabla de cada sección (además del título principal).
SECTION_LIST_COLUMNS = {
    "schedule": ["day", "time"],
    "activities": ["date", "time"],
    "news": ["date"],
    "messages": ["date", "preacher"],
    "hosts": ["program", "schedule"],
}

SECTION_TITLE_FIELD = {
    "schedule": "title",
    "activities": "title",
    "news": "title",
    "messages": "title",
    "hosts": "name",
}


def admin_context(request: Request, **extra) -> dict:
    context = {
        "request": request,
        "admin_user": current_admin(request),
        "csrf_token": get_csrf_token(request),
        "sections": SECTION_LABELS,
    }
    context.update(extra)
    return context


@router.get("/login")
def login_form(request: Request):
    if current_admin(request):
        return RedirectResponse(url="/admin", status_code=303)
    return templates.TemplateResponse(
        request, "admin/login.html", admin_context(request, error=None)
    )


@router.post("/login")
async def login_submit(request: Request):
    form = await request.form()
    ip = client_ip(request)

    locked_seconds = is_locked_out(ip)
    if locked_seconds:
        minutes = max(1, locked_seconds // 60)
        return templates.TemplateResponse(
            request,
            "admin/login.html",
            admin_context(
                request,
                error=f"Demasiados intentos fallidos. Intenta de nuevo en {minutes} minuto(s).",
            ),
            status_code=429,
        )

    username = str(form.get("username", "")).strip()
    password = str(form.get("password", ""))

    if store.verify_admin(username, password):
        clear_attempts(ip)
        request.session.clear()
        request.session["admin_user"] = username
        return RedirectResponse(url="/admin", status_code=303)

    register_failed_attempt(ip)
    return templates.TemplateResponse(
        request,
        "admin/login.html",
        admin_context(request, error="Usuario o contraseña incorrectos."),
        status_code=401,
    )


@router.post("/logout")
def logout(request: Request):
    request.session.clear()
    return RedirectResponse(url="/admin/login", status_code=303)


@router.get("")
def dashboard(request: Request, admin: str = Depends(require_admin)):
    data = store.load()
    counts = {name: len(data.get(name, [])) for name in SECTION_LABELS}
    return templates.TemplateResponse(
        request, "admin/dashboard.html", admin_context(request, counts=counts)
    )


def _check_csrf_or_redirect(request: Request, token: str, redirect_to: str):
    if not verify_csrf(request, token):
        return RedirectResponse(url=redirect_to, status_code=303)
    return None


@router.get("/{section}")
def list_section(request: Request, section: str, admin: str = Depends(require_admin)):
    if section not in SECTION_LABELS:
        return RedirectResponse(url="/admin", status_code=303)
    items = store.get_section(section) or []
    field_labels = {name: label for name, label, _ in SECTION_FIELDS[section]}
    return templates.TemplateResponse(
        request,
        "admin/list.html",
        admin_context(
            request,
            section=section,
            section_label=SECTION_LABELS[section],
            items=items,
            title_field=SECTION_TITLE_FIELD[section],
            columns=SECTION_LIST_COLUMNS[section],
            field_labels=field_labels,
        ),
    )


@router.get("/{section}/nuevo")
def new_item_form(request: Request, section: str, admin: str = Depends(require_admin)):
    if section not in SECTION_LABELS:
        return RedirectResponse(url="/admin", status_code=303)
    return templates.TemplateResponse(
        request,
        "admin/form.html",
        admin_context(
            request,
            section=section,
            section_label=SECTION_LABELS[section],
            fields=SECTION_FIELDS[section],
            item={},
            is_new=True,
        ),
    )


@router.post("/{section}/nuevo")
async def create_item(request: Request, section: str, admin: str = Depends(require_admin)):
    if section not in SECTION_LABELS:
        return RedirectResponse(url="/admin", status_code=303)
    form = await request.form()
    redirect = _check_csrf_or_redirect(request, str(form.get("csrf_token", "")), f"/admin/{section}")
    if redirect:
        return redirect

    item = {name: str(form.get(name, "")).strip()[:2000] for name, _, _ in SECTION_FIELDS[section]}
    store.save_list_item(section, item)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.get("/{section}/{item_id}/editar")
def edit_item_form(request: Request, section: str, item_id: str, admin: str = Depends(require_admin)):
    if section not in SECTION_LABELS:
        return RedirectResponse(url="/admin", status_code=303)
    item = store.get_list_item(section, item_id)
    if item is None:
        return RedirectResponse(url=f"/admin/{section}", status_code=303)
    return templates.TemplateResponse(
        request,
        "admin/form.html",
        admin_context(
            request,
            section=section,
            section_label=SECTION_LABELS[section],
            fields=SECTION_FIELDS[section],
            item=item,
            is_new=False,
        ),
    )


@router.post("/{section}/{item_id}/editar")
async def update_item(request: Request, section: str, item_id: str, admin: str = Depends(require_admin)):
    if section not in SECTION_LABELS:
        return RedirectResponse(url="/admin", status_code=303)
    form = await request.form()
    redirect = _check_csrf_or_redirect(request, str(form.get("csrf_token", "")), f"/admin/{section}")
    if redirect:
        return redirect

    item = {name: str(form.get(name, "")).strip()[:2000] for name, _, _ in SECTION_FIELDS[section]}
    item["id"] = item_id
    store.save_list_item(section, item)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.post("/{section}/{item_id}/eliminar")
async def delete_item(request: Request, section: str, item_id: str, admin: str = Depends(require_admin)):
    if section not in SECTION_LABELS:
        return RedirectResponse(url="/admin", status_code=303)
    form = await request.form()
    redirect = _check_csrf_or_redirect(request, str(form.get("csrf_token", "")), f"/admin/{section}")
    if redirect:
        return redirect

    store.delete_list_item(section, item_id)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.get("/config/ajustes")
def settings_form(request: Request, admin: str = Depends(require_admin)):
    data = store.load()
    return templates.TemplateResponse(
        request,
        "admin/settings.html",
        admin_context(
            request,
            contact=data.get("contact", {}),
            social=data.get("social", {}),
            stream_audio_url=data.get("stream_audio_url", ""),
            youtube_live_url=data.get("youtube_live_url", ""),
            saved=False,
        ),
    )


@router.post("/config/ajustes")
async def settings_save(request: Request, admin: str = Depends(require_admin)):
    form = await request.form()
    if not verify_csrf(request, str(form.get("csrf_token", ""))):
        return RedirectResponse(url="/admin/config/ajustes", status_code=303)

    contact = {
        "phone": str(form.get("phone", "")).strip()[:40],
        "whatsapp": str(form.get("whatsapp", "")).strip()[:40],
        "email": str(form.get("email", "")).strip()[:120],
        "address": str(form.get("address", "")).strip()[:300],
    }
    social = {
        "facebook": str(form.get("facebook", "")).strip()[:300],
        "instagram": str(form.get("instagram", "")).strip()[:300],
        "youtube": str(form.get("youtube", "")).strip()[:300],
        "tiktok": str(form.get("tiktok", "")).strip()[:300],
        "whatsapp": str(form.get("social_whatsapp", "")).strip()[:300],
    }
    store.update_dict_section("contact", contact)
    store.update_dict_section("social", social)
    store.set_scalar("stream_audio_url", str(form.get("stream_audio_url", "")).strip()[:500])
    store.set_scalar("youtube_live_url", str(form.get("youtube_live_url", "")).strip()[:500])

    data = store.load()
    return templates.TemplateResponse(
        request,
        "admin/settings.html",
        admin_context(
            request,
            contact=data.get("contact", {}),
            social=data.get("social", {}),
            stream_audio_url=data.get("stream_audio_url", ""),
            youtube_live_url=data.get("youtube_live_url", ""),
            saved=True,
        ),
    )


@router.get("/config/contrasena")
def password_form(request: Request, admin: str = Depends(require_admin)):
    return templates.TemplateResponse(
        request, "admin/password.html", admin_context(request, error=None, saved=False)
    )


@router.post("/config/contrasena")
async def password_save(request: Request, admin: str = Depends(require_admin)):
    form = await request.form()
    if not verify_csrf(request, str(form.get("csrf_token", ""))):
        return RedirectResponse(url="/admin/config/contrasena", status_code=303)

    current = str(form.get("current_password", ""))
    new = str(form.get("new_password", ""))
    confirm = str(form.get("confirm_password", ""))

    if not store.verify_admin(admin, current):
        return templates.TemplateResponse(
            request,
            "admin/password.html",
            admin_context(request, error="La contraseña actual no es correcta.", saved=False),
        )
    if len(new) < 8:
        return templates.TemplateResponse(
            request,
            "admin/password.html",
            admin_context(request, error="La nueva contraseña debe tener al menos 8 caracteres.", saved=False),
        )
    if new != confirm:
        return templates.TemplateResponse(
            request,
            "admin/password.html",
            admin_context(request, error="Las contraseñas no coinciden.", saved=False),
        )

    store.set_admin_password(new)
    return templates.TemplateResponse(
        request, "admin/password.html", admin_context(request, error=None, saved=True)
    )
