"""Analiza el registro CSV del estudio (botón «Registro CSV») de la prueba Cámara remota → Laptop.

Calcula estabilidad, calidad y consumo, y compara con los criterios propuestos en
docs/prueba-tecnica-camara-remota.md. No mide la latencia vidrio a vidrio: eso se hace
con fotos del cronómetro (ver `latencia.py`).

Uso:  python -m tools.prueba_camara.analisis registro.csv [--minutos 30]
"""
from __future__ import annotations

import argparse
import csv
import statistics
import sys
from dataclasses import dataclass, field
from datetime import datetime
from itertools import pairwise
from pathlib import Path

# Criterios PROPUESTOS (el responsable debe confirmarlos antes de dar un veredicto).
CRITERIOS = {
    "minutos": 30.0,          # duración mínima de la corrida
    "fps_mediana": 27.0,      # fps recibidos, mediana
    "pct_720p": 95.0,         # % de muestras a 1280x720
    "congelamientos": 3,      # máximo en la corrida
    "cortes": 0,              # huecos del registro > HUECO_S
    "termico_max": 2,         # estado térmico máximo aceptado (3 = SEVERE)
}
HUECO_S = 6.0  # el estudio registra cada ~2 s; un hueco mayor indica un corte de la cámara


@dataclass
class Resumen:
    muestras: int = 0
    minutos: float = 0.0
    fps_mediana: float | None = None
    fps_p5: float | None = None
    pct_720p: float | None = None
    kbps_medio: float | None = None
    congelamientos: int | None = None
    paquetes_perdidos: int | None = None
    rtt_mediana_ms: float | None = None
    rtt_p95_ms: float | None = None
    jitterbuffer_mediana_ms: float | None = None
    cortes: int = 0
    mayor_hueco_s: float = 0.0
    codificador: str = ""
    hardware: str = "sin dato"
    pct_limitado: float | None = None
    bateria_inicio: float | None = None
    bateria_fin: float | None = None
    bateria_pct_por_hora: float | None = None
    temp_inicio_c: float | None = None
    temp_fin_c: float | None = None
    temp_max_c: float | None = None
    termico_max: int | None = None
    veredictos: dict[str, str] = field(default_factory=dict)


def _num(v: str | None) -> float | None:
    if v is None:
        return None
    v = v.strip()
    if v in ("", "null", "undefined", "NaN"):
        return None
    try:
        return float(v)
    except ValueError:
        return None


def _pct(valores: list[float], p: float) -> float | None:
    if not valores:
        return None
    orden = sorted(valores)
    k = (len(orden) - 1) * p / 100.0
    lo = int(k)
    hi = min(lo + 1, len(orden) - 1)
    return orden[lo] + (orden[hi] - orden[lo]) * (k - lo)


def _col(filas: list[dict], nombre: str) -> list[float]:
    return [x for x in (_num(f.get(nombre)) for f in filas) if x is not None]


def _delta_contador(filas: list[dict], nombre: str) -> int | None:
    """Los contadores de WebRTC son acumulados y se reinician al reconectar: se suman los incrementos."""
    vals = _col(filas, nombre)
    if not vals:
        return None
    total = 0.0
    for a, b in pairwise(vals):
        if b >= a:
            total += b - a
    return int(total)


def leer(ruta: str | Path, dispositivo: str | None = None) -> list[dict]:
    with open(ruta, newline="", encoding="utf-8") as fh:
        filas = list(csv.DictReader(fh))
    if dispositivo:
        filas = [f for f in filas if f.get("dispositivo") == dispositivo]
    return filas


def resumir(filas: list[dict]) -> Resumen:
    r = Resumen(muestras=len(filas))
    if not filas:
        return r

    tiempos = []
    for f in filas:
        try:
            tiempos.append(datetime.fromisoformat(f["hora_iso"].replace("Z", "+00:00")))
        except (KeyError, ValueError):
            continue
    if len(tiempos) >= 2:
        r.minutos = (tiempos[-1] - tiempos[0]).total_seconds() / 60.0
        huecos = [(b - a).total_seconds() for a, b in pairwise(tiempos)]
        r.cortes = sum(1 for h in huecos if h > HUECO_S)
        r.mayor_hueco_s = max(huecos)

    fps = _col(filas, "rx_fps")
    if fps:
        r.fps_mediana = statistics.median(fps)
        r.fps_p5 = _pct(fps, 5)
    con_res = [f for f in filas if _num(f.get("rx_ancho")) is not None]
    if con_res:
        ok = sum(1 for f in con_res if _num(f["rx_ancho"]) == 1280 and _num(f.get("rx_alto")) == 720)
        r.pct_720p = 100.0 * ok / len(con_res)
    kbps = _col(filas, "rx_kbps")
    r.kbps_medio = statistics.fmean(kbps) if kbps else None
    r.congelamientos = _delta_contador(filas, "rx_congelamientos")
    r.paquetes_perdidos = _delta_contador(filas, "rx_perdidos")
    rtt = _col(filas, "rtt_ms")
    if rtt:
        r.rtt_mediana_ms = statistics.median(rtt)
        r.rtt_p95_ms = _pct(rtt, 95)
    jb = _col(filas, "rx_jitterbuffer_ms")
    r.jitterbuffer_mediana_ms = statistics.median(jb) if jb else None

    cod = [f["tx_encoder"] for f in filas if f.get("tx_encoder")]
    if cod:
        r.codificador = max(set(cod), key=cod.count)
    hw = [f["tx_hw"].strip().lower() for f in filas if f.get("tx_hw", "").strip() != ""]
    if hw:
        r.hardware = {"true": "hardware", "false": "software"}.get(max(set(hw), key=hw.count), "sin dato")
    lim = [f.get("tx_limitacion", "").strip() for f in filas if f.get("tx_fps", "").strip() != ""]
    if lim:
        r.pct_limitado = 100.0 * sum(1 for x in lim if x not in ("", "none")) / len(lim)

    bat = _col(filas, "bateria_pct")
    if bat:
        r.bateria_inicio, r.bateria_fin = bat[0], bat[-1]
        if r.minutos > 0:
            r.bateria_pct_por_hora = (bat[0] - bat[-1]) * 60.0 / r.minutos
    temp = _col(filas, "bateria_c")
    if temp:
        r.temp_inicio_c, r.temp_fin_c, r.temp_max_c = temp[0], temp[-1], max(temp)
    term = _col(filas, "termico")
    r.termico_max = int(max(term)) if term else None

    r.veredictos = evaluar(r)
    return r


