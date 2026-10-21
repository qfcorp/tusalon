#!/usr/bin/env bash
# IA de Tukán y correo de la demo, de punta a punta por HTTP (lo corre tests/run_web.sh, que levanta la API de Anthropic y
# el SMTP de mentira de tests/falsos/ y se los pasa al sitio principal con TUKAN_IA_ENV y TUKAN_CORREO_ENV):
#   1. Ayuda para todos los planes (POST + CSRF, FAQ, sin «límite», «cupo» ni «saldo»); sin configuración responde con el manual
#   2. Asistente IA: el módulo apagado no entra; encendido, agendar desde un mensaje → borrador → Nueva cita → cita guardada
#      (respetando la agenda real); tope silencioso con el mensaje neutro; mensaje para clientes que no vuelven
#   3. Preguntas frecuentes (IA) del súper: aprobar, editar, borrar, agregar; el salón no entra
#   4. «Demo lista» → Enviar por correo: sello sin la contraseña, correo por SMTP, auditoría sin la contraseña, un solo uso,
#      sin CSRF 400, y «Configure el correo de Tukán» en el sitio sin correo
# Uso suelto: BASE=… BASE_APAGADA=… DB=… SMTP_DIR=… IA_LOG=… bash tests/prueba_web_ia.sh
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
no_contiene() { if grep -qiE -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "${B:-$BASE}/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
token() { get "$1" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi; echo "$t"; }
post() { local ruta=$1; shift; local t; t=$(token "$ruta")
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "${B:-$BASE}/?$ruta"; }
seguir() { get "${1#*\?}" > /dev/null; }
registrar() { # salón plan [modulos] [pagado] -> SID, EMAIL y la sesión del dueño en un JAR nuevo
  JAR="$TMP/c_$RANDOM$RANDOM"
  EMAIL="d$RANDOM$RANDOM@prueba.ec"
  TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php tests/demo_web.php "$1" "Dueña Prueba" 0990002222 "$EMAIL" claveSegura1 "$2" "${3:-}" "${4:-}" > /dev/null
  post "r=login" --data-urlencode "email=$EMAIL" -d clave=claveSegura1 > /dev/null
  SID=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL'")
}
respuesta() { sed -n '/data-respuesta-ayuda/,/<\/section>/p' "$TMP/post.html" > "$TMP/resp.html"; }
PROHIBIDAS='l[ií]mite|cupo|saldo|cr[eé]dito'

echo "1. Ayuda para todos los planes (salón con solo la base y sin el módulo)"
registrar "Salón Ayuda Básica" basica "" pagado
check "La Ayuda abre" "200" "$(get "r=ayuda")"
contiene "Título «¿En qué le ayudamos?»" "$TMP/pag.html" "¿En qué le ayudamos?"
contiene "Campo «Escriba su pregunta» con el ejemplo en gris" "$TMP/pag.html" 'placeholder="¿Cómo registro un pago?"'
contiene "… de máximo 500 letras" "$TMP/pag.html" 'maxlength="500"'
contiene "Botón Preguntar" "$TMP/pag.html" ">Preguntar</button>"
contiene "… con token CSRF" "$TMP/pag.html" 'name="csrf"'
get "r=equipo" > /dev/null
contiene "Más (Equipo) tiene Ayuda" "$TMP/pag.html" ">Ayuda</a>"
no_contiene "… y no tiene Asistente IA (módulo apagado)" "$TMP/pag.html" "Asistente IA</a>"
antes=$(wc -l < "$IA_LOG" 2>/dev/null || echo 0)
check "Preguntar responde (200)" "200 " "$(post "r=ayuda" --data-urlencode "pregunta=¿Cómo agrego un servicio nuevo con su precio?")"
respuesta
contiene "Muestra la respuesta" "$TMP/resp.html" "Siga lo que dice"
contiene "… y debajo «¿Le sirvió?» con el WhatsApp" "$TMP/resp.html" "¿Le sirvió? Si no, escríbanos por WhatsApp"
no_contiene "… sin «límite», «cupo» ni «saldo»" "$TMP/resp.html" "$PROHIBIDAS"
check "Llamó una vez a la API (de mentira)" "$((antes+1))" "$(wc -l < "$IA_LOG")"
check "Quedó como pregunta frecuente automática sin aprobar" "automatica|0" "$(sql "SELECT origen || '|' || aprobada FROM tukan_ia_faq WHERE pregunta LIKE '%agrego un servicio nuevo%'")"
post "r=ayuda" --data-urlencode "pregunta=¿cómo agrego un servicio nuevo con su precio" > /dev/null
respuesta
contiene "La misma pregunta: sale de la pregunta frecuente" "$TMP/resp.html" "Siga lo que dice"
check "… sin volver a llamar a la API" "$((antes+1))" "$(wc -l < "$IA_LOG")"
sql "UPDATE salones SET ia_tope_usd = 0 WHERE id = $SID" > /dev/null
post "r=ayuda" --data-urlencode "pregunta=¿Cómo cierro la caja del día?" > /dev/null
respuesta
contiene "En su tope: responde con el manual" "$TMP/resp.html" "Esto dice el manual de TuSalón"
no_contiene "… sin decir por qué" "$TMP/resp.html" "$PROHIBIDAS|pausa|inteligencia"
check "… y sin llamar a la API" "$((antes+1))" "$(wc -l < "$IA_LOG")"
sql "UPDATE salones SET ia_tope_usd = NULL WHERE id = $SID" > /dev/null
r=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code}" --data-urlencode "pregunta=hola" "$BASE/?r=ayuda")
check "Sin token CSRF: 400" "400" "$r"
r=$(get "r=asistente")
check "Asistente IA sin el módulo: 403 «No está en tu plan»" "403" "$r"
contiene "… con el aviso del módulo" "$TMP/pag.html" "Asistente IA"
JAR_BASICA=$JAR
# Sin /etc/tukan-ia.env (sitio sin configuración): la Ayuda sigue con el manual
B=$BASE_APAGADA; JAR="$TMP/c_apagada"
post "r=login" --data-urlencode "email=$EMAIL" -d clave=claveSegura1 > /dev/null
post "r=ayuda" --data-urlencode "pregunta=¿Cómo agrego un peluquero?" > /dev/null
respuesta
contiene "Sin configuración de IA: la Ayuda responde con el manual" "$TMP/resp.html" "Esto dice el manual de TuSalón"
check "… sin llamar a la API" "$((antes+1))" "$(wc -l < "$IA_LOG")"
unset B

