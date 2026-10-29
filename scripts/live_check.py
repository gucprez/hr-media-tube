"""Ejecuta cada conector contra su fuente real e imprime un resumen. Uso: python scripts/live_check.py"""
import asyncio
import sys
from collections import Counter
from pathlib import Path

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from hrgi.connectors import default_connectors
from hrgi.store import Store


async def main() -> int:
    store, failed = Store(), 0
    async with httpx.AsyncClient(timeout=30, follow_redirects=True) as client:
        for c in default_connectors():
            items = await c.run(client)
            store.ingest(items)
            s = c.status
            print(f"{c.id:6} ok={s.last_error is None} items={len(items)} error={s.last_error}")
            failed += s.last_error is not None
    all_ = store.query(limit=500)
    print("total tras agrupar:", len(all_))
    print("por estado:", dict(Counter(i.verification.value for i in all_)))
    print("por categoría:", dict(Counter(i.category.value for i in all_)))
    for i in all_[:5]:
        print(f"- [{i.verification.value}] {i.title[:70]} | {i.sources[0].publisher} | {i.sources[0].url}")
    return 1 if failed else 0


sys.exit(asyncio.run(main()))
