#!/usr/bin/env bash
# App de Google Play (28 oct 2026), de punta a punta por HTTP (lo corre tests/run_web.sh):
#   - Cuenta del revisor de Google (deploy/demo_google.php crear | borrar): salón de ejemplo aparte, contraseña al azar que sale
#     una vez, entra y recorre el sistema, «crear» otra vez cambia la contraseña, «borrar» lo quita sin tocar los demás salones
#   - Modo app (?app=play): sin precios del software ni pagos por fuera (WhatsApp, transferencia, cotización); solo Google Play
#     con los 6 códigos tusalon_basica_* y tusalon_completa_*; demo vencida y «No está en tu plan» sin botones para pagar afuera
#   - assetlinks.json con el paquete com.tukansoftware.tusalon y la huella de la llave de subida (y 404 con Google Play apagado)
# Uso suelto: BASE=http://127.0.0.1:8099 BASE_APAGADA=http://127.0.0.1:8100 DB=tusalon_web_prueba bash tests/prueba_web_google_play.sh
set -u
cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export TZ=America/Guayaquil
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
JAR="$TMP/cookies"
OK=0; FALLAS=0
DBENV="TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD"
sql() { psql -h 127.0.0.1 -U tusalon -d "$DB" -tAc "$1"; }
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -qi -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
entrar() { # correo clave [app] -> sesión nueva en JAR; deja en R "código destino" (sin subshell: el JAR nuevo queda)
  JAR="$TMP/c_$RANDOM$RANDOM"
  get "r=login${3:+&app=play}" > /dev/null
  R=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$(csrf)" \
       --data-urlencode "email=$1" --data-urlencode "clave=$2" "$BASE/?r=login")
}
WA_VENTAS=$(sql "SELECT valor FROM ajustes WHERE clave='whatsapp_ventas'"); WA_VENTAS=${WA_VENTAS:-593996408397}
demo() { env $DBENV php deploy/demo_google.php "$@"; }
# Lo que nunca debe verse dentro de la app (precios del software y pagos por fuera de Google)
sin_pagos_afuera() { # nombre archivo
  no_contiene "$1: sin WhatsApp de ventas para pagar el plan" "$2" "wa.me/$WA_VENTAS?text="
  no_contiene "$1: sin «Pedir cotización»" "$2" "Pedir cotización"
  no_contiene "$1: sin «Contratar»/«Renovar» por fuera" "$2" ">Contratar<\|>Renovar<"
  no_contiene "$1: sin «Total al mes» del plan" "$2" "Total al mes"
  no_contiene "$1: sin pagar por transferencia o DeUna el plan" "$2" "Pagas por transferencia"
  no_contiene "$1: sin PayPal ni Payphone para el plan" "$2" "paypal"
}

echo "1. Cuenta del revisor de Google: crear"
OTROS_ANTES=$(sql "SELECT count(*) FROM salones")
CITAS_OTROS=$(sql "SELECT count(*) FROM citas")
demo crear > "$TMP/crear.txt" 2>&1
check "demo_google.php crear termina bien" "0" "$?"
contiene "Muestra el usuario del revisor" "$TMP/crear.txt" "Usuario:    revisor.google@tukanec.com"
contiene "Muestra la contraseña (una sola vez)" "$TMP/crear.txt" "Contrasena: Revisor-"
no_contiene "Los mensajes van sin tildes (SSH desde el celular)" "$TMP/crear.txt" "[áéíóúñ]"
CLAVE1=$(grep -o 'Revisor-[0-9]\{6\}' "$TMP/crear.txt" | head -1)
SID=$(sql "SELECT salon_id FROM usuarios WHERE email='revisor.google@tukanec.com'")
check "Un salón nuevo y aparte" "$((OTROS_ANTES + 1))" "$(sql "SELECT count(*) FROM salones")"
check "El revisor es el dueño de ese salón" "dueno" "$(sql "SELECT rol FROM usuarios WHERE email='revisor.google@tukanec.com'")"
check "Es el «Salón de Ejemplo Tukán», activo con plan Completa" "Salón de Ejemplo Tukán|activo|completa" "$(sql "SELECT nombre||'|'||estado||'|'||plan_codigo FROM salones WHERE id=$SID")"
check "Sin pagos de suscripción (no es un cliente)" "0" "$(sql "SELECT count(*) FROM suscripciones WHERE salon_id=$SID")"
check "La contraseña no se guarda en claro" "0" "$(sql "SELECT count(*) FROM usuarios WHERE salon_id=$SID AND password_hash LIKE '%$CLAVE1%'")"
check "Trae clientes de ejemplo" "12" "$(sql "SELECT count(*) FROM clientes WHERE salon_id=$SID")"
check "Trae equipo (los 4 tipos)" "4" "$(sql "SELECT count(DISTINCT tipo) FROM profesionales WHERE salon_id=$SID")"
check "Trae citas cobradas" "1" "$(sql "SELECT (count(*) > 20)::int FROM ventas WHERE salon_id=$SID")"
check "Trae citas por atender (hoy o mañana)" "1" "$(sql "SELECT (count(*) > 0)::int FROM citas WHERE salon_id=$SID AND inicio > now()")"
check "No toca las citas de los demás salones" "$CITAS_OTROS" "$(sql "SELECT count(*) FROM citas WHERE salon_id <> $SID")"

