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

use TuSalon\{Db, Planes, Profesionales, Liquidacion};

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
echo "\n" . str_repeat('=', 50) . "\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
exit($fallos ? 1 : 0);
