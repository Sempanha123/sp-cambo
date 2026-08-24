#!/bin/sh
set -eu
umask 077

: "${BACKUP_DIR:?Set BACKUP_DIR}"
: "${DB_DATABASE:?Set DB_DATABASE}"
: "${DB_USERNAME:?Set DB_USERNAME}"
: "${DB_PASSWORD:?Set DB_PASSWORD}"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP_DIR"
work_dir="$(mktemp -d "$BACKUP_DIR/.backup-$stamp.XXXXXX")"
trap 'rm -rf "$work_dir"' EXIT HUP INT TERM

sql="$work_dir/mysql-$stamp.sql"
sql_gz="$work_dir/mysql-$stamp.sql.gz"
omniroute="$work_dir/omniroute-$stamp.tar.gz"
checksums="$work_dir/checksums-$stamp.sha256"

# Write the producer output to a private temporary file. This preserves the
# mysqldump exit status on POSIX sh instead of letting a successful gzip hide it.
docker compose exec -T \
  -e "SP_BACKUP_DATABASE=$DB_DATABASE" \
  -e "SP_BACKUP_USERNAME=$DB_USERNAME" \
  -e "SP_BACKUP_PASSWORD=$DB_PASSWORD" \
  mysql sh -eu -c 'exec mysqldump --single-transaction --routines --triggers --no-tablespaces -u"$SP_BACKUP_USERNAME" -p"$SP_BACKUP_PASSWORD" "$SP_BACKUP_DATABASE"' \
  > "$sql"

test -s "$sql"
# A valid logical dump contains SQL statements; reject empty/warning-only files.
grep -Eq '^(--|/\*!|CREATE |INSERT |LOCK TABLES|SET )' "$sql"
gzip -9 -c "$sql" > "$sql_gz"
test -s "$sql_gz"
gzip -t "$sql_gz"

docker run --rm -v sp-cambo_omniroute-data:/source:ro -v "$work_dir":/backup alpine:3.22 \
  tar -C /source -czf "/backup/omniroute-$stamp.tar.gz" .
test -s "$omniroute"
gzip -t "$omniroute"

(
  cd "$work_dir"
  sha256sum "mysql-$stamp.sql.gz" "omniroute-$stamp.tar.gz" > "checksums-$stamp.sha256"
  sha256sum -c "checksums-$stamp.sha256"
)

# Trigger Redis persistence only after durable database/volume artifacts exist.
docker compose exec -T redis redis-cli BGSAVE >/dev/null

mv "$sql_gz" "$BACKUP_DIR/mysql-$stamp.sql.gz"
mv "$omniroute" "$BACKUP_DIR/omniroute-$stamp.tar.gz"
mv "$checksums" "$BACKUP_DIR/checksums-$stamp.sha256"
rm -f "$sql"

# Prune only after every new artifact was validated and atomically published.
find "$BACKUP_DIR" -maxdepth 1 -type f -mtime +14 -delete
