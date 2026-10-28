#!/usr/bin/env bash
# Corre las pruebas de lógica de TuSalón (tests/run_tests.php, tests/prueba_central.php, tests/prueba_central_v2.php y
# tests/prueba_gastos_recepcion.php, tests/prueba_papelera.php, tests/prueba_modulos.php, tests/prueba_administradores.php, tests/prueba_ia.php y tests/prueba_google_play.php) con Telegram y el SRI simulados
# (prueba_ia levanta sola la API de Anthropic y el SMTP de mentira de tests/falsos/ en puertos libres y los apaga al terminar).
# Uso: bash tests/run_logica.sh
# Variables opcionales: TUSALON_DB_NAME (debe contener "prueba"; se borra), TUSALON_DB_USER, TUSALON_DB_PASS.
# Telegram y SRI simulados en el puerto 8098 (se cambia con TG_PUERTO=...); prueba_ia usa PUERTO_IA y PUERTO_SMTP si se dan (si no, libres al azar).
set -u
export PGTZ=${PGTZ:-America/Guayaquil}   # la misma zona que la app (las fechas de psql y de PHP coinciden)
cd "$(dirname "$0")/.."
export TUSALON_DB_NAME=${TUSALON_DB_NAME:-tusalon_prueba} TUSALON_DB_USER=${TUSALON_DB_USER:-tusalon} TUSALON_DB_PASS=${TUSALON_DB_PASS:-prueba}
TMPL=$(mktemp -d)
TG_PUERTO=${TG_PUERTO:-8098}
export TG_MOCK_LOG="$TMPL/telegram.log" TELEGRAM_API_BASE=http://127.0.0.1:$TG_PUERTO TELEGRAM_BOT_TOKEN=123:prueba TELEGRAM_BOT_USERNAME=TuSalonPruebaBot
export SRI_WS_URL=http://127.0.0.1:$TG_PUERTO/sri SRI_MOCK_LOG="$TMPL/sri.log"   # SRI simulado (mismo simulador)
: > "$TG_MOCK_LOG"
if curl -s -o /dev/null http://127.0.0.1:$TG_PUERTO/; then echo "ERROR: el puerto $TG_PUERTO ya está ocupado (¿otro simulador de Telegram?). Ciérralo y vuelve a correr."; exit 1; fi
php -S 127.0.0.1:$TG_PUERTO tests/simulador.php > "$TMPL/simulador.log" 2>&1 &
SIM=$!
trap 'kill $SIM 2>/dev/null; rm -rf "$TMPL"' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null http://127.0.0.1:$TG_PUERTO/ && break; sleep 0.1; done
FALLO=0
php tests/run_tests.php || FALLO=1
echo
echo "==================== prueba_central ===================="
php tests/prueba_central.php || FALLO=1
echo
echo "==================== prueba_central_v2 ===================="
php tests/prueba_central_v2.php || FALLO=1
echo
echo "==================== prueba_gastos_recepcion ===================="
php tests/prueba_gastos_recepcion.php || FALLO=1
echo
echo "==================== prueba_papelera ===================="
php tests/prueba_papelera.php || FALLO=1
echo
echo "==================== prueba_modulos ===================="
php tests/prueba_modulos.php || FALLO=1
echo
echo "==================== prueba_administradores ===================="
php tests/prueba_administradores.php || FALLO=1
echo
echo "==================== prueba_ia (Ayuda, Asistente IA, ia_uso/ia_tope y correo de la demo; API y SMTP de mentira) ===================="
php tests/prueba_ia.php || FALLO=1
echo
echo "==================== prueba_google_play (planes de \$20 y \$45 en Google, assetlinks y cuenta del revisor) ===================="
php tests/prueba_google_play.php || FALLO=1
[ "$FALLO" -eq 0 ] && echo "TODO BIEN" || echo "HAY FALLAS"
[ "$FALLO" -eq 0 ]
