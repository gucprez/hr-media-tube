"""Analiza el registro CSV del estudio (botón «Registro CSV») de la prueba Cámara remota → Laptop.

Calcula estabilidad, calidad y consumo, y compara con los OBJETIVOS PROVISIONALES de
docs/prueba-tecnica-camara-remota.md. No mide la latencia vidrio a vidrio: eso se hace
con fotos del cronómetro (ver `latencia.py`).

Uso:  python -m tools.prueba_camara.analisis registro.csv --origen real|sintetico [--minutos 30]
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

# OBJETIVOS PROVISIONALES de la prueba (no son la aprobación definitiva del producto).
OBJETIVOS = {
    "minutos": 30.0,        # corrida continua
    "fps_mediana": 27.0,    # fps recibidos, mediana
    "reconexion_s": 15.0,   # la cámara vuelve sola en este tiempo como máximo
}
# La latencia (mediana ≤ 300 ms, y p95 registrada) se evalúa aparte con latencia.py: requiere fotos.
HUECO_S = 6.0      # el estudio registra cada ~2 s; un hueco mayor indica un corte de la cámara
FREEZE_MS = 200    # definición de congelamiento usada por el estudio: > 200 ms sin un cuadro nuevo presentado


@dataclass
class Resumen:
    origen: str = "sin indicar"
    muestras: int = 0
    minutos: float = 0.0
    fps_mediana: float | None = None
    fps_p5: float | None = None
    fps_min: float | None = None
    pct_720p: float | None = None
    kbps_medio: float | None = None
    # Congelamientos medidos por el estudio (definición: FREEZE_MS)
    congelamientos: int | None = None
    congelamiento_max_ms: float | None = None
    congelado_total_s: float | None = None
    pct_pestana_visible: float | None = None
    # Los que informa WebRTC (su propia definición: intervalo > max(3×media, media+150 ms))
    webrtc_congelamientos: int | None = None
    webrtc_congelado_total_s: float | None = None
    paquetes_perdidos: int | None = None
    rtt_mediana_ms: float | None = None
    rtt_p95_ms: float | None = None
    jitterbuffer_mediana_ms: float | None = None
    cortes: int = 0
    mayor_hueco_s: float = 0.0
    reconexiones: list[float] = field(default_factory=list)
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


def _delta_contador_float(filas: list[dict], nombre: str) -> float | None:
    vals = _col(filas, nombre)
    if not vals:
        return None
    return sum(b - a for a, b in pairwise(vals) if b >= a)


def leer(ruta: str | Path, dispositivo: str | None = None) -> list[dict]:
    with open(ruta, newline="", encoding="utf-8") as fh:
        filas = list(csv.DictReader(fh))
    if dispositivo:
        filas = [f for f in filas if f.get("dispositivo") == dispositivo]
    return filas


def _max_contador(filas: list[dict], nombre: str) -> float | None:
    vals = _col(filas, nombre)
    return max(vals) if vals else None


def resumir(filas: list[dict], origen: str = "sin indicar") -> Resumen:
    r = Resumen(origen=origen, muestras=len(filas))
    if not filas:
        return r

    tiempos = []
    for f in filas:
        try:
            tiempos.append(datetime.fromisoformat(f["hora_iso"]))
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
        r.fps_min = min(fps)
    con_res = [f for f in filas if _num(f.get("rx_ancho")) is not None]
    if con_res:
        ok = sum(1 for f in con_res if _num(f["rx_ancho"]) == 1280 and _num(f.get("rx_alto")) == 720)
        r.pct_720p = 100.0 * ok / len(con_res)
    kbps = _col(filas, "rx_kbps")
    r.kbps_medio = statistics.fmean(kbps) if kbps else None
    # Medidos por el estudio: son acumulados por cámara (no se reinician al reconectar).
    medidos = _max_contador(filas, "med_congelamientos")
    r.congelamientos = int(medidos) if medidos is not None else None
    r.congelamiento_max_ms = _max_contador(filas, "med_congelamiento_max_ms")
    total_ms = _max_contador(filas, "med_congelado_total_ms")
    r.congelado_total_s = total_ms / 1000.0 if total_ms is not None else None
    visibles = [f["pestana_visible"].strip().lower() for f in filas if f.get("pestana_visible", "").strip() != ""]
    if visibles:
        r.pct_pestana_visible = 100.0 * sum(1 for v in visibles if v == "true") / len(visibles)
    r.webrtc_congelamientos = _delta_contador(filas, "rx_congelamientos")
    wt = _delta_contador_float(filas, "rx_congelado_total_s")
    r.webrtc_congelado_total_s = wt
    for f in filas:
        for trozo in f.get("reconexion_s", "").split("|"):
            v = _num(trozo)
            if v is not None:
                r.reconexiones.append(v)
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


def evaluar(r: Resumen, objetivos: dict | None = None) -> dict[str, str]:
    """Cada objetivo: 'cumple', 'no cumple' o 'sin dato'. Sin datos NUNCA cuenta como cumplido."""
    c = {**OBJETIVOS, **(objetivos or {})}

    def cmp(valor, ok) -> str:
        return "sin dato" if valor is None else ("cumple" if ok(valor) else "no cumple")

    peor = max(r.reconexiones) if r.reconexiones else None
    return {
        f"Corrida de ≥ {c['minutos']:.0f} min": cmp(r.minutos if r.muestras else None, lambda v: v >= c["minutos"]),
        f"FPS recibidos, mediana ≥ {c['fps_mediana']:.0f}": cmp(r.fps_mediana, lambda v: v >= c["fps_mediana"]),
        f"Reconexión ≤ {c['reconexion_s']:.0f} s (la más lenta del registro)": cmp(peor, lambda v: v <= c["reconexion_s"]),
    }


def veredicto_global(v: dict[str, str]) -> str:
    if any(x == "no cumple" for x in v.values()):
        return "NO ALCANZA los objetivos provisionales"
    if any(x == "sin dato" for x in v.values()):
        return "INCOMPLETO (faltan datos: no se puede afirmar que alcanza los objetivos)"
    return "ALCANZA los objetivos automáticos (falta la latencia vidrio a vidrio y las observaciones manuales)"


def _f(v, unidad="", dec=1) -> str:
    return "sin dato" if v is None else f"{v:.{dec}f}{unidad}"


def informe(r: Resumen) -> str:
    def n(v) -> str:
        return "sin dato" if v is None else str(v)

    rec = (
        f"{len(r.reconexiones)} · más lenta {max(r.reconexiones):.1f} s"
        if r.reconexiones else "ninguna en el registro (probarlas a mano: ver la guía)"
    )
    aviso_pestana = ""
    if r.pct_pestana_visible is not None and r.pct_pestana_visible < 100:
        aviso_pestana = (
            f"\n- **Aviso:** la pestaña del estudio estuvo visible solo el {r.pct_pestana_visible:.0f} % del tiempo; "
            "los congelamientos no se miden con la pestaña oculta."
        )
    lineas = [
        "# Resultado del registro CSV (análisis automático)",
        "",
        f"**Origen de los datos: {r.origen.upper()}**",
        "",
        f"- Muestras: {r.muestras} · Duración: {_f(r.minutos, ' min')}",
        f"- FPS recibidos: mediana {_f(r.fps_mediana)} · percentil 5 {_f(r.fps_p5)} · mínimo {_f(r.fps_min)}",
        f"- Muestras a 1280×720: {_f(r.pct_720p, ' %')} · Tasa media: {_f(r.kbps_medio, ' kbps', 0)}",
        "",
        "## Interrupciones (una mediana puede ocultarlas)",
        f"- Definición de congelamiento: más de {FREEZE_MS} ms sin un cuadro nuevo presentado (≈ 6 cuadros a 30 fps).",
        (
            f"- Congelamientos: {n(r.congelamientos)} · **más largo: {_f(r.congelamiento_max_ms, ' ms', 0)}** · "
            f"**tiempo total congelado: {_f(r.congelado_total_s, ' s')}**"
        ),
        (
            f"- Según WebRTC (su propia definición): {n(r.webrtc_congelamientos)} congelamientos, "
            f"{_f(r.webrtc_congelado_total_s, ' s')} en total"
        ),
        f"- Cortes del registro (huecos > {HUECO_S:.0f} s): {r.cortes} · Mayor hueco: {_f(r.mayor_hueco_s, ' s')}",
        f"- Reconexiones: {rec}" + aviso_pestana,
        f"- Paquetes perdidos: {n(r.paquetes_perdidos)}",
        "",
        "## Red, codificador y consumo",
        (
            f"- RTT: mediana {_f(r.rtt_mediana_ms, ' ms', 0)} · p95 {_f(r.rtt_p95_ms, ' ms', 0)} · "
            f"Búfer de jitter (mediana): {_f(r.jitterbuffer_mediana_ms, ' ms', 0)}"
        ),
        f"- Codificador: {r.codificador or 'sin dato'} ({r.hardware}) · Muestras con limitación: {_f(r.pct_limitado, ' %')}",
        f"- Batería: {_f(r.bateria_inicio, ' %', 0)} → {_f(r.bateria_fin, ' %', 0)} ({_f(r.bateria_pct_por_hora, ' %/h')})",
        (
            f"- Temperatura de batería: {_f(r.temp_inicio_c, ' °C')} → {_f(r.temp_fin_c, ' °C')} "
            f"(máx {_f(r.temp_max_c, ' °C')}) · Estado térmico máx: {n(r.termico_max)}"
        ),
        "",
        "## Objetivos provisionales",
    ]
    lineas += [f"- {k}: **{v}**" for k, v in r.veredictos.items()]
    nota = (
        "_Los objetivos son provisionales y no aprueban el producto. La RTT es latencia de red, no vidrio a vidrio "
        "(esa se mide con fotos y latencia.py). El consumo en %/h es una aproximación._"
    )
    lineas += ["", f"**Resultado automático: {veredicto_global(r.veredictos)}**", "", nota]
    return "\n".join(lineas)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("csv")
    ap.add_argument("--origen", required=True, choices=["real", "sintetico"],
                    help="real = teléfono y laptop físicos; sintetico = cámara simulada o datos generados")
    ap.add_argument("--dispositivo", help="id del dispositivo (si el registro tiene más de uno)")
    ap.add_argument("--minutos", type=float, help="duración mínima exigida (por defecto 30)")
    args = ap.parse_args(argv)
    filas = leer(args.csv, args.dispositivo)
    r = resumir(filas, args.origen)
    if args.minutos is not None:
        r.veredictos = evaluar(r, {"minutos": args.minutos})
    print(informe(r))
    return 0 if veredicto_global(r.veredictos).startswith("ALCANZA") else 1


if __name__ == "__main__":
    sys.exit(main())
