# Herramientas de la prueba Cámara remota → Laptop

| Archivo | Para qué | Estado |
|---------|----------|--------|
| `analisis.py` | Resume el CSV del estudio (fps, resolución, congelamientos, cortes, RTT, batería, temperatura) y lo compara con los criterios propuestos | Probado con datos **sintéticos** (`tests/test_prueba_camara.py`); nunca con un registro real |
| `latencia.py` | Mediana y p95 de la latencia vidrio a vidrio a partir de lecturas del cronómetro | Probado con datos sintéticos |
| `adb_medir.sh` | Muestrea batería/temperatura por ADB inalámbrico y guarda `batterystats` | Solo revisión de sintaxis; **no ejecutado contra un teléfono** |

```bash
python -m tools.prueba_camara.analisis docs/resultados/reales/T1-2026-11-12-pixel7.csv --origen real
python -m tools.prueba_camara.latencia lecturas.csv        # columnas: real,monitor (segundos)
tools/prueba_camara/adb_medir.sh 10 medicion-adb.csv
```
Guía para ejecutar: `docs/GUIA-PRUEBA-CAMARA.md`. Plan técnico: `docs/prueba-tecnica-camara-remota.md`.
