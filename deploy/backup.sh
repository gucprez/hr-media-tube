#!/usr/bin/env bash
# Respaldo de base de datos y archivos. Uso: ./deploy/backup.sh  (cron diario)
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/../hrmedia-backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

env_get() { grep -E "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"'; }
DB_NAME="$(env_get DB_DATABASE)"; DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"; DB_HOST="$(env_get DB_HOST)"

MYSQL_PWD="$DB_PASS" mysqldump -h "${DB_HOST:-127.0.0.1}" -u "$DB_USER" --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip > "$BACKUP_DIR/db_$STAMP.sql.gz"
tar -czf "$BACKUP_DIR/files_$STAMP.tar.gz" -C "$APP_DIR/storage/app" public private
find "$BACKUP_DIR" -type f -mtime +"$KEEP_DAYS" -delete
echo "Respaldo completado: $BACKUP_DIR ($STAMP)"
