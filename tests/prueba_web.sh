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

echo "9. Salón Completa: servicios con pago al peluquero"
JAR="$TMP/c_completa"
EMAIL3="completa$RANDOM@prueba.ec"
post "r=registro" --data-urlencode "salon=Peluquería Estrella" --data-urlencode "nombre=Rosa Dueña" -d telefono=0990001111 \
     --data-urlencode "email=$EMAIL3" -d clave=claveSegura3 -d plan=completa > /dev/null
SID3=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL3'")
SLUG3=$(sql "SELECT slug FROM salones WHERE id=$SID3")
check "Salón nuevo trae horario lunes a sábado" "6" "$(sql "SELECT count(*) FROM horarios WHERE salon_id=$SID3")"
r=$(post "r=servicios" -d accion=crear --data-urlencode "nombre=Corte premium" -d precio=15 -d pago_profesional=6 -d duracion_minutos=30 -d reserva_online=1)
check "Crear servicio con precio \$15 y pago al peluquero \$6" "6.00" "$(sql "SELECT pago_profesional FROM servicios WHERE salon_id=$SID3 AND nombre='Corte premium'")"
r=$(post "r=servicios" -d accion=crear --data-urlencode "nombre=Mal pagado" -d precio=5 -d pago_profesional=9 -d duracion_minutos=30)
contiene "No deja pagar al peluquero más que el precio" "$TMP/post.html" "no puede ser mayor"
get "r=servicios" > /dev/null
contiene "La lista muestra lo que queda al local (\$9,00)" "$TMP/pag.html" '\$9,00'
post "r=equipo" --data-urlencode "nombre=Luis" -d tipo=empleado -d comision_servicio_pct=40 > /dev/null
LUIS3=$(sql "SELECT id FROM profesionales WHERE salon_id=$SID3 AND nombre='Luis'")
EMAIL_LUIS="luis$RANDOM@prueba.ec"
r=$(post "r=equipo" -d accion=acceso -d id=$LUIS3 -d email=$EMAIL_LUIS -d clave=claveLuis1)
check "El dueño le da acceso a Luis" "profesional" "$(sql "SELECT rol FROM usuarios WHERE email='$EMAIL_LUIS'")"
r=$(post "r=horario" -d "abierto[1]=1" -d "abre[1]=09:00" -d "cierra[1]=19:00" -d "abierto[2]=1" -d "abre[2]=09:00" -d "cierra[2]=19:00" \
     -d "abierto[3]=1" -d "abre[3]=09:00" -d "cierra[3]=19:00" -d "abierto[4]=1" -d "abre[4]=09:00" -d "cierra[4]=19:00" \
     -d "abierto[5]=1" -d "abre[5]=09:00" -d "cierra[5]=19:00" -d "abierto[6]=1" -d "abre[6]=09:00" -d "cierra[6]=19:00" \
     -d "abierto[0]=1" -d "abre[0]=09:00" -d "cierra[0]=19:00" \
     -d intervalo_reservas=30 -d anticipacion_minutos=0 -d acepta_reservas=dueno -d avisar_a=ambos -d minutos_para_aceptar=120)
check "Guardar horario y reglas de aceptación" "dueno|ambos|30" "$(sql "SELECT acepta_reservas||'|'||avisar_a||'|'||intervalo_reservas FROM salones WHERE id=$SID3")"
JAR_DUENA=$JAR

echo "10. Portal de reservas del cliente"
MAN=$(date -d '+1 day' +%F)
PREM=$(sql "SELECT id FROM servicios WHERE salon_id=$SID3 AND nombre='Corte premium'")
code=$(curl -s -o "$TMP/pub.html" -w '%{http_code}' "$BASE/?r=reservar&s=$SLUG3")
check "Página pública abre sin cuenta" "200" "$code"
contiene "Muestra al peluquero Luis" "$TMP/pub.html" "Luis"
contiene "Muestra el servicio con su precio" "$TMP/pub.html" '\$15,00'
curl -s "$BASE/?r=horas&s=$SLUG3&p=$LUIS3&serv=$PREM&f=$MAN" > "$TMP/horas.json"
contiene "Horas libres en tiempo real: aparece 10:00" "$TMP/horas.json" '"10:00"'
JAR="$TMP/c_cliente1"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$PREM -d fecha=$MAN -d hora=10:00 \
     --data-urlencode "nombre=Carla Cliente" -d telefono=0997776655)
