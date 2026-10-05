#!/usr/bin/env bash
# Prueba de punta a punta de las pantallas de TuSalón, como lo haría un dueño.
# Requiere: el sitio corriendo en $BASE y acceso psql a la base de prueba.
# Uso: BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web.sh
set -u
BASE=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
TMP=$(mktemp -d)
JAR="$TMP/cookies"; JAR2="$TMP/cookies2"
OK=0; FALLAS=0

sql() { psql -h 127.0.0.1 -U tusalon -d "$DB" -tAc "$1"; }
check() { # nombre esperado real
  if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1";
  else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salió [$3]"; fi
}
contiene() { # nombre archivo texto
  if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1";
  else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi
}
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { # ruta datos...  -> imprime "codigo destino"
  local ruta=$1; shift
  get "$ruta" > /dev/null
  local t; t=$(csrf)
  if [ -z "$t" ]; then get "r=cliente" > /dev/null; t=$(csrf); fi   # el token es de la sesión
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" \
       --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"
}

export TZ=America/Guayaquil   # misma zona horaria que el sistema
HOY=$(date +%F)
EMAIL="dueno$RANDOM@prueba.ec"

echo "1. Registro con prueba gratis (plan Básica)"
r=$(post "r=registro" --data-urlencode "salon=Barbería El Tucán" --data-urlencode "nombre=Dimitry Prueba" \
     --data-urlencode "telefono=0990000000" --data-urlencode "email=$EMAIL" --data-urlencode "clave=claveSegura1" \
     --data-urlencode "plan=basica")
check "Registro redirige al inicio" "302 $BASE/?r=inicio" "$r"
SID=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL'")
check "Prueba gratis hasta dentro de 7 días" "$(date -d '+7 days' +%F)" "$(sql "SELECT prueba_hasta FROM salones WHERE id=$SID")"
check "Se crean 5 servicios de ejemplo" "5" "$(sql "SELECT count(*) FROM servicios WHERE salon_id=$SID")"
get "r=inicio" > /dev/null
contiene "Inicio saluda al dueño" "$TMP/pag.html" "Hola, Dimitry"
contiene "Muestra la franja de prueba" "$TMP/pag.html" "te quedan 7 días"

echo "2. Equipo con los tipos y límite de Básica"
r=$(post "r=equipo" --data-urlencode "nombre=Ana" -d tipo=empleado -d sueldo_mensual=0 -d comision_servicio_pct=40 -d comision_producto_pct=10)
check "Agrega empleada Ana" "302 $BASE/?r=equipo" "$r"
r=$(post "r=equipo" --data-urlencode "nombre=Luis" -d tipo=porcentaje -d pct_profesional=60 -d quien_cobra=local)
check "Agrega a Luis por porcentaje" "302 $BASE/?r=equipo" "$r"
get "r=equipo" > /dev/null
contiene "Básica: con 3 personas pide pasar a Completa" "$TMP/pag.html" "Pasar al plan Completa"
r=$(post "r=equipo" --data-urlencode "nombre=Carlos" -d tipo=alquiler -d monto_arriendo=60 -d frecuencia_arriendo=semanal)
contiene "El 4.º peluquero se rechaza con mensaje claro" "$TMP/post.html" "hasta 3 profesionales"
check "Siguen 3 personas" "3" "$(sql "SELECT count(*) FROM profesionales WHERE salon_id=$SID")"

ANA=$(sql "SELECT id FROM profesionales WHERE salon_id=$SID AND nombre='Ana'")
LUIS=$(sql "SELECT id FROM profesionales WHERE salon_id=$SID AND nombre='Luis'")
CORTE=$(sql "SELECT id FROM servicios WHERE salon_id=$SID AND nombre='Corte de caballero'")
BARBA=$(sql "SELECT id FROM servicios WHERE salon_id=$SID AND nombre='Barba'")

