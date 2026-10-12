# Despliegue en el VPS — guía para el dueño del servidor

> **Regla de oro:** esta guía solo crea cosas **nuevas** (un sitio de Nginx propio, un pool de PHP-FPM propio, una base de datos propia y una carpeta propia).
> No modifica ni detiene ninguna aplicación existente. Ningún paso requiere reiniciar el VPS, tocar el firewall global ni actualizar paquetes del sistema.
> Cada comando que cambia algo fuera de la carpeta del proyecto lo ejecuta **usted** (o lo autoriza expresamente). Nunca comparta contraseñas ni llaves en chats: se escriben solo en el `.env` del servidor.

## 0. Antes de empezar

| Dato | Valor |
|------|-------|
| Dominio previsto | `hr.deportesxtras.com` — **compruebe la ortografía**: sus otros sitios usan `deportesxtra.com` (sin la «s»). Si es un error de escritura, use `hr.deportesxtra.com`. El kit funciona con cualquier dominio: se cambia en un solo sitio (`__DOMINIO__`). |
| DNS | Crear un registro **A** `hr` → `54.39.98.84` (la IP del VPS) en el proveedor del dominio y esperar a que propague. Sin esto no habrá HTTPS. |
| Puertos | La app **no abre puertos propios**: usa 80/443 compartidos por `server_name` y PHP-FPM por socket Unix. No reutiliza 3001, 3002, 3004, 3005, 8010, 8020, 8090, 8100, 8443, 5555, 5556, 8001, 8002. |
| Requisitos | Nginx, PHP 8.2+ (fpm, mbstring, xml, curl, zip, gd, intl, mysql, bcmath), MariaDB 10.6+/MySQL 8, Composer 2, Git. Node 20+ **solo para compilar** (si no hay Node, compile en su equipo y suba `public/build`). |

Verifique primero qué hay instalado (solo lectura): `php -v`, `nginx -v`, `mysql --version`, `composer --version`, `node -v`.

## 1. Usuario y carpeta propios (sin root)
```bash
sudo adduser --system --group --home /var/www/hrmedia hrmedia      # usuario sin privilegios
sudo -u hrmedia git clone -b claude/carmen-cargo-warehouse-system-0jift5 https://github.com/gucprez/hr-media-tube.git /var/www/hrmedia/app
```
(Si prefiere otra ruta, úsela en todos los pasos.) La aplicación **nunca** corre como root.

## 2. Base de datos propia
```sql
CREATE DATABASE hr_media_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hrmedia'@'localhost' IDENTIFIED BY '<contraseña-fuerte-solo-para-esta-app>';
GRANT ALL ON hr_media_system.* TO 'hrmedia'@'localhost';
```
No toca ninguna otra base de datos.

## 3. Código y configuración
```bash
cd /var/www/hrmedia/app
sudo -u hrmedia cp deploy/env.production.example .env        # edite: APP_URL, DB_PASSWORD, SUPERADMIN_*
sudo -u hrmedia composer install --no-dev --optimize-autoloader
sudo -u hrmedia npm ci && sudo -u hrmedia npm run build       # o suba public/build compilado en su equipo
sudo -u hrmedia php artisan key:generate
sudo -u hrmedia php artisan migrate --force --seed            # permisos + super administrador
sudo -u hrmedia php artisan storage:link
sudo -u hrmedia php artisan optimize
chmod 640 .env
```
**No** ejecute `DemoSeeder` en producción (crea usuarios de demostración con contraseña conocida).

## 4. PHP-FPM propio y Nginx nuevo
1. `deploy/php-fpm-hrmedia.conf.example` → `/etc/php/<versión>/fpm/pool.d/hrmedia.conf` (reemplace `__APP_DIR__`), luego `sudo php-fpm<versión> -t && sudo systemctl reload php<versión>-fpm`. Solo añade un pool; los existentes no cambian.
2. `deploy/nginx-hrmedia.conf.example` → `/etc/nginx/sites-available/hrmedia.conf` (reemplace `__DOMINIO__` y `__APP_DIR__` por `/var/www/hrmedia/app`), enlazar en `sites-enabled`, **`sudo nginx -t`** y solo si dice *successful*: `sudo systemctl reload nginx`. Es un archivo nuevo: no se edita ningún sitio existente.
3. HTTPS: `sudo certbot --nginx -d <dominio>` (necesita el DNS del paso 0). Después ponga `SESSION_SECURE_COOKIE=true` y vuelva a ejecutar `php artisan config:cache`.

## 5. Tareas programadas y respaldos
`deploy/cron.example` (crontab del usuario `hrmedia`): scheduler cada minuto (verifica integridad de inventario y gasoil cada noche) y `deploy/backup.sh` diario. El respaldo guarda base de datos + `storage/app/public` (logo) + `storage/app/private` (fotos y documentos) y rota a 14 días. **Copie los respaldos fuera del servidor** (rsync/rclone). Pruebe una restauración antes de operar.

## 6. Prueba de humo
```bash
deploy/smoke-test.sh https://<dominio>
```
Comprueba login, PWA, protección de rutas privadas, que `.env`/`composer.json` no se sirvan y las cabeceras de seguridad.

## 7. Primer acceso
1. Entre con el super administrador de `.env` y **cambie la contraseña**.
2. *Plataforma → Empresas → Nueva empresa* (Carmen Cargo Express): crea roles, sucursal, almacén y numeraciones.
3. *Configuración → Empresa*: suba el **logo real** (el sistema nunca inventa uno), datos fiscales y colores.
4. Cree los usuarios y asigne roles. Cargue categorías, unidades y productos; registre la **entrada inicial** y la **reserva inicial de gasoil** (ajuste de gasoil con motivo).
5. Instale la app en el teléfono: Chrome/Edge → «Instalar aplicación» (requiere HTTPS).

## 8. Actualizar a una versión nueva
```bash
sudo -u hrmedia /var/www/hrmedia/app/deploy/deploy.sh
```
Modo mantenimiento durante la actualización, `git pull`, dependencias, compilación, migraciones, `permissions:sync`, cachés y verificación de integridad.

## 9. Seguridad aplicada (resumen)
- Cabeceras: CSP estricta (sin scripts en línea), HSTS bajo HTTPS, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`. Ajustable con `CSP_MODE=enforce|report|off`.
- Archivos privados (fotos, documentos) fuera de `public/`, servidos solo con sesión + permiso + empresa.
- Límite de intentos de login, límite de peticiones en búsqueda y exportaciones, CSRF en todos los formularios, contraseñas con bcrypt.
- Service worker: solo guarda estáticos públicos; **nunca** datos de la empresa.
- Detalle completo en `docs/FASES_3_A_7.md`.