echo "2. Asistente IA (módulo encendido)"
registrar "Salón Con Asistente" completa   # demo con todos los módulos
JAR_DUENA=$JAR
check "Asistente IA abre" "200" "$(get "r=asistente")"
contiene "Agendar a partir de un mensaje" "$TMP/pag.html" "Agendar a partir de un mensaje"
contiene "Mensaje para clientes que no vuelven" "$TMP/pag.html" "Mensaje para clientes que no vuelven"
get "r=equipo" > /dev/null
contiene "Más (Equipo) tiene Asistente IA" "$TMP/pag.html" "Asistente IA</a>"
DUENA=$(sql "SELECT id FROM profesionales WHERE salon_id=$SID ORDER BY id LIMIT 1")
CORTE=$(sql "SELECT id FROM servicios WHERE salon_id=$SID AND nombre='Corte de dama'")
TINTE=$(sql "SELECT id FROM servicios WHERE salon_id=$SID AND nombre='Tinte'")
VIE=$(php -r 'echo (new DateTimeImmutable("today"))->modify("+2 days")->modify("next friday")->format("Y-m-d");')
sql "INSERT INTO citas (salon_id, profesional_id, inicio, fin, estado, origen, token) VALUES ($SID, $DUENA, '$VIE 15:00', '$VIE 17:15', 'reservada', 'local', md5(random()::text))" > /dev/null
JSON="{\"servicios\":[$CORTE,$TINTE],\"profesional_id\":$DUENA,\"fecha\":\"$VIE\",\"hora\":\"15:00\",\"cliente\":\"Marta\",\"nota\":\"\"}"
r=$(post "r=asistente" -d accion=agendar --data-urlencode "mensaje=Quiero corte y tinte el viernes en la tarde RESPONDER:$JSON")
check "Proponer la cita: 200" "200 " "$r"
contiene "Muestra el borrador (todavía no está guardada)" "$TMP/post.html" "data-borrador-cita"
contiene "… en la hora libre más cercana (15:00 estaba ocupada)" "$TMP/post.html" "data-hora=\"12:45\""
contiene "… y lo dice" "$TMP/post.html" "La hora que pidió no estaba libre"
check "Todavía no se creó ninguna cita nueva" "1" "$(sql "SELECT count(*) FROM citas WHERE salon_id=$SID")"
ENLACE=$(grep -o 'href="/?r=cita_nueva[^"]*"' "$TMP/post.html" | head -1 | sed 's/^href="\/?//;s/"$//;s/&amp;/\&/g')
check "«Revisar y confirmar» abre Nueva cita" "200" "$(get "$ENLACE")"
contiene "… con la hora puesta" "$TMP/pag.html" 'value="12:45"'
contiene "… y los servicios marcados" "$TMP/pag.html" "value=\"$TINTE\" checked"
r=$(post "$ENLACE" -d fecha="$VIE" -d hora=12:45 -d profesional="$DUENA" -d "servicios[]=$CORTE" -d "servicios[]=$TINTE" --data-urlencode "cliente_nuevo=Marta Prueba" -d telefono=0981112233)
check "Confirmar guarda la cita" "2" "$(sql "SELECT count(*) FROM citas WHERE salon_id=$SID")"
check "… a las 12:45 del viernes, sin chocar con la de las 15:00" "$VIE 12:45|$VIE 15:00" "$(sql "SELECT to_char(c.inicio, 'YYYY-MM-DD HH24:MI') || '|' || to_char(c.fin, 'YYYY-MM-DD HH24:MI') FROM citas c WHERE salon_id=$SID ORDER BY id DESC LIMIT 1")"
sql "UPDATE salones SET ia_tope_usd = 0 WHERE id = $SID" > /dev/null
antes=$(wc -l < "$IA_LOG")
post "r=asistente" -d accion=agendar --data-urlencode "mensaje=Corte mañana RESPONDER:$JSON" > /dev/null
contiene "En su tope: mensaje neutro" "$TMP/post.html" "En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí:"
contiene "… con el enlace a Nueva cita" "$TMP/post.html" 'href="/?r=cita_nueva"'
no_contiene "… sin «límite», «cupo» ni «saldo»" "$TMP/post.html" "$PROHIBIDAS"
check "… y sin llamar a la API" "$antes" "$(wc -l < "$IA_LOG")"
sql "UPDATE salones SET ia_tope_usd = NULL WHERE id = $SID" > /dev/null
CLI=$(sql "INSERT INTO clientes (salon_id, nombre, telefono) VALUES ($SID, 'Rosa Vuelve', '0987776666') RETURNING id" | head -1)
sql "INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, estado, origen, token) VALUES ($SID, $CLI, $DUENA, now() - interval '10 weeks', now() - interval '10 weeks' + interval '30 minutes', 'atendida', 'local', md5(random()::text))" > /dev/null
get "r=asistente" > /dev/null
contiene "Rosa sale entre los clientes que no vuelven (con «Escribir mensaje»)" "$TMP/pag.html" "name=\"cliente\" value=\"$CLI\">"
post "r=asistente" -d accion=recuperar -d cliente="$CLI" > /dev/null
contiene "Escribir mensaje: si la IA no da un texto que sirva, mensaje neutro con el enlace a Avisos a clientes" "$TMP/post.html" 'href="/?r=avisos_clientes"'
sql "UPDATE salones SET nombre = 'Salón RESPONDER:Hola Rosa, te extrañamos. ¿Vienes esta semana?' WHERE id = $SID" > /dev/null
post "r=asistente" -d accion=recuperar -d cliente="$CLI" > /dev/null
contiene "Con la IA: el mensaje listo" "$TMP/post.html" "data-mensaje-recuperar"
contiene "… con el botón de WhatsApp al cliente" "$TMP/post.html" "https://wa.me/593987776666"
get "r=avisos_clientes" > /dev/null
contiene "Avisos a clientes ofrece «Mensaje con IA»" "$TMP/pag.html" "Mensaje con IA"
sql "UPDATE salones SET modulos_demo = 'reservas_linea' WHERE id = $SID" > /dev/null
check "Módulo apagado: Asistente IA ya no entra (403)" "403" "$(get "r=asistente")"
get "r=avisos_clientes" > /dev/null
no_contiene "… y Avisos a clientes ya no ofrece «Mensaje con IA»" "$TMP/pag.html" "Mensaje con IA"
check "… pero la Ayuda sigue (200)" "200" "$(get "r=ayuda")"

