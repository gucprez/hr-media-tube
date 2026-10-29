#!/usr/bin/env bash
# Auditoría de SOLO LECTURA del VPS. No instala, no modifica ni reinicia nada.
# Uso:  bash audit-vps.sh | tee audit.txt     (revisa el resultado antes de compartirlo)
# No imprime contenido de archivos de configuración ni variables de entorno (evita filtrar secretos).
set -u
sec() { printf '\n===== %s =====\n' "$1"; }
sec "Sistema"; . /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"; uname -r; uptime -p
sec "Recursos"; echo "CPU: $(nproc)"; free -h | sed -n '1,2p'; df -h / /var 2>/dev/null | sort -u
sec "Puertos en escucha"; (ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null) | awk 'NR==1 || /LISTEN/' | sed 's/users:.*//'
sec "Servicios activos"; systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '{print $1}' | head -40
sec "Docker"; if command -v docker >/dev/null; then docker --version; docker ps --format '{{.Names}}\t{{.Image}}\t{{.Ports}}' 2>&1 | head -30; else echo "docker: no instalado"; fi
sec "Herramientas"; for t in node npm python3 pip3 psql redis-server nginx caddy certbot git ufw fail2ban; do printf '%-14s' "$t"; if command -v "$t" >/dev/null; then ("$t" --version 2>&1 || true) | head -1; else echo "no"; fi; done
sec "Proxy inverso / sitios (solo nombres de archivo)"; ls /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/caddy 2>/dev/null
sec "Certificados (dominio y vencimiento)"; for c in /etc/letsencrypt/live/*/cert.pem; do [ -f "$c" ] && echo "$(basename "$(dirname "$c")"): $(openssl x509 -enddate -noout -in "$c" 2>/dev/null)"; done
sec "Firewall"; (ufw status 2>/dev/null || iptables -S 2>/dev/null | head -15) | head -20
sec "Cron del usuario actual"; crontab -l 2>/dev/null | grep -v '^#' | head -10
sec "Fin"; echo "Revisa que no haya datos sensibles antes de compartir."