def evaluar(r: Resumen, criterios: dict | None = None) -> dict[str, str]:
    """Cada criterio: 'cumple', 'no cumple' o 'sin dato'. Sin datos NUNCA cuenta como cumplido."""
    c = {**CRITERIOS, **(criterios or {})}

    def cmp(valor, ok) -> str:
        return "sin dato" if valor is None else ("cumple" if ok(valor) else "no cumple")

    return {
        f"Duración ≥ {c['minutos']:.0f} min": cmp(r.minutos if r.muestras else None, lambda v: v >= c["minutos"]),
        f"FPS recibidos, mediana ≥ {c['fps_mediana']:.0f}": cmp(r.fps_mediana, lambda v: v >= c["fps_mediana"]),
        f"1280×720 en ≥ {c['pct_720p']:.0f} % de las muestras": cmp(r.pct_720p, lambda v: v >= c["pct_720p"]),
        f"Congelamientos ≤ {c['congelamientos']}": cmp(r.congelamientos, lambda v: v <= c["congelamientos"]),
        f"Cortes (huecos > {HUECO_S:.0f} s) ≤ {c['cortes']}": cmp(r.cortes if r.muestras >= 2 else None, lambda v: v <= c["cortes"]),
        f"Estado térmico máximo ≤ {c['termico_max']}": cmp(r.termico_max, lambda v: v <= c["termico_max"]),
    }


def veredicto_global(v: dict[str, str]) -> str:
    if any(x == "no cumple" for x in v.values()):
        return "NO CUMPLE"
    if any(x == "sin dato" for x in v.values()):
        return "INCOMPLETO (faltan datos: no se puede afirmar que cumple)"
    return "CUMPLE los criterios automáticos (falta la latencia vidrio a vidrio y la revisión humana)"


def _f(v, unidad="", dec=1) -> str:
    return "sin dato" if v is None else f"{v:.{dec}f}{unidad}"


def informe(r: Resumen) -> str:
    def n(v) -> str:
        return "sin dato" if v is None else str(v)

    lineas = [
        "# Resultado del registro CSV (análisis automático)",
        "",
        f"- Muestras: {r.muestras} · Duración: {_f(r.minutos, ' min')}",
        f"- FPS recibidos: mediana {_f(r.fps_mediana)} · percentil 5 {_f(r.fps_p5)}",
        f"- Muestras a 1280×720: {_f(r.pct_720p, ' %')} · Tasa media: {_f(r.kbps_medio, ' kbps', 0)}",
        f"- Congelamientos: {n(r.congelamientos)} · Paquetes perdidos: {n(r.paquetes_perdidos)}",
        (
            f"- RTT: mediana {_f(r.rtt_mediana_ms, ' ms', 0)} · p95 {_f(r.rtt_p95_ms, ' ms', 0)} · "
            f"Búfer de jitter (mediana): {_f(r.jitterbuffer_mediana_ms, ' ms', 0)}"
        ),
        f"- Cortes del registro: {r.cortes} · Mayor hueco: {_f(r.mayor_hueco_s, ' s')}",
        f"- Codificador: {r.codificador or 'sin dato'} ({r.hardware}) · Muestras con limitación: {_f(r.pct_limitado, ' %')}",
        f"- Batería: {_f(r.bateria_inicio, ' %', 0)} → {_f(r.bateria_fin, ' %', 0)} ({_f(r.bateria_pct_por_hora, ' %/h')})",
        (
            f"- Temperatura de batería: {_f(r.temp_inicio_c, ' °C')} → {_f(r.temp_fin_c, ' °C')} "
            f"(máx {_f(r.temp_max_c, ' °C')}) · Estado térmico máx: {n(r.termico_max)}"
        ),
        "",
        "## Criterios propuestos",
    ]
    lineas += [f"- {k}: **{v}**" for k, v in r.veredictos.items()]
    nota = (
        "_La RTT es latencia de red, no la latencia vidrio a vidrio. El consumo en %/h es una aproximación; "
        "ver el procedimiento para la medición con ADB._"
    )
    lineas += ["", f"**Veredicto automático: {veredicto_global(r.veredictos)}**", "", nota]
    return "\n".join(lineas)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("csv")
    ap.add_argument("--dispositivo", help="id del dispositivo (si el registro tiene más de uno)")
    ap.add_argument("--minutos", type=float, help="duración mínima exigida (por defecto 30)")
    args = ap.parse_args(argv)
    filas = leer(args.csv, args.dispositivo)
    r = resumir(filas)
    if args.minutos is not None:
        r.veredictos = evaluar(r, {"minutos": args.minutos})
    print(informe(r))
    return 0 if veredicto_global(r.veredictos).startswith("CUMPLE") else 1


if __name__ == "__main__":
    sys.exit(main())
