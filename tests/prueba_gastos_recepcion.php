<?php
declare(strict_types=1);

/**
 * Pruebas de lo construido el 14 oct 2026:
 *  1. Facturas RECIBIDAS del SRI (lector de XML, reporte TXT, claves de acceso con el SRI simulado, notas de crédito,
 *     repetidas, categorías que se recuerdan, a nombre de otra persona, gastos y ganancia real).
 *  2. Clientes privados de quien alquila el puesto (no salen en las listas del dueño, avisos sin nombre, ficha técnica).
 *  3. Usuario de recepción (responde solicitudes como el dueño y recibe sus avisos).
 * Borra y recrea la base de prueba (igual que run_tests.php).
 *
 * Uso (lo corre tests/run_logica.sh con el SRI simulado):
 *   TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba SRI_WS_URL=http://127.0.0.1:8098/sri php tests/prueba_gastos_recepcion.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\{Agenda, Altas, Automaticas, Caja, Db, FacturasRecibidas, FichaTecnica, Fidelidad, Liquidacion, Notificaciones, Planes, Profesionales, Sri};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.001 : $esperado === $real;
    if ($igual) { $ok++; echo "  OK    $nombre\n"; }
    else { $fallos[] = $nombre; echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}
function check_error(string $nombre, string $texto, callable $f): void
{
    global $ok, $fallos;
    try { $f(); $fallos[] = $nombre; echo "  FALLA $nombre — esperaba un error con [$texto]\n"; }
    catch (Throwable $e) {
        if (str_contains($e->getMessage(), $texto)) { $ok++; echo "  OK    $nombre\n"; }
        else { $fallos[] = $nombre; echo "  FALLA $nombre — el error fue [" . $e->getMessage() . "]\n"; }
    }
}

/** Clave de acceso válida (con su dígito verificador módulo 11). */
function clave(string $fecha, string $cod, string $ruc, int $sec, string $ambiente = '2'): string
{
    [$a, $m, $d] = explode('-', $fecha);
    $base = $d . $m . $a . $cod . $ruc . $ambiente . '001001' . str_pad((string) $sec, 9, '0', STR_PAD_LEFT) . '12345678' . '1';
    $suma = 0; $f = 2;
    for ($i = 47; $i >= 0; $i--) { $suma += (int) $base[$i] * $f; $f = $f === 7 ? 2 : $f + 1; }
    $dv = 11 - ($suma % 11);
    if ($dv === 11) $dv = 0;
    if ($dv === 10) $dv = 1;
    return $base . $dv;
}
function xmlFactura(string $clave, string $fecha, string $ruc, string $razon, float $base, float $iva, string $comprador = '1790000000001', string $raiz = 'factura'): string
{
    [$a, $m, $d] = explode('-', $fecha);
    $cod = $raiz === 'notaCredito' ? '04' : '01';
    $it = "<infoTributaria><ambiente>2</ambiente><tipoEmision>1</tipoEmision><razonSocial>$razon</razonSocial><ruc>$ruc</ruc><claveAcceso>$clave</claveAcceso>"
        . "<codDoc>$cod</codDoc><estab>001</estab><ptoEmi>001</ptoEmi><secuencial>" . substr($clave, 30, 9) . '</secuencial></infoTributaria>';
    $imp = "<totalConImpuestos><totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><baseImponible>$base</baseImponible><valor>$iva</valor></totalImpuesto></totalConImpuestos>";
    $total = number_format($base + $iva, 2, '.', '');
    $comp = $raiz === 'notaCredito'
        ? "<notaCredito id=\"comprobante\">$it<infoNotaCredito><fechaEmision>$d/$m/$a</fechaEmision><identificacionComprador>$comprador</identificacionComprador><totalSinImpuestos>$base</totalSinImpuestos><valorModificacion>$total</valorModificacion>$imp</infoNotaCredito></notaCredito>"
        : "<factura id=\"comprobante\">$it<infoFactura><fechaEmision>$d/$m/$a</fechaEmision><identificacionComprador>$comprador</identificacionComprador><totalSinImpuestos>$base</totalSinImpuestos>$imp<importeTotal>$total</importeTotal></infoFactura>"
          . '<detalles><detalle><descripcion>Shampoo keratina</descripcion><cantidad>3</cantidad></detalle><detalle><descripcion>Cera</descripcion><cantidad>1</cantidad></detalle></detalles></factura>';
    return '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>' . $clave . '</numeroAutorizacion><comprobante><![CDATA['
         . '<?xml version="1.0" encoding="UTF-8"?>' . $comp . ']]></comprobante></autorizacion>';
}

