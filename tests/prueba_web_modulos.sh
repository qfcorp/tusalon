#!/usr/bin/env bash
# Anexo B (programas modulares) y "todo se pide demo", de punta a punta por HTTP (lo corre tests/run_web.sh):
#   - "No está en tu plan" (403) en cada página de un módulo apagado, con el enlace a Mi plan; menús y botones escondidos
#   - páginas públicas (reservas, horas libres) de un módulo apagado: "no disponible" (403) sin borrar nada
#   - cuentas de peluquero y de recepción sin su módulo: no entran
#   - Mi plan: módulos encendidos, los que puede agregar SIN su precio («Precio por cotización») y "Pedir cotización"
#   - Panel Tukán: interruptores por salón (con precio acordado), aprobar el pedido y el catálogo de módulos y planes
#   - pedidos de demo en el panel de Tukán → crear la demo con los módulos elegidos
#   - planes de $20 y $45 (migración 011): Básica con 3 módulos a elegir ($5 cada uno más, Asistente IA aparte) y Completa con todo
# Uso suelto: BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web_modulos.sh
set -u
cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export TZ=America/Guayaquil
TMP=$(mktemp -d)
JAR="$TMP/cookies"
OK=0; FALLAS=0
sql() { psql -h 127.0.0.1 -U tusalon -d "$DB" -tAc "$1"; }
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -qi -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local ruta=$1; shift; get "$ruta" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
demo() { # salon email plan [modulos] [pagado]  -> id del salón
  TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php tests/demo_web.php "$1" "Dueña Módulos" 0990003333 "$2" claveSegura1 "$3" "${4:-}" "${5:-}"
}
entrar() { JAR="$TMP/c_$RANDOM$RANDOM"; post "r=login" --data-urlencode "email=$1" -d clave="$2" > /dev/null; }
# Los salones que se usan en las capturas (tests/capturas_modulos.js) quedan en $TMP/capturas.env
CAPT=${CAPTURAS_ENV:-$TMP/capturas.env}

# Secciones 1 a 7: la Básica como antes de la migración 011 ($25, solo la base, extras a su precio del catálogo).
# Los planes de $20 y $45 se prueban en la sección 8.
sql "UPDATE planes SET precio_mensual = 25, modulos_elegir = 0, precio_modulo_extra = NULL WHERE codigo = 'basica'; UPDATE planes SET precio_mensual = 35 WHERE codigo = 'completa'" > /dev/null
echo "1. Salón Básica pagado (solo la base): cada página de un módulo dice «No está en tu plan»"
EB="basica$RANDOM@prueba.ec"
SB=$(demo "Barbería Solo Base" "$EB" basica "" pagado)
SLUGB=$(sql "SELECT slug FROM salones WHERE id=$SB")
entrar "$EB" claveSegura1
JAR_B=$JAR
for par in inventario:inventario paquetes:paquetes_regalo tarjetas:paquetes_regalo puntos:puntos gastos:gastos_sri reporte:reportes recepcion:recepcion \
           ficha:ficha_tecnica mi_portal:portal_peluquero; do
  ruta=${par%%:*}; mod=${par#*:}
  code=$(get "r=$ruta")
  if [ "$ruta" = "mi_portal" ]; then   # el dueño no tiene portal de peluquero: lo manda a su inicio
    check "mi_portal (dueño) vuelve al inicio" "302" "$code"; continue
  fi
  check "$ruta: 403 «No está en tu plan»" "403" "$code"
  contiene "… $ruta enlaza a Mi plan con su módulo" "$TMP/pag.html" "r=completa&amp;modulo=$mod"
done
get "r=gastos" > /dev/null
no_contiene "La página ya no dice el precio del módulo (regla del dueño)" "$TMP/pag.html" "al mes"
no_contiene "… ni ningún monto" "$TMP/pag.html" '\$[0-9]'
contiene "… y ofrece «Pedir cotización»" "$TMP/pag.html" "Pedir cotización"
contiene "… y que los datos se guardan" "$TMP/pag.html" "tus datos están guardados"
get "r=inicio" > /dev/null
for r in inventario paquetes tarjetas puntos gastos reporte recepcion; do
  no_contiene "El menú no muestra $r" "$TMP/pag.html" "href=\"/?r=$r\""
done
contiene "El menú sí muestra Mi plan" "$TMP/pag.html" 'href="/?r=completa"'
contiene "La tarjeta de gastos del inicio enlaza a Mi plan" "$TMP/pag.html" "modulo=gastos_sri"
get "r=horario" > /dev/null
contiene "Horario: reservas en línea dice «no está en tu plan»" "$TMP/pag.html" 'data-modulo-apagado="reservas_linea"'
contiene "Horario: anticipo también" "$TMP/pag.html" 'data-modulo-apagado="anticipos"'
get "r=cobrar" > /dev/null
no_contiene "Cobrar no ofrece productos ni tarjetas de regalo" "$TMP/pag.html" 'name="tarjeta_codigo"'
get "r=avisos_clientes" > /dev/null
contiene "Clientes por recuperar: no está en tu plan" "$TMP/pag.html" 'data-modulo-apagado="recuperar_clientes"'

echo "2. Páginas públicas de un módulo apagado"
code=$(curl -s -o "$TMP/pub.html" -w '%{http_code}' "$BASE/?r=reservar&s=$SLUGB")
check "Reservas en línea apagada: 403" "403" "$code"
contiene "… «no está disponible en este salón»" "$TMP/pub.html" "no está disponible en este salón"
code=$(curl -s -o "$TMP/horas.json" -w '%{http_code}' "$BASE/?r=horas&s=$SLUGB&p=1&serv=1&f=$(date -d '+1 day' +%F)")
check "Horas libres apagada: 403 JSON" "403" "$code"
contiene "… dice disponible: false" "$TMP/horas.json" '"disponible":false'
check "Mi cuenta del cliente apagada: 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=mi_cuenta&s=$SLUGB")"

