#!/usr/bin/env bash
# Actualiza HR Media System (código nuevo, dependencias, migraciones y cachés). Uso: deploy/deploy.sh
# Lo ejecuta el DUEÑO del servidor, con el usuario de la app (NO root). No toca nada fuera de la carpeta del proyecto.
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"
[ "$(id -u)" -eq 0 ] && { echo "No ejecute este script como root: use el usuario de la app."; exit 1; }
[ -f .env ] || { echo "Falta .env (copie deploy/env.production.example)"; exit 1; }

php artisan down --retry=30 || true
trap 'php artisan up || true' EXIT

git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
if command -v npm >/dev/null 2>&1; then
    npm ci && npm run build
else
    [ -d public/build ] || { echo "No hay Node en el servidor y falta public/build: compile en su equipo (npm run build) y suba public/build."; exit 1; }
fi
php artisan migrate --force
php artisan permissions:sync
php artisan storage:link 2>/dev/null || true
php artisan optimize:clear && php artisan optimize
php artisan inventory:verify || echo "AVISO: inventory:verify encontró diferencias, revise antes de operar."
php artisan fuel:verify || echo "AVISO: fuel:verify encontró diferencias, revise antes de operar."
echo "Actualización completada."
