#!/usr/bin/env bash
# Corre las pruebas de pantallas de TuSalón contra sitios de prueba que levanta solo:
#   tests/prueba_web.sh (todo el sistema), tests/prueba_web_gastos_recepcion.sh (gastos con facturas del SRI, recepción y
#   clientes privados de quien alquila), tests/prueba_web_administradores.sh (administradores con el nivel del dueño),
#   tests/prueba_web_central.sh y tests/prueba_web_central_v2.sh (conector con el
#   Panel Tukán Central, protocolos v1 y v2), tests/prueba_web_papelera.sh (Anexo A: pausar y papelera) y
#   tests/prueba_web_modulos.sh (Anexo B: módulos y "todo se pide demo") y tests/prueba_web_ia.sh (IA de Tukán y correo de la demo, con la
#   API de Anthropic y el SMTP de mentira de tests/falsos/). Con CAPTURAS=<carpeta> también saca las capturas.
# Uso: PGPASSWORD=prueba bash tests/run_web.sh            (o: bash tests/run_web.sh central  para solo el conector)
# Requiere PostgreSQL local con el usuario tusalon (clave prueba) que pueda crear bases.
# Variables opcionales: DB (base de prueba, se borra), PUERTO (sitio principal, 8099), PUERTO_SIM y PUERTO_APAGADA (si no, puertos libres al azar).
set -u
export PGTZ=${PGTZ:-America/Guayaquil}   # la misma zona que la app
cd "$(dirname "$0")/.."
export DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
PUERTO=${PUERTO:-8099}
export BASE="http://127.0.0.1:$PUERTO"
TMPW=$(mktemp -d)
puerto_libre() { php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);'; }
esperar() { for _ in $(seq 1 50); do curl -s -o /dev/null "$1" && return 0; sleep 0.1; done; echo "No arrancó $1"; return 1; }

# Base limpia
psql -h 127.0.0.1 -U tusalon -d postgres -qc "DROP DATABASE IF EXISTS $DB" > /dev/null 2>&1
psql -h 127.0.0.1 -U tusalon -d postgres -qc "CREATE DATABASE $DB" > /dev/null || { echo "ERROR: no se pudo crear la base $DB"; exit 1; }
psql -h 127.0.0.1 -U tusalon -d "$DB" -q -v ON_ERROR_STOP=1 -f db/schema.sql > /dev/null || { echo "ERROR en schema.sql"; exit 1; }

# Payphone y Telegram simulados
export PP_MOCK_DIR="$TMPW/payphone" TG_MOCK_LOG="$TMPW/telegram.log"
mkdir -p "$PP_MOCK_DIR"
P_SIM=${PUERTO_SIM:-$(puerto_libre)}
php -S "127.0.0.1:$P_SIM" tests/simulador.php > "$TMPW/simulador.log" 2>&1 &
PIDS=$!

# Conector con el Panel Tukán Central: datos de prueba (el sitio y las pruebas los ven igual)
export TUKAN_CENTRAL_ID=tusalon-prueba
export TUKAN_CENTRAL_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
export TUKAN_CENTRAL_URL=http://central.prueba.local
export TUKAN_SOLO_CENTRAL=0
export TG_SECRET=secreto-web-prueba

# Sitios de prueba: principal (Google Play prendido) y otro con Google Play apagado
export TUSALON_BORRADOS="$TMPW/borrados" TUSALON_UPLOADS="$TMPW/uploads"   # copias de la papelera (prueba_web_papelera.sh)
SITIO="TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD TUSALON_URL=$BASE TUSALON_UPLOADS=$TMPW/uploads TUSALON_BORRADOS=$TMPW/borrados
       PAYPHONE_API_BASE=http://127.0.0.1:$P_SIM TELEGRAM_WEBHOOK_SECRET=$TG_SECRET SRI_WS_URL=http://127.0.0.1:$P_SIM/sri
       TWA_SHA256=39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC"
