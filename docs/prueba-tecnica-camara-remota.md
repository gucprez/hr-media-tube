# Prueba técnica independiente: Android Cámara remota → Laptop Estudio

**Estado: PREPARADA, NO EJECUTADA.** Nadie ha corrido esta prueba con un teléfono y una laptop reales. Este documento es el plan; los resultados irán en `docs/resultados/`.

- **Alcance:** **una** cámara, **1280×720 a 30 fps**, misma red local, sin cuentas ni nube.
- **Qué se mide:** estabilidad, latencia y consumo (y temperatura, porque condiciona las otras tres).
- **Qué NO vale como prueba:** LDPlayer u otro emulador, Robolectric, la cámara simulada de `studio-server/e2e`. Sirven para desarrollar; no miden un teléfono.
- **Fuera de alcance:** segunda cámara, OCR/pizarra, grabación, transmisión, repeticiones, internet.
- **Rama:** `claude/camara-remota-prueba-tecnica` (independiente del PR de la interfaz demo).

## Qué existe para la prueba
| Pieza | Dónde | Verificado sin dispositivos | Sin verificar |
|-------|-------|-----------------------------|---------------|
| App Android, rol «Cámara remota» | `android/app/.../camera` | Compila; cliente de enlace probado contra el servidor real por sockets; decodificador de QR con códigos sintéticos | Cámara, WebRTC, codificador, permisos, controles, orientación: **nunca ejecutados en un teléfono** |
| Estudio de laptop (servidor + interfaz web) | `android/studio-server` | Pruebas del servidor; extremo a extremo en Linux con Chromium y cámara simulada | Windows y macOS: **nunca ejecutado** |
| Protocolo de conexión v1 | `docs/protocolo-conexion.md`, `android/core-link` | Pruebas unitarias; dos implementaciones (Kotlin y JavaScript) interoperan | — |
| Análisis de resultados | `tools/prueba_camara/` | Con datos sintéticos | Con un registro real |

En el entorno de desarrollo la cámara simulada llegó a 720p a ~20 fps con códec por software. **Ese número no dice nada de un teléfono real.**

## Equipo y condiciones (anotar todo)
- **Teléfono:** Android real de 64 bits, Android 8.0 o superior. Anotar marca, modelo, SoC y versión. Batería entre 80 % y 100 %, brillo fijo al 50 %, modo ahorro apagado, sin funda si se mide temperatura, apoyado en un trípode.
- **Laptop:** real, Windows o macOS (anotar modelo, SO, navegador y versión, versión de Java ≥ 17). Enchufada.
- **Red:** router propio en la misma sala; anotar modelo y banda (preferir 5 GHz). Evitar redes de invitados (aíslan dispositivos).
- **Ambiente:** temperatura de la sala; teléfono a la sombra.
- **Corridas:** A) teléfono **desenchufado** (consumo). B) teléfono enchufado (estabilidad térmica larga). Mínimo 30 min cada una.

## Preparación
1. Laptop: `cd android && ./gradlew :studio-server:installDist` y arrancar `studio-server/build/install/hr-studio/bin/hr-studio` (en Windows, `hr-studio.bat`). Si el sistema pregunta por el cortafuegos, permitir **solo redes privadas**. Abrir `http://localhost:8765`.
2. Teléfono: instalar la APK de depuración de esta rama (`./gradlew assembleDebug`, o el artefacto del CI cuando exista un PR).
3. Opcional, medición independiente de batería: activar *Depuración inalámbrica*, emparejar con `adb pair` y lanzar `tools/prueba_camara/adb_medir.sh` **sin cable USB** (el cable carga el teléfono y falsea el consumo).

