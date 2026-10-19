#!/usr/bin/env bash
# Pantallas: Panel Tukán Central v2, Anexo A (pausar, papelera, restaurar, purga) de punta a punta por HTTP.
# Lo corre tests/run_web.sh (sitio con TUKAN_CENTRAL_* de prueba y TUSALON_BORRADOS en una carpeta temporal).
# Salón pausado: "Tu cuenta está pausada" con el motivo (genérico en abuso) y el WhatsApp de Tukán; su página de reservas
# y el enlace de la cita del cliente "temporalmente no disponible" (503). Borrado: no entra, sus páginas dan 404, sale del
# Panel Tukán (filtro Papelera), se restaura desde el panel y la tarea de cada hora lo borra de verdad a los 30 días.
# Uso suelto: BASE=... DB=... TUKAN_CENTRAL_ID=... TUKAN_CENTRAL_SECRET=... TUSALON_BORRADOS=... bash tests/prueba_web_papelera.sh
set -u
cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8099}
DB=${DB:-tusalon_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export TZ=America/Guayaquil
if [ -z "${TUKAN_CENTRAL_SECRET:-}" ]; then echo "Falta TUKAN_CENTRAL_SECRET (corre: bash tests/run_web.sh central)"; exit 1; fi
TMP=$(mktemp -d)
JAR="$TMP/cookies"
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
no_contiene() { # nombre archivo texto
  if grep -q -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]";
  else OK=$((OK+1)); echo "  OK    $1"; fi
}
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { # ruta datos...  -> imprime "codigo destino"
  local ruta=$1; shift
  get "$ruta" > /dev/null
  local t; t=$(csrf)
  if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi   # el token es de la sesión
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" \
       --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"
}
# Crea un salón nuevo (deja la sesión del dueño). Deja NID (salón) y EMAIL.
registrar() { # [plan] [nombre del salón]
  local plan=${1:-completa} nombre=${2:-Salón de Prueba $RANDOM}
  rm -f "$JAR"
  EMAIL="dueno$RANDOM$RANDOM@prueba.ec"
  # Ya no hay registro con prueba gratis: Tukán crea la demo (tests/demo_web.php) y el dueño entra
  TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php "$(dirname "$0")/demo_web.php" "$nombre" "Dimitry Prueba" 0990000000 "$EMAIL" claveSegura1 "$plan" > /dev/null
  post "r=login" --data-urlencode "email=$EMAIL" --data-urlencode "clave=claveSegura1" > /dev/null
  NID=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL'")
}
entrar() { # email clave
  rm -f "$JAR"
  post "r=login" --data-urlencode "email=$1" --data-urlencode "clave=$2" > /dev/null
}

C97_ID=${TUKAN_CENTRAL_ID:-tusalon-prueba}
export C97_ID
c97_token() {   # c97_token tipo archivo [rol]
  php -r '
  $t = time(); $cuerpo = (string) file_get_contents($argv[2]);
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $p = ["v" => 2, "tipo" => $argv[1], "inst" => getenv("C97_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro", "rol" => $argv[3] ?? "dueno",
        "iat" => $t, "exp" => $t + ($argv[1] === "ejecutar" ? 30 : 60), "nonce" => bin2hex(random_bytes(16)), "bh" => $b(hash("sha256", $cuerpo, true))];
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' -- "$@"
}
c97_sso_token() {
  php -r '
  $t = time(); $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $p = ["v" => 1, "tipo" => "sso", "inst" => getenv("C97_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro",
        "iat" => $t, "exp" => $t + 60, "nonce" => bin2hex(random_bytes(16)), "ir" => $argv[1]];
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' -- "$1"
}
c97_post() {   # c97_post accion 'json' [rol] -> código HTTP; respuesta en $TMP/c97.out
  printf '%s' "$2" > "$TMP/c97_body.json"
  curl -s -o "$TMP/c97.out" -w "%{http_code}" -X POST -H "Content-Type: application/json; charset=utf-8" \
       -H "X-Tukan-Token: $(c97_token "$1" "$TMP/c97_body.json" "${3:-dueno}")" -H "CF-Connecting-IP: 198.51.100.97" \
       --data-binary "@$TMP/c97_body.json" "$BASE/tukan_central.php?accion=$1"
}
c97_ej() {   # c97_ej operacion 'datos json' cliente_id [rol]
  c97_post ejecutar "{\"operacion_id\":\"$(php -r 'echo bin2hex(random_bytes(16));')\",\"accion\":\"$1\",\"cliente_id\":\"$3\",\"datos\":$2}" "${4:-dueno}"
}
c97_json() {
  php -r '$v = json_decode((string) file_get_contents($argv[1]), true);
  foreach (explode(".", $argv[2]) as $k) $v = is_array($v) ? ($v[$k] ?? null) : null;
  echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_null($v) ? "null" : $v));' "$TMP/c97.out" "$1"
}
c97_codigo() { curl -s -o "$TMP/c97_pag.html" -w "%{http_code}" "$BASE/?$1"; }
sql "DELETE FROM tukan_central_pedidos" > /dev/null

