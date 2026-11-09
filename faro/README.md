# Faro de Luz Radio — sitio + panel

Sitio en HTML5 + CSS (plantillas Jinja) con un panel para publicar contenido y una Biblia incluida.
No usa WordPress ni Elementor. Es independiente de `main.py` (HR Media Tube).

## Ejecutar
```bash
pip install -r requirements-dev.txt
FARO_ADMIN_PASSWORD=una-clave-larga FARO_INSECURE_COOKIES=1 uvicorn faro.app:app --reload
```
Sitio: `/` · Panel: `/admin`

## Variables de entorno (no subir a git)
| Variable | Para qué |
|---|---|
| `FARO_ADMIN_PASSWORD` | Contraseña del panel. Sin ella el panel queda desactivado. |
| `FARO_SECRET` | Firma de sesiones (cadena larga y aleatoria). Si falta, las sesiones se cierran al reiniciar. |
| `FARO_DATA_DIR` | Carpeta de datos (SQLite + fotos subidas). **En Railway debe ser un Volume** (p. ej. `/data`) o se pierde todo en cada despliegue. |
| `FARO_INSECURE_COOKIES=1` | Solo en desarrollo local (http). No usar en producción. |

Arranque en producción: `uvicorn faro.app:app --host 0.0.0.0 --port $PORT`

## Qué se edita desde el panel
Noticias (con foto), mensajes del pastor (enlace de YouTube), horarios de la semana, versículos de la portada,
y ajustes (correo, dirección, teléfono, nombre del pastor, «Quiénes somos» y reproductor de la radio).
El teléfono y el reproductor no se muestran mientras estén vacíos.

## La Biblia
Reina-Valera 1909 (dominio público), guardada en `faro/data/biblia/` y servida por la propia web
(`/biblia`, con búsqueda). Regenerar con `faro/tools/construir_biblia.py`.
Fuente: scrollmapper/bible_databases (MIT). **RVR1960 no se incluye**: tiene derechos de autor (Sociedades Bíblicas Unidas).

## Copias de seguridad
Respaldar `FARO_DATA_DIR` completo (`faro.db` y `uploads/`).