## Procedimiento
1. Laptop: elegir la red correcta y **Crear estudio**.
2. Teléfono: **Cámara remota → Escanear QR** (anotar si pide el permiso de cámara en ese momento). Laptop: **Aceptar**.
3. Teléfono: **Iniciar envío** (anotar si pide el micrófono). Anotar el codificador que muestra «Calidad» y si figura como hardware o software.
4. Laptop: seleccionar la cámara → **Poner al aire**. Confirmar el aviso **AL AIRE** en el teléfono.
5. **Corrida de 30 min** sin tocar nada. Al terminar: **Registro CSV** → `docs/resultados/T1-<fecha>-<modelo>-A.csv`.
6. **Latencia vidrio a vidrio**, 10 fotos: cronómetro frente a la cámara; una segunda cámara fotografía a la vez el cronómetro real y el monitor del estudio. Anotar ambos tiempos en `lecturas.csv` (`real,monitor`) y correr `python -m tools.prueba_camara.latencia lecturas.csv`.
7. **Estabilidad** (cada caso 3 veces; anotar si vuelve sola, en cuántos segundos y cuántas fuentes quedan en el estudio; debe ser **una**):
   | Caso | Esperado |
   |------|----------|
   | Apagar y encender el Wi-Fi del teléfono | Vuelve sola ≤ 15 s, una fuente, conserva AL AIRE |
   | Alejarse hasta degradar la señal y volver | Baja la calidad y se recupera |
   | Apagar y encender el router | Vuelve sola |
   | Cerrar y abrir la app | Reconecta sin escanear |
   | Bloquear la pantalla 30 s | **Se espera que se interrumpa** (no hay servicio en primer plano): documentar qué pasa |
   | App en segundo plano 10 s | Igual: documentar |
   | Girar el teléfono 180° | El video no queda invertido |
   | **Crear estudio** de nuevo en la laptop | El teléfono pide vincular otra vez |
8. **Controles:** cambiar de lente, zoom mínimo/máximo, tocar para enfocar, exposición a ambos extremos, silenciar y activar el micrófono. Anotar qué funciona y qué no.
9. Repetir 1–5 en la corrida B (enchufado) → `…-B.csv`.

## Análisis
```bash
python -m tools.prueba_camara.analisis docs/resultados/T1-<fecha>-<modelo>-A.csv
```
Entrega fps (mediana y percentil 5), % de muestras a 1280×720, congelamientos, cortes del registro, RTT, búfer de jitter, codificador, batería en %/h, temperaturas y estado térmico, con un veredicto por criterio. **«Sin dato» nunca cuenta como cumplido.**

## Criterios de aceptación propuestos (deben confirmarse)
| Métrica | Criterio | Fuente |
|---------|----------|--------|
| Duración | ≥ 30 min continuos | CSV |
| FPS recibidos | mediana ≥ 27 | CSV `rx_fps` |
| Resolución | 1280×720 en ≥ 95 % de las muestras | CSV |
| Congelamientos | ≤ 3 | CSV `rx_congelamientos` |
| Cortes | 0 huecos > 6 s durante la corrida sin tocar | CSV |
| Latencia vidrio a vidrio | mediana ≤ 300 ms, p95 ≤ 500 ms | fotos |
| Temperatura | estado térmico ≤ 2 (sin «severo») | CSV / ADB |
| Consumo | **sin umbral aún**: registrar %/h y mAh | CSV / `batterystats` |
| Reconexión tras Wi-Fi apagado | sola ≤ 15 s, una sola fuente | manual |

Solo si se cumplen se sube `--max-cameras` y se prueba una segunda cámara repitiendo todo.

## Resultados (plantilla: `docs/resultados/T1-<fecha>-<modelo>.md`)
```
Teléfono: marca / modelo / SoC / Android       Laptop: modelo / SO / navegador / Java
Red: router / banda                            Sala: °C
Codificador: nombre (hardware | software)
Corrida A (desenchufado): [pegar la salida de tools.prueba_camara.analisis]
Corrida B (enchufado):    [pegar la salida]
Latencia vidrio a vidrio: mediana / p95 (n = 10)
Consumo ADB (batterystats): mAh de la app en 30 min
Estabilidad: caso → resultado → segundos → fuentes en el estudio
Controles: lente / zoom / enfoque / exposición / audio / orientación
Problemas encontrados:
Veredicto: ✅ | ❌ (motivo)          Ejecutó:            Fecha:
```

## Riesgos conocidos antes de empezar (hipótesis, sin confirmar)
1. La captura CameraX→WebRTC por textura nunca se ejecutó: puede fallar la orientación, el tamaño o no entregar cuadros.
2. El teléfono puede no ofrecer codificador H.264 por hardware; entonces WebRTC usa VP8 por software, con más consumo y temperatura.
3. Sin servicio en primer plano, bloquear la pantalla o cambiar de app corta la cámara.
4. Redes con aislamiento de clientes impiden la conexión; el cortafuegos de la laptop puede bloquear el puerto 8765.
5. Los relojes y contadores de batería (`corriente_uA`) cambian de signo y disponibilidad según el fabricante.
