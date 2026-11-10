# Resultado del registro CSV (análisis automático)

**Origen de los datos: SINTETICO**

- Muestras: 15 · Duración: 0.5 min
- FPS recibidos: mediana 20.0 · percentil 5 20.0 · mínimo 20.0
- Muestras a 1280×720: 100.0 % · Tasa media: 433 kbps

## Interrupciones (una mediana puede ocultarlas)
- Definición de congelamiento: más de 200 ms sin un cuadro nuevo presentado (≈ 6 cuadros a 30 fps).
- Congelamientos: 1 · **más largo: 1533 ms** · **tiempo total congelado: 1.5 s**
- Según WebRTC (su propia definición): 1 congelamientos, 1.5 s en total
- Cortes del registro (huecos > 6 s): 0 · Mayor hueco: 2.0 s
- Reconexiones: 1 · más lenta 8.1 s
- Paquetes perdidos: 0

## Red, codificador y consumo
- RTT: mediana 1 ms · p95 1 ms · Búfer de jitter (mediana): 7 ms
- Codificador: simulado-chromium (software) · Muestras con limitación: 0.0 %
- Batería: 100 % → 100 % (0.0 %/h)
- Temperatura de batería: sin dato → sin dato (máx sin dato) · Estado térmico máx: sin dato

## Objetivos provisionales
- Corrida de ≥ 0 min: **cumple**
- FPS recibidos, mediana ≥ 27: **no cumple**
- Reconexión ≤ 15 s (la más lenta del registro): **cumple**

**Resultado automático: NO ALCANZA los objetivos provisionales**

_Los objetivos son provisionales y no aprueban el producto. La RTT es latencia de red, no vidrio a vidrio (esa se mide con fotos y latencia.py). El consumo en %/h es una aproximación._
