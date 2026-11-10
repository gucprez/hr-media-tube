"""Latencia vidrio a vidrio a partir de fotos del cronómetro.

En cada foto se leen dos tiempos: el del cronómetro real y el que muestra el monitor del estudio.
Entrada: CSV con columnas `real` y `monitor` (segundos con decimales, p. ej. 12.84 y 12.61).

Uso:  python -m tools.prueba_camara.latencia lecturas.csv
"""
from __future__ import annotations

import csv
import statistics
import sys

from .analisis import _pct

OBJETIVO_MEDIANA_MS = 300   # objetivo provisional; la p95 se registra sin umbral


def latencias_ms(pares: list[tuple[float, float]]) -> list[float]:
    """Latencia = lectura real − lectura en el monitor. Las negativas son errores de lectura: se rechazan."""
    out = []
    for real, monitor in pares:
        d = (real - monitor) * 1000.0
        if d < 0:
            raise ValueError(f"Lectura imposible: el monitor ({monitor}) va por delante del cronómetro ({real})")
        out.append(d)
    return out


def resumen(pares: list[tuple[float, float]]) -> dict[str, float]:
    ms = latencias_ms(pares)
    if not ms:
        raise ValueError("No hay lecturas")
    return {"muestras": len(ms), "mediana_ms": statistics.median(ms), "p95_ms": _pct(ms, 95), "max_ms": max(ms), "min_ms": min(ms)}


def main(argv: list[str] | None = None) -> int:
    ruta = (argv or sys.argv[1:])[0]
    with open(ruta, newline="", encoding="utf-8") as fh:
        pares = [(float(f["real"]), float(f["monitor"])) for f in csv.DictReader(fh)]
    r = resumen(pares)
    print(f"Muestras: {r['muestras']} · Mediana: {r['mediana_ms']:.0f} ms · p95: {r['p95_ms']:.0f} ms · "
          f"mín {r['min_ms']:.0f} ms · máx {r['max_ms']:.0f} ms")
    ok = r["mediana_ms"] <= OBJETIVO_MEDIANA_MS
    print(f"Objetivo provisional (mediana ≤ {OBJETIVO_MEDIANA_MS} ms): {'cumple' if ok else 'no cumple'} · "
          f"p95 registrada: {r['p95_ms']:.0f} ms (sin objetivo)")
    if r["muestras"] < 10:
        print("Aviso: se piden al menos 10 lecturas; con menos, el p95 no es fiable.")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