echo "2. El revisor entra (en la app) y recorre el sistema"
entrar revisor.google@tukanec.com "$CLAVE1" app; r=$R
check "Entra con la contraseña que dio el servidor" "302" "${r%% *}"
for ruta in inicio agenda clientes servicios equipo caja cuentas reporte inventario paquetes ayuda completa; do
  check "Abre r=$ruta" "200" "$(get "r=$ruta")"
done
get "r=completa" > /dev/null
contiene "Mi plan en la app: pago con Google Play" "$TMP/pag.html" 'id="pago-play"'
for p in basica completa; do for per in mensual semestral anual; do
  contiene "… ofrece tusalon_${p}_${per}" "$TMP/pag.html" "data-producto=\"tusalon_${p}_${per}\""
done; done
sin_pagos_afuera "Mi plan (app)" "$TMP/pag.html"
for ruta in inicio ayuda equipo; do get "r=$ruta" > /dev/null; sin_pagos_afuera "r=$ruta (app)" "$TMP/pag.html"; done
entrar revisor.google@tukanec.com "claveEquivocada1"; r=$R
check "Con otra contraseña no entra" "200" "${r%% *}"

echo "3. Modo app en otros salones: demo vencida y «No está en tu plan» sin pagos por fuera"
EMAIL_B="basica$RANDOM@prueba.ec"
env $DBENV php tests/demo_web.php "Barbería Básica GP" "Dueño Básica" 0990004444 "$EMAIL_B" claveSegura1 basica "" pagado > /dev/null
entrar "$EMAIL_B" claveSegura1 app > /dev/null
get "r=inventario" > /dev/null
contiene "Módulo apagado: «No está en tu plan»" "$TMP/pag.html" "No está en tu plan"
sin_pagos_afuera "No está en tu plan (app)" "$TMP/pag.html"
no_contiene "… sin el formulario para pedirlo" "$TMP/pag.html" 'value="pedir"'
get "r=completa" > /dev/null
sin_pagos_afuera "Mi plan Básica (app)" "$TMP/pag.html"
no_contiene "Mi plan (app): sin precios de los módulos" "$TMP/pag.html" "al mes</"
# La misma persona en el navegador (sin la app) sí ve la cotización y el WhatsApp
JAR_WEB="$TMP/c_web"; JAR=$JAR_WEB
get "r=login" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf=$(csrf)" --data-urlencode "email=$EMAIL_B" -d clave=claveSegura1 "$BASE/?r=login"
get "r=completa" > /dev/null
contiene "En la web: Mi plan con el WhatsApp de Tukán" "$TMP/pag.html" "wa.me/$WA_VENTAS?text="
no_contiene "En la web: sin el pago de Google" "$TMP/pag.html" 'id="pago-play"'
EMAIL_V="vencida$RANDOM@prueba.ec"
env $DBENV php tests/demo_web.php "Peluquería Vencida GP" "Dueña Vencida" 0990005555 "$EMAIL_V" claveSegura1 completa > /dev/null
sql "UPDATE salones SET prueba_hasta = CURRENT_DATE - 3 WHERE id = (SELECT salon_id FROM usuarios WHERE email='$EMAIL_V')" > /dev/null
entrar "$EMAIL_V" claveSegura1 app > /dev/null
get "r=prueba_terminada" > /dev/null
contiene "Demo vencida en la app: «Tu demo terminó»" "$TMP/pag.html" "Tu demo terminó"
sin_pagos_afuera "Demo vencida (app)" "$TMP/pag.html"