check "El cliente reserva y ve la confirmación" "302 $BASE/?r=reservar&s=$SLUG3&ok=1" "$r"
get "r=reservar&s=$SLUG3&ok=1" > /dev/null
contiene "Le dice que la solicitud se está confirmando" "$TMP/pag.html" "Solicitud enviada"
CITA3=$(sql "SELECT max(id) FROM citas WHERE salon_id=$SID3")
check "La cita queda pendiente de aceptar" "pendiente" "$(sql "SELECT estado FROM citas WHERE id=$CITA3")"
curl -s "$BASE/?r=horas&s=$SLUG3&p=$LUIS3&serv=$PREM&f=$MAN" > "$TMP/horas2.json"
contiene "Para otros clientes las 10:00 sale 'en confirmación'" "$TMP/horas2.json" '"en_confirmacion":\["10:00"\]'
JAR="$TMP/c_cliente2"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$PREM -d fecha=$MAN -d hora=10:00 \
     --data-urlencode "nombre=Otro Cliente" -d telefono=0981112222)
contiene "Otro cliente que intenta las 10:00 recibe 'estamos confirmando'" "$TMP/post.html" "confirmando esa hora para otro cliente"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$PREM -d fecha=$MAN -d hora=10:00 -d sitio_web=spam \
     --data-urlencode "nombre=Robot" -d telefono=0981112222)
check "El campo trampa frena a los robots" "1" "$(sql "SELECT count(*) FROM citas WHERE salon_id=$SID3")"
code=$(curl -s -o "$TMP/pubb.html" -w '%{http_code}' "$BASE/?r=reservar&s=$(sql "SELECT slug FROM salones WHERE id=$SID")")
contiene "Un salón Básica no tiene reservas en línea" "$TMP/pubb.html" "todavía no recibe reservas"

echo "11. Avisos, aceptación y portal del peluquero"
check "Se avisa a la dueña y a Luis" "2" "$(sql "SELECT count(*) FROM notificaciones WHERE cita_id=$CITA3")"
JAR=$JAR_DUENA
get "r=inicio" > /dev/null
contiene "La dueña ve la franja de solicitudes" "$TMP/pag.html" "1 solicitud de cita por aceptar"
JAR="$TMP/c_luis"
r=$(post "r=login" -d email=$EMAIL_LUIS -d clave=claveLuis1)
check "Luis entra y va a su portal" "302 $BASE/?r=mi_portal" "$r"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=caja")
check "Luis no puede ver la caja del salón" "302 $BASE/?r=mi_portal" "$code"
get "r=mi_portal" > /dev/null
contiene "Luis ve lo que le toca por el corte premium (\$6,00)" "$TMP/pag.html" '\$6,00'
r=$(post "r=solicitudes" -d cita=$CITA3 -d accion=aceptar)
check "Luis no puede aceptar (lo acepta solo la dueña)" "pendiente" "$(sql "SELECT estado FROM citas WHERE id=$CITA3")"
JAR=$JAR_DUENA
r=$(post "r=solicitudes" -d cita=$CITA3 -d accion=aceptar)
check "La dueña acepta la cita" "reservada" "$(sql "SELECT estado FROM citas WHERE id=$CITA3")"
get "r=solicitudes" > /dev/null
contiene "Ofrece avisar al cliente por WhatsApp" "$TMP/pag.html" "wa.me/593997776655"
r=$(post "r=cobrar&cita=$CITA3" -d cita=$CITA3 -d profesional_id=$LUIS3 -d "servicio[]=$PREM" -d "precio[$PREM]=18" -d metodo_pago=efectivo -d propina=0 -d cobrado_por=local)
check "La dueña cobra \$18 (subió el precio)" "18.00" "$(sql "SELECT total FROM ventas WHERE salon_id=$SID3")"
check "Luis gana igual su pago fijo \$6" "6.00" "$(sql "SELECT ganancia_profesional FROM venta_items vi JOIN ventas v ON v.id=vi.venta_id WHERE v.salon_id=$SID3")"
JAR="$TMP/c_luis"
get "r=mi_portal&p=mes" > /dev/null
contiene "En su portal ve el servicio cobrado" "$TMP/pag.html" "Corte premium"
contiene "Y que el local le debe \$6,00" "$TMP/pag.html" "El local te debe"
r=$(post "r=cita_nueva" -d fecha=$MAN -d hora=12:00 -d profesional=$LUIS3 -d "servicios[]=$PREM")
check "Luis no puede agendar desde el sistema del dueño" "1" "$(sql "SELECT count(*) FROM citas WHERE salon_id=$SID3")"