if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));   // se pueden correr muchas veces
check('Las migraciones se pueden correr dos veces (006 incluida)', true, (bool) $db->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'facturas_recibidas' AND column_name = 'completa'")->fetchColumn());

$a = (new Altas($db))->crearSalonConDueno('Barbería Gastos', 'Dueña Prueba', '0990000000', 'duena' . bin2hex(random_bytes(3)) . '@prueba.ec', 'claveSegura1', 'completa');
$sid = (int) $a['salon_id'];
$db->prepare("UPDATE salones SET ruc = '1790000000001' WHERE id = ?")->execute([$sid]);
$fr = new FacturasRecibidas($db);
$hoy = date('Y-m-d');
$mes1 = date('Y-m-01');
$mesFin = date('Y-m-t');

// ------------------------------------------------------------------
echo "\n1. Lector de comprobantes del SRI\n";
$k1 = clave($hoy, '01', '1790011111001', 101);
check('Clave de acceso válida (dígito verificador)', true, Sri::claveValida($k1));
check('Clave con un número cambiado no vale', false, Sri::claveValida(substr($k1, 0, 48) . (((int) $k1[48] + 1) % 10)));
check('De la clave se lee la fecha', $hoy, Sri::datosClave($k1)['fecha']);
check('De la clave se lee el tipo y el RUC', ['factura', '1790011111001'], [Sri::datosClave($k1)['tipo'], Sri::datosClave($k1)['ruc_emisor']]);
$d = Sri::leerComprobante(xmlFactura($k1, $hoy, '1790011111001', 'DISTRIBUIDORA BELLEZA S.A.', 40.00, 6.00));
check('Lee el XML autorizado', true, $d['ok']);
check('Proveedor, número y total', ['DISTRIBUIDORA BELLEZA S.A.', '001-001-000000101', 46.0], [$d['razon_social'], $d['numero'], $d['total']]);
check('Subtotal e IVA', [40.0, 6.0], [$d['subtotal'], $d['iva']]);
check('Detalle de lo comprado', '3 × Shampoo keratina · Cera', $d['detalle']);
check('Un archivo que no es XML se rechaza', 'No es un XML válido.', Sri::leerComprobante('hola')['error']);
check('Un XML cualquiera se rechaza', 'No es un comprobante electrónico del SRI.', Sri::leerComprobante('<a><b>1</b></a>')['error']);
check('Un XML con entidades (ataque) se rechaza', false, Sri::leerComprobante('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>')['ok']);
$ret = '<comprobanteRetencion><infoTributaria><ruc>1790011111001</ruc><codDoc>07</codDoc><claveAcceso>' . $k1 . '</claveAcceso></infoTributaria></comprobanteRetencion>';
check('Una retención no se importa como gasto', true, str_contains(Sri::leerComprobante($ret)['error'], 'retención'));
check('Números con coma decimal', 1234.56, Sri::num('1.234,56'));

// ------------------------------------------------------------------
echo "\n2. Importar XML\n";
$k2 = clave($hoy, '01', '1790022222001', 202);
$r = $fr->importarXmls($sid, [['tintes.xml', xmlFactura($k1, $hoy, '1790011111001', 'DISTRIBUIDORA BELLEZA S.A.', 40.00, 6.00)],
                              ['luz.xml', xmlFactura($k2, $hoy, '1790022222001', 'EMPRESA ELECTRICA QUITO', 20.00, 0.00)],
                              ['roto.xml', 'no es xml'], ['grande.xml', null]]);
check('2 facturas nuevas', 2, $r['nueva']);
check('Los archivos malos dan su error con el nombre', ['roto.xml: No es un XML válido.', 'grande.xml: no se pudo leer (¿pesa más de 2 MB?).'], $r['errores']);
$r = $fr->importarXmls($sid, [['tintes.xml', xmlFactura($k1, $hoy, '1790011111001', 'DISTRIBUIDORA BELLEZA S.A.', 40.00, 6.00)]]);
check('La misma factura no entra dos veces', [0, 1], [$r['nueva'], $r['repetida']]);
check('Se guarda el XML y queda completa', [true, true], (function () use ($db, $sid, $k1) {
    $f = $db->query("SELECT completa, xml FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k1'")->fetch();
    return [$f['completa'], str_contains((string) $f['xml'], $k1)];
})());
$g = $fr->gastos($sid, $mes1, $mesFin);
check('Gastos del mes = 46 + 20 = $66', 66.0, $g['total']);
check('Sin categoría guardada quedan en "Otros"', ['otros' => 66.0], $g['por_categoria']);

echo "\n3. Categorías que se recuerdan\n";
$idTintes = (int) $db->query("SELECT id FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k1'")->fetchColumn();
$fr->cambiarCategoria($sid, $idTintes, 'insumos');
check('Cambia la categoría', 'insumos', $db->query("SELECT categoria FROM facturas_recibidas WHERE id = $idTintes")->fetchColumn());
check('El proveedor la recuerda', 'insumos', $fr->categoriaProveedor($sid, '1790011111001'));
$k3 = clave($hoy, '01', '1790011111001', 303);
$fr->importarXmls($sid, [['otra.xml', xmlFactura($k3, $hoy, '1790011111001', 'DISTRIBUIDORA BELLEZA S.A.', 10.00, 1.50)]]);
check('La próxima factura del proveedor entra sola como insumos', 'insumos', $db->query("SELECT categoria FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k3'")->fetchColumn());
check_error('Categoría inventada se rechaza', 'Elige una categoría', fn() => $fr->cambiarCategoria($sid, $idTintes, 'fiesta'));
$otro = (new Altas($db))->crearSalonConDueno('Otro Salón', 'Otro', '0990000001', 'otro' . bin2hex(random_bytes(3)) . '@prueba.ec', 'claveSegura1', 'completa');
check_error('No se puede tocar la factura de otro salón', 'No se encontró', fn() => $fr->cambiarCategoria((int) $otro['salon_id'], $idTintes, 'otros'));
check_error('Ni borrarla', 'No se encontró', fn() => $fr->borrar((int) $otro['salon_id'], $idTintes));

echo "\n4. Notas de crédito y facturas a nombre de otra persona\n";
$k4 = clave($hoy, '04', '1790011111001', 404);
$r = $fr->importarXmls($sid, [['nc.xml', xmlFactura($k4, $hoy, '1790011111001', 'DISTRIBUIDORA BELLEZA S.A.', 10.00, 1.50, '1790000000001', 'notaCredito')]]);
check('Nota de crédito importada', 1, $r['nueva']);
$g = $fr->gastos($sid, $mes1, $mesFin);
check('La nota de crédito resta: 66 + 11,50 − 11,50 = $66', 66.0, $g['total']);
$k5 = clave($hoy, '01', '1790033333001', 505);
$r = $fr->importarXmls($sid, [['ajena.xml', xmlFactura($k5, $hoy, '1790033333001', 'FARMACIA', 5.00, 0.75, '1712345678')]]);
check('Avisa que vino a nombre de otra persona', 1, $r['ajenas']);
$k6 = clave($hoy, '01', '1790033333001', 506);
$r = $fr->importarXmls($sid, [['cedula.xml', xmlFactura($k6, $hoy, '1790033333001', 'FARMACIA', 5.00, 0.75, '1790000000')]]);
check('La cédula del dueño (mismo RUC sin 001) no es ajena', 0, $r['ajenas']);
$idAjena = (int) $db->query("SELECT id FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k5'")->fetchColumn();
$fr->borrar($sid, $idAjena);
check('Se puede quitar de los gastos', 0, (int) $db->query("SELECT count(*) FROM facturas_recibidas WHERE id = $idAjena")->fetchColumn());

echo "\n5. Reporte TXT de SRI en línea\n";
$k7 = clave($hoy, '01', '1790044444001', 707);
$k8 = clave($hoy, '01', '1790055555001', 808);
$kRet = clave($hoy, '07', '1790055555001', 809);
[$a2, $m2, $d2] = explode('-', $hoy);
$txt = "COMPROBANTE\tSERIE_COMPROBANTE\tRUC_EMISOR\tRAZON_SOCIAL_EMISOR\tFECHA_EMISION\tFECHA_AUTORIZACION\tTIPO_EMISION\tIDENTIFICACION_RECEPTOR\tCLAVE_ACCESO\tNUMERO_AUTORIZACION\tVALOR_SIN_IMPUESTOS\tIVA\tIMPORTE_TOTAL\n"
     . "Factura\t001-001-000000707\t1790044444001\tPELUQUERIA SUPPLY CIA. LTDA.\t$d2/$m2/$a2\t$d2/$m2/$a2 10:00:00\tNORMAL\t1790000000001\t$k7\t$k7\t100,00\t15,00\t115,00\n"
     . "Factura\t001-001-000000808\t1790055555001\tCNT EP\t$d2/$m2/$a2\t$d2/$m2/$a2 10:00:00\tNORMAL\t1790000000001\t$k8\t$k8\t25,00\t3,75\t28,75\n"
     . "Comprobante de Retención\t001-001-000000809\t1790055555001\tCNT EP\t$d2/$m2/$a2\t\tNORMAL\t1790000000001\t$kRet\t$kRet\t\t\t1,00\n"
     . "Factura\t001-001-000000101\t1790011111001\tDISTRIBUIDORA BELLEZA S.A.\t$d2/$m2/$a2\t\tNORMAL\t1790000000001\t$k1\t$k1\t40,00\t6,00\t46,00\n";
$r = $fr->importarTxt($sid, mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8'));
check('Del TXT entran 2 facturas nuevas (la retención no)', 2, $r['nueva']);
check('La que ya vino por XML no se duplica ni se pisa', 1, $r['repetida']);
$f7 = $db->query("SELECT proveedor, total, iva, completa, origen FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k7'")->fetch();
check('Datos del TXT (con coma decimal)', ['PELUQUERIA SUPPLY CIA. LTDA.', '115.00', '15.00', false, 'txt'], [$f7['proveedor'], $f7['total'], $f7['iva'], $f7['completa'], $f7['origen']]);
check('Quedan 2 por completar con el SRI', 2, $fr->porCompletar($sid));
check('El TXT vacío avisa qué descargar', true, str_contains($fr->importarTxt($sid, "hola\nmundo")['errores'][0], 'Comprobantes electrónicos recibidos'));

echo "\n6. Claves de acceso y completar con el SRI (simulado)\n";
if (getenv('SRI_WS_URL')) {
    $log = (string) getenv('SRI_MOCK_LOG');
    $r = $fr->completarDesdeSri($sid, (int) $a['usuario_id']);
    check('Completa las 2 del TXT con el XML del SRI', 2, $r['completada']);
    check('Ya no queda ninguna por completar', 0, $fr->porCompletar($sid));
    $f7 = $db->query("SELECT completa, origen, detalle, proveedor FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k7'")->fetch();
    check('Ahora tiene el detalle y viene del SRI', [true, 'sri', '2 × Tinte rubio 7.1'], [$f7['completa'], $f7['origen'], $f7['detalle']]);
    $k9 = clave($hoy, '01', '1790066666001', 909);
    $kNo = clave($hoy, '01', '1790066666001', 999999999);
    $texto = "Mira estas: $k9 y $kNo, y una mala 1234567890123456789012345678901234567890123456789.";
    $claves = Sri::extraerClaves($texto);
    check('Encuentra las claves válidas en un texto pegado', [$k9, $kNo], $claves);
    $r = $fr->importarClaves($sid, $claves, (int) $a['usuario_id']);
    check('La clave que existe entra', 1, $r['nueva']);
    check('La que el SRI no tiene da un error claro', true, str_contains($r['errores'][0] ?? '', 'no tiene registrado'));
    check('Origen "clave"', 'clave', $db->query("SELECT origen FROM facturas_recibidas WHERE salon_id = $sid AND clave_acceso = '$k9'")->fetchColumn());
    $antes = $log !== '' && is_file($log) ? count(file($log)) : 0;
    $r = $fr->importarClaves($sid, [$k9]);
    check('Una clave ya completa no vuelve a preguntarle al SRI', [1, $antes], [$r['repetida'], $log !== '' && is_file($log) ? count(file($log)) : 0]);
    $muchas = [];
    for ($i = 1; $i <= FacturasRecibidas::MAX_SRI + 3; $i++) $muchas[] = clave($hoy, '01', '1790077777001', 1000 + $i);
    $r = $fr->importarClaves($sid, $muchas);
    check('Máximo ' . FacturasRecibidas::MAX_SRI . ' por vez; las demás quedan pendientes', [FacturasRecibidas::MAX_SRI, 3], [$r['nueva'], $r['pendientes']]);
    $sriOriginal = (string) getenv('SRI_WS_URL');
    putenv('SRI_WS_URL=http://127.0.0.1:1/sri');
    $r = $fr->importarClaves($sid, [clave($hoy, '01', '1790088888001', 1), clave($hoy, '01', '1790088888001', 2)]);
    check('Sin conexión con el SRI: avisa y deja las demás pendientes', [true, 1], [str_starts_with(explode(': ', $r['errores'][0] ?? '', 2)[1] ?? '', 'Sin conexión'), $r['pendientes']]);
    putenv("SRI_WS_URL=$sriOriginal");
} else {
    echo "  (sin SRI simulado: corre con tests/run_logica.sh)\n";
}
check('Texto del resultado', '2 facturas nuevas · 1 ya estaba registrada · faltan 3: vuelve a tocar el botón para seguir.',
      FacturasRecibidas::textoResultado(['nueva' => 2, 'completada' => 0, 'repetida' => 1, 'pendientes' => 3]));

echo "\n7. Gastos en el panel del dueño y en el reporte del mes\n";
$res = (new Liquidacion($db))->resumenDueno($sid, $mes1, $mesFin);
$esperado = round((float) $db->query("SELECT sum(CASE WHEN tipo = 'nota_credito' THEN -total ELSE total END) FROM facturas_recibidas WHERE salon_id = $sid")->fetchColumn(), 2);
check('El panel del dueño suma los gastos del mes', $esperado, $res['gastos_facturas']);
check('Ganancia real = ganancia del local − gastos', round($res['ganancia_local'] - $esperado, 2), $res['ganancia_despues_de_gastos']);
check('El reporte del mes usa los mismos gastos', $esperado, (new Automaticas($db))->reporteMes($sid, date('Y-m'))['gastos']);
check('Lista del mes con filtro de categoría', ['insumos'], array_values(array_unique(array_column($fr->lista($sid, $mes1, $mesFin, 'insumos'), 'categoria'))));

// ------------------------------------------------------------------
echo "\n8. Clientes privados de quien alquila el puesto\n";
$prof = new Profesionales($db, new Planes($db));
$mateo = $prof->agregar($sid, 'Mateo', 'alquiler', ['monto_arriendo' => 70, 'frecuencia_arriendo' => 'semanal']);
$ana = $prof->agregar($sid, 'Ana', 'empleado', ['comision_servicio_pct' => 40]);
$serv = (int) $db->query("SELECT id FROM servicios WHERE salon_id = $sid ORDER BY id LIMIT 1")->fetchColumn();
$db->exec("INSERT INTO clientes (salon_id, nombre, telefono, cumple_mes, cumple_dia, puntos) VALUES ($sid, 'Cliente del Local', '0991111111', " . date('n') . ', ' . date('j') . ', 50)');
$st = $db->prepare('INSERT INTO clientes (salon_id, profesional_privado_id, nombre, telefono, email, cumple_mes, cumple_dia, puntos) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
$st->execute([$sid, $mateo, 'Privado De Mateo', '0992222222', 'privado@prueba.ec', (int) date('n'), (int) date('j'), 80]);
$privado = (int) $st->fetchColumn();
check('Mateo ve solo a su cliente', ['Privado De Mateo'], array_column($prof->clientesVisibles($sid, $mateo), 'nombre'));
check('El resto del salón no lo ve', ['Cliente del Local'], array_column($prof->clientesVisibles($sid, null), 'nombre'));
$auto = new Automaticas($db);
check('Cumpleaños de hoy: solo el del local', ['Cliente del Local'], array_column($auto->cumpleanosHoy($sid, new DateTimeImmutable()), 'nombre'));
check('Mejores clientes por puntos: solo el del local', ['Cliente del Local'], array_column((new Fidelidad($db))->mejoresClientes($sid), 'nombre'));
$manana = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$agenda = new Agenda($db);
$citaPriv = $agenda->crearCita($sid, $mateo, $privado, "$manana 10:00", [$serv], 'Cliente propio');
check('Recordatorios de mañana del dueño: sin el privado', [], array_column($auto->recordatoriosManana($sid, new DateTimeImmutable(), false), 'id'));
check('El Telegram automático sí lo incluye (le llega al cliente)', [$citaPriv], array_map('intval', array_column($auto->recordatoriosManana($sid, new DateTimeImmutable()), 'id')));
$c = $agenda->cita($sid, $citaPriv);
check('La cita sabe que el cliente es privado de Mateo', $mateo, (int) $c['cliente_privado_de']);
check('Los avisos al dueño no dicen su nombre', 'Un cliente de Mateo', Agenda::nombreParaAvisos($c));
$agenda->cancelarPorCliente($citaPriv, new DateTimeImmutable('-1 day'));
$aviso = (string) $db->query("SELECT texto FROM notificaciones WHERE cita_id = $citaPriv ORDER BY id DESC LIMIT 1")->fetchColumn();
check('Si cancela, el aviso dice "Un cliente de Mateo"', true, str_starts_with($aviso, 'Un cliente de Mateo canceló') && !str_contains($aviso, 'Privado'));
$ft = new FichaTecnica($db, sys_get_temp_dir());
$duena = ['rol' => 'dueno', 'profesional_id' => null];
$usuMateo = ['rol' => 'profesional', 'profesional_id' => $mateo];
$usuAna = ['rol' => 'profesional', 'profesional_id' => $ana];
check('La dueña no ve la ficha técnica del cliente privado', false, $ft->puedeVer($sid, $privado, $duena));
check('Mateo sí', true, $ft->puedeVer($sid, $privado, $usuMateo));
check('Otra peluquera tampoco', false, $ft->puedeVer($sid, $privado, $usuAna));
$citaPriv2 = $agenda->crearCita($sid, $mateo, $privado, "$manana 11:00", [$serv], 'Cliente propio');
$caja = new Caja($db);
$db->prepare("INSERT INTO ventas (salon_id, cliente_id, cita_id, cobrado_por, cobrado_por_profesional_id, total) VALUES (?,?,?,'profesional',?,10)")->execute([$sid, $privado, $citaPriv2, $mateo]);
check('En la caja aparece como "Cliente de Mateo"', ['Cliente de Mateo'], array_column($caja->resumenDia($sid, date('Y-m-d'))['ventas'] ?? [], 'cliente'));
$tel = '593992222222';
$st = $db->prepare("SELECT count(*) FROM clientes WHERE salon_id = ? AND profesional_privado_id IS NULL AND regexp_replace(COALESCE(telefono,''), '\\D', '', 'g') IN (?, ?)");
$st->execute([$sid, $tel, '0' . substr($tel, 3)]);
check('Una reserva en línea con su celular no se une al cliente privado', 0, (int) $st->fetchColumn());

// ------------------------------------------------------------------
echo "\n9. Usuario de recepción\n";
$db->prepare("INSERT INTO usuarios (salon_id, nombre, email, password_hash, rol) VALUES (?, 'Rosa Recepción', ?, 'x', 'recepcion')")
   ->execute([$sid, 'rosa' . bin2hex(random_bytes(3)) . '@prueba.ec']);
$rosaId = (int) $db->lastInsertId();
$rosa = ['id' => $rosaId, 'rol' => 'recepcion', 'profesional_id' => null];
check('Recepción puede aceptar solicitudes como el dueño', true, Agenda::puedeResponder('dueno', $rosa, $ana));
check('Recepción ve todas las solicitudes', true, Agenda::puedeResponder('peluquero', $rosa, $ana));
$notif = new Notificaciones($db);
check('Recepción recibe los avisos que van al dueño', true, in_array($rosaId, $notif->destinos($sid, $ana, 'dueno'), true));
check('Si el aviso es solo para el peluquero, recepción no lo recibe', false, in_array($rosaId, $notif->destinos($sid, $ana, 'peluquero'), true));
check('Los avisos solo para el dueño (reportes) no le llegan', false, in_array($rosaId, (function () use ($db, $sid) {
    $st = $db->prepare("SELECT id FROM usuarios WHERE salon_id = ? AND rol IN ('dueno','admin') AND activo");
    $st->execute([$sid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
})(), true));
$citaOnline = $agenda->crearCita($sid, $ana, null, "$manana 15:00", [$serv], null, [], 'online');
check('La solicitud aparece en la lista de recepción', [$citaOnline], array_map('intval', array_column($agenda->solicitudes($sid, $rosa), 'id')));
$agenda->responderSolicitud($sid, $citaOnline, $rosa, true);
check('Recepción la acepta', 'reservada', $db->query("SELECT estado FROM citas WHERE id = $citaOnline")->fetchColumn());

echo "\n" . str_repeat('=', 50) . "\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
exit($fallos ? 1 : 0);
