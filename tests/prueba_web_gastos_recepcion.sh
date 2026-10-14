#!/usr/bin/env bash
# Pantallas nuevas del 14 oct 2026, de punta a punta por HTTP (lo corre tests/run_web.sh con el SRI simulado):
#   - Mi plan, registro y Cuentas ya no prometen rol de pagos con IESS, décimos, cobro automático del arriendo ni sucursales
#   - Gastos y facturas: subir XML (y .zip), reporte TXT, claves de acceso, completar con el SRI, categoría, quitar, candado en Básica
#   - Recepción: el dueño crea la cuenta; recepción agenda, cobra y acepta solicitudes, pero no ve ganancias ni configuración
#   - Mis clientes (quien alquila el puesto): clientes privados que el dueño y recepción no ven
# Uso suelto: BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web_gastos_recepcion.sh
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
destino() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code} %{redirect_url}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*value="//;s/"$//'; }
token() { get "$1" > /dev/null; local t; t=$(csrf); if [ -z "$t" ]; then get "r=login" > /dev/null; t=$(csrf); fi; echo "$t"; }
post() { local ruta=$1; shift; local t; t=$(token "$ruta")
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?$ruta"; }
postf() { local ruta=$1; shift; local t; t=$(token "$ruta")   # formulario con archivos
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" -F "csrf=$t" "$@" "$BASE/?$ruta"; }
seguir() { get "${1#*\?}" > /dev/null; }   # abre la página a la que redirigió (para leer los avisos)
registrar() { # salón plan -> deja SID y EMAIL (y la sesión del dueño en un JAR nuevo)
  JAR="$TMP/c_$RANDOM$RANDOM"
  EMAIL="d$RANDOM$RANDOM@prueba.ec"
  post "r=registro" --data-urlencode "salon=$1" --data-urlencode "nombre=Dueña Prueba" -d telefono=0990002222 \
       --data-urlencode "email=$EMAIL" -d clave=claveSegura1 -d plan=$2 > /dev/null
  SID=$(sql "SELECT salon_id FROM usuarios WHERE email='$EMAIL'")
}
entrar() { JAR="$TMP/c_$RANDOM$RANDOM"; post "r=login" --data-urlencode "email=$1" -d clave="$2" > /dev/null; }
HOY=$(date +%F); MES=$(date +%Y-%m)
# Claves de acceso válidas y XML de facturas (con el mismo armado que el SRI)
clave() { php -r '[$f,$c,$r,$s]=array_slice($argv,1);[$a,$m,$d]=explode("-",$f);$b=$d.$m.$a.$c.$r."2"."001001".str_pad($s,9,"0",STR_PAD_LEFT)."12345678"."1";$t=0;$k=2;for($i=47;$i>=0;$i--){$t+=$b[$i]*$k;$k=$k==7?2:$k+1;}$v=11-$t%11;if($v==11)$v=0;if($v==10)$v=1;echo $b.$v;' "$@"; }
xml() { # archivo clave ruc razon base iva comprador
  local d=${HOY:8:2} m=${HOY:5:2} a=${HOY:0:4}
  local total; total=$(php -r "echo number_format($5+$6,2,'.','');")
  cat > "$1" <<EOF
<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>$2</numeroAutorizacion><comprobante><![CDATA[<?xml version="1.0" encoding="UTF-8"?><factura id="comprobante"><infoTributaria><ambiente>2</ambiente><tipoEmision>1</tipoEmision><razonSocial>$4</razonSocial><ruc>$3</ruc><claveAcceso>$2</claveAcceso><codDoc>01</codDoc><estab>001</estab><ptoEmi>001</ptoEmi><secuencial>${2:30:9}</secuencial></infoTributaria><infoFactura><fechaEmision>$d/$m/$a</fechaEmision><identificacionComprador>$7</identificacionComprador><totalSinImpuestos>$5</totalSinImpuestos><totalConImpuestos><totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><baseImponible>$5</baseImponible><valor>$6</valor></totalImpuesto></totalConImpuestos><importeTotal>$total</importeTotal></infoFactura><detalles><detalle><descripcion>Tinte rubio cenizo</descripcion><cantidad>4</cantidad></detalle></detalles></factura>]]></comprobante></autorizacion>
EOF
}

echo "1. Textos de venta sin promesas que no existen"
registrar "Salón Promesas" basica
JAR_BASICA=$JAR
get "r=completa" > /dev/null
for t in "IESS" "décimo" "Cobro automático" "cobro automático del arriendo" "sucursal" "Rol de pagos"; do
  no_contiene "Mi plan no dice \"$t\"" "$TMP/pag.html" "$t"
done
contiene "Mi plan ofrece la cuenta de recepción" "$TMP/pag.html" "Cuenta de recepción"
contiene "Mi plan ofrece los gastos con facturas del SRI" "$TMP/pag.html" "facturas que recibes del SRI"
contiene "Mi plan ofrece los clientes privados" "$TMP/pag.html" "Clientes privados para quien alquila el puesto"
get "r=cuentas" > /dev/null
no_contiene "Cuentas (Básica) ya no promete cobro automático del arriendo" "$TMP/pag.html" "automático del arriendo"
no_contiene "Cuentas (Básica) ya no promete rol de pagos" "$TMP/pag.html" "rol de pagos"
get "r=equipo" > /dev/null
no_contiene "Equipo no promete calcular el aporte al IESS" "$TMP/pag.html" "se calcula el aporte"
JAR="$TMP/c_sin"; get "r=registro" > /dev/null
no_contiene "El registro no promete IESS ni sucursales" "$TMP/pag.html" "IESS\|sucursal"
contiene "El registro menciona recepción y gastos" "$TMP/pag.html" "recepción y gastos con tus facturas"

echo "2. Básica: Gastos y Recepción con candado"
JAR=$JAR_BASICA
get "r=gastos" > /dev/null
contiene "Gastos y facturas es del plan Completa" "$TMP/pag.html" "es del plan Completa"
get "r=recepcion" > /dev/null
contiene "Recepción es del plan Completa" "$TMP/pag.html" "es del plan Completa"
r=$(postf "r=gastos" -F accion=xml -F "xml[]=@/etc/hostname")
check "Básica no puede subir facturas" "0" "$(sql "SELECT count(*) FROM facturas_recibidas WHERE salon_id=$SID")"

echo "3. Completa: subir facturas XML"
registrar "Peluquería Facturas" completa
SIDF=$SID; JAR_DUENA=$JAR; EMAIL_DUENA=$EMAIL
get "r=inicio" > /dev/null
contiene "El inicio invita a subir las facturas" "$TMP/pag.html" "Súbelas del SRI"
contiene "El menú tiene Gastos y facturas" "$TMP/pag.html" "r=gastos"
check "La página abre" "200" "$(get "r=gastos")"
contiene "Explica que TuSalón no hace facturas" "$TMP/pag.html" "TuSalón no hace facturas"
K1=$(clave "$HOY" 01 1790011111001 11); K2=$(clave "$HOY" 01 1790022222001 22)
xml "$TMP/tintes.xml" "$K1" 1790011111001 "DISTRIBUIDORA BELLEZA" 40.00 6.00 1790000000001
xml "$TMP/luz.xml" "$K2" 1790022222001 "EMPRESA ELECTRICA" 20.00 0.00 1712345678
echo "no es un xml" > "$TMP/roto.xml"
r=$(postf "r=gastos&mes=$MES" -F accion=xml -F "xml[]=@$TMP/tintes.xml" -F "xml[]=@$TMP/luz.xml" -F "xml[]=@$TMP/roto.xml")
check "Sube y vuelve a la página" "302 $BASE/?r=gastos&mes=$MES" "$r"
seguir "$r"
contiene "Avisa cuántas entraron" "$TMP/pag.html" "2 facturas nuevas"
contiene "Avisa el archivo malo con su nombre" "$TMP/pag.html" "roto.xml: No es un XML válido."
check "Quedan guardadas 2 facturas" "2" "$(sql "SELECT count(*) FROM facturas_recibidas WHERE salon_id=$SIDF")"
contiene "La lista muestra el proveedor" "$TMP/pag.html" "DISTRIBUIDORA BELLEZA"
contiene "Y lo que se compró" "$TMP/pag.html" "4 × Tinte rubio cenizo"
contiene "Gastos del mes = \$66,00" "$TMP/pag.html" '\$66,00'
r=$(postf "r=gastos" -F accion=xml -F "xml[]=@$TMP/tintes.xml"); seguir "$r"
contiene "La misma factura no entra dos veces" "$TMP/pag.html" "1 ya estaba registrada"
(cd "$TMP" && rm -f f.zip && php -r '$z=new ZipArchive;$z->open("f.zip",ZipArchive::CREATE);$z->addFile("tintes.xml");$z->addFile("luz.xml");$z->close();')
r=$(postf "r=gastos" -F accion=xml -F "xml[]=@$TMP/f.zip"); seguir "$r"
contiene "Un .zip con los XML se lee" "$TMP/pag.html" "2 ya estaban registradas"

echo "4. RUC del salón y facturas a nombre de otra persona"
r=$(post "r=gastos" -d accion=ruc -d ruc=1790000000001)
check "Guarda el RUC" "1790000000001" "$(sql "SELECT ruc FROM salones WHERE id=$SIDF")"
r=$(post "r=gastos" -d accion=ruc -d ruc=123); seguir "$r"
contiene "Un RUC mal escrito se rechaza" "$TMP/pag.html" "El RUC tiene 13 números"
get "r=gastos" > /dev/null
contiene "Marca la factura que vino a nombre de otra persona" "$TMP/pag.html" "A nombre de otra persona"
K3=$(clave "$HOY" 01 1790033333001 33)
xml "$TMP/ajena.xml" "$K3" 1790033333001 "FARMACIA" 5.00 0.75 0912345678
r=$(postf "r=gastos" -F accion=xml -F "xml[]=@$TMP/ajena.xml"); seguir "$r"
contiene "Al subirla avisa que no es del RUC del salón" "$TMP/pag.html" "a nombre de otra persona (no de tu RUC)"

echo "5. Categoría y quitar"
F1=$(sql "SELECT id FROM facturas_recibidas WHERE salon_id=$SIDF AND clave_acceso='$K1'")
r=$(post "r=gastos&mes=$MES" -d accion=categoria -d id=$F1 -d categoria=insumos)
check "Cambia la categoría" "insumos" "$(sql "SELECT categoria FROM facturas_recibidas WHERE id=$F1")"
check "El proveedor la recuerda" "insumos" "$(sql "SELECT categoria FROM proveedores WHERE salon_id=$SIDF AND ruc='1790011111001'")"
get "r=gastos&mes=$MES&cat=insumos" > /dev/null
contiene "Filtro por categoría" "$TMP/pag.html" "Facturas · Insumos"
no_contiene "El filtro esconde las de otras categorías" "$TMP/pag.html" "EMPRESA ELECTRICA</div>"
F3=$(sql "SELECT id FROM facturas_recibidas WHERE salon_id=$SIDF AND clave_acceso='$K3'")
r=$(post "r=gastos" -d accion=borrar -d id=$F3)
check "Quitar la factura personal" "0" "$(sql "SELECT count(*) FROM facturas_recibidas WHERE id=$F3")"
get "r=inicio" > /dev/null
contiene "Hoy: los gastos enlazan a la página" "$TMP/pag.html" 'href="/?r=gastos">Gastos (facturas recibidas)'

echo "6. Reporte TXT y completar con el SRI (simulado)"
K4=$(clave "$HOY" 01 1790044444001 44); K5=$(clave "$HOY" 01 1790055555001 55)
D=${HOY:8:2}; M=${HOY:5:2}; A=${HOY:0:4}
printf 'COMPROBANTE\tSERIE_COMPROBANTE\tRUC_EMISOR\tRAZON_SOCIAL_EMISOR\tFECHA_EMISION\tFECHA_AUTORIZACION\tTIPO_EMISION\tIDENTIFICACION_RECEPTOR\tCLAVE_ACCESO\tNUMERO_AUTORIZACION\tVALOR_SIN_IMPUESTOS\tIVA\tIMPORTE_TOTAL\n' > "$TMP/sri.txt"
printf 'Factura\t001-001-000000044\t1790044444001\tPELUQUERIA SUPPLY\t%s/%s/%s\t\tNORMAL\t1790000000001\t%s\t%s\t100,00\t15,00\t115,00\n' "$D" "$M" "$A" "$K4" "$K4" >> "$TMP/sri.txt"
printf 'Factura\t001-001-000000055\t1790055555001\tCNT EP\t%s/%s/%s\t\tNORMAL\t1790000000001\t%s\t%s\t25,00\t3,75\t28,75\n' "$D" "$M" "$A" "$K5" "$K5" >> "$TMP/sri.txt"
r=$(postf "r=gastos" -F accion=txt -F "txt=@$TMP/sri.txt"); seguir "$r"
contiene "Del TXT entran 2" "$TMP/pag.html" "2 facturas nuevas"
contiene "Pide completarlas con el SRI" "$TMP/pag.html" "Completar con el SRI"
contiene "Marca las que no tienen detalle" "$TMP/pag.html" "Sin detalle"
r=$(post "r=gastos" -d accion=completar); seguir "$r"
contiene "Las completa con el SRI" "$TMP/pag.html" "2 completadas con el detalle del SRI"
check "Ya no queda ninguna sin detalle" "0" "$(sql "SELECT count(*) FROM facturas_recibidas WHERE salon_id=$SIDF AND NOT completa")"
K6=$(clave "$HOY" 01 1790066666001 66); KNO=$(clave "$HOY" 01 1790066666001 999999999)
r=$(post "r=gastos" --data-urlencode "claves=Clave: $K6 y $KNO" -d accion=claves); seguir "$r"
contiene "Una clave pegada entra desde el SRI" "$TMP/pag.html" "1 factura nueva"
contiene "La que el SRI no tiene da un aviso claro" "$TMP/pag.html" "no tiene registrado"
r=$(post "r=gastos" --data-urlencode "claves=12345" -d accion=claves); seguir "$r"
contiene "Sin claves válidas, explica qué pegar" "$TMP/pag.html" "son 49 números"

echo "7. Otro salón no toca estas facturas"
registrar "Salón Ajeno" completa
r=$(post "r=gastos" -d accion=borrar -d id=$F1); seguir "$r"
contiene "No encuentra la factura ajena" "$TMP/pag.html" "No se encontró la factura."
check "La factura sigue ahí" "1" "$(sql "SELECT count(*) FROM facturas_recibidas WHERE id=$F1")"
get "r=gastos" > /dev/null
no_contiene "No ve las facturas de otro salón" "$TMP/pag.html" "DISTRIBUIDORA BELLEZA"

echo "8. Cuenta de recepción"
JAR=$JAR_DUENA
EMAIL_REC="rosa$RANDOM@prueba.ec"
r=$(post "r=recepcion" -d accion=crear --data-urlencode "nombre=Rosa Recepción" -d email=$EMAIL_REC -d clave=claveRosa1)
check "El dueño crea la cuenta de recepción" "recepcion" "$(sql "SELECT rol FROM usuarios WHERE email='$EMAIL_REC'")"
r=$(post "r=recepcion" -d accion=crear --data-urlencode "nombre=Otra" -d email=$EMAIL_REC -d clave=claveRosa1)
contiene "No deja repetir el correo" "$TMP/post.html" "ya se usa en otra cuenta"
r=$(post "r=recepcion" -d accion=crear --data-urlencode "nombre=Corta" -d email=corta$RANDOM@prueba.ec -d clave=123)
contiene "Contraseña corta se rechaza" "$TMP/post.html" "al menos 8 caracteres"
# Datos para recepción: peluquera, servicio, una solicitud en línea
post "r=equipo" --data-urlencode "nombre=Ana" -d tipo=empleado -d comision_servicio_pct=40 > /dev/null
ANA=$(sql "SELECT id FROM profesionales WHERE salon_id=$SIDF AND nombre='Ana'")
SERV=$(sql "SELECT id FROM servicios WHERE salon_id=$SIDF ORDER BY id LIMIT 1")
MANANA=$(date -d '+1 day' +%F)
sql "INSERT INTO citas (salon_id, profesional_id, inicio, fin, estado, origen, expira_en) VALUES ($SIDF, $ANA, '$MANANA 16:00', '$MANANA 16:30', 'pendiente', 'online', now() + interval '2 hours')" > /dev/null
SOL=$(sql "SELECT max(id) FROM citas WHERE salon_id=$SIDF")
sql "INSERT INTO cita_servicios (cita_id, servicio_id, precio) VALUES ($SOL, $SERV, 10)" > /dev/null
entrar "$EMAIL_REC" claveRosa1
JAR_REC=$JAR
check "Recepción entra al inicio" "200" "$(get "r=inicio")"
contiene "Saluda a recepción" "$TMP/pag.html" "Hola, Rosa"
no_contiene "No ve la ganancia del local" "$TMP/pag.html" "Ganancia del local"
no_contiene "No ve el día del dueño" "$TMP/pag.html" "Tu día como dueño"
no_contiene "El menú no tiene Equipo" "$TMP/pag.html" ">Equipo<"
no_contiene "El menú no tiene Mi plan" "$TMP/pag.html" "Mi plan"
for ruta in equipo cuentas gastos reporte servicios horario completa inventario recepcion paquetes; do
  check "Recepción no entra a $ruta" "302 $BASE/?r=inicio" "$(destino "r=$ruta")"
done
check "Recepción ve la agenda" "200" "$(get "r=agenda&fecha=$MANANA")"
check "Recepción ve la caja" "200" "$(get "r=caja")"
check "Recepción ve los clientes" "200" "$(get "r=clientes")"
check "Recepción ve las solicitudes" "200" "$(get "r=solicitudes")"
contiene "La solicitud en línea aparece" "$TMP/pag.html" "Aceptar"
r=$(post "r=solicitudes" -d cita=$SOL -d accion=aceptar)
check "Recepción acepta la solicitud" "reservada" "$(sql "SELECT estado FROM citas WHERE id=$SOL")"
r=$(post "r=cita_nueva" -d fecha=$MANANA -d hora=11:00 -d profesional=$ANA -d cliente_id=0 --data-urlencode "cliente_nuevo=Cliente De Rosa" -d telefono=0993333333 -d "servicios[]=$SERV")
CITA_REC=$(sql "SELECT max(id) FROM citas WHERE salon_id=$SIDF")
check "Recepción agenda una cita" "302 $BASE/?r=cita&id=$CITA_REC" "$r"
r=$(post "r=cobrar&cita=$CITA_REC" -d cita=$CITA_REC -d profesional_id=$ANA -d "servicio[]=$SERV" -d "precio[$SERV]=10" -d metodo_pago=efectivo -d cobrado_por=local -d propina=0)
check "Recepción cobra" "302 $BASE/?r=caja" "$r"
check "La cita queda atendida" "atendida" "$(sql "SELECT estado FROM citas WHERE id=$CITA_REC")"
JAR=$JAR_DUENA
r=$(post "r=recepcion" -d accion=desactivar -d id=$(sql "SELECT id FROM usuarios WHERE email='$EMAIL_REC'"))
check "El dueño le quita el acceso" "f" "$(sql "SELECT activo FROM usuarios WHERE email='$EMAIL_REC'")"
JAR=$JAR_REC
check "Sin acceso, recepción vuelve al login" "302 $BASE/?r=login" "$(destino "r=inicio")"
JAR=$JAR_DUENA
post "r=recepcion" -d accion=activar -d id=$(sql "SELECT id FROM usuarios WHERE email='$EMAIL_REC'") > /dev/null
sql "UPDATE salones SET plan_codigo='basica' WHERE id=$SIDF" > /dev/null
entrar "$EMAIL_REC" claveRosa1
get "r=inicio" > /dev/null
contiene "Si el salón baja a Básica, recepción no entra" "$TMP/pag.html" "La cuenta de recepción es del plan Completa"
sql "UPDATE salones SET plan_codigo='completa' WHERE id=$SIDF" > /dev/null

echo "9. Clientes privados de quien alquila el puesto"
JAR=$JAR_DUENA
post "r=equipo" --data-urlencode "nombre=Mateo" -d tipo=alquiler -d monto_arriendo=70 -d frecuencia_arriendo=semanal > /dev/null
MATEO=$(sql "SELECT id FROM profesionales WHERE salon_id=$SIDF AND nombre='Mateo'")
EMAIL_MATEO="mateo$RANDOM@prueba.ec"
post "r=equipo" -d accion=acceso -d id=$MATEO -d email=$EMAIL_MATEO -d clave=claveMateo1 > /dev/null
entrar "$EMAIL_MATEO" claveMateo1
JAR_MATEO=$JAR
get "r=mi_portal" > /dev/null
contiene "El portal de Mateo enlaza Mis clientes" "$TMP/pag.html" "r=mis_clientes"
check "Mis clientes abre" "200" "$(get "r=mis_clientes")"
r=$(post "r=mis_clientes" -d accion=crear --data-urlencode "nombre=Valeria Secreta" -d telefono=0994444444 -d cumple_dia=3 -d cumple_mes=5)
PRIV=$(sql "SELECT id FROM clientes WHERE salon_id=$SIDF AND nombre='Valeria Secreta'")
check "Crea su cliente privado" "$MATEO" "$(sql "SELECT profesional_privado_id FROM clientes WHERE id=$PRIV")"
check "Y abre su ficha" "302 $BASE/?r=mis_clientes&id=$PRIV" "$r"
r=$(post "r=mis_clientes" -d accion=crear --data-urlencode "nombre=X")
contiene "Nombre muy corto se rechaza" "$TMP/post.html" "Escribe el nombre del cliente"
r=$(post "r=mis_clientes" -d accion=crear --data-urlencode "nombre=Cumple Malo" -d cumple_dia=31 -d cumple_mes=2)
contiene "Cumpleaños imposible se rechaza" "$TMP/post.html" "no existe"
get "r=mis_clientes&id=$PRIV" > /dev/null
contiene "Botón de WhatsApp con su número" "$TMP/pag.html" "wa.me/593994444444"
r=$(post "r=mis_clientes&id=$PRIV" -d accion=cita -d id=$PRIV -d fecha=$MANANA -d hora=10:00 -d "servicios[]=$SERV")
CPRIV=$(sql "SELECT max(id) FROM citas WHERE cliente_id=$PRIV")
check "Le agenda una cita en su silla" "$MATEO" "$(sql "SELECT profesional_id FROM citas WHERE id=$CPRIV")"
r=$(post "r=mis_clientes&id=$PRIV" -d accion=cita -d id=$PRIV -d fecha=$MANANA -d hora=10:15 -d "servicios[]=$SERV")
contiene "No deja agendar encima" "$TMP/post.html" "choca con otra cita"
r=$(post "r=mis_clientes&id=$PRIV" -d accion=borrar -d id=$PRIV)
contiene "Con citas no se puede borrar" "$TMP/post.html" "no se puede borrar"
JAR=$JAR_DUENA
get "r=clientes" > /dev/null
no_contiene "El dueño no lo ve en Clientes" "$TMP/pag.html" "Valeria Secreta"
check "Ni abriendo su ficha directo" "302 $BASE/?r=clientes" "$(destino "r=cliente&id=$PRIV")"
get "r=agenda&fecha=$MANANA" > /dev/null
no_contiene "En la agenda del dueño no sale su nombre" "$TMP/pag.html" "Valeria Secreta"
contiene "Sale como \"Cliente de Mateo\"" "$TMP/pag.html" "Cliente de Mateo"
get "r=cita&id=$CPRIV" > /dev/null
no_contiene "La cita no muestra su nombre" "$TMP/pag.html" "Valeria"
no_contiene "Ni su celular" "$TMP/pag.html" "593994444444"
check "La ficha técnica no se abre" "302 $BASE/?r=clientes" "$(destino "r=ficha&cliente=$PRIV")"
r=$(post "r=cita_nueva" -d fecha=$MANANA -d hora=12:00 -d profesional=$ANA -d cliente_id=$PRIV -d "servicios[]=$SERV")
contiene "El dueño no puede agendar al cliente privado" "$TMP/post.html" "Ese cliente no existe"
JAR=$JAR_REC
get "r=agenda&fecha=$MANANA" > /dev/null
no_contiene "Recepción tampoco ve su nombre" "$TMP/pag.html" "Valeria Secreta"
JAR=$JAR_MATEO
get "r=mi_portal" > /dev/null
contiene "Mateo sí lo ve en sus próximas citas" "$TMP/pag.html" "Valeria Secreta"
check "Mateo no entra a la lista de clientes del salón" "302 $BASE/?r=mi_portal" "$(destino "r=clientes")"
entrar "$EMAIL_DUENA" claveSegura1
post "r=equipo" --data-urlencode "nombre=Pablo" -d tipo=empleado -d comision_servicio_pct=40 > /dev/null
PABLO=$(sql "SELECT id FROM profesionales WHERE salon_id=$SIDF AND nombre='Pablo'")
EMAIL_PABLO="pablo$RANDOM@prueba.ec"
post "r=equipo" -d accion=acceso -d id=$PABLO -d email=$EMAIL_PABLO -d clave=clavePablo1 > /dev/null
entrar "$EMAIL_PABLO" clavePablo1
check "Un empleado no tiene Mis clientes" "302 $BASE/?r=mi_portal" "$(destino "r=mis_clientes")"
JAR=$JAR_MATEO
r=$(post "r=mis_clientes" -d accion=editar -d id=$(sql "SELECT id FROM clientes WHERE salon_id=$SIDF AND nombre='Cliente De Rosa'") --data-urlencode "nombre=Robado")
contiene "Mateo no puede editar clientes del local" "$TMP/post.html" "No se encontró ese cliente"

echo
echo "=================================================="
echo "Resultado: $OK correctas, $FALLAS fallidas"
rm -rf "$TMP"
[ "$FALLAS" -eq 0 ]
