# Desplegar AR Excursiones en el VPS

## Primera vez

En el VPS, como usuario con sudo (ej: `ubuntu`):

```bash
git clone --branch claude/tour-agency-website-r09lv1 https://github.com/gucprez/hr-media-tube.git /tmp/hr-media-tube-bootstrap
sudo bash /tmp/hr-media-tube-bootstrap/deploy/deploy.sh
```

Esto:
- Instala Python/venv si hace falta
- Clona el proyecto en `/home/elcorito/ar-excursiones`
- Crea un entorno virtual e instala dependencias
- Crea y arranca el servicio `ar-excursiones` con systemd, escuchando en
  `127.0.0.1:8010` (todavía no público — no toca nginx ni otros proyectos)

## Cuando tengas el dominio

1. Copia `deploy/nginx.conf.template` a `/etc/nginx/sites-available/ar-excursiones`,
   reemplaza `__DOMAIN__` y `__APP_PORT__` (8010).
2. `sudo ln -s /etc/nginx/sites-available/ar-excursiones /etc/nginx/sites-enabled/`
3. `sudo nginx -t && sudo systemctl reload nginx`
4. `sudo certbot --nginx -d tu-dominio.com` (HTTPS gratis)

## Actualizar después de cambios nuevos

```bash
sudo bash /home/elcorito/ar-excursiones/deploy/deploy.sh
```

Es el mismo script — vuelve a bajar el código y reinicia el servicio.

## Notas

- La base de datos (SQLite) vive en `/home/elcorito/ar-excursiones/data/app.db`.
  Persiste entre despliegues porque no se borra el directorio, solo se
  actualiza el código con `git pull`.
- Las imágenes subidas desde el panel `/admin` quedan en
  `/home/elcorito/ar-excursiones/static/uploads/` — tampoco se borran.
- Usuario/contraseña del panel admin por defecto: `Yismellir` / `Flautica08*`
  (definidos en `admin.py`, puedes sobreescribirlos con variables de entorno
  `ADMIN_USERNAME` / `ADMIN_PASSWORD` en el archivo de systemd).
- Este script no toca `mery-whatsapp` (PM2) ni el sitio de
  `vpn.deportesxtra.com` — usa su propio directorio, servicio y puerto.
