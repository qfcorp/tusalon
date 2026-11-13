#!/usr/bin/env bash
# Demos automáticas de 3 días (src/TukanDemos.php, 10 nov 2026), de punta a punta por HTTP. Lo corre tests/run_web.sh:
#   BASE       = sitio de siempre (su Panel Tukán Central no existe: «el Panel no contesta»)
#   BASE_PAGO  = el mismo programa conectado al Panel SIMULADO (tests/panel_simulado.php, en PANEL_SIM; sus archivos en PANEL_SIM_DIR)
# Cubre: formulario «Solicitar demo» con los 6 datos obligatorios y un solo teléfono (rechazo campo por campo, correo malo, se conserva lo escrito),
# pedido completo guardado y visible para el Panel con las claves, aviso al instante (y que sin Panel igual se guarda y sale la
# página de gracias), /manual.pdf y /primeros_pasos.pdf públicos, la tarjeta de la demo solo en cuentas demo y los botones en Ayuda.
set -u
cd "$(dirname "$0")/.."
PRE=TUSALON; TABLA=salones; NEG=salon; NEGOCIO="Salón"   # lo propio de este programa (NEG = columna del nombre del negocio en pedidos_demo)
BASE0=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export TZ=America/Guayaquil
if [ -z "${BASE_PAGO:-}" ] || [ -z "${PANEL_SIM:-}" ] || [ -z "${PANEL_SIM_DIR:-}" ]; then echo "Faltan BASE_PAGO, PANEL_SIM y PANEL_SIM_DIR (corre: bash tests/run_web.sh demos)"; exit 1; fi
TMP=$(mktemp -d)
JAR="$TMP/cookies"
OK=0; FALLAS=0
BASE=$BASE0
U=$(echo "$PRE" | tr 'A-Z' 'a-z')
IP="203.0.113.$((RANDOM % 200 + 20))"   # una dirección propia: el formulario acepta pocos pedidos por hora de la misma
sql() { psql -h 127.0.0.1 -U "$U" -d "$DB" -tAc "$1"; }
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -qi -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -b "$JAR" -c "$JAR" -H "CF-Connecting-IP: $IP" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local ruta=$1; shift; get "$ruta" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi
  curl -s -b "$JAR" -c "$JAR" -H "CF-Connecting-IP: $IP" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