# IA de Tukán y correo: API de Anthropic y SMTP DE MENTIRA (tests/falsos/, de tukan-central/tests) solo para el sitio principal.
# El sitio con Google Play apagado queda sin /etc/tukan-ia.env ni /etc/tukan-correo.env (IA apagada, «Configure el correo de Tukán»).
P_IA=$(puerto_libre); P_SMTP=$(puerto_libre)
export IA_LOG="$TMPW/ia_api.jsonl" SMTP_DIR="$TMPW/correo"
python3 tests/falsos/anthropic_falso.py "$P_IA" "$IA_LOG" > /dev/null 2> "$TMPW/ia_falso.err" &
PIDS="$PIDS $!"
python3 tests/falsos/smtp_falso.py --puerto "$P_SMTP" --modo plano --dir "$SMTP_DIR" --usuario info@tukansoftware.com --clave clave-buzon-web > /dev/null 2> "$TMPW/smtp_falso.err" &
PIDS="$PIDS $!"
printf 'TUKAN_IA_CLAVE=sk-ant-prueba-buena-0123456789\nTUKAN_IA_URL=http://127.0.0.1:%s\nTUKAN_IA_ESPERA_MS=0\n' "$P_IA" > "$TMPW/ia.env"
printf 'TUKAN_SMTP_HOST=127.0.0.1\nTUKAN_SMTP_PUERTO=%s\nTUKAN_SMTP_SEGURIDAD=ninguna\nTUKAN_SMTP_USUARIO=info@tukansoftware.com\nTUKAN_SMTP_CLAVE=clave-buzon-web\nTUKAN_CORREO_DE=info@tukansoftware.com\n' "$P_SMTP" > "$TMPW/correo.env"
export IA_ENV_ARCHIVO="$TMPW/ia.env"
env $SITIO GOOGLE_PLAY_ACTIVO=1 TUKAN_IA_ENV="$TMPW/ia.env" TUKAN_CORREO_ENV="$TMPW/correo.env" php -S "127.0.0.1:$PUERTO" -t public tests/router.php > "$TMPW/servidor.log" 2>&1 &
PIDS="$PIDS $!"
P_APAGADA=${PUERTO_APAGADA:-$(puerto_libre)}
export BASE_APAGADA="http://127.0.0.1:$P_APAGADA"
env $SITIO TUKAN_IA_ENV="$TMPW/no_existe_ia.env" TUKAN_CORREO_ENV="$TMPW/no_existe_correo.env" php -S "127.0.0.1:$P_APAGADA" -t public tests/router.php > "$TMPW/servidor_apagado.log" 2>&1 &
PIDS="$PIDS $!"
trap 'kill $PIDS 2>/dev/null; rm -rf "$TMPW"' EXIT
esperar "$BASE/?r=login" && esperar "$BASE_APAGADA/?r=login" || exit 1

FALLO=0
if [ "${1:-}" != "central" ]; then
  echo "==================== prueba_web ===================="
  bash tests/prueba_web.sh || FALLO=1
fi
if [ "${1:-}" != "central" ]; then
  echo; echo "==================== prueba_web_gastos_recepcion ===================="
  bash tests/prueba_web_gastos_recepcion.sh || FALLO=1
  echo; echo "==================== prueba_web_administradores ===================="
  bash tests/prueba_web_administradores.sh || FALLO=1
  echo; echo "==================== prueba_web_ia (Ayuda, Asistente IA, preguntas frecuentes y correo de la demo) ===================="
  bash tests/prueba_web_ia.sh || FALLO=1
fi
echo; echo "==================== prueba_web_central ===================="
bash tests/prueba_web_central.sh || FALLO=1
echo; echo "==================== prueba_web_central_v2 ===================="
bash tests/prueba_web_central_v2.sh || FALLO=1
echo; echo "==================== prueba_web_papelera ===================="
bash tests/prueba_web_papelera.sh || FALLO=1
if [ "${1:-}" != "central" ]; then
  echo; echo "==================== prueba_web_modulos ===================="
  CAPTURAS_ENV="$TMPW/capturas.env" bash tests/prueba_web_modulos.sh || FALLO=1
  # Capturas de pantalla (Mi plan, "No está en tu plan" y los interruptores del panel) a 390 px y en computadora
  if [ -n "${CAPTURAS:-}" ] && [ -f "$TMPW/capturas.env" ]; then
    (set -a; . "$TMPW/capturas.env"; set +a; CARPETA="$CAPTURAS" node tests/capturas_modulos.js) || FALLO=1
  fi
fi

echo
if grep -qiE "fatal|warning|deprecated|notice" "$TMPW"/servidor*.log; then
  echo "AVISOS DE PHP en los sitios de prueba:"; grep -hiE "fatal|warning|deprecated|notice" "$TMPW"/servidor*.log | sort | uniq -c | head -30
  FALLO=1
else
  echo "Sin avisos de PHP en los sitios de prueba."
fi
[ "$FALLO" -eq 0 ] && echo "TODO BIEN" || echo "HAY FALLAS"
[ "$FALLO" -eq 0 ]
