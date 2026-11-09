"""Autenticación y protección del panel de administración."""

import secrets
import time

from fastapi import HTTPException, Request

SESSION_KEY = "admin_user"
CSRF_KEY = "csrf_token"

MAX_ATTEMPTS = 5
LOCKOUT_SECONDS = 15 * 60

_login_attempts: dict[str, tuple[int, float]] = {}


def current_admin(request: Request) -> str | None:
    return request.session.get(SESSION_KEY)


def require_admin(request: Request) -> str:
    user = current_admin(request)
    if not user:
        raise HTTPException(status_code=303, headers={"Location": "/admin/login"})
    return user


def get_csrf_token(request: Request) -> str:
    token = request.session.get(CSRF_KEY)
    if not token:
        token = secrets.token_urlsafe(24)
        request.session[CSRF_KEY] = token
    return token


def verify_csrf(request: Request, token: str) -> bool:
    expected = request.session.get(CSRF_KEY)
    return bool(expected) and bool(token) and secrets.compare_digest(expected, token)


def client_ip(request: Request) -> str:
    return request.client.host if request.client else "unknown"


def is_locked_out(ip: str) -> int:
    """Devuelve segundos restantes de bloqueo, o 0 si no está bloqueado."""
    count, locked_until = _login_attempts.get(ip, (0, 0.0))
    remaining = locked_until - time.time()
    return int(remaining) if remaining > 0 else 0


def register_failed_attempt(ip: str) -> None:
    count, locked_until = _login_attempts.get(ip, (0, 0.0))
    count += 1
    if count >= MAX_ATTEMPTS:
        locked_until = time.time() + LOCKOUT_SECONDS
        count = 0
    _login_attempts[ip] = (count, locked_until)


def clear_attempts(ip: str) -> None:
    _login_attempts.pop(ip, None)
