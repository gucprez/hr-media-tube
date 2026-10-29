from __future__ import annotations

from datetime import UTC, datetime
from email.utils import parsedate_to_datetime

import httpx
from defusedxml import ElementTree as ET

from ..models import Category, Incident, LocationPrecision, PublisherKind, SourceRef
from .base import USER_AGENT, Connector, make_id

FEED = "https://www.gdacs.org/xml/rss.xml"
NS = {
    "gdacs": "http://www.gdacs.org",
    "geo": "http://www.w3.org/2003/01/geo/wgs84_pos#",
}
TYPES = {
    "EQ": Category.EARTHQUAKE,
    "TC": Category.STORM,
    "FL": Category.FLOOD,
    "VO": Category.VOLCANO,
    "WF": Category.WILDFIRE,
    "DR": Category.EMERGENCY,
}


class GdacsConnector(Connector):
    """GDACS (ONU/Comisión Europea). Alertas globales de desastres, uso con atribución."""

    id = "gdacs"
    name = "GDACS Alertas de desastres"
    license = "Libre uso con atribución a GDACS (UN/EC-JRC)"
    docs_url = "https://www.gdacs.org/feed_reference.aspx"
    interval_seconds = 900

    async def fetch(self, client: httpx.AsyncClient) -> list[Incident]:
        r = await client.get(FEED, headers={"User-Agent": USER_AGENT})
        r.raise_for_status()
        return parse(r.content, datetime.now(UTC))


def _t(item, tag: str) -> str | None:
    el = item.find(tag, NS)
    return el.text.strip() if el is not None and el.text else None


def _date(value: str | None) -> datetime | None:
    if not value:
        return None
    d = parsedate_to_datetime(value)
    return d if d.tzinfo else d.replace(tzinfo=UTC)


def parse(xml: bytes, now: datetime) -> list[Incident]:
    root = ET.fromstring(xml)
    out: list[Incident] = []
    for item in root.iter("item"):
        etype, eid = _t(item, "gdacs:eventtype"), _t(item, "gdacs:eventid")
        category = TYPES.get(etype or "")
        published = _date(_t(item, "pubDate"))
        if category is None or not eid or published is None:
            continue
        lat, lon = _t(item, "geo:Point/geo:lat"), _t(item, "geo:Point/geo:long")
        sev = item.find("gdacs:severity", NS)
        level = _t(item, "gdacs:alertlevel")
        url = (_t(item, "link") or "").replace("http://", "https://", 1)
        out.append(
            Incident(
                id=make_id("gdacs", f"{etype}{eid}"),
                category=category,
                title=_t(item, "title") or f"{etype} {eid}",
                country=_t(item, "gdacs:country"),
                lat=float(lat) if lat else None,
                lon=float(lon) if lon else None,
                location_precision=LocationPrecision.APPROXIMATE if lat else LocationPrecision.NONE,
                occurred_at=_date(_t(item, "gdacs:fromdate")),
                published_at=published,
                updated_at=_date(_t(item, "gdacs:datemodified")) or published,
                severity=" · ".join(x for x in (level, sev.text if sev is not None else None) if x) or None,
                sources=[
                    SourceRef(
                        connector="gdacs",
                        publisher="GDACS",
                        kind=PublisherKind.OFFICIAL,
                        url=url,
                        external_id=f"{etype}{eid}",
                        title=_t(item, "title"),
                        retrieved_at=now,
                    )
                ],
            )
        )
    return out
