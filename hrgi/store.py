from __future__ import annotations

import sqlite3
import threading
from datetime import UTC, datetime, timedelta

from .dedupe import merge, same_event
from .models import Category, Incident, Verification
from .verification import apply

RETENTION = timedelta(days=30)


class Store:
    """Persistencia SQLite (v0). El esquema es JSON + columnas de filtro; migrable a PostGIS."""

    def __init__(self, path: str = ":memory:") -> None:
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._lock = threading.Lock()
        self._db.execute(
            "CREATE TABLE IF NOT EXISTS incidents (id TEXT PRIMARY KEY, category TEXT, country TEXT,"
            " ref_time TEXT, verification TEXT, retracted INTEGER DEFAULT 0, json TEXT)"
        )
        self._db.execute("CREATE INDEX IF NOT EXISTS ix_ref ON incidents(ref_time)")
        self._db.commit()

    def _all(self) -> list[tuple[Incident, bool]]:
        rows = self._db.execute("SELECT json, retracted FROM incidents").fetchall()
        return [(Incident.model_validate_json(j), bool(r)) for j, r in rows]

    def _save(self, inc: Incident, retracted: bool) -> None:
        self._db.execute(
            "INSERT OR REPLACE INTO incidents VALUES (?,?,?,?,?,?,?)",
            (inc.id, inc.category.value, inc.country, inc.reference_time.isoformat(),
             inc.verification.value, int(retracted), inc.model_dump_json()),
        )

    def ingest(self, incoming: list[Incident]) -> int:
        """Agrupa por evento, fusiona fuentes y recalcula verificación. Devuelve nº de grupos tocados."""
        touched: set[str] = set()
        with self._lock:
            current = {i.id: (i, r) for i, r in self._all()}
            for new in incoming:
                match = next((i for i, _ in current.values() if same_event(i, new)), None)
                if match is None:
                    apply(new)
                    current[new.id] = (new, False)
                    touched.add(new.id)
                    continue
                before = match.model_dump_json()
                merge(match, new)
                apply(match, current[match.id][1])
                if match.model_dump_json() != before:
                    touched.add(match.id)
            for iid in touched:
                self._save(*current[iid])
            cutoff = (datetime.now(UTC) - RETENTION).isoformat()
            self._db.execute("DELETE FROM incidents WHERE ref_time < ?", (cutoff,))
            self._db.commit()
        return len(touched)

    def get(self, incident_id: str) -> Incident | None:
        with self._lock:
            row = self._db.execute("SELECT json FROM incidents WHERE id=?", (incident_id,)).fetchone()
        return Incident.model_validate_json(row[0]) if row else None

    def set_retracted(self, incident_id: str, retracted: bool) -> Incident | None:
        with self._lock:
            row = self._db.execute("SELECT json FROM incidents WHERE id=?", (incident_id,)).fetchone()
            if not row:
                return None
            inc = apply(Incident.model_validate_json(row[0]), retracted)
            inc.updated_at = datetime.now(UTC)
            self._save(inc, retracted)
            self._db.commit()
        return inc

    def query(
        self,
        category: Category | None = None,
        country: str | None = None,
        verification: Verification | None = None,
        since: datetime | None = None,
        q: str | None = None,
        limit: int = 100,
    ) -> list[Incident]:
        with self._lock:
            items = [i for i, _ in self._all()]
        out = []
        for i in items:
            if category and i.category != category:
                continue
            if country and (i.country or "").lower() != country.lower():
                continue
            if verification and i.verification != verification:
                continue
            if since and i.reference_time < since:
                continue
            if q and q.lower() not in f"{i.title} {i.region or ''} {i.country or ''}".lower():
                continue
            out.append(i)
        out.sort(key=lambda i: i.reference_time, reverse=True)
        return out[:limit]
