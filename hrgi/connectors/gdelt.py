from __future__ import annotations

import asyncio
from datetime import UTC, datetime
from urllib.parse import urlparse

import httpx

from ..models import Category, Incident, LocationPrecision, PublisherKind, SourceRef
from .base import USER_AGENT, Connector, make_id

API = "https://api.gdeltproject.org/api/v2/doc/doc"
QUERIES: list[tuple[Category, str]] = [
    (Category.MISSILE_DRONE, '("missile attack" OR "drone attack" OR "air raid alert") sourcelang:english'),
    (Category.AIRSTRIKE, '(airstrike OR "air strike" OR bombardment) sourcelang:english'),
    (Category.CYBER, '(cyberattack OR ransomware OR "power outage cyber") sourcelang:english'),
]
GDELT_MIN_INTERVAL = 5.5  # GDELT pide <= 1 petición cada 5 s


class GdeltConnector(Connector):
    """GDELT DOC 2.0: artículos de prensa, NO hechos verificados.

    Cada resultado es un artículo: se registra como REPORTADO, sin coordenadas y
    sin hora del hecho (solo `seendate`, que es la hora de publicación). El país
    de GDELT es el del medio, no el del incidente, por eso no se usa.
    """

    id = "gdelt"
    name = "GDELT DOC 2.0 (noticias)"
    license = "Abierto con atribución a GDELT; enlaza a la fuente original"
    docs_url = "https://blog.gdeltproject.org/gdelt-doc-2-0-api-debuts/"
    interval_seconds = 1800

    async def fetch(self, client: httpx.AsyncClient) -> list[Incident]:
        out: list[Incident] = []
        errors: list[str] = []
        now = datetime.now(UTC)
        for i, (cat, query) in enumerate(QUERIES):
            if i:
                await asyncio.sleep(GDELT_MIN_INTERVAL)
            try:
                out += parse(await self._get(client, query), cat, now)
            except (httpx.HTTPError, ValueError) as exc:
                errors.append(f"{cat.value}: {type(exc).__name__}")
        if errors and not out:
            raise RuntimeError("todas las consultas fallaron: " + "; ".join(errors))
        if errors:
            self.status.warnings = errors
        return out

    async def _get(self, client: httpx.AsyncClient, query: str) -> dict:
        params = {"query": query, "mode": "artlist", "maxrecords": 50, "timespan": "24h", "format": "json"}
        for attempt in range(2):
            r = await client.get(API, params=params, headers={"User-Agent": USER_AGENT})
            if r.status_code == 429 and attempt == 0:
                await asyncio.sleep(15)
                continue
            r.raise_for_status()
            return r.json()
        raise httpx.HTTPError("429 persistente")


def parse(data: dict, cat: Category, now: datetime) -> list[Incident]:
    out: list[Incident] = []
    for a in data.get("articles", []):
        url, title = a.get("url"), (a.get("title") or "").strip()
        if not url or not title or not a.get("seendate"):
            continue
        seen = datetime.strptime(a["seendate"], "%Y%m%dT%H%M%SZ").replace(tzinfo=UTC)
        domain = a.get("domain") or urlparse(url).netloc
        out.append(
            Incident(
                id=make_id("gdelt", url),
                category=cat,
                title=title,
                location_precision=LocationPrecision.NONE,
                occurred_at=None,
                published_at=seen,
                updated_at=seen,
                sources=[
                    SourceRef(
                        connector="gdelt",
                        publisher=domain,
                        kind=PublisherKind.MEDIA,
                        url=url,
                        external_id=url,
                        title=title,
                        retrieved_at=now,
                    )
                ],
            )
        )
    return out
