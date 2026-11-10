# Estudio HR Media Sports (laptop)

Aplicación de escritorio del estudio: un servidor local pequeño + una interfaz que se abre en tu navegador (Chrome recomendado). **Sin cuentas, sin nube, sin servidores de pago.** Funciona en la misma red local; los teléfonos envían su video directamente a esta laptop.

## Por qué servidor local + navegador
Chrome ya trae WebRTC (recepción, decodificación por hardware y reproducción) en Windows, macOS y Linux, sin librerías nativas extra ni licencias adicionales. La alternativa (ventana nativa) obligaría a incorporar una librería WebRTC distinta por sistema operativo, que no se ha evaluado. Si el equipo prefiere una ventana nativa, se puede empaquetar esta misma interfaz en un contenedor de escritorio sin cambiar el protocolo.

## Requisitos
- Java 17 o superior (JRE).
- Chrome, Edge o Firefox reciente (probado solo con Chromium).
- La laptop y los teléfonos en la misma red Wi-Fi/Ethernet. En algunas redes (invitados, hoteles, oficinas) el aislamiento de clientes bloquea la conexión entre dispositivos: usa un router propio o un punto de acceso del teléfono.

## Ejecutar
```bash
cd android
./gradlew :studio-server:installDist
studio-server/build/install/hr-studio/bin/hr-studio        # Windows: hr-studio.bat
```
Abre `http://localhost:8765`. Opciones: `--port 9000`, `--no-browser`, `--max-cameras N` (por defecto **1** hasta validar; ver `docs/PRUEBAS-TECNICAS.md`).

Al abrir por primera vez, Windows o macOS pueden preguntar si permites conexiones entrantes a Java: hay que permitirlas **solo en redes privadas** para que los teléfonos lleguen al puerto del estudio.

## Uso
1. **Crear estudio**: elige la red (IP) y se muestra el QR de vinculación (vence en 5 min). Volver a pulsarlo genera una credencial nueva y olvida los dispositivos.
2. En el teléfono: app → *Cámara remota* → *Escanear QR*.
3. El teléfono aparece como **solicitud**: **Aceptar** o **Rechazar**.
4. Una cámara aceptada es una **fuente**. Seleccionarla la prepara en **Vista previa**; **Poner al aire** la envía a la salida y avisa al teléfono (AL AIRE). Son pasos separados.
5. **Desconectar** olvida el dispositivo. **Registro CSV** descarga las métricas para la validación.

## Costos
Software: $0 (todo código abierto). Infraestructura: ninguna (no hay nube, servidor TURN ni cuentas). El único «costo» es tener una red local compartida.

## Seguridad
Solo la propia laptop puede usar la interfaz y su API (se comprueban dirección, `Host` y `Origin`). Los dispositivos solo llegan a `/ws/device`. Detalle y límites en `docs/protocolo-conexion.md`.

## Pruebas
```bash
./gradlew :studio-server:test                                  # reglas y servidor
node studio-server/e2e/e2e.cjs studio-server/build/install/hr-studio/bin/hr-studio 8799   # extremo a extremo (necesita Playwright y Chromium)
```
La prueba extremo a extremo usa una cámara simulada: **no** valida teléfonos reales.