demo() { env ${PRE}_DB_NAME=$DB ${PRE}_DB_USER=$U ${PRE}_DB_PASS=$PGPASSWORD php tests/demo_web.php "$1" "Dueña Demo" 0990005555 "$2" claveSegura1 "$3" "${4:-}" "${5:-}"; }
entrar() { JAR="$TMP/c_$RANDOM$RANDOM"; get "r=login" > /dev/null
  curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf=$(csrf)" --data-urlencode "email=$1" -d clave=claveSegura1 "$BASE/?r=login"; }
# Los 6 datos buenos; pedir CAMPO=valor … cambia o vacía alguno
pedir() {
  local -A d=([nombre]="Ana Pérez" [nombre_comercial]="$NEGOCIO de Ana $N" [celular]="0991234567" [direccion]="Av. Amazonas N34-12 y Colón" [ciudad]="Quito" [email]="$CORREO")
  local kv a=(); for kv in "$@"; do d[${kv%%=*}]=${kv#*=}; done
  for kv in "${!d[@]}"; do a+=(--data-urlencode "$kv=${d[$kv]}"); done
  post "r=registro" "${a[@]}"
}
N=$RANDOM; CORREO="demoauto$N@prueba.ec"
PLANCOM=$(sql "SELECT codigo FROM planes ORDER BY precio_mensual DESC LIMIT 1")
P0=$(sql "SELECT count(*) FROM pedidos_demo")

echo "1. El formulario «Solicitar demo» pide los 6 datos (un solo teléfono: Celular / WhatsApp)"
JAR="$TMP/c_publico"
check "El formulario abre sin sesión" "200" "$(get "r=registro")"
contiene "Aviso: datos reales o no se prepara la demo (13 nov 2026)" "$TMP/pag.html" "data-aviso-datos-reales"
contiene "… con el texto completo" "$TMP/pag.html" "Si están incompletos o no son verdaderos, no podremos preparar tu demo."
for c in nombre nombre_comercial celular direccion ciudad email; do
  contiene "Campo «$c» obligatorio (también en el navegador)" "$TMP/pag.html" "name=\"$c\" value=\"\"[^>]* required"
done
no_contiene "Ya no pide teléfono fijo" "$TMP/pag.html" 'name="telefono"\|Teléfono de contacto'
contiene "El teléfono se llama «Celular / WhatsApp»" "$TMP/pag.html" '<label for="celular">Celular / WhatsApp</label>'
no_contiene "El formulario ya no desactiva la revisión del navegador" "$TMP/pag.html" "novalidate"
contiene "Dice «Te enviaremos la clave de tu demo a este correo: escríbelo bien.»" "$TMP/pag.html" "Te enviaremos la clave de tu demo a este correo: escríbelo bien."
contiene "Dice «La demo dura 3 días.»" "$TMP/pag.html" "La demo dura 3 días."
contiene "… y cuánto tarda en prepararse" "$TMP/pag.html" "Preparar tu demo toma entre 5 y 10 minutos aproximadamente."
no_contiene "No promete nada gratis" "$TMP/pag.html" "gratis"

echo "2. Formulario incompleto: se rechaza campo por campo y se conserva lo escrito"
r=$(pedir nombre= nombre_comercial= celular= direccion= ciudad= email=)
check "Vacío: vuelve al formulario (no redirige)" "200 " "$r"
for c in nombre nombre_comercial celular direccion ciudad email; do
  contiene "Vacío: mensaje junto a «$c»" "$TMP/post.html" "data-error=\"$c\""
done
for c in nombre nombre_comercial celular direccion ciudad email; do
  pedir "$c=" > /dev/null
  check "Falta solo «$c»: un solo error, el de ese campo" "1|1" "$(grep -o 'data-error="[a-z_]*"' "$TMP/post.html" | wc -l | tr -d ' ')|$(grep -c "data-error=\"$c\"" "$TMP/post.html")"
done
contiene "Se conserva lo que ya escribió (dirección)" "$TMP/post.html" 'name="direccion" value="Av. Amazonas N34-12 y Colón"'
contiene "… y el celular" "$TMP/post.html" 'name="celular" value="0991234567"'
pedir "email=ana.sin.arroba" > /dev/null
contiene "Correo malo: rechazado con su mensaje" "$TMP/post.html" 'data-error="email"[^>]*>El correo no es válido'
pedir "celular=0291234567" > /dev/null
contiene "Celular que no empieza con 09: rechazado" "$TMP/post.html" 'data-error="celular"'
r=$(pedir "telefono=no tengo" "email=otro$N@prueba.ec" "celular=09912abc67")
contiene "Celular con letras: rechazado" "$TMP/post.html" 'data-error="celular"'
no_contiene "… y nunca sale un error de teléfono fijo" "$TMP/post.html" 'data-error="telefono"'
check "Nada de eso guardó un pedido" "$P0" "$(sql "SELECT count(*) FROM pedidos_demo")"

echo "3. Pedido completo con el Panel que NO contesta: se guarda igual y sale la página de gracias"
T0=$(date +%s)
r=$(pedir "email=  $(echo "$CORREO" | tr 'a-z' 'A-Z') ")
check "Vuelve a la misma página (sin error para el interesado)" "302 $BASE/?r=registro" "$r"
check "… sin quedarse esperando al Panel (menos de 8 segundos)" "1" "$([ $(( $(date +%s) - T0 )) -lt 8 ] && echo 1 || echo 0)"
get "r=registro" > /dev/null
contiene "Página de gracias con el texto acordado" "$TMP/pag.html" "¡Listo! Estamos preparando tu demo: toma entre 5 y 10 minutos aproximadamente. Cuando esté lista te llega al correo tu clave, el manual y la guía de primeros pasos. Revisa también la carpeta de spam."
check "El pedido quedó guardado con sus datos (el teléfono es el celular; correo en minúsculas)" "Ana Pérez|$NEGOCIO de Ana $N|0991234567|0991234567|Av. Amazonas N34-12 y Colón|Quito|$CORREO|nueva" \
  "$(sql "SELECT nombre||'|'||$NEG||'|'||telefono||'|'||whatsapp||'|'||direccion||'|'||ciudad||'|'||email||'|'||estado FROM pedidos_demo WHERE email='$CORREO'")"
check "… y NO crea la cuenta (la crea el Panel)" "0" "$(sql "SELECT count(*) FROM $TABLA WHERE nombre='$NEGOCIO de Ana $N'")"
PID=$(sql "SELECT id FROM pedidos_demo WHERE email='$CORREO'")

echo "4. Con el Panel conectado: el aviso llega al instante"
BASE=$BASE_PAGO; JAR="$TMP/c_publico2"
A0=$(cat "$PANEL_SIM_DIR/avisos_demo.jsonl" 2>/dev/null | wc -l | tr -d ' ')
N2=$RANDOM; CORREO2="demoauto$N2@prueba.ec"
r=$(N=$N2 CORREO=$CORREO2 pedir)
check "Pedido completo: página de gracias" "302 $BASE/?r=registro" "$r"
PID2=$(sql "SELECT id FROM pedidos_demo WHERE email='$CORREO2'")
check "El Panel recibió UN aviso (firma y tipo «demo» revisados por el Panel simulado)" "$((A0+1))" "$(cat "$PANEL_SIM_DIR/avisos_demo.jsonl" 2>/dev/null | wc -l | tr -d ' ')"
check "… con la acción y el id de la solicitud" "{\"accion\":\"demo_pedida\",\"id\":\"demo_pedida:$PID2\"}" "$(tail -1 "$PANEL_SIM_DIR/avisos_demo.jsonl")"
N=$N2 CORREO=$CORREO2 pedir "direccion=" > /dev/null
check "Un pedido incompleto no avisa al Panel" "$((A0+1))" "$(cat "$PANEL_SIM_DIR/avisos_demo.jsonl" | wc -l | tr -d ' ')"

echo "5. El Panel lee el pedido con las claves exactas (conector v2)"
v2() { # accion 'json'
  printf '%s' "$2" > "$TMP/cuerpo.json"
  local tok; tok=$(php -r '$t = time(); $c = (string) file_get_contents($argv[1]); $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
    $a = $b(json_encode(["v" => 2, "tipo" => $argv[2], "inst" => getenv("TUKAN_CENTRAL_ID"), "email" => "sistema@tukanec.com", "nombre" => "Panel", "rol" => "dueno",
        "iat" => $t, "exp" => $t + 30, "nonce" => bin2hex(random_bytes(16)), "bh" => $b(hash("sha256", $c, true))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' "$TMP/cuerpo.json" "$1")
  curl -s -o "$TMP/v2.json" -w "%{http_code}" -X POST -H "Content-Type: application/json; charset=utf-8" -H "X-Tukan-Token: $tok" \
       -H "CF-Connecting-IP: 198.51.100.78" --data-binary "@$TMP/cuerpo.json" "$BASE/tukan_central.php?accion=$1"
}
check "«solicitud» del pedido contesta" "200" "$(v2 solicitud "{\"id\":\"demo_pedida:$PID\"}")"
check "… con las claves exactas en el detalle (telefono = celular)" "Ana Pérez|$NEGOCIO de Ana $N|0991234567|0991234567|Av. Amazonas N34-12 y Colón|Quito|$CORREO" \
  "$(php -r '$d = json_decode(file_get_contents($argv[1]), true)["solicitud"]["detalle"] ?? []; echo implode("|", array_map(fn($k) => $d[$k] ?? "(falta $k)", ["nombre", "nombre_comercial", "telefono", "celular", "direccion", "ciudad", "email"]));' "$TMP/v2.json")"
check "«solicitudes» (la lista que revisa el Panel) lo trae como demo_pedida pendiente" "demo_pedida|pendiente" \
  "$(v2 solicitudes '{"tipos":["demo_pedida"],"por_pagina":200}' > /dev/null; php -r '$r = json_decode(file_get_contents($argv[1]), true)["solicitudes"] ?? []; foreach ($r as $s) if ($s["id"] === $argv[2]) echo $s["tipo"], "|", $s["estado"];' "$TMP/v2.json" "demo_pedida:$PID")"
check "El Panel crea la demo del pedido con 3 días" "prueba|$(date -d '+3 days' +%F)" "$(v2 ejecutar "{\"operacion_id\":\"$(php -r 'echo bin2hex(random_bytes(16));')\",\"accion\":\"crear_demo\",\"datos\":{\"nombre\":\"$NEGOCIO de Ana $N\",\"contacto_nombre\":\"Ana Pérez\",\"email\":\"$CORREO\",\"telefono\":\"0991234567\",\"plan\":\"$PLANCOM\",\"dias\":3,\"solicitud_id\":\"demo_pedida:$PID\"}}" > /dev/null; sql "SELECT estado||'|'||prueba_hasta FROM $TABLA WHERE nombre='$NEGOCIO de Ana $N'")"

echo "6. /manual.pdf y /primeros_pasos.pdf son públicos"
for par in manual:Manual_ primeros_pasos:_Primeros_Pasos; do
  f=${par%%:*}; nom=${par#*:}
  code=$(curl -s -o "$TMP/$f.pdf" -D "$TMP/$f.h" -w '%{http_code}|%{content_type}' "$BASE0/$f.pdf")
  check "/$f.pdf sin sesión: 200 y application/pdf" "200|application/pdf" "$code"
  check "… es un PDF de verdad" "%PDF" "$(head -c 4 "$TMP/$f.pdf")"
  contiene "… se descarga con un nombre claro" "$TMP/$f.h" "filename=\"[A-Za-z]*$nom[A-Za-z]*\.pdf\""
done
check "Otro PDF cualquiera no existe" "0" "$(curl -s -o "$TMP/otro.bin" "$BASE0/otro_manual.pdf"; head -c 4 "$TMP/otro.bin" | grep -c '%PDF')"

echo "7. La tarjeta de la demo (solo en cuentas demo) y los botones en Ayuda"
BASE=$BASE0
ED="tarjetademo$RANDOM@prueba.ec"; SD=$(demo "$NEGOCIO Tarjeta Demo" "$ED" "$PLANCOM")
entrar "$ED"; get "r=inicio" > /dev/null
HASTA=$(sql "SELECT to_char(prueba_hasta, 'DD/MM') FROM $TABLA WHERE id=$SD")
contiene "Demo: «Tu demo dura hasta el $HASTA. Descarga el manual y la guía de primeros pasos»" "$TMP/pag.html" "Tu demo dura hasta el $HASTA.</strong> Descarga el manual y la guía de primeros pasos."
contiene "… con el botón del manual" "$TMP/pag.html" 'href="/manual.pdf"'
contiene "… y el de los primeros pasos" "$TMP/pag.html" 'href="/primeros_pasos.pdf"'
check "Ayuda abre" "200" "$(get "r=ayuda")"
contiene "Ayuda (demo): botón del manual" "$TMP/pag.html" 'id="descargas-ayuda"'
EP="tarjetapaga$RANDOM@prueba.ec"; demo "$NEGOCIO Ya Paga" "$EP" "$PLANCOM" "" pagado > /dev/null
entrar "$EP"; get "r=inicio" > /dev/null
no_contiene "Cuenta que ya paga: sin la tarjeta de la demo" "$TMP/pag.html" 'id="tarjeta-demo"'
get "r=ayuda" > /dev/null
contiene "Ayuda (cuenta que paga): los dos botones siguen" "$TMP/pag.html" 'href="/manual.pdf"'
contiene "… primeros pasos también" "$TMP/pag.html" 'href="/primeros_pasos.pdf"'

echo "8. «3 días en todo»: también la demo que Tukán crea a mano en el panel del programa"
ADMD="tukandemos$RANDOM@tukan.ec"
env ${PRE}_DB_NAME=$DB ${PRE}_DB_USER=$U ${PRE}_DB_PASS=$PGPASSWORD ${PRE}_ADMIN_NOMBRE="Súper Demos" ${PRE}_ADMIN_EMAIL=$ADMD ${PRE}_ADMIN_CLAVE=claveAdminSegura1 php bin/crear_admin.php > /dev/null
JAR="$TMP/c_admind"; post "r=admin_entrar" -d email=$ADMD -d clave=claveAdminSegura1 > /dev/null
get "r=admin_planes" > /dev/null
contiene "Planes y precios: los días de demo por defecto son 3" "$TMP/pag.html" 'name="dias_prueba" value="3"'
MAILM="amano$RANDOM@prueba.ec"
post "r=admin_nuevo" --data-urlencode "$NEG=$NEGOCIO A Mano $N" --data-urlencode "nombre=Pedro A Mano" -d telefono=0998887766 -d email=$MAILM -d plan=$PLANCOM > /dev/null
check "Demo creada a mano por Tukán («Nuevo»): 3 días" "prueba|$(date -d '+3 days' +%F)" "$(sql "SELECT estado||'|'||prueba_hasta FROM $TABLA WHERE nombre='$NEGOCIO A Mano $N'")"
get "r=admin_planes" > /dev/null
contiene "Tukán puede seguir cambiando los días por defecto (y alargar cada demo)" "$TMP/pag.html" 'name="dias_prueba"'

echo
echo "Resultado: $OK correctas, $FALLAS fallidas"
[ "$FALLAS" -eq 0 ]
