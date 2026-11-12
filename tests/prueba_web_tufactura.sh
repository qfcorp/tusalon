#!/usr/bin/env bash
# Factura electrónica con TuFactura (módulo facturacion, 9 nov 2026), de punta a punta por HTTP con el TuFactura SIMULADO:
#   apagado se ve la pantalla para pedirlo · encendido: conectar la clave (mala y buena) · facturar un cobro · PDF · automático.
# Lo corre tests/run_web.sh (usa BASE y DB). Uso suelto: BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web_tufactura.sh
set -u
cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
TMP=$(mktemp -d)
JAR="$TMP/cookies"
OK=0; FALLAS=0
sql() { psql -h 127.0.0.1 -U tusalon -d "$DB" -tAc "$1"; }
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -q -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local ruta=$1; shift; get "$ruta" > /dev/null; local t; t=$(csrf)
  curl -s -L -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
puerto_libre() { php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);'; }

# TuFactura simulado
PS=$(puerto_libre)
TF_SIM_DIR="$TMP/sim" php -S "127.0.0.1:$PS" tests/tufactura_simulado.php > /dev/null 2>&1 &
SIMPID=$!
trap 'kill $SIMPID 2>/dev/null; rm -rf "$TMP"' EXIT
mkdir -p "$TMP/sim"
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PS/" && break; sleep 0.1; done

echo "== Factura electrónica con TuFactura"
EMAIL="tf$RANDOM$RANDOM@prueba.ec"
TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php tests/demo_web.php "Negocio Factura" "Dueña Prueba" 0990002222 "$EMAIL" claveSegura1 completa "" pagado > /dev/null
SID=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL'")
post "r=login" --data-urlencode "email=$EMAIL" -d clave=claveSegura1 > /dev/null
get "r=inicio" > /dev/null
contiene "El menú tiene «Factura electrónica»" "$TMP/pag.html" "r=tufactura"
check "Apagado: la pantalla abre (para venderlo)" "200" "$(get 'r=tufactura')"
contiene "Apagado: explica TuFactura y dice Próximamente (sin botón para pedirla)" "$TMP/pag.html" "Próximamente"
no_contiene "Apagado: sin botón para pedirla ni precio" "$TMP/pag.html" "modulo=facturacion"
no_contiene "Apagado: sin el precio de \$30" "$TMP/pag.html" '$30'
no_contiene "Apagado: no pide la clave" "$TMP/pag.html" "Conectar y probar"

sql "UPDATE salones SET modulos_extra = CASE WHEN COALESCE(modulos_extra, '') = '' THEN 'facturacion' ELSE modulos_extra || ',facturacion' END WHERE id = $SID" > /dev/null
get "r=tufactura" > /dev/null
contiene "Encendido: pide conectar la cuenta" "$TMP/pag.html" "Conectar y probar"
post "r=tufactura" -d tf_accion=conectar -d clave=tf_mala -d "url=http://127.0.0.1:$PS" > /dev/null
contiene "Clave mala: aviso claro" "$TMP/pag.html" "empieza con tf_"
post "r=tufactura" -d tf_accion=conectar -d "clave=tf_$(printf 'dead%.0s' $(seq 10))" -d "url=http://127.0.0.1:$PS" > /dev/null
contiene "Clave desactivada en TuFactura: aviso" "$TMP/pag.html" "no válida o desactivada"
post "r=tufactura" -d tf_accion=conectar -d "clave=tf_$(printf 'ab12%.0s' $(seq 10))" -d "url=http://127.0.0.1:$PS" > /dev/null
contiene "Clave buena: conectado" "$TMP/pag.html" "Conectado con TuFactura"
contiene "Muestra la empresa de TuFactura" "$TMP/pag.html" "EMPRESA DE PRUEBA S.A."
check "La clave queda cifrada en la base" "1" "$(sql "SELECT count(*) FROM tukan_factura_conexion WHERE negocio_id = $SID AND clave_cif LIKE 'v1:%' AND clave_cif NOT LIKE '%ab12ab12%'")"

# Un cobro y su factura
VID=$(TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php -r '
spl_autoload_register(function ($c) { if (str_starts_with($c, "TuSalon\\") && is_file($f = "src/" . substr($c, strlen("TuSalon\\")) . ".php")) require $f; });
$db = TuSalon\Db::conectar(); $sid = (int) $argv[1];
$db->prepare("INSERT INTO servicios (salon_id, nombre, precio) VALUES (?, ?, ?)")->execute([$sid, "Servicio facturable", 23]);
$s = (int) $db->lastInsertId(); $p = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = $sid ORDER BY id LIMIT 1")->fetchColumn();
echo (new TuSalon\Liquidacion($db))->registrarVenta(["salon_id" => $sid, "fecha" => date("Y-m-d H:i:s"), "cobrado_por" => "local", "metodo_pago" => "efectivo", "propina" => 0, "cliente_id" => null],
  [["tipo" => "servicio", "servicio_id" => $s, "profesional_id" => $p, "precio_unitario" => 23]]);' "$SID")
get "r=tufactura" > /dev/null
contiene "Cobros recientes con el botón Facturar" "$TMP/pag.html" "name=\"origen_id\" value=\"$VID\""
post "r=tufactura" -d tf_accion=facturar_origen -d "origen_id=$VID" > /dev/null
contiene "Facturar: autorizada" "$TMP/pag.html" "Autorizada"
contiene "Facturar: número de la factura" "$TMP/pag.html" "001-002-000000001"
DOC=$(sql "SELECT id FROM tukan_factura_documentos WHERE negocio_id = $SID AND origen_id = '$VID'")
check "PDF de la factura" "application/pdf" "$(curl -s -b "$JAR" -o "$TMP/f.pdf" -w '%{content_type}' "$BASE/?r=tufactura&pdf=$DOC")"
contiene "El PDF es un PDF" "$TMP/f.pdf" "%PDF"
post "r=tufactura" -d tf_accion=facturar_origen -d "origen_id=$VID" > /dev/null
check "No se factura dos veces" "1" "$(sql "SELECT count(*) FROM tukan_factura_documentos WHERE negocio_id = $SID")"
post "r=tufactura" -d tf_accion=opciones -d auto=1 -d precios_con_iva=1 -d iva=15 > /dev/null
check "Opciones: automático guardado" "1" "$(sql "SELECT auto FROM tukan_factura_conexion WHERE negocio_id = $SID")"
post "r=tufactura" -d tf_accion=desconectar > /dev/null
contiene "Quitar la conexión" "$TMP/pag.html" "Conectar y probar"

echo
echo "TuFactura (pantallas): $OK bien, $FALLAS mal"
[ "$FALLAS" -eq 0 ]