echo "4. assetlinks.json"
code=$(curl -s -D "$TMP/cab.txt" -o "$TMP/al.json" -w '%{http_code}' "$BASE/.well-known/assetlinks.json")
check "Responde 200" "200" "$code"
contiene "Es JSON" "$TMP/cab.txt" "Content-Type: application/json"
check "Paquete com.tukansoftware.tusalon y la huella de la llave de subida" \
  "com.tukansoftware.tusalon|39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC|delegate_permission/common.handle_all_urls" \
  "$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j[0]["target"]["package_name"], "|", implode(",", $j[0]["target"]["sha256_cert_fingerprints"]), "|", $j[0]["relation"][0];' "$TMP/al.json")"
if [ -n "${BASE_APAGADA:-}" ]; then
  check "Con Google Play apagado: 404" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_APAGADA/.well-known/assetlinks.json")"
fi
check "Privacidad pública con cómo borrar la cuenta" "200" "$(curl -s -o "$TMP/priv.html" -w '%{http_code}' "$BASE/?r=privacidad")"
contiene "… «Borrar mi cuenta de TuSalón»" "$TMP/priv.html" "Borrar mi cuenta de TuSalón"
contiene "… y menciona la app de Android" "$TMP/priv.html" "app de Android"

echo "5. Volver a crear cambia la contraseña; borrar quita el salón de ejemplo y nada más"
demo crear > "$TMP/crear2.txt" 2>&1
CLAVE2=$(grep -o 'Revisor-[0-9]\{6\}' "$TMP/crear2.txt" | head -1)
SID2=$(sql "SELECT salon_id FROM usuarios WHERE email='revisor.google@tukanec.com'")
check "Sigue habiendo un solo salón del revisor" "1" "$(sql "SELECT count(*) FROM usuarios WHERE email='revisor.google@tukanec.com'")"
check "El salón anterior se borró" "0" "$(sql "SELECT count(*) FROM salones WHERE id=$SID")"
if [ "$CLAVE1" != "$CLAVE2" ]; then
  entrar revisor.google@tukanec.com "$CLAVE1"; r=$R; check "La contraseña vieja ya no sirve" "200" "${r%% *}"
fi
entrar revisor.google@tukanec.com "$CLAVE2"; r=$R; check "La nueva sí" "302" "${r%% *}"
ANTES=$(sql "SELECT count(*) FROM salones WHERE id <> $SID2")
CITAS_OTROS=$(sql "SELECT count(*) FROM citas WHERE salon_id <> $SID2")
demo borrar > "$TMP/borrar.txt" 2>&1
check "demo_google.php borrar termina bien" "0" "$?"
contiene "Avisa que se borró" "$TMP/borrar.txt" "se borro el SALON DE EJEMPLO"
check "Ya no está el usuario del revisor" "0" "$(sql "SELECT count(*) FROM usuarios WHERE email='revisor.google@tukanec.com'")"
check "Ni su salón" "0" "$(sql "SELECT count(*) FROM salones WHERE id=$SID2")"
check "Los demás salones siguen" "$ANTES" "$(sql "SELECT count(*) FROM salones")"
check "Y sus citas también" "$CITAS_OTROS" "$(sql "SELECT count(*) FROM citas")"
entrar revisor.google@tukanec.com "$CLAVE2"; r=$R; check "El revisor ya no entra" "200" "${r%% *}"
demo borrar > /dev/null 2>&1; check "Borrar otra vez no falla" "0" "$?"
demo > /dev/null 2>&1; check "Sin acción: muestra el uso y sale con error" "1" "$?"

echo
echo "=================================================="
echo "Resultado: $OK correctas, $FALLAS fallidas"
[ "$FALLAS" -eq 0 ]
