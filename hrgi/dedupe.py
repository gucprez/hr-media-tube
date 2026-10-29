from __future__ import annotations

import math
import re
from datetime import timedelta

from .models import Incident

TIME_WINDOW = timedelta(hours=6)
RADIUS_KM = 50.0
TITLE_SIMILARITY = 0.6
_STOP = {"the", "a", "an", "in", "of", "on", "to", "and", "for", "at", "by", "after", "as", "el", "la", "en", "de", "y"}


def haversine_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp, dl = p2 - p1, math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 6371.0 * 2 * math.asin(math.sqrt(a))


def _tokens(title: str) -> set[str]:
    return {t for t in re.findall(r"\w+", title.lower()) if t not in _STOP and len(t) > 2}


def title_similarity(a: str, b: str) -> float:
    ta, tb = _tokens(a), _tokens(b)
    return len(ta & tb) / len(ta | tb) if ta and tb else 0.0


def same_event(a: Incident, b: Incident) -> bool:
    if a.id == b.id:
        return True
    if a.category != b.category:
        return False
    if abs(a.reference_time - b.reference_time) > TIME_WINDOW:
        return False
    if a.lat is not None and b.lat is not None and a.lon is not None and b.lon is not None:
        return haversine_km(a.lat, a.lon, b.lat, b.lon) <= RADIUS_KM
    # Sin coordenadas en alguno: solo se agrupa por título casi idéntico.
    return title_similarity(a.title, b.title) >= TITLE_SIMILARITY


def merge(base: Incident, other: Incident) -> Incident:
    """Fusiona `other` en `base` conservando todas las fuentes sin duplicarlas."""
    seen = {(s.connector, s.external_id) for s in base.sources}
    for s in other.sources:
        if (s.connector, s.external_id) not in seen:
            base.sources.append(s)
    # Prefiere datos oficiales/más precisos y completa campos vacíos; nunca inventa.
    if base.lat is None and other.lat is not None:
        base.lat, base.lon, base.location_precision = other.lat, other.lon, other.location_precision
    base.country = base.country or other.country
    base.region = base.region or other.region
    if other.occurred_at and (base.occurred_at is None or other.occurred_at < base.occurred_at):
        base.occurred_at = other.occurred_at
    base.published_at = min(base.published_at, other.published_at)
    base.updated_at = max(base.updated_at, other.updated_at)
    base.severity = base.severity or other.severity
    return base
