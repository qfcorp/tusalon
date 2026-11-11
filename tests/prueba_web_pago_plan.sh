#!/usr/bin/env bash
# «Pagar mi plan con tarjeta» desde Mi plan (src/TukanPagoPlan.php, 10 nov 2026), de punta a punta por HTTP. Lo corre tests/run_web.sh:
#   BASE       = sitio de siempre (su Panel Tukán Central no existe: «Panel caído»)
#   BASE_PAGO  = el mismo programa conectado al Panel SIMULADO (tests/panel_simulado.php, en PANEL_SIM; sus archivos en PANEL_SIM_DIR)
# Cubre: Panel caído y pago apagado (Mi plan queda como siempre), pago encendido (planes, períodos, valores + IVA, modo de prueba),
# demo que paga y queda activa cuando el Panel manda «registrar_pago», suscripción viva (no se ofrece otra), regreso aprobado y
# no completado, cliente que ya paga (con sus módulos extra, pago de una sola vez), cuenta pausada y la app de Google Play (nada).
# Y la suscripción que se ajusta sola: el súper del programa enciende módulos o sube de plan (se manda «cambiar» al Panel, con lo
# proporcional aparte), no puede quitar módulos contratados ni bajar de plan durante el período pagado, y el Panel caído.
set -u
cd "$(dirname "$0")/.."
PRE=TUSALON; NS=TuSalon; TABLA=salones; FK=salon_id; NEGOCIO="Salón"; RUTA=completa; ADMINP=admin_salon; DEMO_SIN_PRECIOS=0   # lo propio de este programa (RUTA = la página de Mi plan; ADMINP = la del súper)
BASE0=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export TZ=America/Guayaquil
if [ -z "${BASE_PAGO:-}" ] || [ -z "${PANEL_SIM:-}" ] || [ -z "${PANEL_SIM_DIR:-}" ]; then echo "Faltan BASE_PAGO, PANEL_SIM y PANEL_SIM_DIR (corre: bash tests/run_web.sh pago)"; exit 1; fi
TMP=$(mktemp -d)
JAR="$TMP/cookies"
OK=0; FALLAS=0
BASE=$BASE_PAGO
U=$(echo "$PRE" | tr 'A-Z' 'a-z')
sql() { psql -h 127.0.0.1 -U "$U" -d "$DB" -tAc "$1"; }
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -qi -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local ruta=$1; shift; get "$ruta" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
demo() { # negocio correo plan [modulos] [pagado] -> id
  env ${PRE}_DB_NAME=$DB ${PRE}_DB_USER=$U ${PRE}_DB_PASS=$PGPASSWORD php tests/demo_web.php "$1" "Dueña Paga" 0990004444 "$2" claveSegura1 "$3" "${4:-}" "${5:-}"
}
entrar() { # correo [app]
  JAR="$TMP/c_$RANDOM$RANDOM"; get "r=login${2:+&app=play}" > /dev/null
  curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf=$(csrf)" --data-urlencode "email=$1" -d clave=claveSegura1 "$BASE/?r=login"
}
control() { curl -s -o "$TMP/control.json" -X POST --data-binary "$1" "$PANEL_SIM/_control"; }
pedido() { # campo del último pedido «crear» que recibió el Panel simulado
  php -r '$u = null; foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $l) { $j = json_decode($l, true); if (($j["accion"] ?? "") === "crear") $u = $j; }
          $v = $u; foreach (explode(".", $argv[2]) as $k) $v = $v[$k] ?? null; echo is_scalar($v) ? $v : json_encode($v);' "$PANEL_SIM_DIR/pedidos.jsonl" "$1"; }
cuantos() { grep -c "\"accion\":\"$1\"" "$PANEL_SIM_DIR/pedidos.jsonl" 2>/dev/null || true; }
# «registrar_pago» como lo manda el Panel cuando Plux aprueba (conector v2): registrar cliente 'datos json'
registrar() {
  printf '{"operacion_id":"%s","accion":"registrar_pago","cliente_id":"%s","datos":%s}' "$(php -r 'echo bin2hex(random_bytes(16));')" "$1" "$2" > "$TMP/cuerpo.json"
  local tok; tok=$(php -r '$t = time(); $c = (string) file_get_contents($argv[1]); $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
    $a = $b(json_encode(["v" => 2, "tipo" => "ejecutar", "inst" => getenv("TUKAN_CENTRAL_ID"), "email" => "sistema@tukanec.com", "nombre" => "Tukán Pagos", "rol" => "dueno",
        "iat" => $t, "exp" => $t + 30, "nonce" => bin2hex(random_bytes(16)), "bh" => $b(hash("sha256", $c, true))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' "$TMP/cuerpo.json")
  curl -s -o "$TMP/registrar.out" -w "%{http_code}" -X POST -H "Content-Type: application/json; charset=utf-8" -H "X-Tukan-Token: $tok" \
       -H "CF-Connecting-IP: 198.51.100.77" --data-binary "@$TMP/cuerpo.json" "$BASE/tukan_central.php?accion=ejecutar"
}
PLANES=$(sql "SELECT string_agg(codigo, ' ' ORDER BY precio_mensual, codigo) FROM planes"); BAS=${PLANES%% *}; COM=${PLANES##* }
# Los planes como se venden (otras pruebas los mueven); al final se dejan como estaban
ANTES=$(sql "SELECT string_agg(format('UPDATE planes SET precio_mensual = %s, modulos = %L, modulos_elegir = %s, precio_modulo_extra = %s WHERE codigo = %L', precio_mensual, modulos, modulos_elegir, coalesce(precio_modulo_extra::text, 'NULL'), codigo), '; ') FROM planes")
sql "UPDATE planes SET precio_mensual = 12.99, modulos = '', modulos_elegir = 3, precio_modulo_extra = 5 WHERE codigo = '$BAS'; UPDATE planes SET precio_mensual = 25, modulos = '*' WHERE codigo = '$COM'" > /dev/null
control '{"reiniciar":true}'

echo "1. Panel caído (el sitio de siempre): Mi plan de la demo queda como hoy"
ED="pagodemo$RANDOM@prueba.ec"
SD=$(demo "$NEGOCIO Demo Paga" "$ED" "$COM")
BASE=$BASE0; entrar "$ED"
check "Mi plan abre" "200" "$(get "r=$RUTA")"
no_contiene "Sin la sección de pago con tarjeta" "$TMP/pag.html" 'id="pago-tarjeta"'
no_contiene "Sin «Pagar con tarjeta»" "$TMP/pag.html" "Pagar con tarjeta"
if [ "$DEMO_SIN_PRECIOS" = 1 ]; then
  contiene "Sigue «Pide tu cotización»" "$TMP/pag.html" "Pide tu cotización"
  no_contiene "La demo sigue sin ver precios" "$TMP/pag.html" '\$[0-9][0-9.]*,[0-9][0-9]'
fi
sed -e 's/name="csrf" value="[^"]*"//g' "$TMP/pag.html" > "$TMP/caido.html"   # Mi plan «como hoy», para comparar abajo
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual")
check "Si alguien igual manda el pago: vuelve a Mi plan" "302 $BASE/?r=$RUTA" "$r"
get "r=$RUTA" > /dev/null
contiene "… con el aviso de que no está disponible" "$TMP/pag.html" "El pago con tarjeta no está disponible por ahora"

echo "2. Panel que contesta pero con el pago con tarjeta apagado: igual que hoy"
BASE=$BASE_PAGO; entrar "$ED"
control '{"activo":false}'
check "Mi plan abre" "200" "$(get "r=$RUTA")"
no_contiene "Sin la sección de pago con tarjeta" "$TMP/pag.html" 'id="pago-tarjeta"'
if [ "$DEMO_SIN_PRECIOS" = 1 ]; then
  contiene "Sigue «Pide tu cotización»" "$TMP/pag.html" "Pide tu cotización"
  no_contiene "La demo sigue sin ver precios" "$TMP/pag.html" '\$[0-9][0-9.]*,[0-9][0-9]'
fi
sed -e 's/name="csrf" value="[^"]*"//g' "$TMP/pag.html" > "$TMP/apagado.html"
check "Mi plan sale EXACTAMENTE igual que con el Panel caído" "" "$(diff "$TMP/caido.html" "$TMP/apagado.html" | head -5)"
check "El programa preguntó al Panel por este cliente" "1" "$(grep -c "\"accion\":\"estado\",\"cliente_id\":\"$SD\"" "$PANEL_SIM_DIR/pedidos.jsonl")"

echo "3. Pago encendido: la demo elige su plan"
control '{"activo":true,"pruebas":true}'
get "r=$RUTA" > /dev/null
contiene "Sale «Paga tu plan con tarjeta»" "$TMP/pag.html" 'id="pago-tarjeta"'
contiene "Aviso de modo de prueba" "$TMP/pag.html" "Modo de prueba:</strong> no se cobra de verdad"
contiene "Básico mensual \$12,99 + IVA" "$TMP/pag.html" "data-valor=\"$BAS|mensual\">\\\$12,99</span> + IVA cada mes"
contiene "Básico 6 meses (paga 5) \$64,95" "$TMP/pag.html" "data-valor=\"$BAS|semestral\">\\\$64,95</span> + IVA cada 6 meses"
contiene "Básico 1 año (paga 10) \$129,90" "$TMP/pag.html" "data-valor=\"$BAS|anual\">\\\$129,90</span> + IVA cada año"
contiene "Completo mensual \$25" "$TMP/pag.html" "data-valor=\"$COM|mensual\">\\\$25,00</span>"
contiene "Completo 1 año \$250" "$TMP/pag.html" "data-valor=\"$COM|anual\">\\\$250,00</span>"
contiene "Dice lo que incluye el Básico" "$TMP/pag.html" "La base + 3 módulos a tu elección"
contiene "Dice lo que incluye el Completo" "$TMP/pag.html" "Todos los módulos"
contiene "Paga 5, recibe 6" "$TMP/pag.html" "Paga 5, recibe 6"
contiene "Botón «Pagar con tarjeta»" "$TMP/pag.html" ">Pagar con tarjeta</button>"
contiene "Suscripción por defecto" "$TMP/pag.html" 'value="suscripcion" checked'
contiene "… y dice que se cobra sola" "$TMP/pag.html" "Se cobra sola a tu tarjeta cada período"
contiene "Dice que es sin IVA" "$TMP/pag.html" "sin IVA</strong>: al pagar se suma el IVA (15 %)"
control '{"pruebas":false}'; get "r=$RUTA" > /dev/null
no_contiene "En producción no sale «modo de prueba»" "$TMP/pag.html" "Modo de prueba"
control '{"pruebas":true}'

echo "4. La demo paga el Básico mensual: va a la página de pago"
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=inventado|mensual")
check "Plan inventado: vuelve a Mi plan sin crear nada" "302 $BASE/?r=$RUTA|0" "$r|$(cuantos crear)"
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual" -d forma=suscripcion)
check "Lo manda a la página de pago del Panel" "302 $PANEL_SIM/pagar?c=1" "$r"
check "Pedido al Panel: suscripción" "suscripcion" "$(pedido tipo)"
check "… del cliente" "$SD" "$(pedido cliente_id)"
check "… plan y período" "$BAS|mensual|1" "$(pedido plan)|$(pedido periodo)|$(pedido meses)"
check "… valor sin IVA" "12.99" "$(pedido subtotal)"
check "… vuelve a Mi plan de este programa" "$BASE_PAGO/?r=$RUTA" "$(pedido volver)"
check "… con el correo del dueño" "$ED" "$(pedido comprador.correo)"
check "Todavía es demo (no ha pagado)" "prueba" "$(sql "SELECT estado FROM $TABLA WHERE id=$SD")"

echo "5. Vuelve sin pagar: aviso y suscripción esperando (no se ofrece otra)"
check "Mi plan con ?plux=rechazado abre" "200" "$(get "r=$RUTA&plux=rechazado")"
contiene "Aviso «El pago no se completó»" "$TMP/pag.html" "El pago no se completó"
contiene "Se ve «Esperando el pago» con el total con IVA" "$TMP/pag.html" "Esperando el pago · \\\$14,94 con IVA"
contiene "Trae «Pagar ahora»" "$TMP/pag.html" "$PANEL_SIM/pagar?c=1\">Pagar ahora"
no_contiene "Sin botón para crear otra" "$TMP/pag.html" ">Pagar con tarjeta</button>"
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$COM|anual")
check "Si igual manda otra: no se crea" "302 $BASE/?r=$RUTA|1" "$r|$(cuantos crear)"
get "r=$RUTA" > /dev/null
contiene "… y dice que ya tiene una suscripción" "$TMP/pag.html" "Ya tienes una suscripción con tarjeta"
r=$(post "r=$RUTA" -d accion=descartar_pago_tarjeta -d id=1)
get "r=$RUTA" > /dev/null
contiene "«Elegir otro plan»: vuelve el botón de pagar" "$TMP/pag.html" ">Pagar con tarjeta</button>"
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|semestral")
check "Elige ahora 6 meses" "302 $PANEL_SIM/pagar?c=2|semestral|6|64.95" "$r|$(pedido periodo)|$(pedido meses)|$(pedido subtotal)"

echo "6. Plux aprueba: el Panel manda «registrar_pago» y la demo queda como cliente activo"
check "El programa acepta el pago" "200" "$(registrar "$SD" "{\"plan\":\"$BAS\",\"periodo\":\"semestral\",\"metodo\":\"plux\",\"monto\":64.95,\"fecha\":\"$(date +%F)\",\"nota\":\"Plux (prueba)\"}")"
HASTA=$(date -d "+6 months -1 day" +%F)
check "La cuenta queda activa, con su plan y 6 meses pagados" "activo|$BAS|$HASTA" "$(sql "SELECT estado||'|'||plan_codigo||'|'||activo_hasta FROM $TABLA WHERE id=$SD")"
control "{\"aprobar\":2,\"pagado_hasta\":\"$HASTA\"}"
check "Vuelve de la página de pago (?plux=aprobado)" "200" "$(get "r=$RUTA&plux=aprobado")"
contiene "Aviso «¡Pago recibido!»" "$TMP/pag.html" "¡Pago recibido!"
contiene "Ya no es demo: «pagado hasta el»" "$TMP/pag.html" "pagado hasta el $(date -d "$HASTA" +%d/%m/%Y)"
contiene "Suscripción activa, se cobra sola" "$TMP/pag.html" "Suscripción activa · \\\$74,69 con IVA · se cobra sola a tu tarjeta"
no_contiene "No se ofrece pagar otra vez" "$TMP/pag.html" ">Pagar con tarjeta</button>"
no_contiene "Ni «Elegir otro plan»" "$TMP/pag.html" "descartar_pago_tarjeta"
contiene "Sigue «Cancelar mi plan»" "$TMP/pag.html" 'id="cancelar-plan"'
contiene "Le quedan 3 módulos por elegir (Básico)" "$TMP/pag.html" 'data-por-elegir="3"'
post "r=$RUTA" -d accion=cancelar_plan -d confirmo=1 -d motivo=prueba > /dev/null
check "«Cancelar mi plan» avisa al Panel (cancela la suscripción)" "1|1" "$(cuantos cancelar_plan)|$(sql "SELECT avisada FROM tukan_cancelaciones WHERE cliente_id='$SD'")"
post "r=$RUTA" -d accion=seguir_plan > /dev/null

echo "7. Cliente que ya paga: su valor con módulos extra y pago de una sola vez"
EC="pagocli$RANDOM@prueba.ec"
SC=$(demo "$NEGOCIO Cliente Paga" "$EC" "$BAS" "" pagado)
EXTRAS=$(php -r 'require "src/Modulos.php"; $c = $argv[1] . "\\Modulos"; $r = [];   // 4 módulos sin dependencias (3 a elegir + 1 más)
  foreach ($c::CATALOGO as $id => $m) if (!in_array($id, $c::FUERA_DEL_CUPO, true) && empty($m[6]) && count($r) < 4) $r[] = $id; echo implode(",", $r);' "$NS")
sql "UPDATE $TABLA SET modulos_extra = '$EXTRAS,asistente_ia', ruc = '1790011001001' WHERE id = $SC" > /dev/null
entrar "$EC"
get "r=$RUTA" > /dev/null
TOTAL=$(grep -o 'data-total-mensual>[^<]*' "$TMP/pag.html" | sed 's/.*>//')   # «Total al mes» que ya calcula el programa (plan + extras)
MES=$(echo "$TOTAL" | tr -d '$.' | tr ',' '.')
din() { php -r 'echo "\\$" . number_format((float) $argv[1] * (int) $argv[2], 2, ",", ".");' "$1" "$2"; }
check "Con módulos extra paga más que el Básico solo (\$12,99)" "1" "$(php -r 'echo (float) $argv[1] > 12.99 ? 1 : 0;' "$MES")"
contiene "El pago con tarjeta usa ese mismo valor ($TOTAL)" "$TMP/pag.html" "data-valor=\"$BAS|mensual\">$(din "$MES" 1)</span>"
contiene "… 6 meses = 5 veces" "$TMP/pag.html" "data-valor=\"$BAS|semestral\">$(din "$MES" 5)</span>"
contiene "… 1 año = 10 veces" "$TMP/pag.html" "data-valor=\"$BAS|anual\">$(din "$MES" 10)</span>"
contiene "Su plan sale marcado" "$TMP/pag.html" "(tu plan)"
contiene "Siguen los «Renovar» de siempre" "$TMP/pag.html" ">Renovar</a>"
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|anual" -d forma=una_vez)
check "Pago de una sola vez: a la página de pago" "302 $PANEL_SIM/pagar?c=3" "$r"
check "… tipo «cobro», 12 meses, 10 veces su valor" "cobro|anual|12|$(php -r 'echo round((float) $argv[1] * 10, 2);' "$MES")" "$(pedido tipo)|$(pedido periodo)|$(pedido meses)|$(pedido subtotal)"
check "… con su RUC para la factura" "1790011001001" "$(pedido fiscal.identificacion)"
A0=$(sql "SELECT activo_hasta FROM $TABLA WHERE id=$SC")
check "Aprobado: el programa acepta el pago" "200" "$(registrar "$SC" "{\"plan\":\"$BAS\",\"periodo\":\"anual\",\"metodo\":\"plux\",\"monto\":$(php -r 'echo round((float) $argv[1] * 10, 2);' "$MES"),\"fecha\":\"$(date +%F)\",\"nota\":\"Plux (prueba)\"}")"
check "… y el año se suma a lo que ya tenía pagado" "$(date -d "$A0 +1 day +12 months -1 day" +%F)" "$(sql "SELECT activo_hasta FROM $TABLA WHERE id=$SC")"
sql "UPDATE $TABLA SET precio_acordado = 18.5 WHERE id = $SC" > /dev/null
get "r=$RUTA" > /dev/null
contiene "Precio acordado con Tukán: se cobra ese" "$TMP/pag.html" "data-valor=\"$BAS|mensual\">\\\$18,50</span>"
no_contiene "… y solo se ofrece su plan" "$TMP/pag.html" "data-plan=\"$COM\""

echo "8. Cuenta pausada por Tukán: no se reactiva pagando"
sql "UPDATE $TABLA SET estado = 'suspendido', precio_acordado = NULL WHERE id = $SC" > /dev/null
N=$(cuantos crear)
get "r=$RUTA" > /dev/null
no_contiene "Sin pago con tarjeta" "$TMP/pag.html" 'id="pago-tarjeta"'
post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual" > /dev/null
check "… y si lo manda igual, no se crea nada" "$N" "$(cuantos crear)"
sql "UPDATE $TABLA SET estado = 'activo' WHERE id = $SC" > /dev/null

echo "9. Dentro de la app de Google Play: nada de pago con tarjeta (regla de Google)"
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/.well-known/assetlinks.json")" = "200" ]; then
  entrar "$EC" app
  check "Mi plan abre en la app" "200" "$(get "r=$RUTA")"
  contiene "Es la app (paga con Google Play)" "$TMP/pag.html" 'id="pago-play"'
  no_contiene "Sin la sección de pago con tarjeta" "$TMP/pag.html" 'id="pago-tarjeta"'
  no_contiene "Sin «Pagar con tarjeta»" "$TMP/pag.html" "Pagar con tarjeta"
  no_contiene "Sin valores del plan por fuera de Google" "$TMP/pag.html" "+ IVA"
  E0=$(grep -c "\"accion\":\"estado\",\"cliente_id\":\"$SC\"" "$PANEL_SIM_DIR/pedidos.jsonl")
  get "r=$RUTA&plux=aprobado" > /dev/null
  no_contiene "Ni el aviso de regreso del pago" "$TMP/pag.html" "Pago recibido"
  check "En la app ni se pregunta al Panel" "$E0" "$(grep -c "\"accion\":\"estado\",\"cliente_id\":\"$SC\"" "$PANEL_SIM_DIR/pedidos.jsonl")"
  post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual" > /dev/null
  check "Si lo manda igual desde la app, no se crea nada" "$N" "$(cuantos crear)"
else
  echo "  (este sitio no tiene encendida la app de Google Play: no aplica)"
fi

echo "10. La suscripción se ajusta sola cuando el súper del programa cambia módulos o plan (y no puede bajar)"
BASE=$BASE_PAGO
M=($(php -r 'require "src/Modulos.php"; $c = $argv[1] . "\\Modulos"; $r = [];   # 6 módulos sin dependencias
  foreach ($c::CATALOGO as $id => $m) if (!in_array($id, $c::FUERA_DEL_CUPO, true) && empty($m[6]) && count($r) < 6) $r[] = $id; echo implode(" ", $r);' "$NS"))
ES="pagosus$RANDOM@prueba.ec"
SS=$(demo "$NEGOCIO Suscrito" "$ES" "$BAS" "" pagado)
sql "UPDATE $TABLA SET modulos_extra = '${M[0]},${M[1]}' WHERE id = $SS" > /dev/null
entrar "$ES"; JAR_S=$JAR
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual")
CS=${r##*c=}
check "El dueño crea su suscripción mensual (Básico con 2 de sus 3 módulos: \$12,99)" "12.99" "$(pedido subtotal)"
HS=$(sql "SELECT activo_hasta FROM $TABLA WHERE id=$SS")
control "{\"aprobar\":$CS,\"pagado_hasta\":\"$HS\"}"
sql "INSERT INTO suscripciones ($FK, plan_codigo, periodo, meses_pagados, meses_recibidos, monto, inicio, fin, metodo_pago) VALUES ($SS, '$BAS', 'mensual', 1, 1, 12.99, CURRENT_DATE, '$HS', 'plux')" > /dev/null   # su último pago: con tarjeta
ADMP="tukanpago$RANDOM@tukan.ec"
env ${PRE}_DB_NAME=$DB ${PRE}_DB_USER=$U ${PRE}_DB_PASS=$PGPASSWORD ${PRE}_ADMIN_NOMBRE="Súper Pago" ${PRE}_ADMIN_EMAIL=$ADMP ${PRE}_ADMIN_CLAVE=claveAdminSegura1 php bin/crear_admin.php > /dev/null
JAR="$TMP/c_adminp"; post "r=admin_entrar" -d email=$ADMP -d clave=claveAdminSegura1 > /dev/null
JAR_A=$JAR
mods() { local a=(); for m in "$@"; do a+=(-d "mod[$m]=1"); done; post "r=$ADMINP&id=$SS" -d accion=modulos "${a[@]}" -d precio_acordado= > /dev/null; get "r=$ADMINP&id=$SS" > /dev/null; }
extras() { sql "SELECT array_length(string_to_array(nullif(modulos_extra, ''), ','), 1) FROM $TABLA WHERE id=$SS"; }
mods "${M[0]}" "${M[1]}" "${M[2]}"
check "Encender el 3.er módulo incluido: se guarda y NO se manda «cambiar» (no mueve el valor)" "3|0" "$(extras)|$(cuantos cambiar)"
mods "${M[0]}" "${M[1]}" "${M[2]}" "${M[3]}" "${M[4]}"
check "Encender 2 módulos más (+\$10): se manda «cambiar» con el valor nuevo" "5|1|22.99" "$(extras)|$(cuantos cambiar)|$(php -r '$u = null; foreach (file($argv[1]) as $l) { $j = json_decode($l, true); if (($j["accion"] ?? "") === "cambiar") $u = $j; } echo $u["subtotal"];' "$PANEL_SIM_DIR/pedidos.jsonl")"
contiene "El súper ve: la suscripción queda en \$22,99 + IVA desde el próximo cobro" "$TMP/pag.html" "queda en \\\$22,99 + IVA (\\\$26,44 con IVA) desde el próximo cobro"
contiene "… y lo proporcional que se cobra aparte, con el enlace" "$TMP/pag.html" "se cobra aparte \\\$[0-9,]* con IVA: $PANEL_SIM/pagar?c="
JAR=$JAR_S; get "r=$RUTA" > /dev/null
contiene "El dueño ve en Mi plan el cobro «Proporcional módulos»" "$TMP/pag.html" "Proporcional módulos"
contiene "… con «Pagar ahora»" "$TMP/pag.html" ">Pagar ahora</a>"
contiene "… y su suscripción con el valor nuevo" "$TMP/pag.html" "Suscripción activa · \\\$26,44 con IVA"
JAR=$JAR_A
mods "${M[0]}" "${M[1]}" "${M[2]}" "${M[3]}"
contiene "Quitar un módulo contratado: no se permite, con la fecha" "$TMP/pag.html" "Los módulos ya contratados no se pueden quitar mientras dure el período pagado (hasta el $(date -d "$HS" +%d/%m/%Y))"
check "… el módulo sigue encendido y no se mandó nada" "5|1" "$(extras)|$(cuantos cambiar)"
post "r=$ADMINP&id=$SS" -d accion=modulos -d "mod[${M[0]}]=1" -d "mod[${M[1]}]=1" -d "mod[${M[2]}]=1" -d "mod[${M[3]}]=1" -d "mod[${M[4]}]=1" -d precio_acordado=9 > /dev/null
check "Un precio acordado menor tampoco se guarda durante el período" "" "$(sql "SELECT precio_acordado FROM $TABLA WHERE id=$SS")"
post "r=$ADMINP&id=$SS" -d accion=plan -d plan=$COM > /dev/null; get "r=$ADMINP&id=$SS" > /dev/null
check "Subir de plan (a Completo): se hace y se manda «cambiar»" "$COM|2" "$(sql "SELECT plan_codigo FROM $TABLA WHERE id=$SS")|$(cuantos cambiar)"
contiene "… la suscripción queda en \$25 + IVA" "$TMP/pag.html" "queda en \\\$25,00 + IVA"
post "r=$ADMINP&id=$SS" -d accion=plan -d plan=$BAS > /dev/null; get "r=$ADMINP&id=$SS" > /dev/null
contiene "Bajar de plan durante el período: no se permite" "$TMP/pag.html" "No se puede bajar de plan mientras dure el período pagado"
check "… y sigue en Completo" "$COM" "$(sql "SELECT plan_codigo FROM $TABLA WHERE id=$SS")"
# Sin suscripción: todo como siempre
SS0=$SS; SS=$SC
sql "UPDATE $TABLA SET modulos_extra = '${M[0]},${M[1]},${M[2]},${M[3]}' WHERE id = $SS" > /dev/null
mods "${M[0]}" "${M[1]}"
check "Cliente sin suscripción con tarjeta: se le pueden quitar módulos y no se manda nada" "2|2" "$(extras)|$(cuantos cambiar)"
no_contiene "… sin avisos de suscripción" "$TMP/pag.html" "suscripción con tarjeta"
# Panel caído (el sitio de siempre): el cambio se hace igual y se avisa
SS=$SS0; BASE=$BASE0
JAR="$TMP/c_adminp0"; post "r=admin_entrar" -d email=$ADMP -d clave=claveAdminSegura1 > /dev/null
sql "UPDATE $TABLA SET plan_codigo = '$BAS', modulos_extra = '${M[0]},${M[1]},${M[2]}' WHERE id = $SS" > /dev/null
mods "${M[0]}" "${M[1]}" "${M[2]}" "${M[3]}"
check "Panel caído: el cambio de módulos se hace igual" "4" "$(extras)"
contiene "… y avisa que la suscripción se ajustará cuando el Panel responda" "$TMP/pag.html" "se ajustará cuando el Panel responda"
BASE=$BASE_PAGO

echo "11. «Te sugerimos el Completo» y «Pasarme al Completo»"
NCOM=$(sql "SELECT nombre FROM planes WHERE codigo='$COM'")
E6="pagoseis$RANDOM@prueba.ec"
S6=$(demo "$NEGOCIO Casi Completo" "$E6" "$BAS" "" pagado)
pone() { sql "UPDATE $TABLA SET modulos_extra = '$2' WHERE id = $1" > /dev/null; }
pone $S6 "${M[0]},${M[1]},${M[2]},${M[3]},${M[4]},${M[5]}"   # 3 incluidos + 3 más = $27,99: más que el Completo ($25)
BASE=$BASE0; entrar "$E6"; J6=$JAR
get "r=$RUTA" > /dev/null
contiene "Paga más que el Completo: aviso de advertencia arriba de Mi plan" "$TMP/pag.html" 'data-sugerencia="mayor"'
contiene "… «Con tus módulos pagas \$27,99 al mes»" "$TMP/pag.html" 'Con tus módulos pagas <strong>\$27,99</strong> al mes. El plan '"$NCOM"' cuesta <strong>\$25,00</strong>'
contiene "… «Te sugerimos pasarte al Completo»" "$TMP/pag.html" "Te sugerimos pasarte al $NCOM"
contiene "… con el botón «Pasarme al Completo»" "$TMP/pag.html" ">Pasarme al $NCOM</button>"
pone $S6 "${M[0]},${M[1]},${M[2]},${M[3]},${M[4]}"; get "r=$RUTA" > /dev/null   # $22,99: a $2,01 del Completo
contiene "Cerca del Completo: aviso suave «Por \$2,01 más tienes el Completo con todo»" "$TMP/pag.html" 'Por <strong>\$2,01</strong> más tienes el '"$NCOM"' con todo'
contiene "… marcado como «cerca»" "$TMP/pag.html" 'data-sugerencia="cerca"'
# Pedir un módulo más que lo haría pasar del Completo: primero el aviso, sin crear el pedido
P0=$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6")
r=$(post "r=$RUTA" -d accion=pedir -d "modulo=${M[5]}")
check "Pedir un módulo que lo haría pagar más que el Completo: vuelve con el aviso, sin crear el pedido" "302 $BASE/?r=$RUTA&modulo=${M[5]}&sugerir=1|$P0" "$r|$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6")"
get "r=$RUTA&modulo=${M[5]}&sugerir=1" > /dev/null
contiene "… «Si agregas … pagarías \$27,99 al mes»" "$TMP/pag.html" 'pagarías <strong>\$27,99</strong> al mes'
contiene "… con «Pasarme al Completo» y «Pedir solo este módulo»" "$TMP/pag.html" ">Pedir solo este módulo</button>"
post "r=$RUTA" -d accion=pedir -d "modulo=${M[5]}" -d solo_modulo=1 > /dev/null
check "«Pedir solo este módulo»: ahora sí se crea el pedido" "$((P0+1))" "$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6")"
pone $S6 "${M[0]},${M[1]},${M[2]},${M[3]}"; get "r=$RUTA" > /dev/null   # $17,99: lejos
no_contiene "Lejos del Completo (\$17,99): sin aviso" "$TMP/pag.html" 'data-sugerencia'
post "r=$RUTA" -d accion=pedir -d "modulo=${M[4]}" > /dev/null
check "… y pedir un módulo que no llega al Completo se pide directo" "$((P0+2))" "$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6")"
# Sin pago con tarjeta disponible: el plan NO cambia (nadie pasa al Completo sin pagar), sin pedido con nota; mensaje y WhatsApp de Tukán
pone $S6 "${M[0]},${M[1]},${M[2]},${M[3]},${M[4]},${M[5]}"
V6=$(sql "SELECT activo_hasta FROM $TABLA WHERE id=$S6"); PM6=$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6"); RA6=$(sql "SELECT count(*) FROM registro_admin WHERE ${FK} = $S6")
r=$(post "r=$RUTA" -d accion=pasar_completo)
check "Sin pago con tarjeta: vuelve a Mi plan con el aviso" "302 $BASE/?r=$RUTA&completo=sin_tarjeta" "$r"
check "… el plan NO cambia y el vencimiento tampoco" "$BAS|$V6" "$(sql "SELECT plan_codigo||'|'||activo_hasta FROM $TABLA WHERE id=$S6")"
check "… no se crea ningún pedido ni nota para Tukán, ni se anota un cambio" "$PM6|0|$RA6" "$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6")|$(sql "SELECT count(*) FROM pedidos_modulos WHERE ${FK} = $S6 AND nota LIKE 'QUIERE PASARSE%'")|$(sql "SELECT count(*) FROM registro_admin WHERE ${FK} = $S6")"
get "r=$RUTA&completo=sin_tarjeta" > /dev/null
contiene "… mensaje: necesita pagar con tarjeta y ahora no está disponible" "$TMP/pag.html" "Para pasarte al $NCOM necesitas pagar con tarjeta. En este momento el pago con tarjeta no está disponible: escríbenos y te ayudamos."
contiene "… con el botón de WhatsApp de Tukán" "$TMP/pag.html" "data-completo=\"sin-tarjeta\".*wa.me/$(sql "SELECT coalesce((SELECT valor FROM ajustes WHERE clave='whatsapp_ventas'), '593983662181')")?text="
no_contiene "… sin prometer que paga después" "$TMP/pag.html" "próxima renovación"
# Demo sin pago con tarjeta: no ve el botón y no cambia
EDS="pagodemosug$RANDOM@prueba.ec"; SDS=$(demo "$NEGOCIO Demo Con Extras" "$EDS" "$BAS")
pone $SDS "${M[0]},${M[1]},${M[2]},${M[3]},${M[4]},${M[5]}"
JG=$JAR; entrar "$EDS"; get "r=$RUTA" > /dev/null
no_contiene "Demo sin pago con tarjeta: no ve el aviso ni el botón" "$TMP/pag.html" 'data-sugerencia\|value="pasar_completo"'
post "r=$RUTA" -d accion=pasar_completo > /dev/null
check "… y si lo manda igual, la demo no cambia de plan" "$BAS|prueba" "$(sql "SELECT plan_codigo||'|'||estado FROM $TABLA WHERE id=$SDS")"
JAR=$JG
# La ficha que ve Tukán
JAR="$TMP/c_adminp0"; get "r=$ADMINP&id=$S6" > /dev/null
contiene "Tukán también lo ve en la ficha: «Con sus módulos paga \$27,99… Conviene sugerirle»" "$TMP/pag.html" 'Con sus módulos paga <strong>\$27,99</strong> al mes'
no_contiene "… sin el botón del dueño" "$TMP/pag.html" 'value="pasar_completo"'
get "r=$ADMINP&id=$SC" > /dev/null
no_contiene "Ficha de un cliente lejos del Completo: sin aviso" "$TMP/pag.html" 'data-sugerencia'
# Quien ya tiene el Completo y la app de Google Play: nada
sql "UPDATE $TABLA SET plan_codigo = '$COM' WHERE id = $S6" > /dev/null
JAR=$J6; get "r=$RUTA" > /dev/null
no_contiene "Quien ya tiene el Completo: sin aviso" "$TMP/pag.html" 'data-sugerencia'
sql "UPDATE $TABLA SET plan_codigo = '$BAS' WHERE id = $S6" > /dev/null
BASE=$BASE_PAGO
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/.well-known/assetlinks.json")" = "200" ]; then
  entrar "$E6" app; get "r=$RUTA" > /dev/null
  no_contiene "En la app de Google Play: sin aviso ni botón" "$TMP/pag.html" 'data-sugerencia\|pasar_completo'
fi
# Con pago con tarjeta y sin suscripción: lleva a pagar el Completo
entrar "$E6"; J6=$JAR
get "r=$RUTA" > /dev/null
check "Con pago con tarjeta: el aviso sale arriba y en la sección de pagar" "2" "$(grep -o 'data-sugerencia="mayor"' "$TMP/pag.html" | wc -l | tr -d ' ')"
r=$(post "r=$RUTA" -d accion=pasar_completo)
check "«Pasarme al Completo» lleva a pagar el Completo con tarjeta" "302 $BASE/?r=$RUTA&elegir=$COM" "$r"
get "r=$RUTA&elegir=$COM" > /dev/null
contiene "… con el Completo ya marcado" "$TMP/pag.html" "value=\"$COM|mensual\" required checked"
# Con suscripción viva y pagada: pasa ya al Completo; el valor nuevo desde el próximo cobro
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual"); C6=${r##*c=}
H6=$(sql "SELECT activo_hasta FROM $TABLA WHERE id=$S6")
control "{\"aprobar\":$C6,\"pagado_hasta\":\"$H6\"}"
NC=$(cuantos cambiar)
post "r=$RUTA" -d accion=pasar_completo > /dev/null; get "r=$RUTA" > /dev/null
check "Con suscripción pagada: pasa al Completo a mitad del período" "$COM|$((NC+1))" "$(sql "SELECT plan_codigo FROM $TABLA WHERE id=$S6")|$(cuantos cambiar)"
check "… el pedido al Panel lleva pasa_a_completo y el valor del Completo" "1|25" "$(php -r '$u = null; foreach (file($argv[1]) as $l) { $j = json_decode($l, true); if (($j["accion"] ?? "") === "cambiar") $u = $j; } echo (int) ($u["pasa_a_completo"] ?? 0), "|", $u["subtotal"];' "$PANEL_SIM_DIR/pedidos.jsonl")"
contiene "… «¡Listo! Ya tienes el plan Completo»" "$TMP/pag.html" "Ya tienes el plan $NCOM, con todos los módulos"
contiene "… desde el próximo cobro \$25 + IVA; lo ya pagado no se devuelve" "$TMP/pag.html" "Desde el próximo cobro ($(date -d "$H6" +%d/%m/%Y)) la suscripción con tarjeta queda en \\\$25,00 + IVA (\\\$28,75 con IVA). Lo ya pagado no se devuelve."
no_contiene "… y ya no sale el aviso" "$TMP/pag.html" 'data-sugerencia'
# Con suscripción pagada y el Completo MÁS caro que lo que paga: cambia y se cobra aparte lo proporcional de los días que faltan
E7="pagosiete$RANDOM@prueba.ec"; S7=$(demo "$NEGOCIO Sube Al Completo" "$E7" "$BAS" "" pagado)
pone $S7 "${M[0]},${M[1]},${M[2]},${M[3]},${M[4]}"   # $22,99
entrar "$E7"
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual"); C7=${r##*c=}
control "{\"aprobar\":$C7,\"pagado_hasta\":\"$(sql "SELECT activo_hasta FROM $TABLA WHERE id=$S7")\"}"
post "r=$RUTA" -d accion=pasar_completo > /dev/null; get "r=$RUTA" > /dev/null
check "Suscripción pagada de \$22,99: pasa al Completo (\$25)" "$COM" "$(sql "SELECT plan_codigo FROM $TABLA WHERE id=$S7")"
contiene "… y se cobra aparte lo proporcional de los días que faltan" "$TMP/pag.html" "Por los días que faltan del período pagado se cobra aparte \\\$[0-9,]* con IVA"
contiene "… que el dueño paga con «Pagar ahora» (Proporcional módulos)" "$TMP/pag.html" "Proporcional módulos"
contiene "… la suscripción queda en \$25 + IVA desde el próximo cobro" "$TMP/pag.html" "queda en \\\$25,00 + IVA"
JAR=$JAR_A
post "r=$ADMINP&id=$S6" -d accion=plan -d plan=$BAS > /dev/null; get "r=$ADMINP&id=$S6" > /dev/null
contiene "Bajar del Completo al Básico sigue bloqueado durante el período" "$TMP/pag.html" "No se puede bajar de plan mientras dure el período pagado"
check "… y sigue en Completo" "$COM" "$(sql "SELECT plan_codigo FROM $TABLA WHERE id=$S6")"

sql "$ANTES" > /dev/null
echo
echo "Resultado: $OK correctas, $FALLAS fallidas"
[ "$FALLAS" -eq 0 ]
