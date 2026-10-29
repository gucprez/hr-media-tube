from __future__ import annotations

from datetime import UTC, datetime

import httpx

from ..models import Category, Incident, LocationPrecision, PublisherKind, SourceRef
from .base import USER_AGENT, Connector, make_id

FEED = "https://eonet.gsfc.nasa.gov/api/v3/events?status=open&days=7"
CATEGORIES = {
    "wildfires": Category.WILDFIRE,
    "severeStorms": Category.STORM,
    "volcanoes": Category.VOLCANO,
    "floods": Category.FLOOD,
    "earthquakes": Category.EARTHQUAKE,
}


class EonetConnector(Connector):
    """NASA EONET v3. Eventos naturales abiertos; datos públicos de NASA con atribución."""

    id = "eonet"
    name = "NASA EONET Eventos naturales"
    license = "Datos abiertos NASA (atribución)"
    docs_url = "https://eonet.gsfc.nasa.gov/docs/v3"
    interval_seconds = 1800

    async def fetch(self, client: httpx.AsyncClient) -> list[Incident]:
        r = await client.get(FEED, headers={"User-Agent": USER_AGENT})
        r.raise_for_status()
        return parse(r.json(), datetime.now(UTC))


def _dt(s: str) -> datetime:
    return datetime.fromisoformat(s.replace("Z", "+00:00"))


def parse(data: dict, now: datetime) -> list[Incident]:
    out: list[Incident] = []
    for e in data.get("events", []):
        cat = next((CATEGORIES[c["id"]] for c in e.get("categories", []) if c["id"] in CATEGORIES), None)
        geoms = [g for g in e.get("geometry", []) if g.get("type") == "Point"]
        if cat is None or not geoms:
            continue
        geoms.sort(key=lambda g: g["date"])
        first, last = geoms[0], geoms[-1]
        refs = [
            SourceRef(
                connector="eonet",
                publisher="NASA EONET",
                kind=PublisherKind.OFFICIAL,
                url=e["link"],
                external_id=e["id"],
                title=e["title"],
                retrieved_at=now,
            )
        ]
        out.append(
            Incident(
                id=make_id("eonet", e["id"]),
                category=cat,
                title=e["title"],
                lat=last["coordinates"][1],
                lon=last["coordinates"][0],
                location_precision=LocationPrecision.APPROXIMATE,
                occurred_at=_dt(first["date"]),
                published_at=_dt(first["date"]),
                updated_at=_dt(last["date"]),
                sources=refs,
            )
        )
    return out
