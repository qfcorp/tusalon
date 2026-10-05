<?php
declare(strict_types=1);

/**
 * Pruebas de TuSalón en un entorno simulado.
 * Borra y recrea la base de prueba, carga una barbería de ejemplo con los
 * 4 tipos de peluquero y verifica que cada cálculo dé lo que debe.
 *
 * Uso:
 *   TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/run_tests.php
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Planes.php';
require __DIR__ . '/../src/Profesionales.php';
require __DIR__ . '/../src/Liquidacion.php';
require __DIR__ . '/../src/FacturasRecibidas.php';
require __DIR__ . '/../src/Agenda.php';
require __DIR__ . '/../src/Notificaciones.php';
require __DIR__ . '/../src/WhatsApp.php';
require __DIR__ . '/../src/Telegram.php';
require __DIR__ . '/../src/CuentaCliente.php';
require __DIR__ . '/../src/Fotos.php';
require __DIR__ . '/../src/Calificaciones.php';
require __DIR__ . '/../src/Automaticas.php';
require __DIR__ . '/../src/Altas.php';
require __DIR__ . '/../src/PanelTukan.php';

use TuSalon\{Db, Planes, Profesionales, Liquidacion, FacturasRecibidas, Agenda, Notificaciones, Telegram, CuentaCliente, Fotos, Calificaciones, Automaticas, Altas, PanelTukan};

$ok = 0;
$fallos = [];
function check(string $nombre, $esperado, $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real)
        ? abs((float) $esperado - (float) $real) < 0.001
        : $esperado === $real;
    if ($igual) {
        $ok++;
        echo "  OK    $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n";
    }
}

$db = Db::conectar();
if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) {
    exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
}
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));

$planes = new Planes($db);
$prof   = new Profesionales($db, $planes);
$liq    = new Liquidacion($db);

// ------------------------------------------------------------------
echo "\n1. Planes, precios y prueba gratis\n";
$c = $planes->cotizar('basica', 'mensual');
check('Básica mensual = $25', 25.0, $c['monto']);
$c = $planes->cotizar('completa', 'mensual');
check('Completa mensual = $35', 35.0, $c['monto']);
$c = $planes->cotizar('basica', 'semestral');
check('Básica semestral: paga $125', 125.0, $c['monto']);
check('Básica semestral: recibe 6 meses', 6, $c['meses_recibidos']);
$c = $planes->cotizar('completa', 'semestral');
check('Completa semestral: paga $175', 175.0, $c['monto']);
check('Completa semestral: sale a $29,17 al mes', 29.17, $c['precio_por_mes']);
$c = $planes->cotizar('basica', 'anual');
check('Básica anual: paga $250', 250.0, $c['monto']);
$c = $planes->cotizar('completa', 'anual');
check('Completa anual: paga $350', 350.0, $c['monto']);
check('Completa anual: recibe 12 meses', 12, $c['meses_recibidos']);
check('Anual incluye dominio', true, $c['incluye_dominio']);
check('Semestral no incluye dominio', false, $planes->cotizar('completa', 'semestral')['incluye_dominio']);

$hoy = new DateTimeImmutable('2026-10-05');
$salon = $planes->crearSalon('Barbería Don Pepe', 'donpepe', 'completa', $hoy);
$prueba = $db->query("SELECT estado, prueba_hasta FROM salones WHERE id = $salon")->fetch();
check('Salón nuevo empieza en prueba', 'prueba', $prueba['estado']);
check('Prueba gratis de 7 días (hasta 12 oct)', '2026-10-12', $prueba['prueba_hasta']);

$subId = $planes->suscribir($salon, 'completa', 'anual', new DateTimeImmutable('2026-10-12'), 'payphone', 'barberiadonpepe.com');
$sub = $db->query("SELECT fin, dominio, monto FROM suscripciones WHERE id = $subId")->fetch();
check('Suscripción anual termina el 11 oct 2027', '2027-10-11', $sub['fin']);
check('Dominio guardado en la suscripción anual', 'barberiadonpepe.com', $sub['dominio']);
check('Salón pasa a activo al pagar', 'activo', $db->query("SELECT estado FROM salones WHERE id = $salon")->fetchColumn());

// ------------------------------------------------------------------
echo "\n2. Límite del plan Básica (3 profesionales, dueño incluido)\n";
$salonB = $planes->crearSalon('Peluquería Rosita', 'rosita', 'basica', $hoy);
$prof->agregar($salonB, 'Rosa (dueña)', 'dueno');
$prof->agregar($salonB, 'Juan', 'empleado', ['comision_servicio_pct' => 40]);
$prof->agregar($salonB, 'Lucía', 'porcentaje', ['pct_profesional' => 50]);
$error = '';
try {
    $prof->agregar($salonB, 'Cuarto peluquero', 'empleado');
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
check('El 4.º profesional en Básica se rechaza', true, str_contains($error, 'hasta 3'));
check('En Completa no hay límite', true, $planes->puedeAgregarProfesional($salon));

$error = '';
try {
    $prof->agregar($salon, 'Mal configurado', 'alquiler', ['monto_arriendo' => 0]);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
check('Arrendatario sin valor de arriendo se rechaza', true, str_contains($error, 'arriendo'));

// ------------------------------------------------------------------
echo "\n3. Barbería Don Pepe con los 4 tipos\n";
$pepe   = $prof->agregar($salon, 'Pepe (dueño)', 'dueno', [], '2026-01-01');
$ana    = $prof->agregar($salon, 'Ana', 'empleado', [
    'sueldo_mensual' => 300, 'comision_servicio_pct' => 20, 'comision_producto_pct' => 10,
    'afiliado_iess' => true, 'garantizar_basico' => true], '2026-03-01');
$luis   = $prof->agregar($salon, 'Luis', 'porcentaje', ['pct_profesional' => 60, 'quien_cobra' => 'local'], '2026-01-01');
$marta  = $prof->agregar($salon, 'Marta', 'porcentaje', ['pct_profesional' => 50, 'quien_cobra' => 'profesional'], '2026-01-01');
$carlos = $prof->agregar($salon, 'Carlos', 'alquiler', [
    'monto_arriendo' => 60, 'frecuencia_arriendo' => 'semanal', 'comision_producto_pct' => 10], '2026-01-01');

$db->exec("INSERT INTO servicios (salon_id, nombre, precio) VALUES ($salon, 'Corte', 10), ($salon, 'Tinte', 40)");
$db->exec("INSERT INTO servicios (salon_id, profesional_id, nombre, precio) VALUES ($salon, $carlos, 'Corte Carlos', 8)");
$db->exec("INSERT INTO productos (salon_id, nombre, precio, costo, stock) VALUES ($salon, 'Shampoo', 15, 7, 20)");
[$corte, $tinte, $corteCarlos] = $db->query("SELECT id FROM servicios WHERE salon_id = $salon ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$shampoo = (int) $db->query("SELECT id FROM productos WHERE salon_id = $salon")->fetchColumn();

$f = '2026-10-13 10:00';
$srv = fn($id, $p, $precio) => ['tipo' => 'servicio', 'servicio_id' => $id, 'profesional_id' => $p, 'precio_unitario' => $precio];
$prd = fn($p, $cant = 1) => ['tipo' => 'producto', 'producto_id' => $shampoo, 'profesional_id' => $p, 'precio_unitario' => 15, 'cantidad' => $cant];

// 1) Pepe corta y cobra el local
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f, 'cobrado_por' => 'local'], [$srv($corte, $pepe, 10)]);
// 2) Ana: corte + tinte + shampoo, con $2 de propina, cobra el local
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f, 'cobrado_por' => 'local', 'propina' => 2, 'metodo_pago' => 'tarjeta'],
    [$srv($corte, $ana, 10), $srv($tinte, $ana, 40), $prd($ana)]);
// 3) Luis (60 %) corta, cobra el local
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f, 'cobrado_por' => 'local'], [$srv($corte, $luis, 12)]);
// 4) Marta (50 %) corta y cobra ella
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f, 'cobrado_por' => 'profesional', 'cobrado_por_profesional_id' => $marta],
    [$srv($corte, $marta, 12)]);
// 5) Carlos (alquiler) corta y cobra con su QR, y vende un shampoo del local cobrándolo él
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f, 'cobrado_por' => 'profesional', 'cobrado_por_profesional_id' => $carlos, 'metodo_pago' => 'deuna'],
    [$srv($corteCarlos, $carlos, 8), $prd($carlos)]);
// 6) Un cliente de Carlos paga en el datáfono del local
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f, 'cobrado_por' => 'local', 'metodo_pago' => 'tarjeta'],
    [$srv($corteCarlos, $carlos, 8)]);
// Arriendo semanal de Carlos
$liq->cargarArriendo($carlos, '2026-10-13');

check('Pepe (dueño) no genera cuenta', 0.0, $liq->saldo($pepe));
check('Ana: 20 % de $50 + 10 % de $15 + $2 propina = $13,50', 13.50, $liq->saldo($ana));
check('Luis: 60 % de $12 = $7,20 a su favor', 7.20, $liq->saldo($luis));
check('Marta: cobró $12, le debe al local el 50 % = -$6', -6.0, $liq->saldo($marta));
check('Carlos: -$13,50 (shampoo) + $8 (cobró el local) - $60 (arriendo) = -$65,50', -65.50, $liq->saldo($carlos));
check('Stock de shampoo baja de 20 a 18', 18, (int) $db->query("SELECT stock FROM productos WHERE id = $shampoo")->fetchColumn());

$res = $liq->resumenDueno($salon, '2026-10-13', '2026-10-13');
check('Producción de Pepe en la silla = $10', 10.0, $res['produccion_dueno']);
check('Entró a la caja del local = $97 (10+67+12+8)', 97.0, $res['entro_a_caja']);
// 10 Pepe + (65-11,5) Ana + (12-7,2) Luis + 6 Marta + 13,5 shampoo Carlos + 0 + 60 arriendo = 147,80
check('Ganancia del local = $147,80', 147.80, $res['ganancia_local']);

// ------------------------------------------------------------------
echo "\n3b. Facturas que RECIBE el salón (gastos del panel)\n";
$fact = new FacturasRecibidas($db);
$clave = fn($n) => str_pad((string) $n, 49, '0', STR_PAD_LEFT);
$nueva = $fact->importar($salon, ['clave_acceso' => $clave(1), 'fecha_emision' => '2026-10-13',
    'ruc_proveedor' => '1790000000001', 'proveedor' => 'Distribuidora de Tintes', 'subtotal' => 17.39,
    'iva' => 2.61, 'total' => 20.00], 'insumos');
check('Factura de tintes importada', true, $nueva);
$fact->importar($salon, ['clave_acceso' => $clave(2), 'fecha_emision' => '2026-10-13',
    'ruc_proveedor' => '1760000000001', 'proveedor' => 'Empresa Eléctrica', 'subtotal' => 15.00,
    'total' => 15.00], 'servicios_basicos');
$repetida = $fact->importar($salon, ['clave_acceso' => $clave(1), 'fecha_emision' => '2026-10-13',
    'ruc_proveedor' => '1790000000001', 'subtotal' => 17.39, 'total' => 20.00]);
check('La misma factura no se importa dos veces', false, $repetida);
// Nueva factura del mismo proveedor sin categoría: debe recordar "insumos"
$fact->importar($salon, ['clave_acceso' => $clave(3), 'fecha_emision' => '2026-10-14',
    'ruc_proveedor' => '1790000000001', 'subtotal' => 8.70, 'iva' => 1.30, 'total' => 10.00]);
check('Recuerda la categoría del proveedor',
    'insumos', $db->query("SELECT categoria FROM facturas_recibidas WHERE clave_acceso = '{$clave(3)}'")->fetchColumn());

$res = $liq->resumenDueno($salon, '2026-10-13', '2026-10-13');
check('Gastos del día por facturas recibidas = $35', 35.0, $res['gastos_facturas']);
check('Gasto en insumos = $20', 20.0, $res['gastos_por_categoria']['insumos']);
check('Ganancia después de gastos = 147,80 - 35 = $112,80', 112.80, $res['ganancia_despues_de_gastos']);

// ------------------------------------------------------------------
echo "\n4. Clientes privados del arrendatario\n";
$db->exec("INSERT INTO clientes (salon_id, nombre) VALUES ($salon, 'Cliente del local')");
$db->exec("INSERT INTO clientes (salon_id, profesional_privado_id, nombre) VALUES ($salon, $carlos, 'Cliente de Carlos')");
$deCarlos = array_column($prof->clientesVisibles($salon, $carlos), 'nombre');
$delDueno = array_column($prof->clientesVisibles($salon, $pepe), 'nombre');
check('Carlos solo ve a sus clientes', ['Cliente de Carlos'], $deCarlos);
check('El dueño no ve los clientes privados de Carlos', ['Cliente del local'], $delDueno);

// ------------------------------------------------------------------
echo "\n5. Rol de pagos de Ana (octubre 2026)\n";
$liq->registrarAnticipo($ana, '2026-10-15', 50);
$rol = $liq->rolDePagos($ana, 2026, 10);
check('Sueldo $300', 300.0, $rol['sueldo']);
check('Comisiones $11,50', 11.50, $rol['comisiones']);
check('Completa hasta el básico: $170,50', 170.50, $rol['ajuste_basico']);
check('Total ingresos = $482', 482.0, $rol['total_ingresos']);
check('Aporte IESS personal 9,45 % = $45,55', 45.55, $rol['aporte_iess_personal']);
check('Anticipo descontado $50', 50.0, $rol['anticipos']);
check('Propina aparte $2', 2.0, $rol['propinas']);
check('Sin fondos de reserva (menos de 1 año)', 0.0, $rol['fondos_reserva']);
check('Neto a recibir = 482 - 45,55 - 50 + 2 = $388,45', 388.45, $rol['neto_a_recibir']);
check('Costo patronal 11,15 % = $53,74', 53.74, $rol['aporte_iess_patronal']);
check('Provisión décimo tercero = $40,17', 40.17, $rol['provision_decimo_tercero']);
check('Provisión décimo cuarto = $40,17', 40.17, $rol['provision_decimo_cuarto']);

// ------------------------------------------------------------------
echo "\n6. Escalas de comisión (solo plan Completa)\n";
$prof->agregarEscala($ana, 1000, 25);
for ($i = 0; $i < 24; $i++) {   // 24 tintes de $40 = $960 + $50 de antes = $1.010 en servicios
    $liq->registrarVenta(['salon_id' => $salon, 'fecha' => '2026-10-20 11:00', 'cobrado_por' => 'local'], [$srv($tinte, $ana, 40)]);
}
check('En plan Básica no se aplican escalas', 0.0, $liq->aplicarEscalas($ana, '2026-10-01', '2026-10-31', false));
check('Completa: pasa $1.000 → +5 % sobre $1.010 = $50,50', 50.50, $liq->aplicarEscalas($ana, '2026-10-01', '2026-10-31', true));

// ------------------------------------------------------------------
echo "\n7. Liquidación y cierre del periodo\n";
$l = $liq->liquidar($carlos, '2026-10-01', '2026-10-31');
check('Liquidación de Carlos: debe $65,50', -65.50, $l['total']);
check('Texto claro para Carlos', 'El profesional le paga al local $65.50', $l['texto']);
check('Después de liquidar, saldo de Carlos = 0', 0.0, $liq->saldo($carlos));
$l = $liq->liquidar($luis, '2026-10-01', '2026-10-31');
check('Liquidación de Luis: el local le paga $7,20', 'El local le paga $7.20', $l['texto']);

$error = '';
try {
    $liq->registrarVenta(['salon_id' => $salon, 'fecha' => $f], []);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
check('Venta vacía se rechaza', true, $error !== '');

// ------------------------------------------------------------------
echo "\n8. WhatsApp con un clic\n";
check('0991234567 → 593991234567', '593991234567', TuSalon\WhatsApp::normalizarTelefono('0991234567'));
check('+593 99 123 4567 → 593991234567', '593991234567', TuSalon\WhatsApp::normalizarTelefono('+593 99 123 4567'));
check('991234567 → 593991234567', '593991234567', TuSalon\WhatsApp::normalizarTelefono('991234567'));
check('Teléfono vacío → sin enlace', null, TuSalon\WhatsApp::enlace('', 'hola'));
$msg = TuSalon\WhatsApp::recordatorio('Juan Pérez', 'Barbería Don Pepe', new DateTimeImmutable('2026-10-13 10:00'), 'Ana');
check('Recordatorio con día, hora y peluquero', true,
    str_contains($msg, 'Hola Juan') && str_contains($msg, 'martes 13/10') && str_contains($msg, '10:00') && str_contains($msg, 'con Ana'));

// ------------------------------------------------------------------
echo "\n9. Pago al peluquero definido por el dueño en cada servicio\n";
$db->exec("INSERT INTO servicios (salon_id, nombre, precio, pago_profesional, duracion_minutos) VALUES ($salon, 'Corte premium', 15, 6, 30)");
$premium = (int) $db->query("SELECT id FROM servicios WHERE nombre = 'Corte premium'")->fetchColumn();
$antes = $liq->saldo($ana);
$v = $liq->registrarVenta(['salon_id' => $salon, 'fecha' => '2026-10-21 10:00', 'cobrado_por' => 'local'],
    [['tipo' => 'servicio', 'servicio_id' => $premium, 'profesional_id' => $ana, 'precio_unitario' => 15]]);
check('Ana (empleada) gana el pago fijo $6, no su 20 %', 6.0, $liq->saldo($ana) - $antes);
$v2 = $liq->registrarVenta(['salon_id' => $salon, 'fecha' => '2026-10-21 11:00', 'cobrado_por' => 'local'],
    [['tipo' => 'servicio', 'servicio_id' => $premium, 'profesional_id' => $ana, 'precio_unitario' => 20]]);
check('Si el dueño cobra más ($20), el peluquero sigue ganando $6', 6.0,
    (float) $db->query("SELECT ganancia_profesional FROM venta_items WHERE venta_id = $v2")->fetchColumn());
check('Servicio sin pago fijo usa el %: tinte $40 → $8', 8.0,
    (float) $db->query("SELECT ganancia_profesional FROM venta_items vi JOIN servicios s ON s.id = vi.servicio_id
                         WHERE s.nombre = 'Tinte' AND vi.profesional_id = $ana LIMIT 1")->fetchColumn());
$antesLuis = $liq->saldo($luis);
$liq->registrarVenta(['salon_id' => $salon, 'fecha' => '2026-10-21 12:00', 'cobrado_por' => 'local'],
    [['tipo' => 'servicio', 'servicio_id' => $premium, 'profesional_id' => $luis, 'precio_unitario' => 15]]);
check('Luis (porcentaje 60 %) sigue con su %: $9', 9.0, $liq->saldo($luis) - $antesLuis);

// ------------------------------------------------------------------
echo "\n10. Horas libres, solicitudes en línea y avisos\n";
$ag = new Agenda($db);
$ag->horarioPorDefecto($salon);
$db->exec("UPDATE salones SET intervalo_reservas = 30, anticipacion_minutos = 0, acepta_reservas = 'dueno', avisar_a = 'ambos' WHERE id = $salon");
$db->exec("INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol) VALUES
           ($salon, $pepe, 'Pepe', 'pepe@x.ec', 'x', 'dueno'), ($salon, $luis, 'Luis', 'luis@x.ec', 'x', 'profesional')");
$uPepe = $db->query("SELECT * FROM usuarios WHERE email = 'pepe@x.ec'")->fetch();
$uLuis = $db->query("SELECT * FROM usuarios WHERE email = 'luis@x.ec'")->fetch();
$lunes = '2026-11-02';   // lunes
$ahora = new DateTimeImmutable('2026-11-01 12:00');
$h = $ag->horasDelDia($salon, $luis, $lunes, 30, $ahora);
check('Lunes 9:00–19:00 cada 30 min = 20 horas libres', 20, count($h['libres']));
check('Domingo cerrado: sin horas', 0, count($ag->horasLibres($salon, $luis, '2026-11-01', 30, $ahora)));

$cita = $ag->reservarOnline($salon, $luis, $lunes, '10:00', [$corte], 'María Cliente', '0991112233', $ahora);
check('Reserva en línea queda como solicitud pendiente', 'pendiente', $db->query("SELECT estado FROM citas WHERE id = $cita")->fetchColumn());
check('Se crea cliente con su celular', '0991112233', $db->query("SELECT telefono FROM clientes WHERE nombre = 'María Cliente'")->fetchColumn());
check('Avisa al dueño y al peluquero (ambos)', 2, (int) $db->query("SELECT count(*) FROM notificaciones WHERE cita_id = $cita")->fetchColumn());
$h = $ag->horasDelDia($salon, $luis, $lunes, 30, $ahora);
check('Las 10:00 ya no aparece libre', false, in_array('10:00', $h['libres'], true));
check('Las 10:00 aparece "en confirmación"', true, in_array('10:00', $h['en_confirmacion'], true));
$msg = '';
try { $ag->reservarOnline($salon, $luis, $lunes, '10:00', [$corte], 'Otro Cliente', '0982223344', $ahora); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Otro cliente que pide las 10:00 recibe "estamos confirmando"', true, str_contains($msg, 'confirmando esa hora para otro cliente'));
check('Ana sigue libre a las 10:00 (es otro peluquero)', true, in_array('10:00', $ag->horasLibres($salon, $ana, $lunes, 30, $ahora), true));

$msg = '';
try { $ag->responderSolicitud($salon, $cita, $uLuis, true); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Si acepta "solo el dueño", el peluquero no puede aceptar', true, str_contains($msg, 'otra persona'));
$ag->responderSolicitud($salon, $cita, $uPepe, true);
check('El dueño acepta: la cita queda reservada', 'reservada', $db->query("SELECT estado FROM citas WHERE id = $cita")->fetchColumn());
check('Los avisos de esa cita quedan leídos', 0, (int) $db->query("SELECT count(*) FROM notificaciones WHERE cita_id = $cita AND NOT leida")->fetchColumn());
$h = $ag->horasDelDia($salon, $luis, $lunes, 30, $ahora);
check('Ya aceptada, las 10:00 no está ni libre ni en confirmación', false,
    in_array('10:00', $h['libres'], true) || in_array('10:00', $h['en_confirmacion'], true));

$db->exec("UPDATE salones SET acepta_reservas = 'peluquero', avisar_a = 'peluquero' WHERE id = $salon");
$c2 = $ag->reservarOnline($salon, $luis, $lunes, '11:00', [$corte], 'Pedro', '0993334455', $ahora);
check('Avisar solo al peluquero: 1 aviso para Luis', [(int) $uLuis['id']],
    array_map('intval', $db->query("SELECT usuario_id FROM notificaciones WHERE cita_id = $c2")->fetchAll(PDO::FETCH_COLUMN)));
$ag->responderSolicitud($salon, $c2, $uLuis, false);
check('El peluquero rechaza: la hora vuelve a estar libre', true, in_array('11:00', $ag->horasLibres($salon, $luis, $lunes, 30, $ahora), true));

// Solicitud vencida: la hora se libera sola
$db->exec("UPDATE salones SET acepta_reservas = 'dueno', minutos_para_aceptar = 30 WHERE id = $salon");
$c3 = $ag->reservarOnline($salon, $luis, $lunes, '12:00', [$corte], 'Rosa', '0994445566', $ahora);
$db->exec("UPDATE citas SET expira_en = now() - interval '1 minute' WHERE id = $c3");
check('Solicitud vencida: la hora se muestra libre otra vez', true, in_array('12:00', $ag->horasLibres($salon, $luis, $lunes, 30, $ahora), true));

$db->exec("UPDATE salones SET acepta_reservas = 'automatico' WHERE id = $salon");
$c4 = $ag->reservarOnline($salon, $luis, $lunes, '15:00', [$corte], 'Juan', '0995556677', $ahora);
check('Aceptación automática: queda reservada al instante', 'reservada', $db->query("SELECT estado FROM citas WHERE id = $c4")->fetchColumn());

$msg = '';
try { $ag->crearCita($salon, $luis, null, "$lunes 15:15", [$corte]); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('El dueño tampoco puede agendar encima', true, str_contains($msg, 'choca'));
$c5 = $ag->crearCita($salon, $ana, null, "$lunes 16:00", [$corte], null, [$corte => 4.5]);
check('El dueño cambia el precio al agendar: $4,50', 4.5, (float) $db->query("SELECT precio FROM cita_servicios WHERE cita_id = $c5")->fetchColumn());

// ------------------------------------------------------------------
echo "\n11. Cuenta opcional del cliente\n";
$cc = new CuentaCliente($db);
$db->exec("UPDATE salones SET acepta_reservas = 'dueno', avisar_a = 'dueno', minutos_para_aceptar = 120 WHERE id = $salon");
// Una ficha de invitada ya existe con este celular (de una reserva anterior)
$fichaInvitada = (int) $db->query("SELECT id FROM clientes WHERE nombre = 'María Cliente'")->fetchColumn();
$cuentaMaria = $cc->crear($salon, 'María Cliente', '0991112233', 'maria@correo.ec', 'secreto123', true);
check('Privacidad: con solo el celular NO se une a la ficha ajena', true, $cuentaMaria !== $fichaInvitada);
$msg = '';
try { $cc->crear($salon, 'Otra', '0990000001', 'maria@correo.ec', 'secreto123', false); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No deja crear dos cuentas con el mismo correo', true, str_contains($msg, 'Ya tienes una cuenta'));
$db->exec("UPDATE clientes SET email = 'pedro@correo.ec' WHERE nombre = 'Pedro'");
$fichaPedro = (int) $db->query("SELECT id FROM clientes WHERE nombre = 'Pedro'")->fetchColumn();
check('Si el salón ya tenía su correo, se une a su ficha y conserva su historial', $fichaPedro,
    $cc->crear($salon, 'Pedro', '0993334455', 'pedro@correo.ec', 'secreto456', false));
check('Entrar con la contraseña correcta', $cuentaMaria, $cc->entrar($salon, 'MARIA@correo.ec', 'secreto123'));
check('Contraseña equivocada no entra', null, $cc->entrar($salon, 'maria@correo.ec', 'otra'));
check('La cuenta es solo de este salón', null, $cc->entrar($salonB, 'maria@correo.ec', 'secreto123'));
$cCuenta = $ag->reservarConCuenta($salon, $cuentaMaria, $luis, $lunes, '16:00', [$corte], $ahora);
check('Reserva con su cuenta queda en su ficha', $cuentaMaria, (int) $db->query("SELECT cliente_id FROM citas WHERE id = $cCuenta")->fetchColumn());
check('Aparece en sus próximas citas (por confirmar)', 1, count(array_filter($cc->proximas($salon, $cuentaMaria), fn($c) => (int) $c['id'] === $cCuenta)));
$ag->responderSolicitud($salon, $cCuenta, $uPepe, true);
$ag->cambiarEstado($salon, $cCuenta, 'atendida');
$h = $cc->historial($salon, $cuentaMaria);
check('Su historial muestra el servicio atendido', 'Corte', $h[0]['servicios'] ?? null);

echo "\n12. Fotos (solo con cuenta y permiso)\n";
$carpeta = sys_get_temp_dir() . '/tusalon_fotos_' . getmypid();
$fotos = new Fotos($db, $carpeta);
$tmp = tempnam(sys_get_temp_dir(), 'f');
$im = imagecreatetruecolor(3000, 2000); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 76)); imagejpeg($im, $tmp, 90); imagedestroy($im);
$archivo = fn() => ['name' => 'corte.jpg', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK];
$fid = $fotos->subir($salon, $cCuenta, $uLuis, $archivo(), 'despues');
check('El peluquero que atendió sube la foto', true, $fid > 0);
$guardada = $carpeta . '/' . $db->query("SELECT archivo FROM fotos WHERE id = $fid")->fetchColumn();
check('Se achica a máximo 1600 px', 1600, getimagesize($guardada)[0]);
check('La foto aparece en el historial del cliente', 1, count($cc->historial($salon, $cuentaMaria)[0]['fotos']));
check('El cliente dueño de la foto la puede ver', $guardada, $fotos->rutaSiPuedeVer($fid, null, [$salon => $cuentaMaria]));
check('Otro cliente no la puede ver', null, $fotos->rutaSiPuedeVer($fid, null, [$salon => $fichaPedro]));
check('Alguien sin sesión no la puede ver', null, $fotos->rutaSiPuedeVer($fid, null, []));
$uOtroSalon = ['salon_id' => $salonB, 'rol' => 'dueno', 'profesional_id' => null];
check('El dueño de otro salón no la puede ver', null, $fotos->rutaSiPuedeVer($fid, $uOtroSalon, []));
$msg = '';
try { $fotos->subir($salon, $cCuenta, ['rol' => 'profesional', 'profesional_id' => $ana, 'salon_id' => $salon], $archivo()); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Otro peluquero no puede subir fotos a esa cita', true, str_contains($msg, 'Solo el peluquero'));
$cCuenta2 = $ag->crearCita($salon, $luis, $fichaPedro, "$lunes 17:30", [$corte]);
$msg = '';
try { $fotos->subir($salon, $cCuenta2, $uLuis, $archivo()); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Cliente con cuenta pero sin permiso: no se guardan fotos', true, str_contains($msg, 'no dio permiso'));
$cInv = $ag->crearCita($salon, $luis, null, "$lunes 18:30", [$corte]);
$msg = '';
try { $fotos->subir($salon, $cInv, $uLuis, $archivo()); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Cliente invitado: no se guardan fotos', true, str_contains($msg, 'no tiene cuenta'));
file_put_contents($tmp, 'esto no es una foto');
$msg = '';
try { $fotos->subir($salon, $cCuenta, $uLuis, $archivo()); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Un archivo que no es foto se rechaza', true, str_contains($msg, 'debe ser una foto'));
$fotos->borrar($salon, $fid, $uPepe);
check('El dueño borra la foto (y el archivo)', false, is_file($guardada));
@unlink($tmp);

echo "\n13. Telegram (con Telegram simulado)\n";
$logTg = getenv('TG_MOCK_LOG');
if ($logTg && getenv('TELEGRAM_API_BASE')) {
    $tg = new Telegram($db);
    $enlace = $tg->enlaceConectar((int) $uPepe['id']);
    check('Enlace para conectar Telegram', true, str_starts_with((string) $enlace, 'https://t.me/' . getenv('TELEGRAM_BOT_USERNAME') . '?start='));
    $codigo = substr($enlace, strpos($enlace, '=') + 1);
    $tg->procesar(['message' => ['chat' => ['id' => 777], 'text' => "/start $codigo"]]);
    check('Al tocar Iniciar, queda conectado', 777, (int) $db->query("SELECT telegram_chat_id FROM usuarios WHERE id = {$uPepe['id']}")->fetchColumn());
    $tg->procesar(['message' => ['chat' => ['id' => 888], 'text' => "/start $codigo"]]);
    check('El mismo enlace no sirve dos veces', 777, (int) $db->query("SELECT telegram_chat_id FROM usuarios WHERE id = {$uPepe['id']}")->fetchColumn());
    file_put_contents($logTg, '');
    $cTg = $ag->reservarOnline($salon, $luis, $lunes, '13:00', [$corte], 'Lucía Telegram', '0996667788', $ahora);
    $llamadas = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($logTg))));
    $msj = array_values(array_filter($llamadas, fn($l) => $l['metodo'] === 'sendMessage'))[0] ?? null;
    check('Llega el aviso al Telegram del dueño', 777, $msj['datos']['chat_id'] ?? null);
    check('El aviso dice cliente, servicio y hora', true, str_contains($msj['datos']['text'] ?? '', 'Lucía Telegram') && str_contains($msj['datos']['text'] ?? '', '13:00'));
    check('Trae botones Aceptar y Rechazar', "ac:$cTg", $msj['datos']['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null);
    $tg->procesar(['callback_query' => ['id' => 'q1', 'data' => "ac:$cTg", 'message' => ['chat' => ['id' => 777], 'message_id' => 5, 'text' => 'x']]]);
    check('Al tocar Aceptar en Telegram, la cita queda reservada', 'reservada', $db->query("SELECT estado FROM citas WHERE id = $cTg")->fetchColumn());
    $cTg2 = $ag->reservarOnline($salon, $luis, $lunes, '14:00', [$corte], 'Intruso', '0996660000', $ahora);
    $tg->procesar(['callback_query' => ['id' => 'q2', 'data' => "ac:$cTg2", 'message' => ['chat' => ['id' => 999], 'message_id' => 6, 'text' => 'x']]]);
    check('Un Telegram que no es del salón no puede aceptar', 'pendiente', $db->query("SELECT estado FROM citas WHERE id = $cTg2")->fetchColumn());
    putenv('TELEGRAM_API_BASE=http://127.0.0.1:1');   // Telegram caído
    $t0 = microtime(true);
    $ag->reservarOnline($salon, $luis, $lunes, '14:30', [$corte], 'Sin Internet', '0996661111', $ahora);
    check('Si Telegram no responde, la reserva igual se guarda', 'pendiente',
        $db->query("SELECT estado FROM citas c JOIN clientes cl ON cl.id = c.cliente_id WHERE cl.nombre = 'Sin Internet'")->fetchColumn());
} else {
    echo "  (omitido: falta el Telegram simulado)\n";
}


// ------------------------------------------------------------------
echo "\n14. Horario propio, almuerzo y vacaciones por peluquero\n";
putenv('TELEGRAM_API_BASE=' . ($tgBase = 'http://127.0.0.1:8098'));
$ahoraN = new DateTimeImmutable('2026-11-08 12:00');   // domingo
$diasAna = [];
foreach ([2, 3, 4, 5, 6] as $d) $diasAna[$d] = ['abre' => '10:00', 'cierra' => '18:00', 'almuerzo_desde' => '13:00', 'almuerzo_hasta' => '14:00'];
$ag->guardarHorarioProfesional($salon, $ana, $diasAna, false);
check('Ana no trabaja los lunes (su horario propio)', null, $ag->horarioDelDia($salon, $ana, '2026-11-09'));
$hAna = $ag->horasDelDia($salon, $ana, '2026-11-10', 30, $ahoraN)['libres'];
check('Martes de Ana 10:00–18:00 sin el almuerzo = 14 horas', 14, count($hAna));
check('A las 13:00 Ana está almorzando', false, in_array('13:00', $hAna, true));
check('A las 12:30 sí (termina justo antes del almuerzo)', true, in_array('12:30', $hAna, true));
check('Luis sigue con el horario del salón el lunes', 20, count($ag->horasDelDia($salon, $luis, '2026-11-09', 30, $ahoraN)['libres']));
$msg = '';
try { $ag->guardarHorarioProfesional($salon, $ana, [2 => ['abre' => '10:00', 'cierra' => '18:00', 'almuerzo_desde' => '19:00', 'almuerzo_hasta' => '20:00']], false); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Almuerzo fuera del horario: no se guarda', true, str_contains($msg, 'no cuadran'));
$ag->agregarBloqueo($salon, $ana, '2026-11-11 00:00', '2026-11-12 00:00', 'Vacaciones');
check('Vacaciones el miércoles: sin horas', 0, count($ag->horasDelDia($salon, $ana, '2026-11-11', 30, $ahoraN)['libres']));
$ag->agregarBloqueo($salon, $ana, '2026-11-12 15:00', '2026-11-12 16:00', 'Médico');
$hJue = $ag->horasDelDia($salon, $ana, '2026-11-12', 30, $ahoraN)['libres'];
check('Permiso de 15:00 a 16:00: esas horas no se ofrecen', [false, false], [in_array('15:00', $hJue, true), in_array('15:30', $hJue, true)]);
check('…pero 14:30 y 16:00 sí', [true, true], [in_array('14:30', $hJue, true), in_array('16:00', $hJue, true)]);
$msg = '';
try { $ag->reservarOnline($salon, $ana, '2026-11-11', '11:00', [$corte], 'Cliente Vacaciones', '0990001111', $ahoraN); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No se puede reservar con Ana en vacaciones', true, str_contains($msg, 'no está disponible'));
check('Hay 2 bloqueos programados', 2, count($ag->bloqueos($ana)));

// ------------------------------------------------------------------
echo "\n15. Servicios que solo hacen algunos y precio por peluquero\n";
$st = $db->prepare("INSERT INTO servicios (salon_id, nombre, descripcion, precio, duracion_minutos) VALUES (?, 'Keratina', ?, 40, 90) RETURNING id");
$st->execute([$salon, 'Alisado con keratina: lavado, aplicación, secado y planchado.']);
$keratina = (int) $st->fetchColumn();
$ag->guardarServicioProfesionales($salon, $keratina, [$ana => 45.0]);
$deAna = array_column($ag->serviciosDe($salon, $ana, true), null, 'id');
check('Ana hace la keratina a $45 (su precio)', 45.0, (float) ($deAna[$keratina]['precio'] ?? 0));
check('La explicación del servicio llega al portal', true, str_contains((string) ($deAna[$keratina]['descripcion'] ?? ''), 'keratina'));
check('Luis no hace keratina', false, isset(array_column($ag->serviciosDe($salon, $luis, true), null, 'id')[$keratina]));
check('El corte (sin restricción) lo hacen todos', true, isset(array_column($ag->serviciosDe($salon, $luis, true), null, 'id')[$corte]));
$msg = '';
try { $ag->reservarOnline($salon, $luis, '2026-11-09', '10:00', [$keratina], 'Pide Keratina', '0990002222', $ahoraN); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No se puede reservar keratina con Luis', true, $msg !== '');
$cK = $ag->reservarOnline($salon, $ana, '2026-11-10', '10:00', [$keratina], 'Rosa Keratina', '0990003333', $ahoraN);
check('Con Ana la cita queda a $45', 45.0, (float) $db->query("SELECT precio FROM cita_servicios WHERE cita_id = $cK")->fetchColumn());
check('La cita dura 90 minutos', '11:30', (new DateTimeImmutable($db->query("SELECT fin FROM citas WHERE id = $cK")->fetchColumn()))->format('H:i'));
check('Cada cita tiene su enlace secreto', 32, strlen((string) $db->query("SELECT token FROM citas WHERE id = $cK")->fetchColumn()));
$ag->guardarServicioProfesionales($salon, $keratina, []);
check('Vacío = lo vuelven a hacer todos', true, isset(array_column($ag->serviciosDe($salon, $luis, true), null, 'id')[$keratina]));
$ag->guardarServicioProfesionales($salon, $keratina, [$ana => null]);

// ------------------------------------------------------------------
echo "\n16. El dueño acepta y pone el valor que paga el cliente\n";
$ag->responderSolicitud($salon, $cK, $uPepe, true, [$keratina => '50.00']);
$citaK = $ag->cita($salon, $cK);
check('La cita queda reservada', 'reservada', $citaK['estado']);
check('El valor quedó en $50', 50.0, (float) $citaK['lista_servicios'][0]['precio']);
$msgK = $ag->mensajeConfirmacion($citaK, 'Barbería Don Pepe', 'https://tusalon.qfradioec.com');
check('El mensaje al cliente dice el valor a cancelar', true, str_contains($msgK, 'Valor a cancelar: $50,00'));
check('…y trae su enlace para ver o cancelar', true, str_contains($msgK, '/?r=confirmar&t=' . $citaK['token']));
$msg = '';
try { $ag->responderSolicitud($salon, $ag->reservarOnline($salon, $ana, '2026-11-10', '15:00', [$corte], 'Valor Negativo', '0990004444', $ahoraN), $uPepe, true, [$corte => -5]); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No acepta valores negativos', true, str_contains($msg, 'negativo'));

// ------------------------------------------------------------------
echo "\n17. Faltas: tras 2, no puede reservar en línea\n";
$f1 = $ag->reservarOnline($salon, $luis, '2026-11-09', '09:00', [$corte], 'Pedro Faltón', '0990005555', $ahoraN);
$f2 = $ag->reservarOnline($salon, $luis, '2026-11-09', '09:30', [$corte], 'Pedro Faltón', '0990005555', $ahoraN);
$ag->cambiarEstado($salon, $f1, 'no_asistio');
$ag->cambiarEstado($salon, $f2, 'no_asistio');
$pedro = (int) $db->query("SELECT cliente_id FROM citas WHERE id = $f1")->fetchColumn();
check('Pedro tiene 2 faltas', 2, $ag->faltas($salon, $pedro));
$msg = '';
try { $ag->reservarOnline($salon, $luis, '2026-11-09', '11:00', [$corte], 'Pedro Faltón', '0990005555', $ahoraN); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Con 2 faltas no puede reservar en línea', true, str_contains($msg, 'no llegaste'));
$ag->perdonarFaltas($salon, $pedro);
check('El dueño perdona: 0 faltas', 0, $ag->faltas($salon, $pedro));
$f3 = $ag->reservarOnline($salon, $luis, '2026-11-09', '11:00', [$corte], 'Pedro Faltón', '0990005555', $ahoraN);
check('Ya puede reservar otra vez', true, $f3 > 0);
$db->exec("UPDATE salones SET max_faltas = 0 WHERE id = $salon");
$ag->cambiarEstado($salon, $f3, 'no_asistio');
$db->exec("UPDATE clientes SET faltas_desde = NULL WHERE id = $pedro");
check('Con "nunca bloquear" puede reservar aunque tenga faltas', true,
    $ag->reservarOnline($salon, $luis, '2026-11-09', '11:30', [$corte], 'Pedro Faltón', '0990005555', $ahoraN) > 0);
$db->exec("UPDATE salones SET max_faltas = 2 WHERE id = $salon");

// ------------------------------------------------------------------
echo "\n18. El cliente confirma, cancela o cambia (hasta 2 horas antes)\n";
$ag->responderSolicitud($salon, $f3 + 1, $uPepe, true);
$cC = $ag->reservarOnline($salon, $luis, '2026-11-09', '16:00', [$corte], 'Carla Cancela', '0990006666', $ahoraN);
$ag->responderSolicitud($salon, $cC, $uPepe, true);
$citaC = $ag->porToken($ag->cita($salon, $cC)['token']);
check('Se encuentra la cita con su enlace secreto', $cC, (int) $citaC['id']);
check('Un enlace inventado no sirve', null, $ag->porToken(str_repeat('a', 32)));
check('Un enlace con letras raras no sirve', null, $ag->porToken("x' OR 1=1 --"));
check('A 1 hora de la cita ya no puede cancelar', true,
    str_contains((string) $ag->motivoNoCancelar($citaC, new DateTimeImmutable('2026-11-09 15:00')), 'menos de 2 horas'));
check('A 3 horas sí puede', null, $ag->motivoNoCancelar($citaC, new DateTimeImmutable('2026-11-09 13:00')));
$antesAvisos = (int) $db->query("SELECT count(*) FROM notificaciones WHERE cita_id = $cC")->fetchColumn();
$ag->cancelarPorCliente($cC, new DateTimeImmutable('2026-11-09 10:00'));
check('Cancelada por el cliente', ['cancelada', 'cliente'], array_values($db->query("SELECT estado, cancelada_por FROM citas WHERE id = $cC")->fetch(PDO::FETCH_NUM)));
check('Se avisa a los mismos que reciben las reservas', $antesAvisos * 2, (int) $db->query("SELECT count(*) FROM notificaciones WHERE cita_id = $cC")->fetchColumn());
check('La hora quedó libre', true, in_array('16:00', $ag->horasDelDia($salon, $luis, '2026-11-09', 30, $ahoraN)['libres'], true));
$msg = '';
try { $ag->cancelarPorCliente($cC, new DateTimeImmutable('2026-11-09 10:00')); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No se cancela dos veces', true, str_contains($msg, 'ya no está activa'));
$cOk = $ag->reservarOnline($salon, $luis, '2026-11-09', '17:00', [$corte], 'Daniel Confirma', '0990007777', $ahoraN);
$ag->responderSolicitud($salon, $cOk, $uPepe, true);
$ag->confirmarPorCliente($cOk);
$fila = $db->query("SELECT estado, confirmada_cliente_en IS NOT NULL FROM citas WHERE id = $cOk")->fetch(PDO::FETCH_NUM);
check('El cliente confirma: queda confirmada', ['confirmada', true], [$fila[0], (bool) $fila[1]]);
$ag->cambiarEstado($salon, $cOk, 'cancelada');
check('Si cancela el salón, queda marcado como salón', 'salon', $db->query("SELECT cancelada_por FROM citas WHERE id = $cOk")->fetchColumn());

// ------------------------------------------------------------------
echo "\n19. Recordatorio el día anterior (Telegram del cliente con botones)\n";
$auto = new Automaticas($db);
$tg = new Telegram($db);
$cR = $ag->reservarOnline($salon, $luis, '2026-11-16', '10:00', [$corte], 'Raúl Recuerda', '0990008888', new DateTimeImmutable('2026-11-14 12:00'));
$ag->responderSolicitud($salon, $cR, $uPepe, true);
$raul = (int) $db->query("SELECT cliente_id FROM citas WHERE id = $cR")->fetchColumn();
$enlaceC = $tg->enlaceCliente($raul);
check('El cliente tiene su enlace para conectar Telegram', true, str_contains((string) $enlaceC, '?start=c'));
$tg->procesar(['message' => ['chat' => ['id' => 5551], 'text' => '/start ' . substr($enlaceC, strpos($enlaceC, '=') + 1)]]);
check('El cliente queda conectado', 5551, (int) $db->query("SELECT telegram_chat_id FROM clientes WHERE id = $raul")->fetchColumn());
$lista = $auto->recordatoriosManana($salon, new DateTimeImmutable('2026-11-15 10:00'));
$deRaul = array_values(array_filter($lista, fn($c) => (int) $c['id'] === $cR))[0] ?? null;
check('Aparece en "recordar mañana"', true, $deRaul !== null);
check('El WhatsApp trae el valor y el enlace', true, str_contains(urldecode((string) $deRaul['wa']), 'Valor: $10,00') && str_contains(urldecode((string) $deRaul['wa']), 'r=confirmar'));
file_put_contents($logTg, '');
check('Antes de las 9:00 no se envía', 0, $auto->enviarRecordatorios($salon, new DateTimeImmutable('2026-11-15 08:00')));
check('Desde las 9:00 se envía a quien tiene Telegram', 1, $auto->enviarRecordatorios($salon, new DateTimeImmutable('2026-11-15 10:00')));
check('No se envía dos veces', 0, $auto->enviarRecordatorios($salon, new DateTimeImmutable('2026-11-15 11:00')));
$llamadas = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($logTg))));
$rec = array_values(array_filter($llamadas, fn($l) => ($l['datos']['chat_id'] ?? 0) === 5551))[0] ?? null;
check('Trae botones "Confirmo" y "Cancelar"', ["cc:$cR", "cx:$cR"],
    array_column($rec['datos']['reply_markup']['inline_keyboard'][0] ?? [], 'callback_data'));
$tg->procesar(['callback_query' => ['id' => 'q9', 'data' => "cx:$cR", 'message' => ['chat' => ['id' => 9999], 'message_id' => 3, 'text' => 'x']]]);
check('Otro Telegram no puede cancelar la cita de Raúl', 'reservada', $db->query("SELECT estado FROM citas WHERE id = $cR")->fetchColumn());
$tg->procesar(['callback_query' => ['id' => 'q10', 'data' => "cc:$cR", 'message' => ['chat' => ['id' => 5551], 'message_id' => 3, 'text' => 'x']]]);
check('Raúl toca "Confirmo": cita confirmada', 'confirmada', $db->query("SELECT estado FROM citas WHERE id = $cR")->fetchColumn());
$cR2 = $ag->crearCita($salon, $ana, $raul, '2026-11-20 10:00', [$corte]);
file_put_contents($logTg, '');
check('Al aceptar, la confirmación con el valor le llega por Telegram', true, $tg->confirmacionAlCliente($salon, $cR2));
check('…y dice el valor', true, str_contains((string) file_get_contents($logTg), 'Valor a cancelar: $10,00'));

// ------------------------------------------------------------------
echo "\n20. Cumpleaños (solo día y mes)\n";
$cc2 = new CuentaCliente($db);
$msg = '';
try { CuentaCliente::validarCumple(4, 31); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('31 de abril no existe', true, str_contains($msg, 'no existe'));
$msg = '';
try { CuentaCliente::validarCumple(5, null); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Si pone el mes, debe poner el día', true, str_contains($msg, 'mes y el día'));
check('29 de febrero sí vale', [2, 29], CuentaCliente::validarCumple(2, 29));
check('Vacío = no lo dio', [null, null], CuentaCliente::validarCumple(null, null));
$sofia = $cc2->crear($salon, 'Sofía Cumple', '0990009999', 'sofia@x.ec', 'clave1234', false, 11, 20);
check('Se guarda el cumpleaños al crear la cuenta', [11, 20], array_map('intval', array_values($db->query("SELECT cumple_mes, cumple_dia FROM clientes WHERE id = $sofia")->fetch(PDO::FETCH_NUM))));
$db->exec("UPDATE clientes SET telegram_chat_id = 5552 WHERE id = $sofia");
$cc2->guardarCumple($salon, $raul, 2, 29);
check('Cumpleañeros del 20/11', ['Sofía Cumple'], array_column($auto->cumpleanosHoy($salon, new DateTimeImmutable('2026-11-20 09:00')), 'nombre'));
file_put_contents($logTg, '');
check('Antes de las 8:00 no saluda', 0, $auto->saludarCumpleanos($salon, new DateTimeImmutable('2026-11-20 07:00')));
check('Desde las 8:00 saluda', 1, $auto->saludarCumpleanos($salon, new DateTimeImmutable('2026-11-20 09:00')));
check('Solo una vez al año', 0, $auto->saludarCumpleanos($salon, new DateTimeImmutable('2026-11-20 15:00')));
$tgLog = (string) file_get_contents($logTg);
check('A Sofía le llega "Feliz cumpleaños" por Telegram', true, str_contains($tgLog, 'Feliz cumpleaños, Sofía'));
check('Al dueño le llega quién cumple hoy', true, str_contains($tgLog, 'Hoy cumplen años: Sofía Cumple'));
check('El del 29/2 se saluda el 28/2 en año no bisiesto', true,
    in_array('Raúl Recuerda', array_column($auto->cumpleanosHoy($salon, new DateTimeImmutable('2027-02-28 09:00')), 'nombre'), true));
check('…y el 29/2 en año bisiesto', [false, true], [
    in_array('Raúl Recuerda', array_column($auto->cumpleanosHoy($salon, new DateTimeImmutable('2028-02-28 09:00')), 'nombre'), true),
    in_array('Raúl Recuerda', array_column($auto->cumpleanosHoy($salon, new DateTimeImmutable('2028-02-29 09:00')), 'nombre'), true)]);

// ------------------------------------------------------------------
echo "\n21. Clientes que no vuelven\n";
$st = $db->prepare("INSERT INTO clientes (salon_id, nombre, telefono) VALUES (?, ?, ?) RETURNING id");
$st->execute([$salon, 'Tomás Olvidado', '0991230001']); $tomas = (int) $st->fetchColumn();
$st->execute([$salon, 'Vale Frecuente', '0991230002']); $vale = (int) $st->fetchColumn();
$st->execute([$salon, 'Juan Ya Agendó', '0991230003']); $juanA = (int) $st->fetchColumn();
foreach ([[$tomas, '2026-09-01 10:00'], [$tomas, '2026-08-01 10:00'], [$vale, '2026-11-25 10:00'], [$juanA, '2026-09-02 10:00']] as [$cl, $cuando]) {
    $id = $ag->crearCita($salon, $marta, $cl, $cuando, [$corte]);
    $ag->cambiarEstado($salon, $id, 'atendida');
}
$ag->crearCita($salon, $marta, $juanA, '2026-12-05 10:00', [$corte]);
$rec = array_column($auto->porRecuperar($salon, new DateTimeImmutable('2026-12-01 10:00')), null, 'nombre');
check('Tomás (13 semanas sin venir) aparece', true, isset($rec['Tomás Olvidado']));
check('Vale (vino hace 1 semana) no aparece', false, isset($rec['Vale Frecuente']));
check('Juan (ya tiene cita) no aparece', false, isset($rec['Juan Ya Agendó']));
check('Mensaje listo con el enlace de reservas', true, str_contains(urldecode((string) ($rec['Tomás Olvidado']['wa'] ?? '')), 'r=reservar&s=donpepe'));

// ------------------------------------------------------------------
echo "\n22. Calificación después del servicio\n";
$cal = new Calificaciones($db);
$db->exec("UPDATE salones SET google_resenas_url = 'https://g.page/r/donpepe/review' WHERE id = $salon");
$msg = '';
try { $cal->calificar($ag->cita($salon, $cR), 5); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No se califica antes de ser atendido', true, str_contains($msg, 'cuando te hayan atendido'));
$ag->cambiarEstado($salon, $cR, 'atendida');
check('5 estrellas: invita a reseñar en Google', 'https://g.page/r/donpepe/review', $cal->calificar($ag->cita($salon, $cR), 5, '¡Excelente!'));
$msg = '';
try { $cal->calificar($ag->cita($salon, $cR), 1); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Solo se califica una vez', true, str_contains($msg, 'Ya calificaste'));
$ag->cambiarEstado($salon, $cK, 'atendida');
check('3 estrellas: no se le manda a Google', null, $cal->calificar($ag->cita($salon, $cK), 3));
check('El dueño recibe aviso de la calificación', true,
    (bool) $db->query("SELECT 1 FROM notificaciones WHERE texto LIKE '★★★★★%Raúl Recuerda%'")->fetchColumn());
$db->exec("UPDATE citas SET fin = '2026-11-16 10:30', calificacion_pedida_en = NULL WHERE id = $cR");
$cAt = $ag->crearCita($salon, $luis, $raul, '2026-11-16 15:00', [$corte]);
$ag->cambiarEstado($salon, $cAt, 'atendida');
check('Se pide calificar por Telegram una sola vez', [1, 0], [
    $auto->pedirCalificaciones($salon, new DateTimeImmutable('2026-11-16 18:00')),
    $auto->pedirCalificaciones($salon, new DateTimeImmutable('2026-11-16 19:00'))]);

// ------------------------------------------------------------------
echo "\n23. Reporte del mes\n";
$rep = $auto->reporteMes($salon, '2026-10');
check('Ventas de octubre', true, $rep['total'] > 0 && $rep['ventas'] > 0);
check('Lo más vendido va primero', (int) max(array_column($rep['servicios'], 'cantidad')), (int) ($rep['servicios'][0]['cantidad'] ?? 0));
check('El equipo viene ordenado por lo vendido', true, (float) $rep['equipo'][0]['vendido'] >= (float) end($rep['equipo'])['vendido']);
check('Calcula las horas más vacías', 3, count($rep['horas_muertas']));
$txt = Automaticas::textoReporte($rep, 'Barbería Don Pepe');
check('El texto dice el mes', true, str_contains($txt, 'Reporte de octubre 2026'));
$db->exec("UPDATE calificaciones SET creada_en = '2026-11-16 12:00'");   // en la prueba, calificadas en noviembre
$repN = $auto->reporteMes($salon, '2026-11');
check('Noviembre: cuenta las faltas', true, $repN['faltas'] >= 3);
check('Noviembre: la calificación promedio (5 y 3 = 4)', 4.0, $repN['estrellas']);
file_put_contents($logTg, '');
check('El día 1 antes de las 7:00 no se envía', false, $auto->enviarReporteMensual($salon, new DateTimeImmutable('2026-11-01 06:00')));
check('El día 1 desde las 7:00 se envía', true, $auto->enviarReporteMensual($salon, new DateTimeImmutable('2026-11-01 07:30')));
check('Solo una vez por mes', false, $auto->enviarReporteMensual($salon, new DateTimeImmutable('2026-11-01 08:30')));
check('Otro día del mes no se envía', false, $auto->enviarReporteMensual($salon, new DateTimeImmutable('2026-11-02 08:30')));
check('Le llega al Telegram del dueño', true, str_contains((string) file_get_contents($logTg), 'Reporte de octubre 2026'));
$todo = $auto->correr(new DateTimeImmutable('2026-11-20 10:00'));
check('La tarea de cada hora recorre los salones activos', true, $todo['salones'] >= 1);


// ------------------------------------------------------------------
echo "\n24. Panel Tukán: planes, pagos, vencimientos y salones\n";
$pl = new Planes($db);
check('Días de prueba por defecto: 7', '7', $pl->ajuste('dias_prueba'));
$pl->guardarAjustes(['dias_prueba' => '10', 'semestral_paga' => '5', 'semestral_recibe' => '6', 'anual_paga' => '9',
                     'anual_recibe' => '12', 'dias_gracia' => '3', 'whatsapp_ventas' => '+593 99 640 8397']);
check('El panel cambia la promoción anual a "paga 9"', 315.0, $pl->cotizar('completa', 'anual')['monto']);
check('…y el texto de la promoción', 'Paga 9, recibe 12 + dominio propio', $pl->textoPeriodo('anual'));
check('El WhatsApp de ventas queda solo con números', '593996408397', $pl->ajuste('whatsapp_ventas'));
$msg = '';
try { $pl->guardarAjustes(['dias_prueba' => '7', 'semestral_paga' => '7', 'semestral_recibe' => '6', 'anual_paga' => '10', 'anual_recibe' => '12', 'dias_gracia' => '3', 'whatsapp_ventas' => '593996408397']); }
catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No deja pagar más meses de los que recibe', true, $msg !== '');
$pl->guardarPlan('basica', 'Básica', 27.5, 3, 'Para empezar');
check('El panel cambia el precio de la Básica', 27.5, $pl->cotizar('basica', 'mensual')['monto']);
$pl->guardarPlan('basica', 'Básica', 25, 3);
$pl->guardarAjustes(['dias_prueba' => '7', 'semestral_paga' => '5', 'semestral_recibe' => '6', 'anual_paga' => '10',
                     'anual_recibe' => '12', 'dias_gracia' => '3', 'whatsapp_ventas' => '593996408397']);

$alta = (new Altas($db))->crearSalonConDueno('Barbería Panel', 'Mario Dueño', '0991231234', 'mario@panel.ec', 'claveMario1', 'completa');
$sp = (int) $alta['salon_id'];
check('El panel crea el salón con su dueño y 5 servicios', [true, 5],
    [$alta['usuario_id'] > 0, (int) $db->query("SELECT count(*) FROM servicios WHERE salon_id = $sp")->fetchColumn()]);
$msg = '';
try { (new Altas($db))->crearSalonConDueno('Otra', 'Xavier', '', 'mario@panel.ec', 'claveMario1', 'completa'); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('No repite el correo de un dueño', true, str_contains($msg, 'Ya existe'));
check('Dos salones con el mismo nombre tienen enlaces distintos', 'barberia-panel-2', (new Altas($db))->slugLibre('Barbería Panel'));

$fila = fn() => $db->query("SELECT estado, prueba_hasta, activo_hasta FROM salones WHERE id = $sp")->fetch();
$hoyP = new DateTimeImmutable('today');
check('Recién creado: en prueba', 'prueba', $pl->estadoCuenta($fila(), $hoyP));
check('Al día 8: prueba terminada (bloqueado)', [false, 'prueba_vencida'],
    [$pl->alDia($fila(), $hoyP->modify('+8 days')), $pl->estadoCuenta($fila(), $hoyP->modify('+8 days'))]);
$panel = new PanelTukan($db);
$panel->extenderPrueba($sp, 7);
check('El panel da 7 días más de prueba', $hoyP->modify('+14 days')->format('Y-m-d'), $fila()['prueba_hasta']);

$pago1 = $pl->suscribir($sp, 'completa', 'mensual', $hoyP, 'transferencia');
$finMes = $hoyP->modify('+1 month')->modify('-1 day')->format('Y-m-d');
check('Pago mensual: activo y pagado hasta dentro de un mes', ['activo', $finMes], [$fila()['estado'], $fila()['activo_hasta']]);
check('Inicio sugerido del próximo pago: el día siguiente', $hoyP->modify('+1 month')->format('Y-m-d'), $pl->inicioSugerido($sp)->format('Y-m-d'));
$pago2 = $pl->suscribir($sp, 'completa', 'semestral', $pl->inicioSugerido($sp), 'deuna', null, 150.0);
check('Renovar semestral con precio especial: suma 6 meses y guarda $150', [$hoyP->modify('+7 months')->modify('-1 day')->format('Y-m-d'), '150.00'],
    [$fila()['activo_hasta'], $db->query("SELECT monto FROM suscripciones WHERE id = $pago2")->fetchColumn()]);
$pl->anularPago($pago2);
check('Anular el pago devuelve la fecha anterior', $finMes, $fila()['activo_hasta']);
$diaVence = new DateTimeImmutable($finMes);
check('Al vencer: 3 días de gracia con aviso', ['por_vencer', true],
    [$pl->estadoCuenta($fila(), $diaVence->modify('+2 days')), $pl->alDia($fila(), $diaVence->modify('+2 days'))]);
check('Pasada la gracia: vencido y bloqueado', ['vencido', false],
    [$pl->estadoCuenta($fila(), $diaVence->modify('+4 days')), $pl->alDia($fila(), $diaVence->modify('+4 days'))]);
$panel->cambiarEstado($sp, 'suspendido');
check('Suspendido: bloqueado aunque haya pagado', false, $pl->alDia($fila(), $hoyP));
$panel->cambiarEstado($sp, 'activo');

$lista = array_column($panel->salones(), null, 'id');
check('La lista del panel trae dueño y estado', ['Mario Dueño', 'activo'], [$lista[$sp]['dueno'], $lista[$sp]['estado_cuenta']]);
check('Buscar por correo del dueño', [$sp], array_map('intval', array_column($panel->salones('', 'mario@panel'), 'id')));
check('Filtrar "en prueba" no trae a Mario', false, in_array($sp, array_map('intval', array_column($panel->salones('prueba'), 'id')), true));
$res = $panel->resumen($hoyP);
check('Resumen: cobrado este mes ($35 de Mario + $350 anual de Don Pepe; el anulado no cuenta)', 385.0, $res['cobrado_mes']);
check('Resumen: ingreso mensual de lo vigente', true, $res['mensual'] >= 35.0);
$nueva = $panel->nuevaClaveDueno($sp);
$hash = $db->query("SELECT password_hash FROM usuarios WHERE id = {$alta['usuario_id']}")->fetchColumn();
check('Nueva contraseña del dueño: funciona y la anterior no', [true, false],
    [password_verify($nueva['clave'], $hash), password_verify('claveMario1', $hash)]);

// ------------------------------------------------------------------
echo "\n" . str_repeat('=', 50) . "\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
exit($fallos ? 1 : 0);
