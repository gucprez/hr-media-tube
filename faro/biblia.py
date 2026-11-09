"""Lector bíblico: Reina-Valera 1909 (dominio público), guardado en faro/data/biblia."""
import json
import re
import unicodedata
from functools import lru_cache
from pathlib import Path

RUTA = Path(__file__).resolve().parent / "data" / "biblia"


@lru_cache(maxsize=1)
def indice() -> list[dict]:
    return json.loads((RUTA / "indice.json").read_text(encoding="utf-8"))


def libro_info(slug: str) -> dict | None:
    return next((x for x in indice() if x["slug"] == slug), None)


@lru_cache(maxsize=70)
def libro(slug: str) -> dict | None:
    if not libro_info(slug):  # evita rutas arbitrarias
        return None
    return json.loads((RUTA / f"{slug}.json").read_text(encoding="utf-8"))


def normaliza(texto: str) -> str:
    """La edición de 1909 imprime en mayúsculas la primera palabra ("EN el principio")."""
    m = re.match(r"^([A-ZÁÉÍÓÚÑÜ]{2,})(\b.*)$", texto, flags=re.DOTALL)
    return m.group(1).capitalize() + m.group(2) if m else texto


def capitulo(slug: str, n: int) -> list[str] | None:
    b = libro(slug)
    if not b or not 1 <= n <= len(b["capitulos"]):
        return None
    return [normaliza(v) for v in b["capitulos"][n - 1]]


def _plano(s: str) -> str:
    return "".join(c for c in unicodedata.normalize("NFD", s.lower()) if unicodedata.category(c) != "Mn")


@lru_cache(maxsize=1)
def _todo() -> list[tuple[str, str, int, int, str, str]]:
    filas = []
    for info in indice():
        b = libro(info["slug"])
        for ci, cap in enumerate(b["capitulos"], start=1):
            for vi, texto in enumerate(cap, start=1):
                filas.append((info["slug"], info["nombre"], ci, vi, texto, _plano(texto)))
    return filas


def buscar(consulta: str, limite: int = 60) -> list[dict]:
    q = _plano(consulta.strip())
    if len(q) < 3:
        return []
    out = []
    for slug, nombre, c, v, texto, plano in _todo():
        if q in plano:
            out.append({"slug": slug, "libro": nombre, "cap": c, "ver": v, "texto": normaliza(texto)})
            if len(out) >= limite:
                break
    return out
