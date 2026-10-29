from __future__ import annotations

import asyncio
import os
import secrets
import time
from collections import defaultdict, deque
from contextlib import asynccontextmanager
from datetime import UTC, datetime
from typing import Annotated

import httpx
from fastapi import APIRouter, Depends, Header, HTTPException, Query, Request

from .connectors import Connector, ConnectorStatus, default_connectors
from .models import Category, DataClass, Incident, Verification
from .store import Store

router = APIRouter(prefix="/api")


class State:
    def __init__(self) -> None:
        self.store = Store(os.environ.get("HRGI_DB", ":memory:"))
        self.connectors: list[Connector] = default_connectors()
        self.hits: dict[str, deque[float]] = defaultdict(deque)


def get_state(request: Request) -> State:
    return request.app.state.hrgi


StateDep = Annotated[State, Depends(get_state)]


def rate_limit(request: Request, state: StateDep) -> None:
    """60 peticiones/min por IP (en memoria; detrás de proxy usar X-Forwarded-For de confianza)."""
    ip = request.client.host if request.client else "?"
    q, now = state.hits[ip], time.monotonic()
    while q and now - q[0] > 60:
        q.popleft()
    if len(q) >= 60:
        raise HTTPException(429, "Demasiadas peticiones")
    q.append(now)


def require_admin(authorization: str = Header(default="")) -> None:
    token = os.environ.get("ADMIN_TOKEN", "")
    supplied = authorization.removeprefix("Bearer ").strip()
    if not token or not secrets.compare_digest(supplied, token):
        raise HTTPException(401, "No autorizado")


async def ingest_once(state: State, client: httpx.AsyncClient, only: str | None = None) -> dict[str, int]:
    result: dict[str, int] = {}
    for c in state.connectors:
        if only and c.id != only:
            continue
        items = await c.run(client)
        result[c.id] = state.store.ingest(items) if items else 0
    return result


async def _loop(state: State, connector: Connector, client: httpx.AsyncClient) -> None:
    while True:
        state.store.ingest(await connector.run(client))
        await asyncio.sleep(connector.interval_seconds)


@asynccontextmanager
async def lifespan(app):
    app.state.hrgi = state = State()
    tasks: list[asyncio.Task] = []
    client = httpx.AsyncClient(timeout=30, follow_redirects=True)
    if os.environ.get("HRGI_INGEST", "0") == "1":
        tasks = [asyncio.create_task(_loop(state, c, client)) for c in state.connectors]
    yield
    for t in tasks:
        t.cancel()
    await client.aclose()


def _view(i: Incident) -> dict:
    d = i.model_dump(mode="json")
    d["data_class"] = i.data_class(datetime.now(UTC)).value
    return d


@router.get("/health")
def health() -> dict:
    return {"status": "ok"}


@router.get("/incidents", dependencies=[Depends(rate_limit)])
def incidents(
    state: StateDep,
    category: Category | None = None,
    country: str | None = None,
    verification: Verification | None = None,
    data_class: DataClass | None = None,
    since: datetime | None = None,
    q: str | None = Query(default=None, max_length=100),
    limit: int = Query(default=100, ge=1, le=500),
) -> dict:
    items = [_view(i) for i in state.store.query(category, country, verification, since, q, limit)]
    if data_class:
        items = [i for i in items if i["data_class"] == data_class.value]
    return {"count": len(items), "incidents": items}


@router.get("/incidents/{incident_id}", dependencies=[Depends(rate_limit)])
def incident(incident_id: str, state: StateDep) -> dict:
    inc = state.store.get(incident_id)
    if not inc:
        raise HTTPException(404, "No existe")
    return _view(inc)


@router.get("/sources", dependencies=[Depends(rate_limit)])
def sources(state: StateDep) -> list[ConnectorStatus]:
    return [c.status for c in state.connectors]


@router.post("/admin/ingest", dependencies=[Depends(require_admin)])
async def admin_ingest(state: StateDep, connector: str | None = None) -> dict:
    async with httpx.AsyncClient(timeout=30, follow_redirects=True) as client:
        return await ingest_once(state, client, connector)


@router.post("/admin/incidents/{incident_id}/retract", dependencies=[Depends(require_admin)])
def retract(incident_id: str, state: StateDep, retracted: bool = True) -> dict:
    inc = state.store.set_retracted(incident_id, retracted)
    if not inc:
        raise HTTPException(404, "No existe")
    return _view(inc)
