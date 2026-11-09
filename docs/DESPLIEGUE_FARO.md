# Poner Faro de Luz en línea (Railway)

La base de datos **no se crea a mano**. Es un archivo SQLite (`faro.db`) que la app crea sola la primera vez
que arranca, con los horarios, versículos y textos iniciales. Solo hay que darle una carpeta que no se borre (un Volume).

## 1. Crear el servicio
1. En Railway: **New Project → Deploy from GitHub repo → `gucprez/hr-media-tube`**.
2. En el servicio: **Settings → Source → Branch** = `claude/jolly-feynman-5o4df6` (o `main` cuando se fusione).
3. **Settings → Deploy → Custom Start Command**:
   ```
   uvicorn faro.app:app --host 0.0.0.0 --port $PORT
   ```
   (El `Procfile.txt` del repo arranca otra app, HR Media Tube. Por eso el comando se pone aquí.)

## 2. Volume (donde vive la base de datos y las fotos)
1. En el proyecto: **Create → Volume** y conéctalo al servicio.
2. **Mount path:** `/data`

## 3. Variables (Settings → Variables)
| Variable | Valor |
|---|---|
| `FARO_DATA_DIR` | `/data` |
| `FARO_ADMIN_PASSWORD` | la contraseña que tú elijas (larga; no la compartas) |
| `FARO_SECRET` | una cadena larga al azar (genera una con el comando de abajo) |

Generar `FARO_SECRET` (en tu computadora):
```bash
python3 -c "import secrets; print(secrets.token_urlsafe(48))"
```
**No** pongas `FARO_INSECURE_COOKIES` en producción.

## 4. Dirección temporal y primera prueba
1. **Settings → Networking → Generate Domain**. Railway te da una dirección `https://….up.railway.app`.
2. Abre esa dirección: debe verse la web. La base de datos ya se creó en `/data/faro.db`.
3. Abre `/admin`, entra con tu contraseña y publica una noticia de prueba.
4. Haz un nuevo despliegue (**Redeploy**) y comprueba que la noticia sigue ahí. Si desaparece, el Volume no está bien montado en `/data`.

## 5. Llenar el contenido (en `/admin`)
- **Ajustes:** teléfono (solo cuando esté confirmado), nombre del pastor, «Quiénes somos».
  Deja vacío el reproductor hasta que la radio transmita de verdad.
- **Horarios:** la hora de cada actividad.
- **Mensajes del pastor:** enlace de YouTube de cada video.
- **Noticias:** con foto opcional.

## 6. Dominio `farodeluzradio.com` (solo cuando tú lo autorices)
Antes de tocar el dominio, haz una copia de seguridad del sitio actual (archivos y base de datos desde cPanel).
Luego: en Railway **Settings → Networking → Custom Domain** y crea en el DNS el registro que Railway indique.
No hay que borrar WordPress para probar: mientras no cambies el DNS, el sitio viejo sigue publicado.

## Copias de seguridad
Todo lo que se publica está en `/data` (`faro.db` + carpeta `uploads/`). Respáldalo con regularidad.
Revisa en Railway qué copias de Volume ofrece tu plan.

## Probar en tu computadora (opcional)
```bash
pip install -r requirements-dev.txt
FARO_ADMIN_PASSWORD=prueba FARO_INSECURE_COOKIES=1 uvicorn faro.app:app --reload
```
Abre http://localhost:8000 y http://localhost:8000/admin
