#!/usr/bin/env bash
# Corre las pruebas de lógica de TuSalón (tests/run_tests.php y tests/prueba_central.php) con Telegram simulado.
# Uso: bash tests/run_logica.sh
# Variables opcionales: TUSALON_DB_NAME (debe contener "prueba"; se borra), TUSALON_DB_USER, TUSALON_DB_PASS.
# Telegram simulado en el puerto 8098 (el que usan las pruebas de run_tests.php).
set -u
cd "$(dirname "$0")/.."
export TUSALON_DB_NAME=${TUSALON_DB_NAME:-tusalon_prueba} TUSALON_DB_USER=${TUSALON_DB_USER:-tusalon} TUSALON_DB_PASS=${TUSALON_DB_PASS:-prueba}
TMPL=$(mktemp -d)
export TG_MOCK_LOG="$TMPL/telegram.log" TELEGRAM_API_BASE=http://127.0.0.1:8098 TELEGRAM_BOT_TOKEN=123:prueba TELEGRAM_BOT_USERNAME=TuSalonPruebaBot
: > "$TG_MOCK_LOG"
if curl -s -o /dev/null http://127.0.0.1:8098/; then echo "ERROR: el puerto 8098 ya está ocupado (¿otro simulador de Telegram?). Ciérralo y vuelve a correr."; exit 1; fi
php -S 127.0.0.1:8098 tests/simulador.php > "$TMPL/simulador.log" 2>&1 &
SIM=$!
trap 'kill $SIM 2>/dev/null; rm -rf "$TMPL"' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null http://127.0.0.1:8098/ && break; sleep 0.1; done
FALLO=0
php tests/run_tests.php || FALLO=1
echo
echo "==================== prueba_central ===================="
php tests/prueba_central.php || FALLO=1
[ "$FALLO" -eq 0 ] && echo "TODO BIEN" || echo "HAY FALLAS"
[ "$FALLO" -eq 0 ]