echo "12. Cuenta del cliente, historial y fotos"
JAR="$TMP/c_cuenta"
MAILC="carla$RANDOM@correo.ec"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$PREM -d fecha=$MAN -d hora=15:00 -d modo=cuenta \
     --data-urlencode "nombre=Carla Cuenta" -d telefono=0995554433 -d email=$MAILC -d clave=claveCarla1 -d acepta_fotos=1)
check "Reservar creando cuenta" "302 $BASE/?r=reservar&s=$SLUG3&ok=1" "$r"
check "La cuenta queda con permiso de fotos" "t" "$(sql "SELECT acepta_fotos FROM clientes WHERE email='$MAILC'")"
get "r=reservar&s=$SLUG3" > /dev/null
contiene "La próxima vez reserva sin escribir sus datos" "$TMP/pag.html" "Reservas con tu cuenta"
get "r=mi_cuenta&s=$SLUG3" > /dev/null
contiene "Mi cuenta muestra su cita por confirmar" "$TMP/pag.html" "Por confirmar"
CITAC=$(sql "SELECT c.id FROM citas c JOIN clientes cl ON cl.id=c.cliente_id WHERE cl.email='$MAILC'")
JAR=$JAR_DUENA
post "r=solicitudes" -d cita=$CITAC -d accion=aceptar > /dev/null
post "r=cobrar&cita=$CITAC" -d cita=$CITAC -d profesional_id=$LUIS3 -d "servicio[]=$PREM" -d "precio[$PREM]=15" -d metodo_pago=efectivo -d propina=0 -d cobrado_por=local > /dev/null
check "La dueña acepta y cobra la cita de Carla" "atendida" "$(sql "SELECT estado FROM citas WHERE id=$CITAC")"
JAR="$TMP/c_luis"
get "r=mi_portal&p=mes" > /dev/null
contiene "En el portal de Luis aparece el enlace Fotos" "$TMP/pag.html" "r=fotos&amp;cita=$CITAC"
python3 -c "from PIL import Image; Image.new('RGB',(2400,1800),(30,90,76)).save('$TMP/corte.jpg', quality=85)"
get "r=fotos&cita=$CITAC" > /dev/null
T=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -F "csrf=$T" -F momento=despues -F "foto=@$TMP/corte.jpg;type=image/jpeg" "$BASE/?r=fotos&cita=$CITAC")
FOTO=$(sql "SELECT max(id) FROM fotos WHERE cita_id=$CITAC")
check "Luis sube una foto desde su celular" "302" "$code"
check "La foto queda guardada" "1" "$(sql "SELECT count(*) FROM fotos WHERE cita_id=$CITAC")"
JAR="$TMP/c_cuenta"
code=$(curl -s -b "$JAR" -o "$TMP/f.jpg" -w '%{http_code} %{content_type}' "$BASE/?r=foto&id=$FOTO")
check "Carla ve su foto" "200 image/jpeg" "$code"
get "r=mi_cuenta&s=$SLUG3" > /dev/null
contiene "Su historial muestra el servicio con la foto" "$TMP/pag.html" "r=foto&amp;id=$FOTO"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=foto&id=$FOTO")
check "Sin sesión, la foto no se ve" "404" "$code"
code=$(curl -s -b "$TMP/c_cliente2" -o /dev/null -w '%{http_code}' "$BASE/?r=foto&id=$FOTO")
check "Otro cliente no ve la foto" "404" "$code"
ARCH=$(sql "SELECT archivo FROM fotos WHERE id=$FOTO")
tipo=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/uploads/fotos/$ARCH")
if [[ "$tipo" == image/* ]]; then FALLAS=$((FALLAS+1)); echo "  FALLA La foto se puede bajar por su dirección directa";
else OK=$((OK+1)); echo "  OK    La foto no se puede bajar por su dirección directa"; fi
JAR="$TMP/c_nuevo_navegador"
r=$(post "r=cliente_entrar&s=$SLUG3" -d email=$MAILC -d clave=claveCarla1)
check "Carla entra con su cuenta desde otro celular" "302 $BASE/?r=mi_cuenta&s=$SLUG3" "$r"

echo "14. Servicios con explicación, quién lo hace y precio por peluquero"
JAR=$JAR_DUENA
ROSA3=$(sql "SELECT id FROM profesionales WHERE salon_id=$SID3 AND tipo='dueno'")
r=$(post "r=servicios" -d accion=crear --data-urlencode "nombre=Keratina" -d precio=40 -d duracion_minutos=90 -d reserva_online=1 \
     --data-urlencode "descripcion=Alisado con keratina: lavado, aplicación, secado y planchado." -d quien_enviado=1 \
     -d "hace[$LUIS3]=1" -d "precio_prof[$LUIS3]=35")
KERA=$(sql "SELECT id FROM servicios WHERE salon_id=$SID3 AND nombre='Keratina'")
check "Se guarda la explicación del servicio" "Alisado con keratina: lavado, aplicación, secado y planchado." "$(sql "SELECT descripcion FROM servicios WHERE id=$KERA")"
check "Solo Luis la hace, a \$35" "$LUIS3|35.00" "$(sql "SELECT profesional_id||'|'||precio FROM servicio_profesional WHERE servicio_id=$KERA")"
r=$(post "r=servicios" -d accion=crear --data-urlencode "nombre=Nadie lo hace" -d precio=10 -d duracion_minutos=30 -d quien_enviado=1)
contiene "Si no marca a nadie, avisa" "$TMP/post.html" "Marca al menos un peluquero"
code=$(curl -s -o "$TMP/pub2.html" -w '%{http_code}' "$BASE/?r=reservar&s=$SLUG3")
contiene "El portal tiene el desplegable de servicios" "$TMP/pub2.html" 'id="servicio" name="servicio"'
contiene "Cada servicio lleva su explicación" "$TMP/pub2.html" 'data-desc="Alisado con keratina'
contiene "Explica cómo ver el detalle (mouse o ⓘ)" "$TMP/pub2.html" "Deja el mouse sobre un servicio"
contiene "Lleva el precio de Luis para la keratina" "$TMP/pub2.html" "\"$KERA\":35"
curl -s "$BASE/?r=horas&s=$SLUG3&p=$ROSA3&serv=$KERA&f=$MAN" > "$TMP/h3.json"
contiene "Con Rosa la keratina no sale (no la hace)" "$TMP/h3.json" "no hace este servicio"
curl -s "$BASE/?r=horas&s=$SLUG3&p=$LUIS3&serv=$KERA&f=$MAN" > "$TMP/h4.json"
contiene "Con Luis sí hay horas para keratina" "$TMP/h4.json" '"11:00"'

echo "15. Horario propio y vacaciones del peluquero"
get "r=horario_peluquero&p=$LUIS3" > /dev/null
contiene "Página de horario de Luis" "$TMP/pag.html" "Horario de Luis"
PASADO=$(date -d '+2 day' +%F); DOW=$(date -d '+2 day' +%w)
r=$(post "r=horario_peluquero&p=$LUIS3" -d accion=bloqueo -d desde=$PASADO -d hasta=$PASADO --data-urlencode "motivo=Vacaciones")
check "Se guarda el bloqueo" "1" "$(sql "SELECT count(*) FROM bloqueos WHERE profesional_id=$LUIS3")"
curl -s "$BASE/?r=horas&s=$SLUG3&p=$LUIS3&serv=$PREM&f=$PASADO" > "$TMP/h5.json"
contiene "Ese día los clientes ven que no atiende" "$TMP/h5.json" "vacaciones o permiso"
r=$(post "r=horario_peluquero&p=$LUIS3" -d accion=horario -d modo=propio -d "trabaja[1]=1" -d "abre[1]=10:00" -d "cierra[1]=18:00" \
     -d "alm_desde[1]=13:00" -d "alm_hasta[1]=14:00")
check "Horario propio: solo lunes con almuerzo" "1|13:00:00" "$(sql "SELECT count(*)||'|'||max(almuerzo_desde) FROM horarios_profesional WHERE profesional_id=$LUIS3")"
r=$(post "r=horario_peluquero&p=$LUIS3" -d accion=horario -d modo=salon)
check "Vuelve al horario del salón" "0" "$(sql "SELECT count(*) FROM horarios_profesional WHERE profesional_id=$LUIS3")"
r=$(post "r=horario" -d "abierto[1]=1" -d "abre[1]=09:00" -d "cierra[1]=19:00" -d "abierto[2]=1" -d "abre[2]=09:00" -d "cierra[2]=19:00" \
     -d "abierto[3]=1" -d "abre[3]=09:00" -d "cierra[3]=19:00" -d "abierto[4]=1" -d "abre[4]=09:00" -d "cierra[4]=19:00" \
     -d "abierto[5]=1" -d "abre[5]=09:00" -d "cierra[5]=19:00" -d "abierto[6]=1" -d "abre[6]=09:00" -d "cierra[6]=19:00" \
     -d "abierto[0]=1" -d "abre[0]=09:00" -d "cierra[0]=19:00" \
     -d intervalo_reservas=30 -d anticipacion_minutos=0 -d acepta_reservas=dueno -d avisar_a=ambos -d minutos_para_aceptar=120 \
     -d horas_cancelacion=3 -d max_faltas=2 -d semanas_sin_volver=8 --data-urlencode "google_resenas_url=https://g.page/r/estrella/review")
check "Reglas para clientes guardadas" "3|2|8|https://g.page/r/estrella/review" \
      "$(sql "SELECT horas_cancelacion||'|'||max_faltas||'|'||semanas_sin_volver||'|'||google_resenas_url FROM salones WHERE id=$SID3")"
r=$(post "r=horario" -d "abierto[1]=1" -d "abre[1]=09:00" -d "cierra[1]=19:00" -d intervalo_reservas=30 -d anticipacion_minutos=0 \
     -d acepta_reservas=dueno -d avisar_a=ambos -d minutos_para_aceptar=120 -d horas_cancelacion=3 -d max_faltas=2 -d semanas_sin_volver=8 \
     --data-urlencode "google_resenas_url=javascript:alert(1)")
contiene "No acepta enlaces raros para Google" "$TMP/post.html" "debe empezar con https"

echo "16. Reserva con cumpleaños, el dueño pone el valor y el cliente confirma"
JAR="$TMP/c_cumple"
MAILK="kati$RANDOM@correo.ec"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$KERA -d fecha=$MAN -d hora=11:00 -d modo=cuenta \
     --data-urlencode "nombre=Kati Keratina" -d telefono=0993334455 -d email=$MAILK -d clave=claveKati1 -d cumple_dia=20 -d cumple_mes=11)
check "Reserva creando cuenta con cumpleaños" "302 $BASE/?r=reservar&s=$SLUG3&ok=1" "$r"
check "El cumpleaños se guarda sin año (mes|día)" "11|20" "$(sql "SELECT cumple_mes||'|'||cumple_dia FROM clientes WHERE email='$MAILK'")"
CK=$(sql "SELECT c.id FROM citas c JOIN clientes cl ON cl.id=c.cliente_id WHERE cl.email='$MAILK'")
check "La cita tiene el precio de Luis (\$35)" "35.00" "$(sql "SELECT precio FROM cita_servicios WHERE cita_id=$CK")"
JAR="$TMP/c_cumple_mal"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$PREM -d fecha=$MAN -d hora=16:00 -d modo=cuenta \
     --data-urlencode "nombre=Fecha Mala" -d telefono=0993334466 -d email="mala$RANDOM@correo.ec" -d clave=claveMala1 -d cumple_dia=31 -d cumple_mes=4)
contiene "31 de abril no se acepta" "$TMP/post.html" "no existe"
JAR=$JAR_DUENA
get "r=solicitudes" > /dev/null
contiene "Al aceptar, el dueño puede poner el valor" "$TMP/pag.html" "name=\"precio\[$KERA\]\""
r=$(post "r=solicitudes" -d cita=$CK -d accion=aceptar -d "precio[$KERA]=38.50")
check "El dueño acepta con valor \$38,50" "reservada|38.50" "$(sql "SELECT c.estado||'|'||cs.precio FROM citas c JOIN cita_servicios cs ON cs.cita_id=c.id WHERE c.id=$CK")"
get "r=solicitudes" > /dev/null
contiene "El WhatsApp al cliente dice el valor a cancelar" "$TMP/pag.html" "Valor%20a%20cancelar%3A%20%2438%2C50"
TOK=$(sql "SELECT token FROM citas WHERE id=$CK")
JAR="$TMP/c_cumple"
get "r=confirmar&t=$TOK" > /dev/null
contiene "La página de la cita muestra el valor" "$TMP/pag.html" '\$38,50'
contiene "Tiene el botón para confirmar" "$TMP/pag.html" "Confirmo que voy"
r=$(post "r=confirmar&t=$TOK" -d accion=confirmar)
check "El cliente confirma con un toque" "confirmada|true" "$(sql "SELECT estado||'|'||(confirmada_cliente_en IS NOT NULL) FROM citas WHERE id=$CK")"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=confirmar&t=00000000000000000000000000000000")
check "Un enlace inventado no muestra nada" "404" "$code"
get "r=mi_cuenta&s=$SLUG3" > /dev/null
contiene "En su cuenta puede cambiar o cancelar" "$TMP/pag.html" "Confirmar, cambiar o cancelar"
contiene "En su cuenta ve su cumpleaños" "$TMP/pag.html" '<option value="20" selected>'

echo "17. Cambiar y cancelar desde el enlace"
get "r=reservar&s=$SLUG3&cambia=$TOK" > /dev/null
contiene "Al cambiar, avisa que la anterior se libera" "$TMP/pag.html" "Estás cambiando tu cita"
r=$(post "r=reservar&s=$SLUG3&cambia=$TOK" -d profesional=$LUIS3 -d servicio=$KERA -d fecha=$MAN -d hora=12:30)
check "Reserva la nueva hora (12:30)" "302 $BASE/?r=reservar&s=$SLUG3&ok=1" "$r"
check "La cita anterior quedó cancelada por el cliente" "cancelada|cliente" "$(sql "SELECT estado||'|'||cancelada_por FROM citas WHERE id=$CK")"
CK2=$(sql "SELECT max(c.id) FROM citas c JOIN clientes cl ON cl.id=c.cliente_id WHERE cl.email='$MAILK'")
TOK2=$(sql "SELECT token FROM citas WHERE id=$CK2")
r=$(post "r=confirmar&t=$TOK2" -d accion=cancelar)
check "Cancela la nueva desde su enlace" "cancelada" "$(sql "SELECT estado FROM citas WHERE id=$CK2")"
EN1H=$(date -d '+1 hour' '+%F %H:%M'); EN90=$(date -d '+90 minutes' '+%F %H:%M')
CERCA=$(sql "INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, estado) SELECT $SID3, cliente_id, $LUIS3, '$EN1H', '$EN90', 'reservada' FROM citas WHERE id=$CK RETURNING token" | head -1)
get "r=confirmar&t=$CERCA" > /dev/null
contiene "A menos de 3 horas ya no puede cancelar" "$TMP/pag.html" "Faltan menos de 3 horas"
r=$(post "r=confirmar&t=$CERCA" -d accion=cancelar)
check "…ni a la fuerza" "reservada" "$(sql "SELECT estado FROM citas WHERE token='$CERCA'")"

echo "18. Calificación con estrellas y reseña en Google"
TOKC=$(sql "SELECT token FROM citas WHERE id=$CITAC")
JAR="$TMP/c_cuenta"
get "r=confirmar&t=$TOKC" > /dev/null
contiene "Después del servicio pide calificar" "$TMP/pag.html" "¿Cómo te fue con Luis?"
r=$(post "r=confirmar&t=$TOKC" -d accion=calificar -d estrellas=5 --data-urlencode "comentario=Me encantó")
check "Se guarda la calificación" "5|Me encantó" "$(sql "SELECT estrellas||'|'||comentario FROM calificaciones WHERE cita_id=$CITAC")"
get "r=confirmar&t=$TOKC" > /dev/null
contiene "Con 5 estrellas invita a reseñar en Google" "$TMP/pag.html" "g.page/r/estrella/review"
r=$(post "r=confirmar&t=$TOKC" -d accion=calificar -d estrellas=1)
check "No se puede calificar dos veces" "1" "$(sql "SELECT count(*) FROM calificaciones WHERE cita_id=$CITAC")"
JAR=$JAR_DUENA
get "r=cita&id=$CITAC" > /dev/null
contiene "La dueña ve la calificación en la cita" "$TMP/pag.html" "★★★★★"

echo "19. Avisos a clientes, faltas, reporte y tareas automáticas"
CL_F=$(sql "SELECT cliente_id FROM citas WHERE id=$CITA3")
sql "INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, estado) VALUES ($SID3, $CL_F, $LUIS3, now() - interval '3 days', now() - interval '3 days' + interval '30 minutes', 'no_asistio'), ($SID3, $CL_F, $LUIS3, now() - interval '2 days', now() - interval '2 days' + interval '30 minutes', 'no_asistio')" > /dev/null
get "r=cliente&id=$CL_F" > /dev/null
contiene "La ficha muestra las faltas" "$TMP/pag.html" "2 faltas"
contiene "…y que no puede reservar en línea" "$TMP/pag.html" "No puede reservar en línea"
r=$(post "r=cliente&id=$CL_F" -d accion=perdonar)
get "r=cliente&id=$CL_F" > /dev/null
if grep -q "2 faltas" "$TMP/pag.html"; then FALLAS=$((FALLAS+1)); echo "  FALLA Perdonar faltas"; else OK=$((OK+1)); echo "  OK    Perdonar faltas"; fi
r=$(post "r=cliente&id=$CL_F" --data-urlencode "nombre=Carla Cliente" -d telefono=0997776655 -d cumple_dia=5 -d cumple_mes=3)
check "El dueño pone el cumpleaños en la ficha" "3|5" "$(sql "SELECT cumple_mes||'|'||cumple_dia FROM clientes WHERE id=$CL_F")"
JAR="$TMP/c_rec"
r=$(post "r=reservar&s=$SLUG3" -d profesional=$LUIS3 -d servicio=$PREM -d fecha=$MAN -d hora=17:00 --data-urlencode "nombre=Rita Recordar" -d telefono=0992221100)
CRIT=$(sql "SELECT max(id) FROM citas WHERE salon_id=$SID3")
JAR=$JAR_DUENA
post "r=solicitudes" -d cita=$CRIT -d accion=aceptar > /dev/null
get "r=avisos_clientes" > /dev/null
contiene "Avisos a clientes: la cita de mañana para recordar" "$TMP/pag.html" "Rita Recordar"
contiene "…con WhatsApp listo y el enlace para confirmar" "$TMP/pag.html" "r%3Dconfirmar%26t%3D"
r=$(post "r=avisos_clientes" -d accion=recordado -d cita=$CRIT)
check "Marcar 'ya lo envié'" "t" "$(sql "SELECT recordatorio_en IS NOT NULL FROM citas WHERE id=$CRIT")"
get "r=inicio" > /dev/null
code=$(get "r=reporte&mes=$(date +%Y-%m)")
check "El reporte del mes abre" "200" "$code"
contiene "Muestra lo más vendido" "$TMP/pag.html" "Corte premium"
r=$(post "r=reporte&mes=$(date +%Y-%m)" -d enviar=1)
check "Enviar el reporte deja el aviso al dueño" "1" "$(sql "SELECT count(*) FROM notificaciones WHERE salon_id=$SID3 AND texto LIKE '📊 Reporte de%'")"
JAR="$TMP/c_luis"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=reporte")
check "El peluquero no ve el reporte del dueño" "302 $BASE/?r=mi_portal" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/bin/tareas.php")
if [ "$code" == "200" ] && curl -s "$BASE/bin/tareas.php" | grep -q "salones"; then FALLAS=$((FALLAS+1)); echo "  FALLA Las tareas se pueden correr desde internet";
else OK=$((OK+1)); echo "  OK    Las tareas automáticas no se pueden correr desde internet"; fi

echo "13. Telegram: solo acepta avisos con el texto secreto"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '{}' "$BASE/?r=telegram")
check "Sin el texto secreto, se rechaza" "403" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Telegram-Bot-Api-Secret-Token: equivocado" -d '{}' "$BASE/?r=telegram")
check "Con un texto secreto equivocado, se rechaza" "403" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Telegram-Bot-Api-Secret-Token: ${TG_SECRET:-}" -d '{"update_id":1}' "$BASE/?r=telegram")
check "Con el texto secreto correcto, se acepta" "200" "$code"

echo
echo "=================================================="
echo "Resultado: $OK correctas, $FALLAS fallidas"
rm -rf "$TMP"
[ "$FALLAS" -eq 0 ]
