#!/usr/bin/env bash
# Pantallas crudas del celular para la ficha de Google Play de TuSalón (las arma tukan-apps: tienda/tusalon/herramientas/armar_tienda_salon_spa.py).
# Levanta un TuSalón LOCAL con Google Play prendido (modo app: sin precios del software) y el salón de ejemplo del manual
# (docs/manual/generar/demo.php: datos inventados), saca las pantallas y borra la base.
# Uso: CAPTURAS=/carpeta PGPASSWORD=prueba bash tests/capturas_tienda.sh
# Variables opcionales: PUERTO (8419), DB (tusalon_gp_tienda_prueba), DB_USER (tusalon).
set -euo pipefail
cd "$(dirname "$0")/.."
CAPTURAS=${CAPTURAS:?Falta CAPTURAS=/carpeta}
PUERTO=${PUERTO:-8419}
export TUSALON_DB_NAME=${DB:-tusalon_gp_tienda_prueba} TUSALON_DB_USER=${DB_USER:-tusalon} TUSALON_DB_PASS=${PGPASSWORD:-prueba}
export PGPASSWORD=$TUSALON_DB_PASS TZ=America/Guayaquil PGTZ=America/Guayaquil
export PLAYWRIGHT_BROWSERS_PATH=${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}
case "$TUSALON_DB_NAME" in *prueba*) ;; *) echo "Por seguridad la base debe llamarse *prueba*"; exit 1;; esac
TMPD=$(mktemp -d)
export TUSALON_URL="http://127.0.0.1:$PUERTO" TUSALON_UPLOADS="$TMPD/uploads" TUSALON_BORRADOS="$TMPD/borrados"
export TELEGRAM_BOT_TOKEN=tienda TELEGRAM_BOT_USERNAME=TuSalonBot TELEGRAM_API_BASE=http://127.0.0.1:9 GOOGLE_PLAY_ACTIVO=1
export TUKAN_IA_ENV="$TMPD/sin_ia.env" TUKAN_CORREO_ENV="$TMPD/sin_correo.env"
psql -q -h 127.0.0.1 -U "$TUSALON_DB_USER" -d postgres -c "DROP DATABASE IF EXISTS $TUSALON_DB_NAME" -c "CREATE DATABASE $TUSALON_DB_NAME" 2>/dev/null
psql -q -h 127.0.0.1 -U "$TUSALON_DB_USER" -d "$TUSALON_DB_NAME" -v ON_ERROR_STOP=1 -f db/schema.sql > /dev/null 2>&1
php docs/manual/generar/demo.php docs/manual/generar/imagenes > "$TMPD/datos.env"
php -S "127.0.0.1:$PUERTO" -t public tests/router.php > "$TMPD/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null || true; psql -q -h 127.0.0.1 -U "$TUSALON_DB_USER" -d postgres -c "DROP DATABASE IF EXISTS $TUSALON_DB_NAME" 2>/dev/null || true; rm -rf "$TMPD"' EXIT
for _ in $(seq 1 30); do curl -s -o /dev/null "$TUSALON_URL/?r=login" && break; sleep 0.3; done
python3 tests/capturas_tienda.py "$TUSALON_URL" "$TMPD/datos.env" "$CAPTURAS"
if grep -q "PHP Fatal\|PHP Warning\|PHP Deprecated\|PHP Notice" "$TMPD/servidor.log"; then
  echo "Errores de PHP:"; grep "PHP Fatal\|PHP Warning\|PHP Deprecated\|PHP Notice" "$TMPD/servidor.log" | cut -c1-300; exit 1
fi
echo "Listo: $CAPTURAS/cap_es"
