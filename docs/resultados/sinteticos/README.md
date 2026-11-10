# Resultados sintéticos (NO son mediciones de un dispositivo)

| Archivo | Origen |
|---------|--------|
| `e2e-camara-simulada.csv` | Registro del estudio durante la prueba automática `studio-server/e2e/e2e.cjs`: Chromium en Linux con una **cámara simulada** (patrón de prueba del navegador), en la misma máquina, sin red real |
| `e2e-camara-simulada-analisis.md` | Ese registro pasado por `tools/prueba_camara/analisis.py --origen sintetico` |
| `e2e-camara-simulada-comprobaciones.txt` | Las comprobaciones de esa corrida |

Para qué sirven: demuestran que la vinculación, el video WebRTC, la medición de congelamientos y de reconexión, el CSV y el analizador funcionan de punta a punta.
Para qué **no** sirven: los ~20 fps, la tasa y la latencia de red de ese registro son del entorno de desarrollo (códec por software, CPU compartida, sin Wi-Fi). No dicen nada de un teléfono ni de una laptop reales. El congelamiento de 1,5 s y la reconexión que aparecen fueron **provocados a propósito** por la prueba.
