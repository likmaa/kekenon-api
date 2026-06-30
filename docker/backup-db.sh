#!/bin/bash
# Database backup script for Kêkênon (INFRA-04)
# Usage (sur la machine hôte, depuis la racine backend) : ./docker/backup-db.sh
#
# --- Cron sur le serveur reel (exemple : tous les jours a 3h) ---
# crontab -e
# 0 3 * * * cd /chemin/vers/backend && /usr/bin/env bash ./docker/backup-db.sh >> /var/log/kekenon-backup.log 2>&1
#
# Verifier CONTAINER_NAME ci-dessous (docker ps) : souvent <projet>-mysql-1
#
# --- Upload S3 optionnel (apres generation du fichier) : decommenter et adapter ---
# if command -v aws >/dev/null 2>&1 && [ -n "${BACKUP_S3_BUCKET:-}" ]; then
#   aws s3 cp "${BACKUP_DIR}/${FILENAME}" "s3://${BACKUP_S3_BUCKET}/db/${FILENAME}"
# fi

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${SCRIPT_DIR}/backups"
RETENTION_DAYS=30
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

source "${BACKEND_DIR}/.env" 2>/dev/null || true

DB_NAME="${DB_DATABASE:-kekenon}"
DB_USER="${DB_USERNAME:-root}"
DB_PASS="${DB_PASSWORD:-}"
CONTAINER_NAME="backend-mysql-1"

mkdir -p "$BACKUP_DIR"

FILENAME="backup_${DB_NAME}_${TIMESTAMP}.sql.gz"

echo "[$(date)] Starting backup of ${DB_NAME}..."

docker exec "$CONTAINER_NAME" mysqldump \
    -u"$DB_USER" \
    -p"$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_NAME" | gzip > "${BACKUP_DIR}/${FILENAME}"

SIZE=$(du -h "${BACKUP_DIR}/${FILENAME}" | cut -f1)
echo "[$(date)] Backup completed: ${FILENAME} (${SIZE})"

# Cleanup old backups
find "$BACKUP_DIR" -name "backup_*.sql.gz" -mtime +$RETENTION_DAYS -delete
REMAINING=$(find "$BACKUP_DIR" -name "backup_*.sql.gz" | wc -l)
echo "[$(date)] Retained ${REMAINING} backups (${RETENTION_DAYS} days retention)"
