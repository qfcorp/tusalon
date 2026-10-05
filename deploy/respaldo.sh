#!/usr/bin/env bash
# Respaldo diario de TuSalón: base de datos + fotos. Guarda 14 días.
# Cron (todos los días a las 3:15 de la mañana):
#   15 3 * * * /var/www/tusalon/deploy/respaldo.sh >> /var/log/tusalon-respaldo.log 2>&1
set -euo pipefail
umask 077   # solo el dueño del servidor puede leer los respaldos

APP="${APP:-/var/www/tusalon}"
DESTINO="${DESTINO:-/var/backups/tusalon}"
DIAS="${DIAS:-14}"

# Lee los datos de conexión de config/.env
set -a; . <(grep -E '^TUSALON_DB_(HOST|NAME|USER|PASS)=' "$APP/config/.env"); set +a
export PGPASSWORD="${TUSALON_DB_PASS}"

FECHA=$(date +%Y-%m-%d_%H%M)
mkdir -p "$DESTINO"
chmod 700 "$DESTINO"

pg_dump -h "${TUSALON_DB_HOST:-127.0.0.1}" -U "$TUSALON_DB_USER" -Fc "$TUSALON_DB_NAME" > "$DESTINO/base_$FECHA.dump"
if [ -d "$APP/uploads" ]; then
  tar -czf "$DESTINO/fotos_$FECHA.tar.gz" -C "$APP" uploads
fi

# Verifica que el respaldo se pueda leer
pg_restore --list "$DESTINO/base_$FECHA.dump" > /dev/null

# Borra respaldos de más de $DIAS días
find "$DESTINO" -name 'base_*.dump' -mtime +"$DIAS" -delete
find "$DESTINO" -name 'fotos_*.tar.gz' -mtime +"$DIAS" -delete

echo "$(date '+%F %T') respaldo OK: $(du -h "$DESTINO/base_$FECHA.dump" | cut -f1)"
