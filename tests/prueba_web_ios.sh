#!/usr/bin/env bash
# App de iPhone (regla 3.1.1 de Apple, 13 nov 2026): con «TukanIOS» en el User-Agent (o la cookie canal=ios que deja ?app=ios) no se ve
# ningún precio de los planes o módulos de Tukán, ni botones, enlaces o textos para pagar, contratar, cotizar, mejorar o cancelar el plan.
# Sin TukanIOS todo sigue igual. Lo corre tests/run_web.sh (parte "ios"), contra BASE_PAGO (conectado al Panel SIMULADO, con el pago
# con tarjeta encendido: así se ve que en el iPhone no sale aunque esté disponible) y contra BASE (registro).
set -u
cd "$(dirname "$0")/.."
PRE=TUSALON; TABLA=salones; FK=salon_id; NEGOCIO="Salón"; RUTA=completa; RUTA_MODULO=inventario; MODULO=asistente_ia; PEDIR="Pedir cotización"   # lo propio de este programa
BASE0=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export TZ=America/Guayaquil
if [ -z "${BASE_PAGO:-}" ] || [ -z "${PANEL_SIM:-}" ] || [ -z "${PANEL_SIM_DIR:-}" ]; then echo "Faltan BASE_PAGO, PANEL_SIM y PANEL_SIM_DIR (corre: bash tests/run_web.sh ios)"; exit 1; fi
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
OK=0; FALLAS=0
BASE=$BASE_PAGO
U=$(echo "$PRE" | tr 'A-Z' 'a-z')
UA_IOS='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 TukanIOS/1.0.0'
UA_WEB='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
UA=$UA_WEB
IP="198.51.100.$((RANDOM % 200 + 20))"
sql() { psql -h 127.0.0.1 -U "$U" -d "$DB" -tAc "$1"; }
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -q -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]: $(grep -o -- ".\{0,40\}$3.\{0,40\}" "$2" | head -1)"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -A "$UA" -H "CF-Connecting-IP: $IP" -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local ruta=$1; shift; get "$ruta" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi
  curl -s -A "$UA" -H "CF-Connecting-IP: $IP" -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
