# Despliegue en VPS Linux (Ubuntu 22.04/24.04)

## Requisitos
Nginx, PHP 8.2+ (fpm, mbstring, xml, curl, zip, gd, intl, mysql, bcmath), MariaDB 10.6+/MySQL 8, Composer 2, Node 20+ (solo para compilar), Git.

## 1. Base de datos
```sql
CREATE DATABASE hr_media_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hrmedia'@'localhost' IDENTIFIED BY '<contraseña-fuerte>';
GRANT ALL ON hr_media_system.* TO 'hrmedia'@'localhost';
```

## 2. Código
```bash
cd /var/www && git clone <repo> hrmedia && cd hrmedia
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
# Edite .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://su-dominio, DB_*, SUPERADMIN_*
php artisan migrate --force --seed
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 3. Permisos
```bash
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \; ; find storage bootstrap/cache -type f -exec chmod 664 {} \;
chmod 640 .env
```
El *document root* de Nginx es `public/` (nunca la raíz del proyecto).

## 4. Nginx + HTTPS
Ver `deploy/nginx.conf.example`; `certbot --nginx -d su-dominio`. Con HTTPS ponga `SESSION_SECURE_COOKIE=true`.
Límites de subida: `client_max_body_size 20M;` y `upload_max_filesize = 20M` / `post_max_size = 25M` en PHP.

## 5. Scheduler y colas
```
* * * * * cd /var/www/hrmedia && php artisan schedule:run >> /dev/null 2>&1
```
Si usa colas (`QUEUE_CONNECTION=database`), ejecute `php artisan queue:work` con Supervisor/systemd.

## 6. Respaldos
`deploy/backup.sh` genera dump de BD + `storage/app/public` comprimidos con rotación (14 días). Cron: `0 2 * * * /var/www/hrmedia/deploy/backup.sh`. Copie los respaldos fuera del servidor (rclone/rsync).

## 7. Actualizar
```bash
git pull && composer install --no-dev -o && npm ci && npm run build
php artisan migrate --force && php artisan permissions:sync && php artisan optimize
```
`permissions:sync` agrega permisos nuevos al catálogo; los roles existentes no se sobrescriben salvo `--reset-roles`.

## 8. Nueva empresa cliente
Super administrador → **Plataforma → Empresas → Nueva empresa**. Se crean roles, sucursal, almacén y numeraciones. No requiere reprogramar.