echo "3. Cuentas de peluquero y de recepción sin su módulo"
LUISB=$(sql "INSERT INTO profesionales (salon_id, nombre, tipo) VALUES ($SB, 'Luis Base', 'empleado') RETURNING id" | head -1)
sql "INSERT INTO reglas_pago (profesional_id, vigente_desde, comision_servicio_pct) VALUES ($LUISB, CURRENT_DATE, 40)" > /dev/null
ELB="luisb$RANDOM@prueba.ec"
HASH=$(php -r 'echo password_hash("claveLuis1", PASSWORD_DEFAULT);')
sql "INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol) VALUES ($SB, $LUISB, 'Luis Base', '$ELB', '$HASH', 'profesional')" > /dev/null
entrar "$ELB" claveLuis1
check "El peluquero sin «Portal del peluquero» no entra (403)" "403" "$(get "r=mi_portal")"
contiene "… y se le explica por qué" "$TMP/pag.html" "El portal del peluquero no está en el plan de este salón"
ERB="recb$RANDOM@prueba.ec"
sql "INSERT INTO usuarios (salon_id, nombre, email, password_hash, rol) VALUES ($SB, 'Rec Base', '$ERB', '$HASH', 'recepcion')" > /dev/null
entrar "$ERB" claveLuis1
check "Recepción sin su módulo no entra (403)" "403" "$(get "r=inicio")"

