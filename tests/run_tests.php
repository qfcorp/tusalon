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

use TuSalon\{Db, Planes, Profesionales, Liquidacion, FacturasRecibidas, Agenda, Notificaciones, Telegram, CuentaCliente, Fotos};

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
echo "\n" . str_repeat('=', 50) . "\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
exit($fallos ? 1 : 0);
