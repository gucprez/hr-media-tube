"""Sesión de administrador: una contraseña (FARO_ADMIN_PASSWORD) y cookie firmada.

Nada de contraseñas en el código ni en el repositorio: todo viene de variables de entorno.
"""
import hashlib
import hmac
import os
import secrets
import time

from fastapi import HTTPException, Request

COOKIE = "faro_sesion"
DURACION = 60 * 60 * 8
_SECRETO_PROCESO = secrets.token_bytes(32)
_intentos: dict[str, list[float]] = {}


def _secreto() -> bytes:
    s = os.environ.get("FARO_SECRET")
    return s.encode() if s else _SECRETO_PROCESO


def admin_configurado() -> bool:
    return bool(os.environ.get("FARO_ADMIN_PASSWORD"))


def clave_correcta(intento: str) -> bool:
    esperada = os.environ.get("FARO_ADMIN_PASSWORD", "")
    if not esperada:
        return False
    a = hashlib.sha256(intento.encode()).digest()
    b = hashlib.sha256(esperada.encode()).digest()
    return hmac.compare_digest(a, b)


def _firma(texto: str) -> str:
    return hmac.new(_secreto(), texto.encode(), hashlib.sha256).hexdigest()


def crear_sesion() -> str:
    cuerpo = f"{int(time.time()) + DURACION}.{secrets.token_hex(8)}"
    return f"{cuerpo}.{_firma(cuerpo)}"


def _valida(valor: str | None) -> str | None:
    """Devuelve el identificador de sesión si la cookie es válida."""
    if not valor:
        return None
    try:
        exp, nonce, firma = valor.split(".")
        if not hmac.compare_digest(_firma(f"{exp}.{nonce}"), firma) or int(exp) < time.time():
            return None
    except ValueError:
        return None
    return nonce


def token_csrf(nonce: str) -> str:
    return _firma("csrf:" + nonce)


def limitar_login(ip: str) -> bool:
    """True si aún puede intentar (5 intentos fallidos cada 10 minutos)."""
    ahora = time.time()
    recientes = [t for t in _intentos.get(ip, []) if ahora - t < 600]
    _intentos[ip] = recientes
    return len(recientes) < 5


def registrar_fallo(ip: str) -> None:
    _intentos.setdefault(ip, []).append(time.time())


def sesion_actual(request: Request) -> str | None:
    return _valida(request.cookies.get(COOKIE))


def requiere_admin(request: Request) -> str:
    nonce = sesion_actual(request)
    if not nonce:
        raise HTTPException(status_code=303, headers={"Location": "/admin/login"})
    return nonce


async def verificar_csrf(request: Request, nonce: str) -> None:
    form = await request.form()
    if not hmac.compare_digest(str(form.get("csrf", "")), token_csrf(nonce)):
        raise HTTPException(status_code=403, detail="Sesión no válida. Recarga la página.")


def cookies_seguras() -> bool:
    return os.environ.get("FARO_INSECURE_COOKIES") != "1"