echo "4. Mi plan: lo que tiene, lo que puede agregar y «Pedir cotización»"
JAR=$JAR_B
check "Mi plan abre" "200" "$(get "r=completa")"
contiene "Muestra lo que paga al mes" "$TMP/pag.html" 'data-total-mensual>\$25,00'
contiene "Lista la base" "$TMP/pag.html" "Lo que siempre tienes"
no_contiene "Los módulos que no tiene no muestran precio" "$TMP/pag.html" '+\$'
contiene "… dicen «Precio por cotización»" "$TMP/pag.html" "Precio por cotización"
contiene "… y «Pedir cotización»" "$TMP/pag.html" "Pedir cotización"
no_contiene "Ya no dice «Pedir este módulo»" "$TMP/pag.html" "Pedir este módulo"
no_contiene "Sin el precio del plan Completa" "$TMP/pag.html" "\$$(sql "SELECT to_char(precio_mensual,'FM999990.00') FROM planes WHERE codigo='completa'" | sed 's/\./,/')"
no_contiene "Sin la palabra prohibida en Mi plan" "$TMP/pag.html" "gratis"
r=$(post "r=completa" -d accion=pedir -d modulo=inventario)
check "Pedir vuelve a Mi plan" "302 $BASE/?r=completa" "$r"
get "r=completa" > /dev/null
contiene "Dice que Tukán escribe con el valor" "$TMP/pag.html" "Tukán te escribe con el valor"
contiene "El pedido aparece como pendiente" "$TMP/pag.html" "Tukán lo está revisando"
PM=$(sql "SELECT id FROM pedidos_modulos WHERE salon_id=$SB AND modulos='inventario'")
check "Pedido guardado" "pendiente" "$(sql "SELECT estado FROM pedidos_modulos WHERE id=$PM")"
post "r=completa" -d accion=pedir -d modulo=inventario > /dev/null
check "Pedir dos veces no duplica" "1" "$(sql "SELECT count(*) FROM pedidos_modulos WHERE salon_id=$SB AND modulos='inventario'")"
post "r=completa" -d accion=pedir -d modulo=oro > /dev/null
check "Módulo que no existe no se guarda" "0" "$(sql "SELECT count(*) FROM pedidos_modulos WHERE modulos='oro'")"
get "r=completa&modulo=gastos_sri" > /dev/null
contiene "Desde «No está en tu plan» resalta el módulo" "$TMP/pag.html" 'class="modulo modulo-activo" id="modulo-gastos_sri"'
get "r=gastos" > /dev/null
contiene "«No está en tu plan»: el botón Pedir cotización va a Mi plan" "$TMP/pag.html" 'action="/?r=completa"'
post "r=completa" -d accion=pedir -d modulo=gastos_sri > /dev/null
check "… y su pedido llega a Tukán (cambio_modulos)" "pendiente" "$(sql "SELECT estado FROM pedidos_modulos WHERE salon_id=$SB AND modulos='gastos_sri'")"

echo "5. Panel Tukán: aprobar el pedido, interruptores y precio acordado"
JAR="$TMP/c_admin"
ADM="tukanmod$RANDOM@tukan.ec"
TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD TUSALON_ADMIN_NOMBRE="Dimitry Tukán" TUSALON_ADMIN_EMAIL=$ADM \
  TUSALON_ADMIN_CLAVE=claveAdminSegura1 php bin/crear_admin.php > /dev/null
