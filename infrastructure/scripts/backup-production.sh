#!/usr/bin/env bash

set -Eeuo pipefail

umask 077

app_dir="${APP_DIR:-/opt/breviews}"
backup_dir="${BACKUP_DIR:-${app_dir}/backups/daily}"
retention_days="${BACKUP_RETENTION_DAYS:-14}"
api_storage_volume="${API_STORAGE_VOLUME:-revieworbit_api_storage}"
compose=(docker compose --env-file "${app_dir}/.env.production" -f "${app_dir}/docker-compose.production.yml")
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
database_path="${backup_dir}/breviews-${timestamp}.dump"
media_path="${backup_dir}/breviews-media-${timestamp}.tar.gz"

mkdir -p "${backup_dir}"
exec 9>"${backup_dir}/.backup.lock"

if ! flock -n 9; then
    echo "A Breviews backup is already running."
    exit 0
fi

cleanup() {
    rm -f "${database_path}.tmp" "${media_path}.tmp"
}
trap cleanup EXIT

"${compose[@]}" exec -T postgres sh -c \
    'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner' \
    > "${database_path}.tmp"

"${compose[@]}" exec -T postgres pg_restore --list < "${database_path}.tmp" >/dev/null

docker run --rm \
    -v "${api_storage_volume}:/source:ro" \
    alpine:3.22 \
    sh -c 'cd /source && tar czf - .' \
    > "${media_path}.tmp"

tar tzf "${media_path}.tmp" >/dev/null

mv "${database_path}.tmp" "${database_path}"
mv "${media_path}.tmp" "${media_path}"
sha256sum "${database_path}" "${media_path}" > "${backup_dir}/breviews-${timestamp}.sha256"

find "${backup_dir}" -type f \
    \( -name 'breviews-*.dump' -o -name 'breviews-media-*.tar.gz' -o -name 'breviews-*.sha256' \) \
    -mtime "+${retention_days}" -delete

echo "Breviews backup completed at ${timestamp}."
