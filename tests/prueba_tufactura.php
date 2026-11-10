<?php
declare(strict_types=1);

/**
 * Factura electrónica con TuFactura (módulo «facturacion», 9 nov 2026), con el TuFactura SIMULADO (tests/tufactura_simulado.php):
 * el módulo viene apagado (ni el plan Completo ni la demo lo traen), se enciende, se conecta la clave, cada cobro se factura
 * (a mano o solo al cobrar), nunca dos veces, con los datos del cliente; apagado no factura. Borra y recrea la base de prueba.
 * Uso: lo corre tests/run_logica.sh.
 */
define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, strlen('TuSalon\\')) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\{Altas, Db, FacturaTukan, Liquidacion, Modulos};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.001 : $esperado === $real;
    if ($igual) { $ok++; echo "  OK    $nombre\n"; }
    else { $fallos[] = $nombre; echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}

if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));

// TuFactura simulado
$puerto = (int) (getenv('TF_SIM_PUERTO') ?: 8296);
$simDir = sys_get_temp_dir() . '/tf_sim_tusalon_' . getmypid();
@mkdir($simDir);
$proc = proc_open(['php', '-S', "127.0.0.1:$puerto", RAIZ . '/tests/tufactura_simulado.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, ['TF_SIM_DIR' => $simDir] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) usleep(100000);
$sim = "http://127.0.0.1:$puerto";

echo "== Factura electrónica con TuFactura (módulo facturacion)\n";
$mods = new Modulos($db);
$planes = $mods->planes();
$completo = (string) array_key_last($planes);   // el plan más caro (Completo / Completa)
$alt = new Altas($db);
$crear = current(array_filter(get_class_methods($alt), fn($m) => preg_match('/^crear\w+ConDueno$/', $m) === 1));
$a = $alt->$crear('Negocio Factura', 'Dueña Prueba', '0990000000', 'tf' . bin2hex(random_bytes(3)) . '@prueba.ec', 'claveSegura1', $completo);
$sid = (int) reset($a);
$cat = $mods->catalogo();
check('El módulo existe en el catálogo', true, Modulos::existe('facturacion'));
check('No entra en ningún plan (ni en Completo)', true, in_array('facturacion', Modulos::FUERA_DE_PLANES, true));
check('Precio del módulo en el programa: $0 (TuFactura se paga aparte)', 0.0, (float) ($cat['facturacion']['precio_mensual'] ?? -1));
check('Viene APAGADO (demo con todo incluida)', false, FacturaTukan::activo($sid));
check('El Completo pagado tampoco lo trae', false, in_array('facturacion', $planes[$completo]['modulos'] ?? [], true));

// Servicio, profesional y cliente para cobrar
$db->prepare('INSERT INTO servicios (salon_id, nombre, precio) VALUES (?, ?, ?)')->execute([$sid, 'Servicio completo', 23]);
$servicio = (int) $db->lastInsertId();
$prof = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = $sid ORDER BY id LIMIT 1")->fetchColumn();
$db->prepare('INSERT INTO clientes (salon_id, nombre, telefono, email, cedula) VALUES (?, ?, ?, ?, ?)')->execute([$sid, 'María Pérez', '0991234567', 'maria@ejemplo.com', '1710034065']);
$cliente = (int) $db->lastInsertId();
$liq = new Liquidacion($db);
$cobrar = fn(?int $cli = null, float $precio = 23, string $metodo = 'efectivo') => $liq->registrarVenta(['salon_id' => $sid, 'fecha' => date('Y-m-d H:i:s'), 'cobrado_por' => 'local',
    'metodo_pago' => $metodo, 'propina' => 2, 'cliente_id' => $cli], [['tipo' => 'servicio', 'servicio_id' => $servicio, 'profesional_id' => $prof, 'precio_unitario' => $precio]]);

$v0 = $cobrar($cliente);
$tf = FacturaTukan::enlace($db);
check('Con el módulo apagado el cobro no se factura', null, $tf->deOrigen($sid, 'venta', (string) $v0));

$mods->cambiar($sid, ['facturacion'], [], false, 'Compró TuFactura', 'prueba');
check('Encendido desde el Panel', true, FacturaTukan::activo($sid));

// Datos de un cobro
[$cli, $items, $extra] = FacturaTukan::datos($sid, (string) $v0);
check('Datos: cliente con su cédula', '1710034065', $cli['identificacion']);
check('Datos: correo del cliente', 'maria@ejemplo.com', $cli['correo']);
check('Datos: el servicio con su precio (sin la propina)', 23.0, $items[0]['precio'] * $items[0]['cantidad']);
check('Datos: nombre del servicio', 'Servicio completo', $items[0]['descripcion']);
check('Datos: forma de pago', 'efectivo', $extra['forma']);
check('Datos de otro negocio: no', null, FacturaTukan::datos($sid + 999, (string) $v0));
check('Cobros recientes: aparece el cobro', (string) $v0, FacturaTukan::cobros($sid)[0][0] ?? null);

// Conectar y facturar a mano
$con = $tf->conectar($sid, 'tf_' . str_repeat('a1', 20), $sim);
check('Conectado con TuFactura (simulado)', 'EMPRESA DE PRUEBA S.A.', $con['empresa']);
$d = $tf->facturar($sid, 'venta', (string) $v0, ...FacturaTukan::datos($sid, (string) $v0));
check('Factura a mano: autorizada', 'autorizado', $d['estado']);
check('Factura a mano: total igual al cobro (23,00)', 23.0, (float) $d['total']);
$env = json_decode((string) $d['envio'], true);
check('Factura a nombre del cliente', '1710034065', $env['cliente']['identificacion']);
check('Forma de pago del SRI: efectivo (01)', '01', $env['forma_pago']);
$d2 = $tf->facturar($sid, 'venta', (string) $v0, ...FacturaTukan::datos($sid, (string) $v0));
check('El mismo cobro no se factura dos veces', (int) $d['id'], (int) $d2['id']);

// Automático al cobrar
$v1 = $cobrar(null, 11.5, 'transferencia');
check('Sin «automático» no factura solo', null, $tf->deOrigen($sid, 'venta', (string) $v1));
$tf->opciones($sid, true, true, 15);
$v2 = $cobrar(null, 11.5, 'tarjeta');
$d3 = $tf->deOrigen($sid, 'venta', (string) $v2);
check('Automático: el cobro sale facturado solo', 'autorizado', $d3['estado'] ?? null);
check('Automático: sin cliente va a consumidor final', '9999999999999', json_decode((string) ($d3['envio'] ?? '{}'), true)['cliente']['identificacion'] ?? null);
check('Automático: tarjeta = forma 19', '19', json_decode((string) ($d3['envio'] ?? '{}'), true)['forma_pago'] ?? null);

// Dentro de una transacción que se deshace: no se factura nada
$db->beginTransaction();
$v3 = $cobrar(null, 5);
$db->rollBack();
check('Cobro deshecho: no se factura', null, $tf->deOrigen($sid, 'venta', (string) $v3));

// Apagar el módulo: deja de facturar solo (las facturas hechas se quedan)
$mods->cambiar($sid, [], ['facturacion'], false, 'Dejó TuFactura', 'prueba');
$v4 = $cobrar(null, 8);
check('Apagado: ya no factura solo', null, $tf->deOrigen($sid, 'venta', (string) $v4));
check('Apagado: las facturas hechas se quedan', 2, count($tf->lista($sid)));

proc_terminate($proc);
array_map('unlink', glob("$simDir/*") ?: []);
@rmdir($simDir);
echo "\n" . $ok . ' bien, ' . count($fallos) . " mal\n";
exit($fallos ? 1 : 0);
