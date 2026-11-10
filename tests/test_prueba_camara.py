"""Pruebas de las herramientas de análisis de la prueba Cámara remota → Laptop (con datos sintéticos)."""
import csv
from datetime import datetime, timedelta, timezone

import pytest

from tools.prueba_camara import analisis, latencia

CAMPOS = ["hora_iso", "dispositivo", "nombre", "rx_ancho", "rx_alto", "rx_fps", "rx_kbps", "rx_jitterbuffer_ms",
          "rx_congelamientos", "rx_perdidos", "rx_codec", "rtt_ms", "tx_fps", "tx_kbps", "tx_encoder", "tx_hw",
          "tx_limitacion", "bateria_pct", "bateria_c", "termico", "carga_uAh", "corriente_uA", "lente", "zoom", "audio"]


def filas(minutos=30, fps=30, ancho=1280, alto=720, hueco_en=None, congel_final=0, bat=(90, 80), temp=(30.0, 38.0),
          termico=0, hw="true", limitacion="none"):
    t0 = datetime(2026, 11, 10, 15, 0, 0, tzinfo=timezone.utc)
    n = int(minutos * 60 / 2) + 1
    out, t = [], t0
    for i in range(n):
        if hueco_en is not None and i == hueco_en:
            t += timedelta(seconds=20)          # corte de 20 s
        k = i / max(n - 1, 1)
        out.append({
            "hora_iso": t.isoformat().replace("+00:00", "Z"), "dispositivo": "cam1", "nombre": "Cel",
            "rx_ancho": ancho, "rx_alto": alto, "rx_fps": fps, "rx_kbps": 2300, "rx_jitterbuffer_ms": 60,
            "rx_congelamientos": round(congel_final * k), "rx_perdidos": i // 100, "rx_codec": "video/H264", "rtt_ms": 8,
            "tx_fps": fps, "tx_kbps": 2400, "tx_encoder": "c2.qti.avc.encoder", "tx_hw": hw, "tx_limitacion": limitacion,
            "bateria_pct": round(bat[0] + (bat[1] - bat[0]) * k), "bateria_c": round(temp[0] + (temp[1] - temp[0]) * k, 1),
            "termico": termico, "carga_uAh": "", "corriente_uA": "", "lente": "Back", "zoom": 1, "audio": "true",
        })
        t += timedelta(seconds=2)
    return [{k: str(v) for k, v in f.items()} for f in out]


def test_corrida_buena_cumple_todo():
    r = analisis.resumir(filas())
    assert r.minutos == pytest.approx(30, abs=0.1)
    assert r.fps_mediana == 30
    assert r.pct_720p == 100
    assert r.congelamientos == 0
    assert r.cortes == 0
    assert r.hardware == "hardware"
    assert r.bateria_pct_por_hora == pytest.approx(20, abs=0.5)      # 10 % en 30 min
    assert r.temp_max_c == 38.0
    assert all(v == "cumple" for v in r.veredictos.values())
    assert analisis.veredicto_global(r.veredictos).startswith("CUMPLE")


def test_fps_bajo_no_cumple():
    r = analisis.resumir(filas(fps=20))
    assert "no cumple" in r.veredictos["FPS recibidos, mediana ≥ 27"]
    assert analisis.veredicto_global(r.veredictos) == "NO CUMPLE"


def test_resolucion_degradada_no_cumple():
    r = analisis.resumir(filas(ancho=640, alto=360))
    assert r.pct_720p == 0
    assert analisis.veredicto_global(r.veredictos) == "NO CUMPLE"


def test_corte_del_registro_se_detecta():
    r = analisis.resumir(filas(hueco_en=300))
    assert r.cortes == 1
    assert r.mayor_hueco_s >= 20
    assert analisis.veredicto_global(r.veredictos) == "NO CUMPLE"


def test_corrida_corta_no_cumple_duracion():
    r = analisis.resumir(filas(minutos=5))
    assert r.veredictos["Duración ≥ 30 min"] == "no cumple"


def test_congelamientos_y_termico():
    r = analisis.resumir(filas(congel_final=9, termico=3))
    assert r.congelamientos == 9
    assert r.veredictos["Congelamientos ≤ 3"] == "no cumple"
    assert r.veredictos["Estado térmico máximo ≤ 2"] == "no cumple"


def test_contador_que_se_reinicia_al_reconectar_no_resta():
    f = filas(minutos=1)
    for i, v in enumerate([0, 1, 2, 0, 1]):           # reinicio tras reconexión
        f[i]["rx_congelamientos"] = str(v)
    for x in f[5:]:
        x["rx_congelamientos"] = "1"
    assert analisis.resumir(f).congelamientos == 3


def test_sin_datos_nunca_es_cumple():
    f = filas()
    for x in f:
        x["rx_fps"] = ""
        x["termico"] = ""
    r = analisis.resumir(f)
    assert r.fps_mediana is None
    assert r.veredictos["FPS recibidos, mediana ≥ 27"] == "sin dato"
    assert analisis.veredicto_global(r.veredictos).startswith("INCOMPLETO")
    vacio = analisis.resumir([])
    assert vacio.muestras == 0


def test_software_y_limitacion_se_informan():
    r = analisis.resumir(filas(hw="false", limitacion="cpu"))
    assert r.hardware == "software"
    assert r.pct_limitado == 100


def test_cli_lee_csv_y_devuelve_codigo(tmp_path, capsys):
    ruta = tmp_path / "r.csv"
    with open(ruta, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=CAMPOS, quoting=csv.QUOTE_ALL)
        w.writeheader()
        w.writerows(filas())
    assert analisis.main([str(ruta)]) == 0
    assert "Veredicto automático: CUMPLE" in capsys.readouterr().out
    with open(ruta, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=CAMPOS, quoting=csv.QUOTE_ALL)
        w.writeheader()
        w.writerows(filas(fps=15))
    assert analisis.main([str(ruta)]) == 1


def test_latencia_mediana_y_p95():
    pares = [(10.00 + i, 10.00 + i - 0.2) for i in range(9)] + [(30.0, 29.5)]
    r = latencia.resumen(pares)
    assert r["muestras"] == 10
    assert r["mediana_ms"] == pytest.approx(200, abs=0.01)
    assert r["max_ms"] == pytest.approx(500, abs=0.01)
    assert 200 <= r["p95_ms"] <= 500


def test_latencia_rechaza_lecturas_imposibles():
    with pytest.raises(ValueError):
        latencia.latencias_ms([(10.0, 10.3)])
    with pytest.raises(ValueError):
        latencia.resumen([])
