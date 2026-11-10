# Arquitectura para Windows, macOS y Chrome

**Estado: propuesta sin validar.** El servidor local con navegador es una solución **para la prueba**; todavía **no fija la arquitectura final**. La compatibilidad con Windows, macOS y Chromebook sigue **sin comprobar**. Ninguna opción de este documento se ha ejecutado en Windows, macOS ni Chrome de escritorio. Debe leerse antes de ampliar la prueba técnica de cámara remota.

## Punto de partida (lo que hay y lo que no demuestra)
- La interfaz de demostración está escrita en **Kotlin + Jetpack Compose para Android**. **No corre en Windows, macOS ni Chrome y no demuestra compatibilidad con ellos.** Jetpack Compose de Android usa APIs de Android (Activity, recursos, vistas); portarla exige otra tecnología o una migración.
- Lo único reutilizable hoy fuera de Android es código sin dependencias de Android:
  | Código | Lenguaje | Dónde podría reutilizarse |
  |--------|----------|---------------------------|
  | Reglas del partido (`domain`) | Kotlin puro | JVM (Windows/macOS). En el navegador solo si se compila con Kotlin Multiplatform o se reescribe |
  | Protocolo de conexión (`core-link`) | Kotlin/JVM + especificación | JVM; en el navegador ya existe una implementación JavaScript (solo en la prueba e2e) |
  | Especificaciones (`protocolo-conexion.md`, `protocolo-pizarra.md`) | Texto | Cualquier plataforma |
- El estudio de laptop de esta rama **no es la interfaz Compose**: es un servidor JVM + una interfaz web aparte, más simple, hecha para la prueba técnica.

## Qué necesita cada plataforma
| Necesidad | Android | Windows / macOS | Chrome / PWA / Chromebook |
|-----------|---------|-----------------|---------------------------|
| Interfaz | Compose (existe, demo) | Por construir | Por construir (la interfaz web de prueba es un punto de partida) |
| Recibir video WebRTC | libwebrtc (incorporado, sin ejecutar) | Navegador, o librería nativa por evaluar | Nativo del navegador |
| Enviar cámara | CameraX + libwebrtc (sin ejecutar) | Por evaluar | `getUserMedia` (por evaluar) |
| Aceptar conexiones de la red local | Sí (socket) | Sí (proceso local) | **No**: una página no puede abrir un puerto; necesita un proceso local o un servidor |
| Componer, grabar, transmitir | Por evaluar (T4, T5) | Por evaluar | Con límites por evaluar (T6) |
| Instalación | APK | Ejecutable + runtime | Ninguna (pestaña) o PWA |

## Opciones
### A. Servidor local (JVM) + interfaz web en el navegador — *la usada en la prueba técnica*
- **Cómo:** un proceso en la laptop hace la señalización y sirve la interfaz; Chrome recibe y reproduce el video.
- **A favor:** un solo código para Windows y macOS; WebRTC, decodificación y reproducción ya vienen en Chrome; sin librerías nativas por sistema; probado de forma automática en Linux.
- **En contra / sin verificar:** requiere Java 17 instalado (o empaquetarlo con `jpackage`); no es una ventana nativa; cortafuegos de Windows/macOS; componer, grabar y transmitir quedarían dentro del navegador, con los límites de T6; pestañas en segundo plano pueden limitarse; **en un Chromebook no hay JVM** salvo con el entorno Linux, así que un Chromebook como estudio no está resuelto.
- **Dato del desarrollo:** Chromium bloqueó a una página de otro origen que intentaba conectarse a `127.0.0.1` (comprobaciones de acceso a red local). Con la interfaz servida desde el propio `localhost` no ocurre, pero descarta alojar la interfaz en otro sitio sin más.

### B. Aplicación nativa de escritorio con Compose Multiplatform
- **Cómo:** mover la interfaz a módulos compartidos y compilar para JVM de escritorio.
- **A favor:** reutilizaría reglas, protocolo y parte de la interfaz Compose; ventana nativa.
- **En contra / sin verificar:** la interfaz actual usa APIs solo de Android (hay que migrarla); recibir WebRTC en la JVM de escritorio necesita una librería nativa por sistema operativo cuya madurez y licencia **no se han evaluado**; no cubre Chrome.

### C. Flutter (la base propuesta en el cambio de alcance)
- **Cómo:** una sola base para Android, Windows, macOS y Web con un plugin de WebRTC.
- **A favor:** una interfaz para todas las plataformas objetivo.
- **En contra / sin verificar:** obliga a reescribir la interfaz y las reglas en Dart; la integración con los motores audiovisuales es justo lo que debe validar **T8**; licencias de los plugins por revisar.

### D. Solo web (PWA) sin proceso local
- **Cómo:** todo en el navegador.
- **En contra:** una página no puede escuchar en la red local, así que la señalización necesita un servidor (en la laptop o en internet). Sin él no cumple «misma red, sin nube». Solo sería viable con un servidor de señalización, que es la opción A con otro nombre.

## Cómo encaja con las capas
```
[Android: Cámara remota]──WebRTC (video, DTLS-SRTP)──▶[Receptor de video: Chrome (A) | librería nativa (B) | plugin (C)]
        │                                                        ▲
        └──ws:// señalización firmada (protocolo-conexion.md)──▶[Proceso local del estudio: vinculación, dispositivos]
                                                                 │
                                         [Interfaz del estudio: web (A) | Compose Desktop (B) | Flutter (C)]
```
El protocolo y la vinculación son los mismos en las tres opciones: cambiar de opción no obliga a cambiar la app Android.

## Recomendación (provisional)
1. **Para la prueba técnica de una cámara: opción A**, porque es la única ya implementada y comprobable, y es la que menos supuestos añade.
2. **No decidir la arquitectura del producto todavía.** Depende de mediciones que no existen:
   | Decisión | Prueba que la resuelve |
   |----------|------------------------|
   | ¿Chrome recibe 720p/30 estable en Windows y macOS? | T1 (esta prueba técnica) |
   | ¿El navegador puede componer, grabar y transmitir? | T4, T5, T6 |
   | ¿Flutter integra los motores sin código nativo propio? | T8 |
   | ¿Hace falta ventana nativa (opción B)? | Tras T5/T6, si el navegador no alcanza |
3. **Antes de ampliar** (segunda cámara, pizarra, grabación): ejecutar T1 en Windows **y** en macOS y registrar el resultado. Hasta entonces, todo lo de Windows, macOS y Chrome en la matriz sigue en «no verificado».

## Qué habría que construir en cada opción (estimación cualitativa, sin medir)
| | A (servidor + web) | B (Compose Desktop) | C (Flutter) |
|---|---|---|---|
| Interfaz de estudio completa | Rehacer en web la interfaz demo | Migrar la interfaz Compose | Reescribir en Dart |
| Video | Ya resuelto por el navegador | Evaluar e integrar librería nativa | Validar plugin (T8) |
| Reglas del partido | Reescribir en JS o compilar con KMP | Reutilizar | Reescribir en Dart |
| Empaquetado | `jpackage` o instalador con JRE | Instalador nativo | Instaladores de Flutter |
| Chromebook | Sin resolver | No aplica | Web |