echo "3. Preguntas frecuentes (IA) del súper usuario"
check "El salón no entra al panel de Tukán" "302" "$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/?r=admin_faq")"
JAR="$TMP/c_admin"
ADM="tukan$RANDOM@tukan.ec"
TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD TUSALON_ADMIN_NOMBRE="Dimitry" TUSALON_ADMIN_EMAIL=$ADM TUSALON_ADMIN_CLAVE=claveAdminSegura1 php bin/crear_admin.php > /dev/null
post "r=admin_entrar" -d email=$ADM -d clave=claveAdminSegura1 > /dev/null
check "Preguntas frecuentes (IA) abre" "200" "$(get "r=admin_faq")"
contiene "Está en el menú del panel" "$TMP/pag.html" "Preguntas frecuentes (IA)</a>"
contiene "Por revisar: la pregunta que respondió la IA" "$TMP/pag.html" "agrego un servicio nuevo"
contiene "… con Aprobar, Editar y Borrar" "$TMP/pag.html" 'value="aprobar"'
FID=$(sql "SELECT id FROM tukan_ia_faq WHERE pregunta LIKE '%agrego un servicio nuevo%'")
r=$(post "r=admin_faq" -d accion=aprobar -d id="$FID")
check "Aprobar" "1" "$(sql "SELECT aprobada FROM tukan_ia_faq WHERE id=$FID")"
post "r=admin_faq" -d accion=editar -d id="$FID" --data-urlencode "pregunta=¿Cómo agrego un servicio nuevo con su precio?" --data-urlencode "respuesta=1. Más → Servicios. 2. **Agregar servicio**." > /dev/null
check "Editar: queda aprobada y escrita a mano" "manual|1" "$(sql "SELECT origen || '|' || aprobada FROM tukan_ia_faq WHERE id=$FID")"
post "r=admin_faq" -d accion=agregar --data-urlencode "pregunta=¿Dónde veo mis citas de mañana?" --data-urlencode "respuesta=En la Agenda." > /dev/null
check "Agregar pregunta a mano (aprobada)" "manual|1" "$(sql "SELECT origen || '|' || aprobada FROM tukan_ia_faq WHERE pregunta LIKE '%citas de mañana%'")"
NID=$(sql "SELECT id FROM tukan_ia_faq WHERE pregunta LIKE '%citas de mañana%'")
post "r=admin_faq" -d accion=borrar -d id="$NID" > /dev/null
check "Borrar" "0" "$(sql "SELECT count(*) FROM tukan_ia_faq WHERE id=$NID")"
check "Todo queda en la auditoría (registro_admin)" "4" "$(sql "SELECT count(*) FROM registro_admin WHERE accion LIKE 'Preguntas frecuentes (IA):%'")"
r=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" -d accion=borrar -d id="$FID" "$BASE/?r=admin_faq")
check "Sin token CSRF: 400" "400" "$r"
JAR=$JAR_BASICA
post "r=ayuda" --data-urlencode "pregunta=¿Cómo agrego un servicio nuevo con su precio?" > /dev/null
respuesta
contiene "La Ayuda de los salones ya responde con la versión editada" "$TMP/resp.html" "<strong>Agregar servicio</strong>"

