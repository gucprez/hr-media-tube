from __future__ import annotations

import hashlib
from abc import ABC, abstractmethod
from datetime import UTC, datetime

import httpx
from pydantic import BaseModel

from ..models import Incident

USER_AGENT = "HRGlobalIntelligence/0.1 (+https://github.com/gucprez/hr-media-tube)"


class ConnectorStatus(BaseModel):
    id: str
    name: str
    license: str
    docs_url: str
    interval_seconds: int
    enabled: bool = True
    last_run: datetime | None = None
    last_success: datetime | None = None
    last_error: str | None = None
    last_count: int = 0
    warnings: list[str] = []  # fallos parciales (p. ej. una consulta de varias)


class Connector(ABC):
    """Fuente de datos. Cada conector documenta licencia y límites de uso."""

    id: str
    name: str
    license: str
    docs_url: str
    interval_seconds: int = 900

    def __init__(self) -> None:
        self.status = ConnectorStatus(
            id=self.id,
            name=self.name,
            license=self.license,
            docs_url=self.docs_url,
            interval_seconds=self.interval_seconds,
        )

    @abstractmethod
    async def fetch(self, client: httpx.AsyncClient) -> list[Incident]: ...

    async def run(self, client: httpx.AsyncClient) -> list[Incident]:
        self.status.last_run = datetime.now(UTC)
        self.status.warnings = []
        try:
            items = await self.fetch(client)
        except Exception as exc:  # noqa: BLE001 - el error se registra y se muestra en el panel admin
            self.status.last_error = f"{type(exc).__name__}: {exc}"[:500]
            self.status.last_count = 0
            return []
        self.status.last_success = self.status.last_run
        self.status.last_error = None
        self.status.last_count = len(items)
        return items


def make_id(connector: str, external_id: str) -> str:
    return "hrgi-" + hashlib.sha1(f"{connector}:{external_id}".encode()).hexdigest()[:12]