post "r=admin_entrar" -d email=$ADM -d clave=claveAdminSegura1 > /dev/null
get "r=admin" > /dev/null
contiene "El resumen lista los salones que piden módulos" "$TMP/pag.html" "Salones que piden módulos"
get "r=admin_salon&id=$SB" > /dev/null
contiene "La ficha tiene la sección Módulos" "$TMP/pag.html" 'id="modulos"'
contiene "… con el pedido para aprobar" "$TMP/pag.html" "Aprobar y encender"
contiene "… un interruptor por módulo" "$TMP/pag.html" 'name="mod\[reservas_linea\]"'
r=$(post "r=admin_salon&id=$SB" -d accion=pedido_modulos -d pedido=$PM -d resultado=aprobar)
check "Aprobar el pedido" "atendida" "$(sql "SELECT estado FROM pedidos_modulos WHERE id=$PM")"
check "… enciende Inventario como extra" "inventario" "$(sql "SELECT modulos_extra FROM salones WHERE id=$SB")"
JAR=$JAR_B
check "El dueño ya entra a Inventario" "200" "$(get "r=inventario")"
JAR="$TMP/c_admin"
r=$(post "r=admin_salon&id=$SB" -d accion=modulos -d "mod[inventario]=1" -d "mod[reservas_linea]=1" -d "mod[anticipos]=1" -d precio_acordado=30 --data-urlencode "motivo=Promoción")
check "Guardar interruptores: encendidos y precio acordado" "reservas_linea,anticipos,inventario|30.00" "$(sql "SELECT modulos_extra||'|'||precio_acordado FROM salones WHERE id=$SB")"
check "… queda en el historial de módulos" "1" "$(sql "SELECT count(*) FROM modulos_cambios WHERE salon_id=$SB AND motivo='Promoción'")"
check "… y en los movimientos del panel" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE salon_id=$SB AND accion LIKE 'Módulos: Encendió%'")"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=reservar&s=$SLUGB")
check "Con Reservas encendido, la página pública abre" "200" "$code"
r=$(post "r=admin_salon&id=$SB" -d accion=modulos -d "mod[inventario]=1" -d "mod[anticipos]=1" -d precio_acordado=)
check "Marcar Anticipo sin Reservas: se enciende lo que necesita" "reservas_linea,anticipos,inventario|" "$(sql "SELECT modulos_extra||'|'||COALESCE(precio_acordado::text,'') FROM salones WHERE id=$SB")"
r=$(post "r=admin_salon&id=$SB" -d accion=modulos -d "mod[inventario]=1")
check "Apagar Reservas y Anticipo juntos" "inventario" "$(sql "SELECT modulos_extra FROM salones WHERE id=$SB")"
check "Apagar no borra los datos (el producto sigue)" "ok" "$(sql "INSERT INTO productos (salon_id, nombre, precio) VALUES ($SB, 'Cera', 5)" > /dev/null; post "r=admin_salon&id=$SB" -d accion=modulos > /dev/null; [ "$(sql "SELECT count(*) FROM productos WHERE salon_id=$SB")" = "1" ] && echo ok)"
check "… y al encender vuelve igual" "1" "$(post "r=admin_salon&id=$SB" -d accion=modulos -d "mod[inventario]=1" > /dev/null; sql "SELECT count(*) FROM productos WHERE salon_id=$SB AND nombre='Cera'")"

echo "6. Catálogo de módulos y planes"
get "r=admin_planes" > /dev/null
contiene "Planes y precios tiene el catálogo de módulos" "$TMP/pag.html" 'id="catalogo"'
r=$(post "r=admin_planes" -d accion=catalogo -d "m[reservas_linea][precio_mensual]=6" -d "m[reservas_linea][descuento]=2" -d "m[reservas_linea][orden]=10" \
     --data-urlencode "m[reservas_linea][descripcion]=Tu enlace de reservas en línea para tus clientes" -d "plan_mod[basica][telegram]=1" \
     $(for m in reservas_linea anticipos ficha_tecnica recuperar_clientes equipo_ilimitado portal_peluquero clientes_privados recepcion inventario paquetes_regalo puntos gastos_sri reportes telegram asistente_ia; do printf -- '-d plan_mod[completa][%s]=1 ' $m; done))
check "Guardar precio de un módulo" "6.00" "$(sql "SELECT precio_mensual FROM modulos WHERE id='reservas_linea'")"
check "Básica ahora trae Telegram; Completa sigue con todos" "telegram|*" "$(sql "SELECT string_agg(modulos, '|' ORDER BY codigo) FROM planes")"
post "r=admin_planes" -d accion=catalogo -d "m[reservas_linea][precio_mensual]=5" \
     $(for m in reservas_linea anticipos ficha_tecnica recuperar_clientes equipo_ilimitado portal_peluquero clientes_privados recepcion inventario paquetes_regalo puntos gastos_sri reportes telegram asistente_ia; do printf -- '-d plan_mod[completa][%s]=1 ' $m; done) > /dev/null
check "Volver a como estaba" "5.00|*" "$(sql "SELECT precio_mensual FROM modulos WHERE id='reservas_linea'")|$(sql "SELECT string_agg(modulos, '|' ORDER BY codigo) FROM planes WHERE codigo='completa'")"