echo "4. «Demo lista» → Enviar por correo"
JAR="$TMP/c_admin"
MAILN="demo$RANDOM@salon.ec"
r=$(post "r=admin_nuevo" --data-urlencode "salon=Salón Correo Demo" --data-urlencode "nombre=Rosa Cliente" -d telefono=0998887766 -d email=$MAILN -d plan=completa)
seguir "$r"
cp "$TMP/pag.html" "$TMP/demo_lista.html"
CLAVE=$(grep -o 'class="clave-temporal">[^<]*' "$TMP/demo_lista.html" | sed 's/.*>//')
SALON=$(sql "SELECT salon_id FROM usuarios WHERE email='$MAILN'")
contiene "La pantalla de la demo tiene «Enviar por correo»" "$TMP/demo_lista.html" "Enviar por correo</h2>"
contiene "… con el correo de la demo ya puesto" "$TMP/demo_lista.html" "value=\"$MAILN\""
contiene "… y la ayuda bajo el campo" "$TMP/demo_lista.html" "Le llega «Su demo de TuSalón está lista» con sus datos de entrada, desde info@tukansoftware.com."
SELLO=$(grep -o 'name="sello" value="[^"]*"' "$TMP/demo_lista.html" | sed 's/.*value="//;s/"$//')
check "Hay contraseña y sello" "sí" "$([ -n "$CLAVE" ] && [ -n "$SELLO" ] && echo sí || echo no)"
check "El sello no lleva la contraseña en claro" "no" "$(echo "$SELLO" | grep -q -- "$CLAVE" && echo sí || echo no)"
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "sello=$SELLO" -d para="$MAILN" "$BASE/?r=admin_demo_correo&id=$SALON")
check "Sin token CSRF: 400" "400" "$r"
T=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/demo_lista.html" | head -1 | sed 's/.*value="//;s/"$//')
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "csrf=$T" --data-urlencode "sello=$SELLO" -d para="no-es-correo" "$BASE/?r=admin_demo_correo&id=$SALON")
check "Correo inválido: 422 y el formulario otra vez" "422" "$r"
contiene "… con el error" "$TMP/post.html" "Escribe un correo válido"
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "csrf=$T" --data-urlencode "sello=$SELLO" -d para="$MAILN" "$BASE/?r=admin_demo_correo&id=$SALON")
check "Enviar por correo: 200" "200" "$r"
contiene "«Correo enviado a … desde info@tukansoftware.com. Que revise también la carpeta de spam.»" "$TMP/post.html" "Correo enviado a <strong>$MAILN</strong> desde info@tukansoftware.com. Que revise también la carpeta de spam."
no_contiene "La pantalla del resultado ya no muestra la contraseña" "$TMP/post.html" "$CLAVE"
sleep 0.3
EML=$(grep -l -- "$MAILN" "$SMTP_DIR"/*.eml 2>/dev/null | head -1)
check "El SMTP recibió el correo para la demo" "sí" "$([ -n "$EML" ] && echo sí || echo no)"
contiene "… desde info@tukansoftware.com" "$EML" "<info@tukansoftware.com>"
php -r '$m = file_get_contents($argv[1]); preg_match("/^Subject: (.*(?:\r?\n[ \t].*)*)/m", $m, $a); echo iconv_mime_decode(preg_replace("/\r?\n[ \t]+/", " ", trim($a[1])), 0, "UTF-8"), "\n", quoted_printable_decode($m);' "$EML" > "$TMP/correo.txt"
contiene "… con la contraseña de la demo dentro del correo" "$TMP/correo.txt" "$CLAVE"
contiene "… y el asunto «Su demo de TuSalón está lista»" "$TMP/correo.txt" "Su demo de TuSalón"
check "Auditoría «Envió la demo por correo a …»" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE salon_id=$SALON AND accion = 'Envió la demo por correo a $MAILN'")"
check "… y ninguna línea de la auditoría tiene la contraseña" "0" "$(sql "SELECT count(*) FROM registro_admin WHERE accion LIKE '%$CLAVE%'")"
check "La contraseña no está en ninguna tabla de texto de la base (salvo el hash)" "0" "$(pg_dump -h 127.0.0.1 -U tusalon --data-only "$DB" 2>/dev/null | grep -c -- "$CLAVE")"
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "csrf=$T" --data-urlencode "sello=$SELLO" -d para="$MAILN" "$BASE/?r=admin_demo_correo&id=$SALON")
check "El mismo sello otra vez: 410 (ya se envió)" "410" "$r"
contiene "… «Este envío ya caducó»" "$TMP/post.html" "Este envío ya caducó"
# Sitio sin /etc/tukan-correo.env: «Configure el correo de Tukán»
B=$BASE_APAGADA; JAR="$TMP/c_admin_apagada"
post "r=admin_entrar" -d email=$ADM -d clave=claveAdminSegura1 > /dev/null
r=$(post "r=admin_nuevo" --data-urlencode "salon=Salón Sin Correo" --data-urlencode "nombre=Luis Cliente" -d telefono=0998887700 -d email="sincorreo$RANDOM@salon.ec" -d plan=completa)
seguir "$r"
contiene "Sin correo configurado: botón desactivado «Configure el correo de Tukán»" "$TMP/pag.html" "disabled data-correo-sin-configurar>Configure el correo de Tukán"
contiene "… con el comando para el servidor" "$TMP/pag.html" "bash deploy/configurar_correo.sh"
unset B

echo
echo "Pantallas IA y correo: $OK correctas, $FALLAS fallidas"
rm -rf "$TMP"
[ "$FALLAS" -eq 0 ]
