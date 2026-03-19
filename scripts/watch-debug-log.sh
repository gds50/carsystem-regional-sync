#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="gds50.beget.tech"
REMOTE_USER="gds50_gds"
REMOTE_LOG_FILE="~/tyumen.carsystem.su/public_html/wp-content/debug.log"

echo "==> Watching remote debug.log..."
echo "==> Host: ${REMOTE_USER}@${REMOTE_HOST}"
echo "==> File: ${REMOTE_LOG_FILE}"

ssh "${REMOTE_USER}@${REMOTE_HOST}" "touch ${REMOTE_LOG_FILE} && tail -f ${REMOTE_LOG_FILE}"