echo "3. Agenda y citas"
r=$(post "r=cita_nueva" -d fecha=$HOY -d hora=10:00 -d profesional=$ANA -d cliente_id=0 \
     --data-urlencode "cliente_nuevo=Juan Pérez" -d telefono=0991234567 -d "servicios[]=$CORTE" -d "servicios[]=$BARBA")
CITA=$(sql "SELECT max(id) FROM citas WHERE salon_id=$SID")
check "Crea la cita y abre su ficha" "302 $BASE/?r=cita&id=$CITA" "$r"
check "Duración = corte 30 + barba 20 = 50 min" "10:50" "$(sql "SELECT to_char(fin,'HH24:MI') FROM citas WHERE id=$CITA")"
r=$(post "r=cita_nueva" -d fecha=$HOY -d hora=10:30 -d profesional=$ANA -d "servicios[]=$CORTE")
contiene "No deja agendar encima de otra cita" "$TMP/post.html" "choca con otra cita de las 10:00"
get "r=cita&id=$CITA" > /dev/null
contiene "Botón de WhatsApp con número ecuatoriano" "$TMP/pag.html" "wa.me/593991234567"
contiene "Mensaje de recordatorio listo" "$TMP/pag.html" "te%20recordamos%20tu%20cita"
get "r=agenda&fecha=$HOY" > /dev/null
contiene "La cita aparece en la agenda" "$TMP/pag.html" "Juan Pérez"
contiene "La agenda muestra la silla de Luis" "$TMP/pag.html" "Agenda de Luis"
r=$(post "r=cita&id=$CITA" -d estado=confirmada)
check "Confirmar cita" "confirmada" "$(sql "SELECT estado FROM citas WHERE id=$CITA")"
r=$(post "r=cita&id=$CITA" -d estado=atendida)
check "No se puede marcar 'atendida' sin cobrar" "confirmada" "$(sql "SELECT estado FROM citas WHERE id=$CITA")"

echo "4. Cobros"
r=$(post "r=cobrar&cita=$CITA" -d cita=$CITA -d profesional_id=$ANA -d "servicio[]=$CORTE" -d "servicio[]=$BARBA" \
     -d "precio[$CORTE]=6" -d "precio[$BARBA]=4" -d metodo_pago=efectivo -d propina=1 -d cobrado_por=local)
check "Cobro de la cita va a la caja" "302 $BASE/?r=caja" "$r"
check "La cita queda atendida" "atendida" "$(sql "SELECT estado FROM citas WHERE id=$CITA")"
r=$(post "r=cobrar" -d profesional_id=$LUIS -d "servicio[]=$CORTE" -d "precio[$CORTE]=10" -d metodo_pago=deuna -d propina=0 -d cobrado_por=local)
check "Cobro directo sin cita" "302 $BASE/?r=caja" "$r"
r=$(post "r=cobrar" -d profesional_id=$LUIS -d metodo_pago=efectivo -d cobrado_por=local)
contiene "Cobro sin servicios se rechaza" "$TMP/post.html" "Marca al menos un servicio"
get "r=caja" > /dev/null
contiene "Caja: entró \$21,00 (6+4+1+10)" "$TMP/pag.html" '\$21,00'
contiene "Caja: efectivo esperado \$11,00" "$TMP/pag.html" '\$11,00'
contiene "Caja: DeUna separado" "$TMP/pag.html" 'DeUna'
r=$(post "r=caja" -d efectivo_contado=10)
check "Cierre de caja con faltante de \$1" "-1.00" "$(sql "SELECT diferencia FROM cierres_caja WHERE salon_id=$SID")"
r=$(post "r=caja" -d efectivo_contado=10)
check "No se puede cerrar dos veces" "1" "$(sql "SELECT count(*) FROM cierres_caja WHERE salon_id=$SID")"

