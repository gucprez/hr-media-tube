from __future__ import annotations

from datetime import UTC, datetime, timedelta
from enum import StrEnum

from pydantic import BaseModel, Field


class Category(StrEnum):
    MISSILE_DRONE = "missile_drone"
    AIRSTRIKE = "airstrike"
    ARMED_CONFLICT = "armed_conflict"
    EARTHQUAKE = "earthquake"
    WILDFIRE = "wildfire"
    STORM = "storm"
    FLOOD = "flood"
    VOLCANO = "volcano"
    CYBER = "cyber"
    EMERGENCY = "emergency"


class Verification(StrEnum):
    OFFICIAL = "official"  # confirmado por fuente oficial
    CORROBORATED = "corroborated"  # varias fuentes independientes
    REPORTED = "reported"  # reportado, pendiente de confirmación
    RETRACTED = "retracted"  # desmentido o corregido


class PublisherKind(StrEnum):
    OFFICIAL = "official"
    MEDIA = "media"


class LocationPrecision(StrEnum):
    EXACT = "exact"  # coordenadas del propio proveedor (p. ej. epicentro)
    APPROXIMATE = "approximate"  # área o centroide, no un punto operativo
    NONE = "none"  # sin coordenadas: no se inventan


class DataClass(StrEnum):
    CURRENT = "current"
    HISTORICAL = "historical"
    SIMULATED = "simulated"  # solo datos de prueba; ningún conector lo produce


CURRENT_WINDOW = timedelta(hours=48)


class SourceRef(BaseModel):
    connector: str  # id del conector que lo obtuvo
    publisher: str  # organismo o medio (dominio para noticias)
    kind: PublisherKind
    url: str
    external_id: str
    title: str | None = None
    retrieved_at: datetime


class Incident(BaseModel):
    id: str
    category: Category
    title: str
    country: str | None = None
    region: str | None = None
    lat: float | None = Field(default=None, ge=-90, le=90)
    lon: float | None = Field(default=None, ge=-180, le=180)
    location_precision: LocationPrecision = LocationPrecision.NONE
    occurred_at: datetime | None = None  # cuándo ocurrió; None si la fuente no lo dice
    published_at: datetime  # cuándo se publicó (no es la hora del hecho)
    updated_at: datetime
    severity: str | None = None
    sources: list[SourceRef]
    verification: Verification = Verification.REPORTED
    confidence: float = Field(default=0.0, ge=0, le=1)
    confidence_rationale: str = ""
    simulated: bool = False

    @property
    def reference_time(self) -> datetime:
        return self.occurred_at or self.published_at

    def data_class(self, now: datetime | None = None) -> DataClass:
        if self.simulated:
            return DataClass.SIMULATED
        now = now or datetime.now(UTC)
        return DataClass.CURRENT if now - self.reference_time <= CURRENT_WINDOW else DataClass.HISTORICAL
