#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="gds50.beget.tech"
REMOTE_USER="gds50_gds"
REMOTE_PLUGIN_DIR="~/tyumen.carsystem.su/public_html/wp-content/plugins/carsystem-regional-sync"

LOCAL_PLUGIN_DIR="plugin/carsystem-regional-sync/"

echo "==> Checking local plugin directory..."
if [ ! -d "$LOCAL_PLUGIN_DIR" ]; then
  echo "ERROR: Local plugin directory not found: $LOCAL_PLUGIN_DIR"
  exit 1
fi

echo "==> Creating remote plugin directory if needed..."
ssh "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p ${REMOTE_PLUGIN_DIR}"

echo "==> Deploying plugin to Beget..."
rsync -avz --delete \
  --exclude ".git" \
  --exclude ".DS_Store" \
  --exclude "*.log" \
  --exclude "node_modules" \
  --exclude "vendor" \
  "${LOCAL_PLUGIN_DIR}" \
  "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PLUGIN_DIR}"

echo "==> Deploy completed successfully."