echo "5. Cuentas del equipo"
check "Ana: 40 % de \$10 + \$1 propina = \$5,00" "5.00" "$(sql "SELECT sum(monto) FROM movimientos_profesional WHERE profesional_id=$ANA")"
check "Luis: 60 % de \$10 = \$6,00" "6.00" "$(sql "SELECT sum(monto) FROM movimientos_profesional WHERE profesional_id=$LUIS")"
get "r=cuentas" > /dev/null
contiene "Cuentas muestra lo que se le paga a Luis" "$TMP/pag.html" '\$6,00'
r=$(post "r=cuentas" -d id=$ANA -d accion=cerrar)
check "Cerrar cuenta de Ana" "1" "$(sql "SELECT count(*) FROM liquidaciones WHERE profesional_id=$ANA")"
check "Saldo pendiente de Ana = 0" "0" "$(sql "SELECT count(*) FROM movimientos_profesional WHERE profesional_id=$ANA AND liquidacion_id IS NULL")"
get "r=inicio" > /dev/null
contiene "Inicio: ganancia del local \$10,00 (21 - 5 - 6)" "$TMP/pag.html" '\$10,00'
contiene "Básica: gastos con facturas bloqueado" "$TMP/pag.html" "Plan Completa"

echo "6. Clientes y servicios"
r=$(post "r=cliente" --data-urlencode "nombre=María López" -d telefono=0987654321 --data-urlencode "alergias=Amoníaco")
check "Crear cliente" "302 $BASE/?r=clientes" "$r"
get "r=clientes&q=mar" > /dev/null
contiene "Buscar cliente por nombre" "$TMP/pag.html" "María López"
r=$(post "r=servicios" -d accion=crear --data-urlencode "nombre=Corte degradado" -d precio=8 -d duracion_minutos=40)
check "Agregar servicio" "1" "$(sql "SELECT count(*) FROM servicios WHERE salon_id=$SID AND nombre='Corte degradado'")"

echo "7. Seguridad"
check "Sin sesión, manda al login" "302" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=caja")"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d "nombre=X" "$BASE/?r=cliente")
check "Formulario sin token se rechaza" "400" "$code"
# Otro salón no puede ver datos del primero
JAR_ORIG=$JAR; JAR=$JAR2
post "r=registro" --data-urlencode "salon=Otro Salón" --data-urlencode "nombre=Otra Persona" \
     --data-urlencode "email=otro$RANDOM@prueba.ec" --data-urlencode "clave=claveSegura2" -d plan=completa > /dev/null
get "r=cita&id=$CITA" > /dev/null
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=cita&id=$CITA")
check "Otro salón no ve la cita ajena" "302 $BASE/?r=agenda" "$code"
get "r=clientes" > /dev/null
if grep -q "Juan Pérez" "$TMP/pag.html"; then FALLAS=$((FALLAS+1)); echo "  FALLA Otro salón ve clientes ajenos"; else OK=$((OK+1)); echo "  OK    Otro salón no ve clientes ajenos"; fi
r=$(post "r=cuentas" -d id=$LUIS -d accion=cerrar)
check "Otro salón no puede cerrar cuentas ajenas" "0" "$(sql "SELECT count(*) FROM liquidaciones WHERE profesional_id=$LUIS")"
JAR=$JAR_ORIG
JAR="$TMP/cookies3"   # navegador nuevo, sin sesión
r=$(post "r=login" -d email=$EMAIL -d clave=equivocada)
JAR=$JAR_ORIG
contiene "Contraseña equivocada da mensaje claro" "$TMP/post.html" "no coinciden"

echo "8. Prueba gratis vencida"
sql "UPDATE salones SET prueba_hasta = '$(date -d '-1 day' +%F)' WHERE id=$SID" > /dev/null
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=agenda")
check "Prueba vencida lleva a 'Tu prueba terminó'" "302 $BASE/?r=prueba_terminada" "$code"
get "r=completa" > /dev/null
contiene "Puede ver planes y precios: Completa anual \$350" "$TMP/pag.html" '\$350,00'
sql "UPDATE salones SET prueba_hasta = '$(date -d '+7 days' +%F)' WHERE id=$SID" > /dev/null

echo
echo "=================================================="
echo "Resultado: $OK correctas, $FALLAS fallidas"
rm -rf "$TMP"
[ "$FALLAS" -eq 0 ]
