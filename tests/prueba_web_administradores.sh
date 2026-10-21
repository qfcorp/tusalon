#!/usr/bin/env bash
# Administradores (21 oct 2026), de punta a punta por HTTP (lo corre tests/run_web.sh):
#   - El dueño crea administradores en Equipo → Administradores (nombre, correo y contraseña; activar y desactivar)
#   - Máximo 4 activos además del dueño: «Puede tener hasta 4 administradores además del dueño»
#   - No cuentan en el límite de personas (salón con solo la base: sigue pudiendo agregar peluqueros)
#   - Mismos accesos que el dueño: recorre TODAS las rutas privadas de public/index.php y compara
#   - No pueden tocar al dueño ni desactivarse o cambiarse el rol a sí mismos; rol inválido y sin CSRF se rechazan
# Uso suelto: BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web_administradores.sh
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
destino() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/dest.html" -w "%{http_code} %{redirect_url}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
token() { get "$1" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi; echo "$t"; }
post() { local ruta=$1; shift; local t; t=$(token "$ruta")
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
seguir() { get "${1#*\?}" > /dev/null; }
registrar() { # salón plan -> SID, EMAIL y la sesión del dueño en un JAR nuevo
  JAR="$TMP/c_$RANDOM$RANDOM"
  EMAIL="d$RANDOM$RANDOM@prueba.ec"
  if [ "$2" = "basica" ]; then set -- "$1" basica "" pagado; else set -- "$1" completa "" ""; fi
  TUSALON_DB_NAME=$DB TUSALON_DB_USER=tusalon TUSALON_DB_PASS=$PGPASSWORD php tests/demo_web.php "$1" "Dueña Prueba" 0990002222 "$EMAIL" claveSegura1 "$2" "$3" "$4" > /dev/null
  post "r=login" --data-urlencode "email=$EMAIL" -d clave=claveSegura1 > /dev/null
  SID=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL'")
}
entrar() { JAR="$TMP/c_$RANDOM$RANDOM"; post "r=login" --data-urlencode "email=$1" -d clave="$2" > /dev/null; }
admins() { sql "SELECT count(*) FROM usuarios WHERE salon_id=$SID AND rol='admin' AND activo"; }

echo "1. El dueño ve la sección Administradores en Equipo y crea uno"
registrar "Salón Administradores" completa
JAR_DUENA=$JAR; EMAIL_DUENA=$EMAIL; DUENA_ID=$(sql "SELECT id FROM usuarios WHERE email='$EMAIL'")
check "Equipo abre" "200" "$(get "r=equipo")"
contiene "Equipo tiene la sección Administradores" "$TMP/pag.html" "data-seccion-administradores"
contiene "… con el enlace a la pantalla" "$TMP/pag.html" "r=administradores"
contiene "… y dice que no cuentan en el límite de personas" "$TMP/pag.html" "no cuentan en el límite de personas"
contiene "El menú «Más» tiene Administradores" "$TMP/pag.html" ">Administradores</a>"
check "La pantalla Administradores abre" "200" "$(get "r=administradores")"
contiene "Muestra al dueño" "$TMP/pag.html" "Es la cuenta principal ante Tukán"
contiene "Cupo: 0 de 4" "$TMP/pag.html" "0 de 4 administradores activos"
contiene "Formulario con nombre" "$TMP/pag.html" 'name="nombre"'
contiene "… correo" "$TMP/pag.html" 'name="email"'
contiene "… contraseña" "$TMP/pag.html" 'name="clave"'
contiene "… y token CSRF" "$TMP/pag.html" 'name="csrf"'
no_contiene "El dueño no tiene botón para desactivarse" "$TMP/pag.html" 'value="desactivar"'
r=$(post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Ana Admin" --data-urlencode "email=ana.$SID@prueba.ec" -d clave=claveAna123)
check "Crear vuelve a la pantalla" "302 $BASE/?r=administradores" "$r"
seguir "$r"
contiene "Avisa que ya puede entrar" "$TMP/pag.html" "ya puede entrar"
check "Quedó como administrador, sin persona" "admin|" "$(sql "SELECT rol || '|' || coalesce(profesional_id::text,'') FROM usuarios WHERE email='ana.$SID@prueba.ec'")"
ANA_ID=$(sql "SELECT id FROM usuarios WHERE email='ana.$SID@prueba.ec'")

echo "2. Validaciones en el servidor"
antes=$(sql "SELECT count(*) FROM usuarios WHERE salon_id=$SID")
for rol in dueno profesional superadmin chofer; do
  post "r=administradores" -d accion=crear -d rol=$rol --data-urlencode "nombre=Mal Rol" --data-urlencode "email=malrol.$SID@prueba.ec" -d clave=claveSegura1 > /dev/null
  contiene "Rol «$rol»: «Rol no válido»" "$TMP/post.html" "Rol no válido"
done
check "Con rol inválido no se guardó nada" "$antes" "$(sql "SELECT count(*) FROM usuarios WHERE salon_id=$SID")"
check "Sigue habiendo un solo dueño" "1" "$(sql "SELECT count(*) FROM usuarios WHERE salon_id=$SID AND rol='dueno'")"
post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Repetido" --data-urlencode "email=$EMAIL_DUENA" -d clave=claveSegura1 > /dev/null
contiene "Correo repetido: error" "$TMP/post.html" "Ese correo ya se usa"
post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Corta" --data-urlencode "email=corta.$SID@prueba.ec" -d clave=123 > /dev/null
contiene "Contraseña corta: error" "$TMP/post.html" "al menos 8 caracteres"
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" -d accion=crear -d rol=admin --data-urlencode "nombre=Sin Token" --data-urlencode "email=sintoken.$SID@prueba.ec" -d clave=claveSegura1 "$BASE/?r=administradores")
check "Sin token CSRF: 400" "400" "$r"
check "… y no se creó" "0" "$(sql "SELECT count(*) FROM usuarios WHERE email='sintoken.$SID@prueba.ec'")"
check "Sigue habiendo 1 administrador" "1" "$(admins)"

echo "3. El administrador entra y tiene los mismos accesos que el dueño (todas las rutas privadas)"
entrar "ana.$SID@prueba.ec" claveAna123
JAR_ANA=$JAR
check "Entra al inicio" "200" "$(get "r=inicio")"
contiene "Ve el resumen de ganancias como el dueño" "$TMP/pag.html" "r=equipo"
RUTAS=$(php -r '$s = file_get_contents("public/index.php"); preg_match("/\\\$privadas = \[(.*?)\];/s", $s, $m); preg_match_all("/\x27([a-z_]+)\x27/", $m[1], $r); echo implode(" ", $r[1]);')
N=0
for ruta in $RUTAS; do
  [ "$ruta" = "salir" ] && continue
  N=$((N+1))
  JAR=$JAR_DUENA; d=$(destino "r=$ruta")
  JAR=$JAR_ANA;   a=$(destino "r=$ruta")
  check "Ruta $ruta: el administrador llega a lo mismo que el dueño ($d)" "$d" "$a"
done
check "Se recorrieron todas las rutas privadas (más de 30)" "1" "$([ "$N" -gt 30 ] && echo 1 || echo 0)"
JAR=$JAR_ANA
for ruta in equipo servicios horario cuentas completa caja reporte gastos recepcion administradores inventario; do
  check "Administrador abre $ruta (200)" "200" "$(get "r=$ruta")"
done
get "r=completa" > /dev/null
contiene "Ve Mi plan con los módulos de la demo" "$TMP/pag.html" "Todo lo que tiene tu demo"
get "r=equipo" > /dev/null
contiene "Ve el formulario para agregar peluqueros" "$TMP/pag.html" "Agregar al equipo"

echo "4. El administrador maneja a otros, pero no al dueño ni a sí mismo"
r=$(post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Beto Admin" --data-urlencode "email=beto.$SID@prueba.ec" -d clave=claveBeto123)
check "Un administrador crea a otro" "302 $BASE/?r=administradores" "$r"
BETO_ID=$(sql "SELECT id FROM usuarios WHERE email='beto.$SID@prueba.ec'")
get "r=administradores" > /dev/null
contiene "Cupo: 2 de 4" "$TMP/pag.html" "2 de 4 administradores activos"
r=$(post "r=administradores" -d accion=desactivar -d id=$DUENA_ID)
contiene "No puede desactivar al dueño" "$TMP/post.html" "La cuenta del dueño no se puede cambiar"
post "r=administradores" -d accion=clave -d id=$DUENA_ID -d clave=hackeada123 > /dev/null
contiene "No puede cambiarle la contraseña al dueño" "$TMP/post.html" "La cuenta del dueño no se puede cambiar"
post "r=administradores" -d accion=editar -d id=$DUENA_ID -d rol=admin --data-urlencode "nombre=Hack" --data-urlencode "email=hack.$SID@prueba.ec" > /dev/null
contiene "No puede editar al dueño" "$TMP/post.html" "La cuenta del dueño no se puede cambiar"
check "El dueño sigue intacto" "dueno|true|$EMAIL_DUENA" "$(sql "SELECT rol || '|' || activo || '|' || email FROM usuarios WHERE id=$DUENA_ID")"
post "r=administradores" -d accion=desactivar -d id=$ANA_ID > /dev/null
contiene "No puede desactivarse a sí misma" "$TMP/post.html" "No puedes desactivarte a ti mismo"
post "r=administradores" -d accion=editar -d id=$ANA_ID -d rol=recepcion --data-urlencode "nombre=Ana Admin" --data-urlencode "email=ana.$SID@prueba.ec" > /dev/null
contiene "No puede cambiarse el rol a sí misma" "$TMP/post.html" "No puedes cambiarte el rol a ti mismo"
check "Ana sigue activa y administradora" "admin|true" "$(sql "SELECT rol || '|' || activo FROM usuarios WHERE id=$ANA_ID")"
post "r=administradores" -d accion=editar -d id=$BETO_ID -d rol=dueno --data-urlencode "nombre=Beto" --data-urlencode "email=beto.$SID@prueba.ec" > /dev/null
contiene "Editar con rol inválido: error" "$TMP/post.html" "Rol no válido"
check "… Beto sigue como administrador" "admin" "$(sql "SELECT rol FROM usuarios WHERE id=$BETO_ID")"
r=$(post "r=administradores" -d accion=desactivar -d id=$BETO_ID)
check "Un administrador desactiva a otro" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$BETO_ID")"
entrar "beto.$SID@prueba.ec" claveBeto123
check "El desactivado ya no entra" "302 $BASE/?r=login" "$(destino "r=inicio")"
JAR=$JAR_ANA
r=$(post "r=administradores" -d accion=activar -d id=$BETO_ID)
check "Un administrador lo vuelve a activar" "t" "$(sql "SELECT activo FROM usuarios WHERE id=$BETO_ID")"
r=$(post "r=recepcion" -d accion=crear --data-urlencode "nombre=Rosa Recepción" --data-urlencode "email=rosa.$SID@prueba.ec" -d clave=claveRosa123)
check "Un administrador crea una cuenta de recepción" "recepcion" "$(sql "SELECT rol FROM usuarios WHERE email='rosa.$SID@prueba.ec'")"
ROSA_ID=$(sql "SELECT id FROM usuarios WHERE email='rosa.$SID@prueba.ec'")
r=$(post "r=recepcion" -d accion=desactivar -d id=$ROSA_ID)
check "… y la desactiva" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$ROSA_ID")"
r=$(post "r=recepcion" -d accion=activar -d id=$ROSA_ID)
r=$(post "r=equipo" -d tipo=empleado --data-urlencode "nombre=Pepe Peluquero" -d sueldo_mensual=0 -d comision_servicio_pct=40 -d comision_producto_pct=10)
check "Un administrador agrega un peluquero" "1" "$(sql "SELECT count(*) FROM profesionales WHERE salon_id=$SID AND nombre='Pepe Peluquero' AND activo")"
PEPE=$(sql "SELECT id FROM profesionales WHERE salon_id=$SID AND nombre='Pepe Peluquero'")
r=$(post "r=equipo" -d accion=acceso -d id=$PEPE --data-urlencode "email=pepe.$SID@prueba.ec" -d clave=clavePepe123)
check "… le da acceso a su portal" "profesional" "$(sql "SELECT rol FROM usuarios WHERE email='pepe.$SID@prueba.ec'")"
r=$(post "r=equipo" -d accion=desactivar -d id=$PEPE)
check "… y lo desactiva" "f" "$(sql "SELECT activo FROM profesionales WHERE id=$PEPE")"
PEPE_U=$(sql "SELECT id FROM usuarios WHERE email='pepe.$SID@prueba.ec'")
post "r=administradores" -d accion=desactivar -d id=$PEPE_U > /dev/null
contiene "Las cuentas de peluquero no se manejan en Administradores" "$TMP/post.html" "Esa cuenta se maneja en Equipo"

echo "5. Límite de 4 administradores activos además del dueño"
JAR=$JAR_DUENA
for n in 3 4; do
  post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Admin $n" --data-urlencode "email=admin$n.$SID@prueba.ec" -d clave=claveSegura1 > /dev/null
done
check "Hay 4 administradores activos" "4" "$(admins)"
get "r=administradores" > /dev/null
contiene "La pantalla avisa que llegó al límite" "$TMP/pag.html" "data-limite-admin"
post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Quinto" --data-urlencode "email=quinto.$SID@prueba.ec" -d clave=claveSegura1 > /dev/null
contiene "Crear el quinto: «Puede tener hasta 4 administradores además del dueño»" "$TMP/post.html" "Puede tener hasta 4 administradores además del dueño"
check "… no se creó" "0" "$(sql "SELECT count(*) FROM usuarios WHERE email='quinto.$SID@prueba.ec'")"
r=$(post "r=administradores" -d accion=desactivar -d id=$BETO_ID)
r=$(post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Quinto" --data-urlencode "email=quinto.$SID@prueba.ec" -d clave=claveSegura1)
check "Con un cupo libre sí se crea" "4" "$(admins)"
post "r=administradores" -d accion=activar -d id=$BETO_ID > /dev/null
contiene "Activar un quinto: el mismo mensaje" "$TMP/post.html" "Puede tener hasta 4 administradores además del dueño"
check "… Beto sigue sin acceso" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$BETO_ID")"
post "r=administradores" -d accion=editar -d id=$ROSA_ID -d rol=admin --data-urlencode "nombre=Rosa" --data-urlencode "email=rosa.$SID@prueba.ec" > /dev/null
contiene "Pasar recepción a administrador con 4: el mismo mensaje" "$TMP/post.html" "Puede tener hasta 4 administradores además del dueño"
check "… Rosa sigue en recepción" "recepcion" "$(sql "SELECT rol FROM usuarios WHERE id=$ROSA_ID")"

echo "6. Recepción y peluqueros no entran a Administradores"
entrar "rosa.$SID@prueba.ec" claveRosa123
check "Recepción va al inicio" "302 $BASE/?r=inicio" "$(destino "r=administradores")"
get "r=inicio" > /dev/null
no_contiene "Recepción no ve el enlace" "$TMP/pag.html" "r=administradores"

echo "7. No cuentan en el límite de personas (salón con solo la base: hasta 3)"
registrar "Barbería Base Admins" basica
for n in 1 2 3 4; do
  post "r=administradores" -d accion=crear -d rol=admin --data-urlencode "nombre=Admin $n" --data-urlencode "email=base$n.$SID@prueba.ec" -d clave=claveSegura1 > /dev/null
done
check "Con solo la base se crean 4 administradores (no dependen de ningún módulo)" "4" "$(admins)"
get "r=equipo" > /dev/null
contiene "Equipo sigue ofreciendo agregar peluqueros" "$TMP/pag.html" "Agregar al equipo"
for n in 1 2; do
  r=$(post "r=equipo" -d tipo=empleado --data-urlencode "nombre=Persona $n" -d sueldo_mensual=0 -d comision_servicio_pct=40 -d comision_producto_pct=10)
done
check "Llega a 3 personas (dueño + 2) con 4 administradores" "3" "$(sql "SELECT count(*) FROM profesionales WHERE salon_id=$SID AND activo")"
get "r=equipo" > /dev/null
contiene "Ahí sí llega al límite de personas" "$TMP/pag.html" "Tu plan permite hasta 3 personas"
contiene "… y aclara que los administradores no cuentan" "$TMP/pag.html" "Los administradores no cuentan"
get "r=administradores" > /dev/null
no_contiene "Administradores no está bloqueado por ningún módulo" "$TMP/pag.html" "No está en tu plan"

echo
echo "=================================================="
echo "Resultado: $OK correctas, $FALLAS fallidas"
rm -rf "$TMP"
[ "$FALLAS" -eq 0 ]