echo "1. Salones de prueba (con página de reservas y una cita con su enlace)"
registrar completa "Barbería Web Papelera $RANDOM"; NA=$NID; EA=$EMAIL; JAR_A="$TMP/jar_a"; cp "$JAR" "$JAR_A"
NOMBRE_A=$(sql "SELECT nombre FROM salones WHERE id=$NA"); SLUG_A=$(sql "SELECT slug FROM salones WHERE id=$NA")
PROF_A=$(sql "SELECT id FROM profesionales WHERE salon_id=$NA ORDER BY id LIMIT 1")
CITA_A=$(sql "INSERT INTO citas (salon_id, profesional_id, inicio, fin, estado) VALUES ($NA, $PROF_A, (CURRENT_DATE + 2) + time '10:00', (CURRENT_DATE + 2) + time '11:00', 'confirmada') RETURNING id" | head -1)
TOK_A=$(sql "SELECT token FROM citas WHERE id=$CITA_A")
registrar completa "Salón Web Vecino $RANDOM"; NB=$NID; SLUG_B=$(sql "SELECT slug FROM salones WHERE id=$NB")
check "Reservas del salón: 200" "200" "$(c97_codigo "r=reservar&s=$SLUG_A")"
check "Enlace de la cita del cliente: 200" "200" "$(c97_codigo "r=confirmar&t=$TOK_A")"

echo "2. Pausar (falta de pago): pantalla del salón y páginas públicas"
check "pausar sin motivo: 422" "422 datos.motivo" "$(c97_ej pausar '{"tipo":"falta_pago"}' "$NA") $(c97_json campo)"
check "pausar: 200" "200" "$(c97_ej pausar '{"tipo":"falta_pago","motivo":"Debe la mensualidad de octubre"}' "$NA")"
check "pausar: en la base" "suspendido|falta_pago|Debe la mensualidad de octubre" "$(sql "SELECT estado||'|'||pausa_tipo||'|'||pausa_motivo FROM salones WHERE id=$NA")"
r=$(curl -s -b "$JAR_A" -c "$JAR_A" -o "$TMP/c97_pag.html" -w "%{http_code} %{redirect_url}" "$BASE/?r=inicio")
check "El dueño pausado: va a su pantalla de cuenta" "302 $BASE/?r=prueba_terminada" "$r"
curl -s -b "$JAR_A" -c "$JAR_A" -o "$TMP/c97_pag.html" "$BASE/?r=prueba_terminada"
contiene "Pantalla: Tu cuenta está pausada" "$TMP/c97_pag.html" "Tu cuenta está pausada"
contiene "Pantalla: con el motivo" "$TMP/c97_pag.html" "Debe la mensualidad de octubre"
contiene "Pantalla: WhatsApp de Tukán" "$TMP/c97_pag.html" "https://wa.me/593996408397"
check "Reservas del pausado: 503" "503" "$(c97_codigo "r=reservar&s=$SLUG_A")"
contiene "Reservas: temporalmente no disponible" "$TMP/c97_pag.html" "Temporalmente no disponible"
no_contiene "Reservas: sin el nombre del salón" "$TMP/c97_pag.html" "$NOMBRE_A"
check "Enlace de la cita del pausado: 503" "503" "$(c97_codigo "r=confirmar&t=$TOK_A")"
check "Horas libres (JSON) del pausado: 503" "503" "$(c97_codigo "r=horas&s=$SLUG_A&f=$(date -d '+2 days' +%F)&p=$PROF_A&serv=1")"
contiene "… en JSON" "$TMP/c97_pag.html" '"disponible":false'
check "El vecino no se toca: 200" "200" "$(c97_codigo "r=reservar&s=$SLUG_B")"
c97_ej pausar '{"tipo":"abuso","motivo":"Mandó spam desde las reservas"}' "$NA" > /dev/null
curl -s -b "$JAR_A" -c "$JAR_A" -o "$TMP/c97_pag.html" "$BASE/?r=prueba_terminada"
contiene "Abuso: sigue pausada" "$TMP/c97_pag.html" "Tu cuenta está pausada"
no_contiene "Abuso: el motivo no se muestra" "$TMP/c97_pag.html" "spam"
contiene "Abuso: texto genérico" "$TMP/c97_pag.html" "pausó tu cuenta temporalmente"
check "reactivar: 200" "200" "$(c97_ej reactivar '{}' "$NA")"
check "Reactivado: reservas 200" "200" "$(c97_codigo "r=reservar&s=$SLUG_A")"
check "Reactivado: entra al inicio" "200" "$(curl -s -b "$JAR_A" -c "$JAR_A" -o /dev/null -w "%{http_code}" "$BASE/?r=inicio")"

