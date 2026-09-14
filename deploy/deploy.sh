#!/usr/bin/env bash
# Deploy / update AR Excursiones on a fresh or existing VPS.
# Run as a user with sudo (e.g. `ubuntu`): sudo bash deploy/deploy.sh
#
# Safe to re-run: it only touches its own app dir, its own systemd service
# (ar-excursiones.service) and its own venv. It does not touch other
# projects, PM2 processes, or other nginx sites on the same server.

set -euo pipefail

REPO_URL="https://github.com/gucprez/hr-media-tube.git"
BRANCH="claude/tour-agency-website-r09lv1"
APP_USER="elcorito"
APP_DIR="/home/${APP_USER}/ar-excursiones"
APP_PORT="8010"
SERVICE_NAME="ar-excursiones"

if [ "$EUID" -ne 0 ]; then
  echo "Run this with sudo: sudo bash deploy/deploy.sh"
  exit 1
fi

echo "==> Installing system dependencies (python3-venv, git)"
apt-get update -qq
apt-get install -y -qq python3-venv python3-pip git

echo "==> Fetching code to ${APP_DIR}"
if [ -d "${APP_DIR}/.git" ]; then
  sudo -u "${APP_USER}" git -C "${APP_DIR}" fetch origin
  sudo -u "${APP_USER}" git -C "${APP_DIR}" checkout "${BRANCH}"
  sudo -u "${APP_USER}" git -C "${APP_DIR}" pull origin "${BRANCH}"
else
  sudo -u "${APP_USER}" git clone --branch "${BRANCH}" "${REPO_URL}" "${APP_DIR}"
fi

echo "==> Setting up Python virtualenv"
sudo -u "${APP_USER}" python3 -m venv "${APP_DIR}/.venv"
sudo -u "${APP_USER}" "${APP_DIR}/.venv/bin/pip" install --quiet --upgrade pip
sudo -u "${APP_USER}" "${APP_DIR}/.venv/bin/pip" install --quiet -r "${APP_DIR}/requirements.txt"

echo "==> Installing systemd service"
sed \
  -e "s#__APP_USER__#${APP_USER}#g" \
  -e "s#__APP_DIR__#${APP_DIR}#g" \
  -e "s#__APP_PORT__#${APP_PORT}#g" \
  "${APP_DIR}/deploy/ar-excursiones.service.template" > "/etc/systemd/system/${SERVICE_NAME}.service"

systemctl daemon-reload
systemctl enable --now "${SERVICE_NAME}"
systemctl restart "${SERVICE_NAME}"

echo ""
echo "==> Done. Service status:"
systemctl --no-pager status "${SERVICE_NAME}" || true
echo ""
echo "App is running on 127.0.0.1:${APP_PORT} (not public yet)."
echo "Next step once you have a domain: see deploy/nginx.conf.template"
echo "  (proxies your domain -> 127.0.0.1:${APP_PORT}, then run certbot for HTTPS)"
