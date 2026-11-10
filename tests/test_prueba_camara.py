"""Pruebas de las herramientas de análisis de la prueba Cámara remota → Laptop (con datos sintéticos)."""
import csv
from datetime import UTC, datetime, timedelta

import pytest

from tools.prueba_camara import analisis, latencia

CAMPOS = ["hora_iso", "dispositivo", "nombre", "rx_ancho", "rx_alto", "rx_fps", "rx_kbps", "rx_jitterbuffer_ms",
          "rx_congelamientos", "rx_perdidos", "rx_codec", "rtt_ms", "tx_fps", "tx_kbps", "tx_encoder", "tx_hw",
          "tx_limitacion", "bateria_pct", "bateria_c", "termico", "carga_uAh", "corriente_uA", "lente", "zoom", "audio",
          "rx_congelado_total_s", "med_congelamientos", "med_congelamiento_max_ms", "med_congelado_total_ms",
          "pestana_visible", "reconexion_s"]


def filas(minutos=30, fps=30, ancho=1280, alto=720, hueco_en=None, congel_final=0, bat=(90, 80), temp=(30.0, 38.0),
          termico=0, hw="true", limitacion="none", max_ms=0, total_ms=0, visible="true", reconexion=None):
    t0 = datetime(2026, 11, 10, 15, 0, 0, tzinfo=UTC)
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
            "rx_congelado_total_s": round(total_ms / 1000 * k, 2), "med_congelamientos": round(congel_final * k),
            "med_congelamiento_max_ms": max_ms if i > n // 2 else 0, "med_congelado_total_ms": round(total_ms * k),
            "pestana_visible": visible, "reconexion_s": reconexion if (reconexion and i == n // 2) else "",
        })
        t += timedelta(seconds=2)
    return [{k: str(v) for k, v in f.items()} for f in out]


def test_corrida_buena_alcanza_objetivos_si_hubo_reconexion_rapida():
    r = analisis.resumir(filas(reconexion="4.5"), "sintetico")
    assert r.origen == "sintetico"
    assert r.minutos == pytest.approx(30, abs=0.1)
    assert r.fps_mediana == 30
    assert r.pct_720p == 100
    assert r.congelamientos == 0
    assert r.reconexiones == [4.5]
    assert r.hardware == "hardware"
    assert r.bateria_pct_por_hora == pytest.approx(20, abs=0.5)      # 10 % en 30 min
    assert all(v == "cumple" for v in r.veredictos.values())
    assert analisis.veredicto_global(r.veredictos).startswith("ALCANZA")


def test_sin_reconexiones_en_el_registro_queda_incompleto():
    r = analisis.resumir(filas())
    assert r.reconexiones == []
    assert analisis.veredicto_global(r.veredictos).startswith("INCOMPLETO")


def test_reconexion_lenta_no_alcanza():
    r = analisis.resumir(filas(reconexion="3.0|22.4"))
    assert r.reconexiones == [3.0, 22.4]
    assert "no cumple" in r.veredictos["Reconexión ≤ 15 s (la más lenta del registro)"]


def test_fps_bajo_no_alcanza():
    r = analisis.resumir(filas(fps=20, reconexion="2"))
    assert r.veredictos["FPS recibidos, mediana ≥ 27"] == "no cumple"
    assert analisis.veredicto_global(r.veredictos).startswith("NO ALCANZA")


def test_mediana_buena_no_oculta_un_congelamiento_largo():
    # 30 fps de mediana, pero con un congelamiento de 4,2 s y 6 s congelados en total.
    r = analisis.resumir(filas(congel_final=5, max_ms=4200, total_ms=6000, reconexion="2"))
    assert r.fps_mediana == 30
    assert r.congelamientos == 5
    assert r.congelamiento_max_ms == 4200
    assert r.congelado_total_s == pytest.approx(6.0)
    texto = analisis.informe(r)
    assert "más largo: 4200 ms" in texto
    assert "tiempo total congelado: 6.0 s" in texto
    assert "más de 200 ms sin un cuadro nuevo" in texto


def test_corte_del_registro_se_detecta():
    r = analisis.resumir(filas(hueco_en=300))
    assert r.cortes == 1
    assert r.mayor_hueco_s >= 20
    assert "Cortes del registro (huecos > 6 s): 1" in analisis.informe(r)