demo() { env ${PRE}_DB_NAME=$DB ${PRE}_DB_USER=$U ${PRE}_DB_PASS=$PGPASSWORD php tests/demo_web.php "$1" "Dueño iPhone" 0990007777 "$2" claveSegura1 "$3" "${4:-}" "${5:-}"; }
entrar() { # correo [ios|web] [primera página]
  JAR="$TMP/c_$RANDOM$RANDOM"; if [ "${2:-ios}" = ios ]; then UA=$UA_IOS; else UA=$UA_WEB; fi
  get "r=login${3:+&$3}" > /dev/null
  curl -s -A "$UA" -H "CF-Connecting-IP: $IP" -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf=$(csrf)" --data-urlencode "email=$1" -d clave=claveSegura1 "$BASE/?r=login"; }
cuantos() { cat "$PANEL_SIM_DIR/pedidos.jsonl" 2>/dev/null | grep -c "\"accion\":\"$1\""; }
# Lo que nunca debe salir en el iPhone (en Mi plan, registro, plan vencido/prueba terminada, módulo no incluido)
sin_venta() { # nombre-de-la-pantalla archivo
  no_contiene "$1: sin precios de Tukán (\$0,00)" "$2" '\$ *[0-9][0-9.]*,[0-9][0-9]'
  no_contiene "$1: sin «USD»" "$2" 'USD'
  no_contiene "$1: sin «Paga»" "$2" 'Paga'
  no_contiene "$1: sin «Contratar»" "$2" 'Contratar'
  no_contiene "$1: sin «contratar»" "$2" 'contratar'
  no_contiene "$1: sin «cotización»" "$2" 'otizaci'
  no_contiene "$1: sin «Renovar»" "$2" 'Renovar'
  no_contiene "$1: sin «Renueva»" "$2" 'Renueva'
  no_contiene "$1: sin WhatsApp de venta (wa.me)" "$2" 'wa\.me/'
  no_contiene "$1: sin enlaces a pagos.tukanec.com" "$2" 'pagos\.tukanec\.com'
  no_contiene "$1: sin la página de pago del Panel" "$2" "$PANEL_SIM/pagar"
  no_contiene "$1: sin «Solicitar este módulo»" "$2" 'Solicitar este módulo'
  no_contiene "$1: sin nada de Google Play" "$2" 'Google Play'
  contiene "$1: con la marca tukan-ios en el diseño" "$2" 'tukan-ios'
}
PLANES=$(sql "SELECT string_agg(codigo, ' ' ORDER BY precio_mensual, codigo) FROM planes"); BAS=${PLANES%% *}; COM=${PLANES##* }
curl -s -o /dev/null -X POST --data-binary '{"reiniciar":true}' "$PANEL_SIM/_control"
curl -s -o /dev/null -X POST --data-binary '{"activo":true,"pruebas":true}' "$PANEL_SIM/_control"

echo "1. Mi plan de un negocio que ya paga, desde la app de iPhone"
EP="iphone.pagado$RANDOM@prueba.ec"
SP=$(demo "$NEGOCIO iPhone Activo" "$EP" "$BAS" "" pagado)
entrar "$EP" ios
check "Mi plan abre" "200" "$(get "r=$RUTA")"
cp "$TMP/pag.html" "$TMP/ios_plan.html"
sin_venta "Mi plan" "$TMP/ios_plan.html"
contiene "Dice el plan que tiene (información neutra)" "$TMP/ios_plan.html" "<h1>Mi plan</h1>"
contiene "… y hasta cuándo está activo" "$TMP/ios_plan.html" "activo hasta el $(date -d '+30 days' +%d/%m/%Y)"
no_contiene "Sin «Paga tu plan con tarjeta»" "$TMP/ios_plan.html" 'id="pago-tarjeta"'
no_contiene "Sin «Lo que pagas al mes»" "$TMP/ios_plan.html" 'Lo que pagas al mes'
no_contiene "Sin «Cancelar mi plan»" "$TMP/ios_plan.html" 'cancelar-plan'
no_contiene "Sin el pago con Google Play" "$TMP/ios_plan.html" 'id="pago-play"'
no_contiene "Sin «te sugerimos el plan completo»" "$TMP/ios_plan.html" 'pasar_completo'
contiene "El menú sigue llevando a la privacidad y a borrar la cuenta (Apple lo exige)" "$TMP/ios_plan.html" 'r=privacidad'
check "La app no le preguntó nada de pagos al Panel" "0" "$(cat "$PANEL_SIM_DIR/pedidos.jsonl" 2>/dev/null | grep -c "\"cliente_id\":\"$SP\"")"

echo "2. El mismo negocio en el navegador (sin TukanIOS): todo sigue igual"
entrar "$EP" web
check "Mi plan abre" "200" "$(get "r=$RUTA")"
cp "$TMP/pag.html" "$TMP/web_plan.html"
contiene "Sale «Paga tu plan con tarjeta»" "$TMP/web_plan.html" 'id="pago-tarjeta"'
contiene "… con sus precios" "$TMP/web_plan.html" '\$[0-9]'
contiene "Sale «Cancelar mi plan»" "$TMP/web_plan.html" 'id="cancelar-plan"'
no_contiene "Sin la marca tukan-ios" "$TMP/web_plan.html" 'tukan-ios'

echo "3. Desde el iPhone, las acciones de pago, cambio y cancelación se rechazan con un mensaje neutro"
entrar "$EP" ios
C0=$(cuantos crear)
r=$(post "r=$RUTA" -d accion=pagar_tarjeta -d "opcion=$BAS|mensual" -d forma=suscripcion)
check "Pagar con tarjeta: vuelve a Mi plan (no va a la página de pago)" "302 $BASE/?r=$RUTA" "$r"
check "… y no se creó ningún cobro en el Panel" "$C0" "$(cuantos crear)"
get "r=$RUTA" > /dev/null
contiene "… con el aviso neutro" "$TMP/pag.html" "Esta acción no está disponible en la app."
for acc in pasar_completo pagar_mejora pasar_sin_cobro descartar_pago_tarjeta; do
  post "r=$RUTA" -d "accion=$acc" > /dev/null; get "r=$RUTA" > /dev/null
  contiene "«$acc» rechazado con el aviso neutro" "$TMP/pag.html" "Esta acción no está disponible en la app."
done
check "El plan no cambió" "$BAS" "$(sql "SELECT plan_codigo FROM $TABLA WHERE id = $SP")"
post "r=$RUTA" -d accion=cancelar_plan -d confirmo=1 -d motivo=prueba > /dev/null; get "r=$RUTA" > /dev/null
contiene "Cancelar el plan: rechazado con el aviso neutro" "$TMP/pag.html" "Esta acción no está disponible en la app."
check "… y el plan no quedó cancelado" "0" "$(sql "SELECT count(*) FROM tukan_cancelaciones WHERE cliente_id = '$SP'" 2>/dev/null || echo 0)"
entrar "$EP" web; get "r=$RUTA" > /dev/null
no_contiene "En la web tampoco aparece cancelado" "$TMP/pag.html" "Tu plan quedó cancelado"

echo "4. La cookie de ?app=ios basta (aunque el User-Agent no traiga TukanIOS)"
entrar "$EP" web "app=ios"
check "Mi plan abre" "200" "$(get "r=$RUTA")"
contiene "Con la marca tukan-ios" "$TMP/pag.html" 'tukan-ios'
no_contiene "Sin «Paga tu plan con tarjeta»" "$TMP/pag.html" 'id="pago-tarjeta"'

echo "5. Demo desde el iPhone: sin cotización ni WhatsApp"
ED="iphone.demo$RANDOM@prueba.ec"
SD=$(demo "$NEGOCIO iPhone Demo" "$ED" "$COM")
entrar "$ED" ios
check "Mi plan abre" "200" "$(get "r=$RUTA")"
sin_venta "Mi plan (demo)" "$TMP/pag.html"
entrar "$ED" web; get "r=$RUTA" > /dev/null
contiene "En la web la demo sigue con su botón de WhatsApp" "$TMP/pag.html" 'wa\.me/'

echo "6. Avisos de la cuenta (plan por vencer): en el iPhone solo el texto, sin botón"
sql "UPDATE $TABLA SET activo_hasta = CURRENT_DATE + 2 WHERE id = $SP" > /dev/null
entrar "$EP" ios; get "r=inicio" > /dev/null
contiene "Dice que el plan vence en 2 días" "$TMP/pag.html" "Tu plan vence en 2 días."
no_contiene "… sin botón al lado" "$TMP/pag.html" 'Tu plan vence en 2 días.</span><a'
no_contiene "… ni «Renovar» en el Inicio" "$TMP/pag.html" '>Renovar<'
get "r=clientes" > /dev/null
contiene "En otra pantalla, el mismo aviso" "$TMP/pag.html" "Tu plan vence en 2 días."
no_contiene "… sin botón «Renovar»" "$TMP/pag.html" '>Renovar<'
entrar "$EP" web; get "r=clientes" > /dev/null
contiene "… y en otra pantalla con «Renovar»" "$TMP/pag.html" '>Renovar<'

echo "7. Plan vencido: la pantalla de plan vencido sin cómo pagar"
sql "UPDATE $TABLA SET activo_hasta = CURRENT_DATE - 60 WHERE id = $SP" > /dev/null
entrar "$EP" ios
r=$(curl -s -A "$UA" -b "$JAR" -c "$JAR" -o /dev/null -w "%{redirect_url}" "$BASE/?r=inicio")
check "Lleva a la pantalla de plan vencido" "$BASE/?r=prueba_terminada" "$r"
check "Plan vencido abre" "200" "$(get "r=prueba_terminada")"
sin_venta "Plan vencido" "$TMP/pag.html"
contiene "Dice que el plan venció (información neutra)" "$TMP/pag.html" "Tu plan venció"
check "Mi plan abre con el plan vencido" "200" "$(get "r=$RUTA")"
sin_venta "Mi plan (vencido)" "$TMP/pag.html"
entrar "$EP" web; get "r=prueba_terminada" > /dev/null
contiene "En la web sigue el WhatsApp para contratar" "$TMP/pag.html" 'wa\.me/'

echo "8. Demo terminada: «Tu periodo de prueba terminó.» y nada más"
sql "UPDATE $TABLA SET prueba_hasta = CURRENT_DATE - 2 WHERE id = $SD" > /dev/null
entrar "$ED" ios
check "Prueba terminada abre" "200" "$(get "r=prueba_terminada")"
sin_venta "Prueba terminada" "$TMP/pag.html"
contiene "Dice que la prueba terminó" "$TMP/pag.html" "Tu periodo de prueba terminó."

echo "9. Módulo no incluido en el plan"
EM="iphone.modulo$RANDOM@prueba.ec"
SM=$(demo "$NEGOCIO iPhone Modulo" "$EM" "$BAS" "" pagado)
sql "UPDATE $TABLA SET modulos_extra = '' WHERE id = $SM" > /dev/null 2>&1
entrar "$EM" ios
check "La página de un módulo apagado abre «No está en tu plan»" "403" "$(get "r=$RUTA_MODULO")"
sin_venta "Módulo no incluido" "$TMP/pag.html"
contiene "Dice «Este módulo no está incluido en tu plan.»" "$TMP/pag.html" "Este módulo no está incluido en tu plan."
check "Factura electrónica (módulo apagado) abre" "200" "$(get "r=tufactura")"
sin_venta "Factura electrónica apagada" "$TMP/pag.html"
no_contiene "… sin «Quiero facturar con TuFactura»" "$TMP/pag.html" "Quiero facturar"
no_contiene "… sin «Pregunta a Tukán»" "$TMP/pag.html" "Pregunta a Tukán"
C1=$(sql "SELECT count(*) FROM pedidos_modulos WHERE $FK = $SM")
post "r=$RUTA" -d accion=pedir -d "modulo=$MODULO" > /dev/null
check "Desde el iPhone no se puede pedir (comprar) un módulo que no está en el plan" "$C1" "$(sql "SELECT count(*) FROM pedidos_modulos WHERE $FK = $SM")"
entrar "$EM" web; get "r=$RUTA_MODULO" > /dev/null
contiene "En la web sigue «$PEDIR»" "$TMP/pag.html" "$PEDIR"

echo "10. Solicitar demo (registro) desde el iPhone, y la página de gracias"
BASE=$BASE0; JAR="$TMP/c_registro"; UA=$UA_IOS
check "El formulario abre con ?app=ios" "200" "$(get "r=registro&app=ios")"
sin_venta "Registro" "$TMP/pag.html"
check "Deja la cookie canal=ios" "1" "$(grep -c 'canal	ios' "$JAR")"
N=$RANDOM
r=$(post "r=registro" --data-urlencode "nombre=Ana iPhone" --data-urlencode "nombre_comercial=$NEGOCIO iPhone $N" -d celular=0991234567 \
    --data-urlencode "direccion=Av. Amazonas N34-12" -d ciudad=Quito -d "email=iphone.registro$N@prueba.ec")
check "Pedido completo: página de gracias" "302 $BASE/?r=registro" "$r"
get "r=registro" > /dev/null
contiene "Es la página de gracias" "$TMP/pag.html" "data-gracias"
sin_venta "Registro (gracias)" "$TMP/pag.html"
JAR="$TMP/c_registro_web"; UA=$UA_WEB; N=$RANDOM
post "r=registro" --data-urlencode "nombre=Ana Web" --data-urlencode "nombre_comercial=$NEGOCIO Web $N" -d celular=0991234567 \
    --data-urlencode "direccion=Av. Amazonas N34-12" -d ciudad=Quito -d "email=web.registro$N@prueba.ec" > /dev/null
get "r=registro" > /dev/null
contiene "En la web la página de gracias sigue con su WhatsApp" "$TMP/pag.html" 'wa\.me/'
no_contiene "… y sin la marca tukan-ios" "$TMP/pag.html" 'tukan-ios'

echo
echo "Resultado: $OK bien, $FALLAS fallas"
[ "$FALLAS" -eq 0 ]
