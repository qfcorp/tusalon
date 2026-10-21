#!/usr/bin/env bash
# Regenera el manual de TuSalón: base de ejemplo nueva (docs/manual/generar/demo.php), capturas reales y el PDF.
#   bash docs/manual/generar/generar.sh
# Variables opcionales: PUERTO (8231), DB (tusalon_manual_prueba), DB_USER (tusalon), DB_PASS (prueba),
#                       SOLO_PDF=1 (no toma capturas, solo vuelve a armar el PDF con las que ya hay).
# Necesita: PostgreSQL, PHP, Python 3 con playwright y pypdf, pdftotext (poppler) y Chromium de Playwright
# (PLAYWRIGHT_BROWSERS_PATH, por defecto /opt/pw-browsers; nunca corre «playwright install»).
# Al final revisa el texto del manual y del PDF: si aparece la palabra prohibida o un precio del software, avisa y falla.
set -euo pipefail
AQUI=$(cd "$(dirname "$0")" && pwd)
MANUAL=$(cd "$AQUI/.." && pwd)
RAIZ=$(cd "$MANUAL/../.." && pwd)
PUERTO=${PUERTO:-8231}
export TUSALON_DB_NAME=${DB:-tusalon_manual_prueba} TUSALON_DB_USER=${DB_USER:-tusalon} TUSALON_DB_PASS=${DB_PASS:-prueba}
export TUSALON_URL="http://127.0.0.1:$PUERTO" TZ=America/Guayaquil PGTZ=America/Guayaquil PGPASSWORD=${DB_PASS:-prueba}
export PLAYWRIGHT_BROWSERS_PATH=${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}
PDF="$RAIZ/docs/Manual_TuSalon.pdf"
cd "$RAIZ"
if [ "${SOLO_PDF:-0}" != "1" ]; then
  case "$TUSALON_DB_NAME" in *prueba*|*manual*) ;; *) echo "Por seguridad la base debe llamarse *manual* o *prueba*"; exit 1;; esac
  TMPD=$(mktemp -d)
  export TUSALON_UPLOADS="$TMPD/uploads" TUSALON_BORRADOS="$TMPD/borrados"
  # Telegram «configurado» solo para que se vea el botón «Conectar mi Telegram» (no se manda nada: la API apunta a un puerto cerrado)
  export TELEGRAM_BOT_TOKEN=manual TELEGRAM_BOT_USERNAME=TuSalonBot TELEGRAM_API_BASE=http://127.0.0.1:9 GOOGLE_PLAY_ACTIVO=0
  echo "== 1. Base de ejemplo nueva ($TUSALON_DB_NAME)"
  psql -q -h 127.0.0.1 -U "$TUSALON_DB_USER" -d postgres -c "DROP DATABASE IF EXISTS $TUSALON_DB_NAME" -c "CREATE DATABASE $TUSALON_DB_NAME" 2>/dev/null
  psql -q -h 127.0.0.1 -U "$TUSALON_DB_USER" -d "$TUSALON_DB_NAME" -v ON_ERROR_STOP=1 -f db/schema.sql > /dev/null 2>&1
  php "$AQUI/demo.php" "$AQUI/imagenes" > "$TMPD/datos.env"
  echo "== 2. Programa en http://127.0.0.1:$PUERTO"
  php -S "127.0.0.1:$PUERTO" -t public tests/router.php > "$TMPD/servidor.log" 2>&1 &
  SERVIDOR=$!
  trap 'kill $SERVIDOR 2>/dev/null || true; psql -q -h 127.0.0.1 -U "$TUSALON_DB_USER" -d postgres -c "DROP DATABASE IF EXISTS $TUSALON_DB_NAME" 2>/dev/null || true; rm -rf "$TMPD"' EXIT
  for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PUERTO/?r=login" && break; sleep 0.3; done
  echo "== 3. Capturas"
  rm -f "$MANUAL"/capturas/*.png
  python3 "$AQUI/capturas.py" --base "http://127.0.0.1:$PUERTO" --salida "$MANUAL/capturas" --datos "$TMPD/datos.env"
  if grep -q "PHP Fatal\|PHP Warning\|PHP Deprecated\|PHP Notice" "$TMPD/servidor.log"; then
    echo "Errores de PHP durante las capturas:"; grep "PHP Fatal\|PHP Warning\|PHP Deprecated\|PHP Notice" "$TMPD/servidor.log" | cut -c1-300; exit 1
  fi
fi
echo "== 4. PDF del manual"
python3 "$AQUI/pdf.py" "$MANUAL/manual.html" "$PDF" "Manual de TuSalón"
pdfinfo "$PDF" 2>/dev/null | grep -E "Pages" || true
echo "== 5. Revisión: ni la palabra prohibida ni precios del software"
python3 "$AQUI/revisar.py" "$MANUAL/manual.html" "$PDF" "$RAIZ/docs/MANUAL.md"