echo "7. Pedidos de demo en el panel de Tukán"
JAR="$TMP/c_pub"; rm -f "$JAR"
EP="pedido$RANDOM@prueba.ec"
post "r=registro" --data-urlencode "nombre=Carla Pide" --data-urlencode "salon=Salón Carla Pide" --data-urlencode "ciudad=Cuenca" -d whatsapp=0995556666 \
     --data-urlencode "email=$EP" --data-urlencode "necesita=Quiero reservas" -d "modulos[]=reservas_linea" > /dev/null
PD=$(sql "SELECT id FROM pedidos_demo WHERE email='$EP'")
JAR="$TMP/c_admin"
get "r=admin" > /dev/null
contiene "El resumen lista los pedidos de demo" "$TMP/pag.html" "Salón Carla Pide"
contiene "… con «Crear demo»" "$TMP/pag.html" "r=admin_nuevo&amp;pedido=$PD"
get "r=admin_nuevo&pedido=$PD" > /dev/null
contiene "Nueva demo llena los datos del pedido" "$TMP/pag.html" "value=\"$EP\""
r=$(post "r=admin_nuevo&pedido=$PD" -d pedido=$PD --data-urlencode "salon=Salón Carla Pide" --data-urlencode "nombre=Carla Pide" -d telefono=0995556666 \
     -d email=$EP -d plan=basica -d solo=1 -d "mod[reservas_linea]=1")
SC=$(sql "SELECT salon_id FROM usuarios WHERE email='$EP'")
check "Crea la demo con solo Reservas" "reservas_linea|prueba|demo_pedida" "$(sql "SELECT modulos_demo||'|'||estado||'|'||origen FROM salones WHERE id=$SC")"
check "El pedido queda «demo creada»" "demo_creada|$SC" "$(sql "SELECT estado||'|'||salon_creado_id FROM pedidos_demo WHERE id=$PD")"
check "Lo que pidió queda en las notas" "1" "$(sql "SELECT count(*) FROM salones WHERE id=$SC AND notas_admin LIKE '%Quiero reservas%'")"
EP2="pedido2$RANDOM@prueba.ec"
JAR="$TMP/c_pub2"
post "r=registro" --data-urlencode "nombre=Otro Pide" --data-urlencode "salon=Salón Descartado" --data-urlencode "ciudad=Loja" -d whatsapp=0995557777 --data-urlencode "email=$EP2" > /dev/null
PD2=$(sql "SELECT id FROM pedidos_demo WHERE email='$EP2'")
JAR="$TMP/c_admin"
post "r=admin" -d pedido=$PD2 -d estado=descartada > /dev/null
check "Descartar un pedido de demo" "descartada" "$(sql "SELECT estado FROM pedidos_demo WHERE id=$PD2")"

