import os
import re
import uuid

from fastapi import APIRouter, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from starlette.datastructures import UploadFile

import db
from sections import SECTIONS

router = APIRouter(prefix="/admin")
templates = Jinja2Templates(directory="templates")

ADMIN_USERNAME = os.environ.get("ADMIN_USERNAME", "Yismellir")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "Flautica08*")

UPLOAD_DIR = os.path.join("static", "uploads")


def _slugify(text):
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9áéíóúñü\s-]", "", text)
    text = (
        text.replace("á", "a")
        .replace("é", "e")
        .replace("í", "i")
        .replace("ó", "o")
        .replace("ú", "u")
        .replace("ñ", "n")
        .replace("ü", "u")
    )
    text = re.sub(r"[\s_-]+", "-", text).strip("-")
    return text or "item"


def is_logged_in(request: Request) -> bool:
    return bool(request.session.get("admin_logged_in"))


def require_login(request: Request):
    if not is_logged_in(request):
        return RedirectResponse(
            url=f"/admin/login?next={request.url.path}", status_code=303
        )
    return None


async def _save_upload(upload: UploadFile) -> str:
    os.makedirs(UPLOAD_DIR, exist_ok=True)
    ext = os.path.splitext(upload.filename or "")[1].lower() or ".jpg"
    if ext not in (".jpg", ".jpeg", ".png", ".webp", ".gif"):
        ext = ".jpg"
    filename = f"{uuid.uuid4().hex}{ext}"
    dest_path = os.path.join(UPLOAD_DIR, filename)
    content = await upload.read()
    with open(dest_path, "wb") as f:
        f.write(content)
    return f"uploads/{filename}"


@router.get("/login", response_class=HTMLResponse)
def login_form(request: Request, next: str = "/admin"):
    if is_logged_in(request):
        return RedirectResponse(url="/admin", status_code=303)
    return templates.TemplateResponse(
        request, "admin/login.html", {"error": None, "next": next}
    )


@router.post("/login", response_class=HTMLResponse)
def login_submit(
    request: Request,
    username: str = Form(...),
    password: str = Form(...),
    next: str = Form("/admin"),
):
    if username == ADMIN_USERNAME and password == ADMIN_PASSWORD:
        request.session["admin_logged_in"] = True
        return RedirectResponse(url=next or "/admin", status_code=303)
    return templates.TemplateResponse(
        request,
        "admin/login.html",
        {"error": "Usuario o contraseña incorrectos.", "next": next},
        status_code=401,
    )


@router.get("/logout")
def logout(request: Request):
    request.session.clear()
    return RedirectResponse(url="/admin/login", status_code=303)


@router.get("", response_class=HTMLResponse)
def dashboard(request: Request):
    redirect = require_login(request)
    if redirect:
        return redirect
    counts = {key: len(db.list_items(key)) for key in SECTIONS}
    return templates.TemplateResponse(
        request,
        "admin/dashboard.html",
        {
            "sections": SECTIONS,
            "counts": counts,
            "all_sections": SECTIONS,
            "active_page": "dashboard",
        },
    )


@router.get("/{section}", response_class=HTMLResponse)
def section_list(request: Request, section: str):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section not in SECTIONS:
        return RedirectResponse(url="/admin", status_code=303)
    items = db.list_items(section)
    return templates.TemplateResponse(
        request,
        "admin/list.html",
        {
            "section_key": section,
            "section": SECTIONS[section],
            "items": items,
            "all_sections": SECTIONS,
            "active_page": section,
        },
    )


@router.get("/{section}/nuevo", response_class=HTMLResponse)
def section_new_form(request: Request, section: str):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section not in SECTIONS:
        return RedirectResponse(url="/admin", status_code=303)
    return templates.TemplateResponse(
        request,
        "admin/form.html",
        {
            "section_key": section,
            "section": SECTIONS[section],
            "item": {},
            "is_new": True,
            "all_sections": SECTIONS,
            "active_page": section,
        },
    )


@router.get("/{section}/{item_id}/editar", response_class=HTMLResponse)
def section_edit_form(request: Request, section: str, item_id: int):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section not in SECTIONS:
        return RedirectResponse(url="/admin", status_code=303)
    item = db.get_item(section, item_id)
    if not item:
        return RedirectResponse(url=f"/admin/{section}", status_code=303)
    return templates.TemplateResponse(
        request,
        "admin/form.html",
        {
            "section_key": section,
            "section": SECTIONS[section],
            "item": item,
            "is_new": False,
            "all_sections": SECTIONS,
            "active_page": section,
        },
    )


async def _read_form_data(request: Request, section_key: str, existing: dict):
    form = await request.form()
    config = SECTIONS[section_key]
    data = dict(existing)
    for field in config["fields"]:
        name = field["name"]
        if field["type"] == "image":
            upload = form.get(name)
            if isinstance(upload, UploadFile) and upload.filename:
                data[name] = await _save_upload(upload)
            else:
                data.setdefault(name, "")
        else:
            data[name] = str(form.get(name, "") or "")

    if section_key == "noticias":
        title = data.get("title", "").strip()
        if title and (not existing.get("slug") or existing.get("title") != title):
            base_slug = _slugify(title)
            data["slug"] = base_slug
        elif existing.get("slug"):
            data["slug"] = existing["slug"]
    return data


@router.post("/{section}/nuevo")
async def section_create(request: Request, section: str):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section not in SECTIONS:
        return RedirectResponse(url="/admin", status_code=303)
    data = await _read_form_data(request, section, {})
    new_id = db.create_item(section, data)
    if section == "noticias":
        slug = data.get("slug") or ""
        others = [i for i in db.list_items(section) if i["id"] != new_id]
        if any(i.get("slug") == slug for i in others):
            data["slug"] = f"{slug}-{new_id}"
            db.update_item(section, new_id, data)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.post("/{section}/{item_id}/editar")
async def section_update(request: Request, section: str, item_id: int):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section not in SECTIONS:
        return RedirectResponse(url="/admin", status_code=303)
    existing = db.get_item(section, item_id)
    if not existing:
        return RedirectResponse(url=f"/admin/{section}", status_code=303)
    data = await _read_form_data(request, section, existing)
    db.update_item(section, item_id, data)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.post("/{section}/{item_id}/eliminar")
def section_delete(request: Request, section: str, item_id: int):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section in SECTIONS:
        db.delete_item(section, item_id)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.post("/{section}/{item_id}/toggle")
def section_toggle(request: Request, section: str, item_id: int):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section in SECTIONS:
        db.toggle_active(section, item_id)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)


@router.post("/{section}/{item_id}/mover/{direction}")
def section_move(request: Request, section: str, item_id: int, direction: str):
    redirect = require_login(request)
    if redirect:
        return redirect
    if section in SECTIONS and direction in ("up", "down"):
        db.move_item(section, item_id, direction)
    return RedirectResponse(url=f"/admin/{section}", status_code=303)