echo "3. Borrar (papelera)"
check "Borrar con rol soporte: 403" "403 no_permitido" "$(c97_ej borrar_cliente "{\"confirmar\":\"$NOMBRE_A\",\"motivo\":\"Cerró\"}" "$NA" soporte) $(c97_json codigo)"
check "Borrar con el nombre mal: 422" "422 datos.confirmar" "$(c97_ej borrar_cliente '{"confirmar":"otro","motivo":"Cerró"}' "$NA") $(c97_json campo)"
check "Borrar: 200" "200" "$(c97_ej borrar_cliente "{\"confirmar\":\"$NOMBRE_A\",\"motivo\":\"Cerró el local\"}" "$NA")"
COPIA=$(c97_json copia)
check "Borrar: borrar_definitivo_el = hoy + 30" "$(date -d '+30 days' +%F)" "$(c97_json borrar_definitivo_el)"
check "Borrar: la copia existe, en la carpeta de borrados, con permisos 600" "1 1 600" \
      "$([ -f "$COPIA" ] && echo 1 || echo 0) $([[ "$COPIA" == "$TUSALON_BORRADOS/"* ]] && echo 1 || echo 0) $(stat -c %a "$COPIA" 2>/dev/null)"
check "Borrar: la copia se lee (gzip JSON) y es de ese salón" "$NA" "$(php -r 'echo json_decode(gzdecode(file_get_contents($argv[1])), true)["salon_id"];' "$COPIA")"
r=$(curl -s -o "$TMP/c97_web.bin" -w "%{http_code}" "$BASE/respaldos/borrados/$(basename "$COPIA")")
check "Borrar: la copia no se puede bajar desde la web (está fuera de public/)" "no" "$([ "$r" = "200" ] || head -c2 "$TMP/c97_web.bin" | od -An -tx1 | grep -q '1f 8b' && echo si || echo no)"
r=$(curl -s -b "$JAR_A" -c "$JAR_A" -o /dev/null -w "%{http_code} %{redirect_url}" "$BASE/?r=inicio")
check "La sesión abierta del borrado ya no sirve: va al ingreso" "302 $BASE/?r=login" "$r"
entrar "$EA" "claveSegura1"
check "No puede entrar con su clave" "1" "$(grep -c 'El correo o la contraseña no coinciden' "$TMP/post.html")"
check "Reservas del borrado: 404" "404" "$(c97_codigo "r=reservar&s=$SLUG_A")"
no_contiene "Reservas del borrado: sin su nombre" "$TMP/c97_pag.html" "$NOMBRE_A"
check "Enlace de la cita del borrado: 404" "404" "$(c97_codigo "r=confirmar&t=$TOK_A")"
check "El vecino sigue: 200" "200" "$(c97_codigo "r=reservar&s=$SLUG_B")"
c97_post clientes '{"por_pagina":200}' > /dev/null
check "clientes: no sale" "0" "$(c97_json clientes | grep -c "\"id\":\"$NA\"")"
check "clientes con filtros.estado = papelera: sale" "200 1" "$(c97_post clientes '{"filtros":{"estado":"papelera"}}') $(c97_json clientes | grep -c "\"id\":\"$NA\"")"
check "… con papelera.motivo" "Cerró el local" "$(c97_json clientes.0.papelera.motivo)"
check "Otra operación sobre el borrado: 409 en_papelera" "409 en_papelera" "$(c97_ej nota_interna '{"texto":"x"}' "$NA") $(c97_json detalle.motivo)"