echo "8. Planes de \$20 y \$45 (migración 011): Básica con 3 módulos a elegir, Completa con todo"
sql "UPDATE planes SET precio_mensual = 20, modulos = '', modulos_elegir = 3, precio_modulo_extra = 5 WHERE codigo = 'basica'; UPDATE planes SET precio_mensual = 45, modulos = '*' WHERE codigo = 'completa'" > /dev/null
E20="veinte$RANDOM@prueba.ec"
S20=$(demo "Barbería Veinte" "$E20" basica "" pagado)
sql "UPDATE salones SET modulos_extra = 'reservas_linea,ficha_tecnica,inventario,puntos,asistente_ia' WHERE id = $S20" > /dev/null
entrar "$E20" claveSegura1
JAR_20=$JAR
get "r=completa" > /dev/null
contiene "Mi plan dice cuántos módulos trae a elección" "$TMP/pag.html" "3 módulos a tu elección"
contiene "… los 3 elegidos dicen «Incluido»" "$TMP/pag.html" "elegido, incluido en el plan"
contiene "… el cuarto módulo cuesta \$5" "$TMP/pag.html" "Extra · \$5,00 al mes"
contiene "… total: \$20 + \$5 (cuarto módulo) + \$5 (Asistente IA) = \$30" "$TMP/pag.html" 'data-total-mensual>\$30,00'
contiene "… y el semestral: 5 × \$30" "$TMP/pag.html" '\$150,00'
sql "UPDATE salones SET modulos_extra = 'reservas_linea' WHERE id = $S20" > /dev/null
get "r=completa" > /dev/null
contiene "Con 1 elegido: le quedan 2 por elegir" "$TMP/pag.html" 'data-por-elegir="2"'
contiene "… y los demás dicen «Puedes elegirlo en tu plan»" "$TMP/pag.html" "Puedes elegirlo en tu plan"
no_contiene "… sin el precio de lo que no tiene" "$TMP/pag.html" '+\$'
contiene "… el total sigue en \$20" "$TMP/pag.html" 'data-total-mensual>\$20,00'
sql "UPDATE salones SET modulos_extra = '' WHERE id = $S20" > /dev/null
get "r=completa" > /dev/null
contiene "Sin módulos todavía: «la base y 3 módulos a tu elección: elígelos abajo»" "$TMP/pag.html" "3 módulos a tu elección: elígelos abajo"
r=$(post "r=completa" -d accion=pedir -d modulo=telegram)
check "Pedir uno de los que puede elegir (Telegram) guarda el pedido" "pendiente" "$(sql "SELECT estado FROM pedidos_modulos WHERE salon_id=$S20 AND modulos='telegram'")"
E45="cuarenta$RANDOM@prueba.ec"
S45=$(demo "Salón Cuarenta y cinco" "$E45" completa "" pagado)
entrar "$E45" claveSegura1
get "r=completa" > /dev/null
contiene "Completa: paga \$45 con todo" "$TMP/pag.html" 'data-total-mensual>\$45,00'
contiene "… con el Asistente IA incluido" "$TMP/pag.html" 'data-modulo="asistente_ia"'
no_contiene "… y sin módulos a elegir" "$TMP/pag.html" "a tu elección"
JAR="$TMP/c_admin"
get "r=admin_planes" > /dev/null
contiene "Planes y precios: campo «Módulos que elige el salón»" "$TMP/pag.html" 'name="modulos_elegir"'
contiene "… y «Cada módulo más»" "$TMP/pag.html" 'name="precio_modulo_extra"'
contiene "… Google Play sugiere \$23,99 para la Básica de \$20" "$TMP/pag.html" '\$23,99'
contiene "… y \$52,99 para la Completa de \$45" "$TMP/pag.html" '\$52,99'
r=$(post "r=admin_planes" -d accion=plan -d codigo=basica --data-urlencode "nombre=Básica" -d precio_mensual=20 -d max_profesionales=3 -d modulos_elegir=4 -d precio_modulo_extra=4)
check "Guardar 4 módulos a elegir a \$4 cada uno más" "4|4.00" "$(sql "SELECT modulos_elegir||'|'||precio_modulo_extra FROM planes WHERE codigo='basica'")"
r=$(post "r=admin_planes" -d accion=plan -d codigo=basica --data-urlencode "nombre=Básica" -d precio_mensual=20 -d max_profesionales=3 -d modulos_elegir=3 -d precio_modulo_extra=)
check "Vacío = el precio del catálogo" "3|" "$(sql "SELECT modulos_elegir||'|'||coalesce(precio_modulo_extra::text,'') FROM planes WHERE codigo='basica'")"
post "r=admin_planes" -d accion=plan -d codigo=basica --data-urlencode "nombre=Básica" -d precio_mensual=20 -d max_profesionales=3 -d modulos_elegir=3 -d precio_modulo_extra=5 > /dev/null
check "Queda en 3 a elegir y \$5 cada uno más" "3|5.00" "$(sql "SELECT modulos_elegir||'|'||precio_modulo_extra FROM planes WHERE codigo='basica'")"

# Para las capturas de pantalla
{
  echo "BASE=$BASE"; echo "EMAIL_BASICA=$EB"; echo "CLAVE=claveSegura1"; echo "ADMIN=$ADM"; echo "ADMIN_CLAVE=claveAdminSegura1"; echo "SALON_BASICA=$SB"
} > "$CAPT"

echo
echo "Resultado: $OK correctas, $FALLAS fallidas"
[ "$FALLAS" -eq 0 ]
