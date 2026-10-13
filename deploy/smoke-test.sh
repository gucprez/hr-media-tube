#!/usr/bin/env bash
# Prueba rápida tras instalar/actualizar. Uso: deploy/smoke-test.sh https://hr.deportesxtra.com
set -uo pipefail
BASE="${1:?Indique la URL base, p. ej. https://hr.deportesxtra.com}"
fail=0
check() { # descripción, comando
    if eval "$2" >/dev/null 2>&1; then echo "OK   $1"; else echo "FALLA $1"; fail=1; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$BASE$1"; }
hdr()  { curl -s -D - -o /dev/null "$BASE$1" | tr -d '\r'; }

check "login responde 200"                       '[ "$(code /login)" = 200 ]'
check "página sin conexión (PWA) 200"            '[ "$(code /offline)" = 200 ]'
check "manifest.webmanifest 200"                 '[ "$(code /manifest.webmanifest)" = 200 ]'
check "service worker 200"                       '[ "$(code /sw.js)" = 200 ]'
check "dashboard exige sesión (302/401)"         '[ "$(code /dashboard)" = 302 ] || [ "$(code /dashboard)" = 401 ]'
check "reportes exigen sesión"                   '[ "$(code /reportes)" = 302 ]'
check "archivos privados no son públicos"        '[ "$(code /storage/private)" != 200 ]'
check ".env no se sirve"                         '[ "$(code /.env)" != 200 ]'
check "composer.json no se sirve"                '[ "$(code /composer.json)" != 200 ]'
check "cabecera Content-Security-Policy"         'hdr /login | grep -qi "^content-security-policy:"'
check "cabecera X-Content-Type-Options"          'hdr /login | grep -qi "^x-content-type-options: nosniff"'
case "$BASE" in https://*) check "HSTS activo" 'hdr /login | grep -qi "^strict-transport-security:"';; esac
check "sin versión de servidor expuesta"         '! hdr /login | grep -qiE "^x-powered-by:"'
[ $fail -eq 0 ] && echo "Todo correcto." || { echo "Hay comprobaciones fallidas."; exit 1; }
