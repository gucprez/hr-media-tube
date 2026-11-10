# HR Media Tube

App FastAPI desplegada en Railway.

```bash
pip install -r requirements.txt
uvicorn main:app --reload
```

## Colaboración con IAs
Este repo está pensado para que **Claude** y **ChatGPT** trabajen juntos.
Lee [`AGENTS.md`](AGENTS.md) (reglas) y [`docs/COLABORACION.md`](docs/COLABORACION.md) (flujo).

## HR Media Sports (multiplataforma)
App de producción de básquetbol en desarrollo, de código abierto. **Hoy solo existe una interfaz de demostración para Android** (Kotlin + Compose); no hay todavía compatibilidad demostrada con Windows, macOS ni Chrome, ni cámaras, OCR, transmisión, grabación o repeticiones.
- Alcance, capas y servicios puente: [`docs/PLATAFORMAS.md`](docs/PLATAFORMAS.md)
- Funciones verificadas por plataforma (hoy: ninguna en dispositivo): [`docs/MATRIZ-FUNCIONES.md`](docs/MATRIZ-FUNCIONES.md)
- Protocolo de pizarra v1: [`docs/protocolo-pizarra.md`](docs/protocolo-pizarra.md)
- Licencias de dependencias: [`docs/LICENCIAS.md`](docs/LICENCIAS.md)
- Procedimiento de pruebas técnicas: [`docs/PRUEBAS-TECNICAS.md`](docs/PRUEBAS-TECNICAS.md)
- Licencia: Apache-2.0 ([`LICENSE`](LICENSE))
- App Android (demo de interfaz) e instrucciones de APK: [`android/README.md`](android/README.md)
- Contenido para descansos (entrega 1: interfaz, biblioteca y listas; sin reproducción real): [`docs/CONTENIDO-DESCANSOS.md`](docs/CONTENIDO-DESCANSOS.md)