def test_corrida_corta_no_cumple_duracion():
    r = analisis.resumir(filas(minutos=5))
    assert r.veredictos["Corrida de ≥ 30 min"] == "no cumple"


def test_resolucion_y_termico_se_registran():
    r = analisis.resumir(filas(ancho=640, alto=360, termico=3))
    assert r.pct_720p == 0
    assert r.termico_max == 3


def test_contador_webrtc_que_se_reinicia_al_reconectar_no_resta():
    f = filas(minutos=1)
    for i, v in enumerate([0, 1, 2, 0, 1]):           # reinicio tras reconexión
        f[i]["rx_congelamientos"] = str(v)
    for x in f[5:]:
        x["rx_congelamientos"] = "1"
    assert analisis.resumir(f).webrtc_congelamientos == 3


def test_sin_datos_nunca_es_cumple():
    f = filas(reconexion="2")
    for x in f:
        x["rx_fps"] = ""
        x["med_congelamientos"] = ""
        x["med_congelamiento_max_ms"] = ""
        x["med_congelado_total_ms"] = ""
    r = analisis.resumir(f)
    assert r.fps_mediana is None
    assert r.congelamientos is None and r.congelamiento_max_ms is None
    assert r.veredictos["FPS recibidos, mediana ≥ 27"] == "sin dato"
    assert analisis.veredicto_global(r.veredictos).startswith("INCOMPLETO")
    assert "sin dato" in analisis.informe(r)
    assert analisis.resumir([]).muestras == 0


def test_pestana_oculta_se_avisa():
    f = filas()
    for x in f[: len(f) // 2]:
        x["pestana_visible"] = "false"
    r = analisis.resumir(f)
    assert r.pct_pestana_visible == pytest.approx(50, abs=1)
    assert "pestaña del estudio estuvo visible solo" in analisis.informe(r)


def test_software_y_limitacion_se_informan():
    r = analisis.resumir(filas(hw="false", limitacion="cpu"))
    assert r.hardware == "software"
    assert r.pct_limitado == 100


def _escribir(ruta, datos):
    with open(ruta, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=CAMPOS, quoting=csv.QUOTE_ALL)
        w.writeheader()
        w.writerows(datos)


def test_cli_exige_origen_y_lo_muestra(tmp_path, capsys):
    ruta = tmp_path / "r.csv"
    _escribir(ruta, filas(reconexion="3"))
    with pytest.raises(SystemExit):
        analisis.main([str(ruta)])                     # sin --origen no se analiza
    capsys.readouterr()
    assert analisis.main([str(ruta), "--origen", "sintetico"]) == 0
    out = capsys.readouterr().out
    assert "Origen de los datos: SINTETICO" in out
    assert "Resultado automático: ALCANZA" in out
    _escribir(ruta, filas(fps=15, reconexion="3"))
    assert analisis.main([str(ruta), "--origen", "real"]) == 1
    assert "Origen de los datos: REAL" in capsys.readouterr().out


def test_registro_antiguo_sin_columnas_nuevas_da_sin_dato():
    viejos = [{k: v for k, v in f.items() if not k.startswith("med_") and k not in ("pestana_visible", "reconexion_s", "rx_congelado_total_s")}
              for f in filas()]
    r = analisis.resumir(viejos)
    assert r.congelamiento_max_ms is None and r.congelado_total_s is None
    assert r.fps_mediana == 30


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


def test_latencia_cli_objetivo_y_p95(tmp_path, capsys):
    ruta = tmp_path / "l.csv"
    ruta.write_text("real,monitor\n" + "\n".join(f"{10 + i}.50,{10 + i}.30" for i in range(10)), encoding="utf-8")
    assert latencia.main([str(ruta)]) == 0
    out = capsys.readouterr().out
    assert "mediana ≤ 300 ms): cumple" in out and "p95 registrada" in out
    ruta.write_text("real,monitor\n" + "\n".join(f"{10 + i}.90,{10 + i}.30" for i in range(10)), encoding="utf-8")
    assert latencia.main([str(ruta)]) == 1
