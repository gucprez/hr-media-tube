from __future__ import annotations

from datetime import UTC, datetime

import httpx

from ..models import Category, Incident, LocationPrecision, PublisherKind, SourceRef
from .base import USER_AGENT, Connector, make_id

FEED = "https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/4.5_day.geojson"


class UsgsConnector(Connector):
    """USGS Earthquake Hazards Program. Dominio público (US Gov); sin clave.

    El feed no da hora de publicación: `updated` es la última modificación
    publicada y se usa como published_at; `time` es la hora real del sismo.
    """

    id = "usgs"
    name = "USGS Earthquakes (M4.5+, 24 h)"
    license = "Dominio público (USGS)"
    docs_url = "https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php"
    interval_seconds = 300

    async def fetch(self, client: httpx.AsyncClient) -> list[Incident]:
        r = await client.get(FEED, headers={"User-Agent": USER_AGENT})
        r.raise_for_status()
        return parse(r.json(), datetime.now(UTC))


def parse(data: dict, now: datetime) -> list[Incident]:
    out: list[Incident] = []
    for f in data.get("features", []):
        p, coords = f["properties"], f["geometry"]["coordinates"]
        if p.get("type") != "earthquake" or p.get("time") is None:
            continue
        occurred = datetime.fromtimestamp(p["time"] / 1000, UTC)
        updated = datetime.fromtimestamp((p.get("updated") or p["time"]) / 1000, UTC)
        out.append(
            Incident(
                id=make_id("usgs", f["id"]),
                category=Category.EARTHQUAKE,
                title=p.get("title") or f"M {p.get('mag')} earthquake",
                region=p.get("place"),
                lat=coords[1],
                lon=coords[0],
                location_precision=LocationPrecision.EXACT,
                occurred_at=occurred,
                published_at=updated,
                updated_at=updated,
                severity=f"M{p['mag']}" if p.get("mag") is not None else None,
                sources=[
                    SourceRef(
                        connector="usgs",
                        publisher="USGS",
                        kind=PublisherKind.OFFICIAL,
                        url=p["url"],
                        external_id=f["id"],
                        title=p.get("title"),
                        retrieved_at=now,
                    )
                ],
            )
        )
    return out