echo "4. Panel Tukán propio: filtro Papelera y restaurar"
JAR_S="$TMP/jar_super"; rm -f "$JAR_S"
curl -s -c "$JAR_S" -b "$JAR_S" -o /dev/null -H "CF-Connecting-IP: 198.51.100.98" "$BASE/tukan_central.php?accion=sso&t=$(c97_sso_token '/?r=admin_salones')"
curl -s -b "$JAR_S" -c "$JAR_S" -o "$TMP/c97_pag.html" "$BASE/?r=admin_salones"
no_contiene "Lista del panel: no sale" "$TMP/c97_pag.html" "$NOMBRE_A"
contiene "Lista del panel: opción Papelera" "$TMP/c97_pag.html" 'value="papelera"'
curl -s -b "$JAR_S" -c "$JAR_S" -o "$TMP/c97_pag.html" "$BASE/?r=admin_salones&f=papelera"
contiene "Filtro Papelera: sale" "$TMP/c97_pag.html" "$NOMBRE_A"
contiene "Filtro Papelera: con la fecha del borrado definitivo" "$TMP/c97_pag.html" "se borra el $(date -d '+30 days' +%d/%m/%Y)"
curl -s -b "$JAR_S" -c "$JAR_S" -o "$TMP/c97_pag.html" "$BASE/?r=admin_salon&id=$NA"
contiene "Ficha del panel: En la papelera" "$TMP/c97_pag.html" "En la papelera"
contiene "Ficha del panel: motivo" "$TMP/c97_pag.html" "Cerró el local"
T=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/c97_pag.html" | head -1 | sed 's/.*value="//;s/"$//')
r=$(curl -s -b "$JAR_S" -c "$JAR_S" -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$T" --data-urlencode "accion=restaurar" "$BASE/?r=admin_salon&id=$NA")
check "Restaurar desde el panel" "302 " "$r $(sql "SELECT eliminado_en FROM salones WHERE id=$NA")"
check "Restaurado: reservas 200" "200" "$(c97_codigo "r=reservar&s=$SLUG_A")"
entrar "$EA" "claveSegura1"
check "Restaurado: puede entrar" "200" "$(get "r=inicio")"
check "Restaurado: auditoría del panel" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE salon_id=$NA AND accion='Restaurado de la papelera'")"

echo "5. Purga a los 30 días con la tarea de cada hora (bin/tareas.php con otra fecha)"
c97_ej borrar_cliente "{\"confirmar\":\"$NA\",\"motivo\":\"Ahora sí\"}" "$NA" > /dev/null
COPIA2=$(c97_json copia)
salida=$(TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php bin/tareas.php "$(date -d '+29 days' '+%F %H:%M')" 2>&1)
check "Tarea a los 29 días: no lo borra" "1" "$(sql "SELECT count(*) FROM salones WHERE id=$NA")"
salida=$(TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php bin/tareas.php "$(date -d '+31 days' '+%F %H:%M')" 2>&1)
check "Tarea a los 31 días: borrado de verdad" "0 0 0" \
      "$(sql "SELECT count(*) FROM salones WHERE id=$NA") $(sql "SELECT count(*) FROM citas WHERE salon_id=$NA") $(sql "SELECT count(*) FROM usuarios WHERE email='$EA'")"
c97_txt() { if echo "$2" | grep -q -- "$3"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — [$2]"; fi; }
c97_txt "La tarea lo dice en su resumen" "$salida" "papelera: borrados 1"
check "La copia se conserva" "1" "$([ -f "$COPIA2" ] && echo 1 || echo 0)"
check "El vecino sigue" "1 200" "$(sql "SELECT count(*) FROM salones WHERE id=$NB") $(c97_codigo "r=reservar&s=$SLUG_B")"
check "Después de la purga: la ficha da 404" "404" "$(c97_post cliente "{\"id\":\"$NA\"}")"

echo "----------------------------------------"
echo "Resultado: $OK correctas, $FALLAS fallidas"
rm -rf "$TMP"
[ "$FALLAS" -eq 0 ]
