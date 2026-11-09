"""Almacén de contenido editable desde el panel de administración.

Se guarda en un único archivo JSON (data/store/content.json, fuera de
git). La primera vez que se ejecuta la app, se siembra con el
contenido real que ya existía como código (programación, actividades,
etc.) para no perder nada.
"""

import json
import secrets
import threading
from pathlib import Path

import bcrypt

from data.schedule import SCHEDULE as DEFAULT_SCHEDULE
from data.news import NEWS as DEFAULT_NEWS
from data.activities import ACTIVITIES as DEFAULT_ACTIVITIES
from data.messages import MESSAGES as DEFAULT_MESSAGES
from data.hosts import HOSTS as DEFAULT_HOSTS

BASE_DIR = Path(__file__).resolve().parent.parent
STORE_DIR = BASE_DIR / "data" / "store"
STORE_FILE = STORE_DIR / "content.json"
SECRET_FILE = STORE_DIR / ".session_secret"

STORE_DIR.mkdir(parents=True, exist_ok=True)

_lock = threading.Lock()

LIST_SECTIONS = ("schedule", "activities", "news", "messages", "hosts")

DEFAULT_ADMIN_USERNAME = "admin"


def _hash_password(password: str) -> str:
    # bcrypt solo usa los primeros 72 bytes; limitamos para evitar errores
    # con contraseñas muy largas.
    return bcrypt.hashpw(password.encode("utf-8")[:72], bcrypt.gensalt()).decode("utf-8")


def _with_ids(items: list[dict]) -> list[dict]:
    out = []
    for item in items:
        item = dict(item)
        item.setdefault("id", secrets.token_hex(4))
        out.append(item)
    return out


def _default_content() -> dict:
    initial_password = secrets.token_urlsafe(9)
    return {
        "schedule": _with_ids(DEFAULT_SCHEDULE),
        "activities": _with_ids(DEFAULT_ACTIVITIES),
        "news": _with_ids(DEFAULT_NEWS),
        "messages": _with_ids(DEFAULT_MESSAGES),
        "hosts": _with_ids(DEFAULT_HOSTS),
        "contact": {
            "phone": "",
            "whatsapp": "",
            "email": "farodeluzradio.com@gmail.com",
            "address": "C/ Principal Padre Granero, No. 10, Puerto Plata, República Dominicana",
        },
        "social": {
            "facebook": "",
            "instagram": "",
            "youtube": "",
            "tiktok": "",
            "whatsapp": "",
        },
        "stream_audio_url": "",
        "youtube_live_url": "",
        "admin": {
            "username": DEFAULT_ADMIN_USERNAME,
            "password_hash": _hash_password(initial_password),
        },
        # Se expone una sola vez para que el primer mensaje al dueño del
        # sitio pueda mostrar la contraseña inicial generada.
        "_initial_password_shown": False,
        "_initial_password_plain": initial_password,
    }


def _read_raw() -> dict:
    if not STORE_FILE.exists():
        data = _default_content()
        _write_raw(data)
        return data
    try:
        return json.loads(STORE_FILE.read_text())
    except json.JSONDecodeError:
        data = _default_content()
        _write_raw(data)
        return data


def _write_raw(data: dict) -> None:
    STORE_FILE.write_text(json.dumps(data, ensure_ascii=False, indent=2))


def load() -> dict:
    with _lock:
        return _read_raw()


def save(data: dict) -> None:
    with _lock:
        _write_raw(data)


def pop_initial_password() -> str | None:
    """Devuelve la contraseña inicial en texto plano una sola vez."""
    with _lock:
        data = _read_raw()
        if data.get("_initial_password_shown"):
            return None
        plain = data.get("_initial_password_plain")
        data["_initial_password_shown"] = True
        data.pop("_initial_password_plain", None)
        _write_raw(data)
        return plain


def get_section(name: str):
    return load().get(name)


def save_list_item(section: str, item: dict) -> None:
    assert section in LIST_SECTIONS
    data = load()
    items = data.get(section, [])
    item = dict(item)
    if item.get("id"):
        items = [item if i["id"] == item["id"] else i for i in items]
    else:
        item["id"] = secrets.token_hex(4)
        items = items + [item]
    data[section] = items
    save(data)


def delete_list_item(section: str, item_id: str) -> None:
    assert section in LIST_SECTIONS
    data = load()
    data[section] = [i for i in data.get(section, []) if i.get("id") != item_id]
    save(data)


def get_list_item(section: str, item_id: str) -> dict | None:
    for item in get_section(section) or []:
        if item.get("id") == item_id:
            return item
    return None


def update_dict_section(name: str, values: dict) -> None:
    data = load()
    section = dict(data.get(name, {}))
    section.update(values)
    data[name] = section
    save(data)


def set_scalar(name: str, value) -> None:
    data = load()
    data[name] = value
    save(data)


def get_session_secret() -> str:
    if SECRET_FILE.exists():
        return SECRET_FILE.read_text().strip()
    secret = secrets.token_hex(32)
    SECRET_FILE.write_text(secret)
    return secret


def verify_admin(username: str, password: str) -> bool:
    admin = load().get("admin", {})
    if username != admin.get("username"):
        return False
    stored = admin.get("password_hash", "")
    if not stored:
        return False
    try:
        return bcrypt.checkpw(password.encode("utf-8")[:72], stored.encode("utf-8"))
    except ValueError:
        return False


def set_admin_password(new_password: str) -> None:
    data = load()
    admin = dict(data.get("admin", {}))
    admin["password_hash"] = _hash_password(new_password)
    data["admin"] = admin
    save(data)
