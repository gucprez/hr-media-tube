#!/usr/bin/env bash
# Mide batería y temperatura del teléfono durante la prueba, de forma independiente de la app.
# NO PROBADO en un teléfono real todavía: el formato de `dumpsys` cambia entre fabricantes; revisa la salida.
#
# Requisitos: adb (Android platform-tools) y depuración INALÁMBRICA (Ajustes ▸ Opciones de desarrollador ▸
# Depuración inalámbrica). No uses cable USB: el cable carga el teléfono y falsea el consumo.
#
# Uso:   ./adb_medir.sh [segundos_entre_muestras=10] [archivo=medicion-adb.csv]
# Parar: Ctrl+C (al salir guarda el resumen de consumo por app en batterystats.txt)
set -u
INTERVALO="${1:-10}"
SALIDA="${2:-medicion-adb.csv}"
PAQUETE="com.hrmediasports.studio"

command -v adb >/dev/null || { echo "No se encontró adb"; exit 1; }
[ "$(adb get-state 2>/dev/null)" = "device" ] || { echo "No hay un teléfono conectado por adb"; exit 1; }

campo() { adb shell dumpsys battery | tr -d '\r' | awk -F': ' -v k="$1" '$1 ~ k"$" {print $2; exit}'; }

alimentacion=$(adb shell dumpsys battery | tr -d '\r' | grep -E "(AC|USB|Wireless) powered: true" || true)
if [ -n "$alimentacion" ]; then
  echo "AVISO: el teléfono está cargando ($alimentacion). El consumo medido NO será válido."
fi

echo "Reiniciando estadísticas de batería…"
adb shell dumpsys batterystats --reset >/dev/null
echo "hora_iso,nivel_pct,temperatura_c,voltaje_mv,estado,termico" > "$SALIDA"

al_salir() {
  echo; echo "Guardando consumo por app en batterystats.txt…"
  adb shell dumpsys batterystats --charged "$PAQUETE" > batterystats.txt 2>/dev/null || true
  echo "Listo: $SALIDA y batterystats.txt"
  exit 0
}
trap al_salir INT TERM

echo "Midiendo cada ${INTERVALO}s → $SALIDA (Ctrl+C para terminar)"
while true; do
  nivel=$(campo "level"); temp=$(campo "temperature"); volt=$(campo "voltage"); estado=$(campo "status")
  termico=$(adb shell dumpsys thermalservice 2>/dev/null | tr -d '\r' | awk -F': ' '/Thermal Status/ {print $2; exit}')
  temp_c=$(awk -v t="${temp:-}" 'BEGIN { if (t == "") print ""; else printf "%.1f", t / 10 }')
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ),${nivel:-},${temp_c},${volt:-},${estado:-},${termico:-}" | tee -a "$SALIDA"
  sleep "$INTERVALO"
done
