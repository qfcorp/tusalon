<?php
declare(strict_types=1);

/**
 * «Pagar mi plan con tarjeta» desde Mi plan (src/TukanPagoPlan.php, 10 nov 2026), con el Panel Tukán Central SIMULADO
 * (tests/panel_simulado.php): Panel caído, pago apagado, pago encendido, modo de prueba, valores de cada plan y período
 * (los que ya calcula el programa: plan + módulos extra, o el precio acordado), demo que paga, cliente con suscripción viva,
 * regreso aprobado y no completado, y que «registrar_pago» (lo que manda el Panel cuando Plux aprueba) deja la cuenta activa.
 * Borra y recrea la base de prueba. Uso: lo corre tests/run_logica.sh.
 */
const NS = 'TuSalon';          // espacio de nombres del programa
const TABLA = 'salones';        // tabla de los negocios
const PRE = 'TUSALON';         // prefijo de las variables del programa
define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, NS . '\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, strlen(NS) + 1) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});
require RAIZ . '/src/TukanCancelarPlan.php';
require RAIZ . '/src/TukanPagoPlan.php';

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.001 : $esperado === $real;
    if ($igual) { $ok++; echo "  OK    $nombre\n"; }
    else { $fallos[] = $nombre; echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}
function titulo(string $t): void { echo "\n$t\n"; }

if (!str_contains((string) getenv(PRE . '_DB_NAME'), 'prueba')) exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
$clase = fn(string $c) => NS . '\\' . $c;
$db = ($clase('Db'))::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));

// Panel simulado (y un puerto cerrado para «Panel caído»)
$libre = function (): int { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = stream_socket_get_name($s, false); fclose($s); return (int) substr($n, strrpos($n, ':') + 1); };
$puerto = (int) (getenv('PANEL_SIM_PUERTO') ?: $libre());
$simDir = sys_get_temp_dir() . '/panel_sim_' . strtolower(PRE) . '_' . getmypid();
@mkdir($simDir);
$SECRETO = str_repeat('ab12', 16);
$ID = strtolower(PRE) . '-prueba';
$proc = proc_open(['php', '-S', "127.0.0.1:$puerto", RAIZ . '/tests/panel_simulado.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null,
    ['PANEL_SIM_DIR' => $simDir, 'TUKAN_CENTRAL_ID' => $ID, 'TUKAN_CENTRAL_SECRET' => $SECRETO] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) usleep(100000);
$sim = "http://127.0.0.1:$puerto";
$conf = ['id' => $ID, 'secreto' => $SECRETO, 'url' => $sim];
TukanCancelarPlan::$config = $conf;
putenv('TUKAN_CANCELAR_FALSO');
$control = function (array $d) use ($sim): array {
    $ch = curl_init("$sim/_control");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($d), CURLOPT_RETURNTRANSFER => true]);
    return json_decode((string) curl_exec($ch), true) ?: [];
};
$pedidos = fn(): array => array_map(fn($l) => json_decode($l, true), is_file("$simDir/pedidos.jsonl") ? file("$simDir/pedidos.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
$estado = fn(int $sid) => TukanPagoPlan::estado((string) $sid, 'duena@prueba.ec', true);

$Planes = $clase('Planes'); $Modulos = $clase('Modulos'); $Altas = $clase('Altas'); $V2 = $clase('TukanCentralV2');
$planes = new $Planes($db);
$mods = new $Modulos($db);
$lista = $mods->planes();
$BAS = (string) array_key_first($lista);   // Básico (basico / basica)
$COM = (string) array_key_last($lista);    // Completo (completo / completa)
$alt = new $Altas($db);
$crear = current(array_filter(get_class_methods($alt), fn($m) => preg_match('/^crear\w+ConDueno$/', $m) === 1));
$nuevo = function (string $nombre, string $plan) use ($alt, $crear): int {
    $a = $alt->$crear($nombre, 'Dueña Prueba', '0990000000', 'pp' . bin2hex(random_bytes(4)) . '@prueba.ec', 'claveSegura1', $plan);
    return (int) reset($a);
};
$fila = function (int $sid) use ($db): array { return $db->query('SELECT * FROM ' . TABLA . " WHERE id = $sid")->fetch(PDO::FETCH_ASSOC); };
$opciones = function (int $sid) use ($planes, $mods, $fila, $Modulos): array {
    $s = $fila($sid);
    $mods->olvidar();
    return TukanPagoPlan::opciones($mods->planes(), $planes->periodos(), fn(string $pl, string $per) => $planes->cotizar($pl, $per, $sid),
        fn(string $per) => $planes->textoPeriodo($per), (string) $s['plan_codigo'], $s['precio_acordado'] !== null,
        count(array_diff($Modulos::ids(), $Modulos::FUERA_DE_PLANES)));
};
$html = fn(int $sid, string $vuelta = '') => TukanPagoPlan::html($estado($sid), $opciones($sid), '<input type="hidden" name="csrf" value="x">', '/?r=mi_plan', $Modulos::esDemo($fila($sid)), $vuelta);
$hoy = new DateTimeImmutable('today');

titulo('1. Los planes y sus valores (lo que ya calcula el programa)');
check('El Básico vale $12,99 al mes', 12.99, (float) $lista[$BAS]['precio_mensual']);
check('El Completo vale $25 al mes', 25.0, (float) $lista[$COM]['precio_mensual']);
$demo = $nuevo('Demo Que Paga', $COM);
check('La cuenta nueva es una demo', 'prueba', $fila($demo)['estado']);
$op = $opciones($demo);
check('Se ofrecen los dos planes', [$BAS, $COM], array_keys($op));
check('Básico mensual: $12,99', 12.99, $op[$BAS]['precios']['mensual']['monto']);
check('Básico 6 meses: paga 5 = $64,95', 64.95, $op[$BAS]['precios']['semestral']['monto']);
check('Básico 1 año: paga 10 = $129,90', 129.90, $op[$BAS]['precios']['anual']['monto']);
check('Completo mensual: $25', 25.0, $op[$COM]['precios']['mensual']['monto']);
check('Completo 6 meses: paga 5 = $125', 125.0, $op[$COM]['precios']['semestral']['monto']);
check('Completo 1 año: paga 10 = $250', 250.0, $op[$COM]['precios']['anual']['monto']);
check('6 meses: se reciben 6', 6, $op[$BAS]['precios']['semestral']['meses']);
check('1 año: se reciben 12', 12, $op[$COM]['precios']['anual']['meses']);
check('El Básico dice lo que incluye', 'La base + 3 módulos a tu elección', $op[$BAS]['incluye']);
check('El Completo dice lo que incluye', 'Todos los módulos', $op[$COM]['incluye']);

titulo('2. Panel caído, token malo o pago apagado: Mi plan queda como siempre');
TukanCancelarPlan::$config = ['url' => 'http://127.0.0.1:' . $libre()] + $conf;
check('Panel caído: no hay estado', null, $estado($demo));
check('Panel caído: no se muestra nada', '', $html($demo));
TukanCancelarPlan::$config = ['secreto' => str_repeat('ff00', 16)] + $conf;
check('Secreto equivocado (el Panel contesta 401): no se muestra nada', '', $html($demo));
TukanCancelarPlan::$config = ['id' => '', 'secreto' => '', 'url' => ''];
check('Programa sin conectar al Panel: no se muestra nada', '', $html($demo));
TukanCancelarPlan::$config = $conf;
$control(['activo' => false]);
check('Apagado: el Panel contesta activo = false', false, $estado($demo)['activo'] ?? null);
check('Apagado: no se muestra nada', '', $html($demo));
$msg = '';
try { TukanPagoPlan::crear(['cliente_id' => (string) $demo, 'nombre' => 'Demo Que Paga', 'plan' => $BAS, 'plan_nombre' => 'Básico', 'periodo' => 'mensual', 'meses' => 1, 'subtotal' => 12.99, 'suscripcion' => true, 'volver' => 'https://x.prueba.ec/?r=mi_plan']); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Apagado: si igual se pide el cobro, el Panel lo rechaza con su mensaje', true, str_contains($msg, 'no está disponible'));
putenv('TUKAN_CANCELAR_FALSO=' . $simDir . '/falso.txt');
check('Con el Panel de mentira de las otras pruebas (TUKAN_CANCELAR_FALSO): apagado', '', $html($demo));
putenv('TUKAN_CANCELAR_FALSO');

titulo('3. Pago encendido: la demo ve los planes y «Pagar con tarjeta»');
$control(['activo' => true, 'pruebas' => true]);
$h = $html($demo);
check('Sale la sección', true, str_contains($h, 'id="pago-tarjeta"'));
check('Botón «Pagar con tarjeta»', true, str_contains($h, '>Pagar con tarjeta</button>'));
check('Aviso de modo de prueba', true, str_contains($h, 'Modo de prueba:</strong> no se cobra de verdad'));
check('Básico mensual con «+ IVA»', true, (bool) preg_match('/data-valor="' . $BAS . '\|mensual">\$12,99<\/span> \+ IVA cada mes/', $h));
check('Completo anual', true, (bool) preg_match('/data-valor="' . $COM . '\|anual">\$250,00<\/span> \+ IVA cada año/', $h));
check('Dice que los valores son sin IVA', true, str_contains($h, 'sin IVA</strong>: al pagar se suma el IVA (15 %)'));
check('Por defecto suscripción, y lo dice claro', true, (bool) preg_match('/value="suscripcion" checked>.*Se cobra sola a tu tarjeta cada período/s', $h));
check('También deja pagar solo esta vez', true, str_contains($h, 'value="una_vez"'));
check('A la demo le dice que queda activa', true, str_contains($h, 'tu cuenta queda activa'));
$control(['pruebas' => false]);
check('En producción no sale el aviso de prueba', false, str_contains($html($demo), 'Modo de prueba'));
$control(['pruebas' => true]);

titulo('4. Regreso de la página de pago');
check('?plux=aprobado: pago recibido', true, str_contains($html($demo, 'aprobado'), 'data-pago="aprobado">¡Pago recibido!'));
check('?plux=rechazado: no se completó', true, str_contains($html($demo, 'rechazado'), 'data-pago="no-completado">El pago no se completó'));
check('Sin regreso: ningún aviso', false, str_contains($html($demo), 'data-pago="aprobado"') || str_contains($html($demo), 'no-completado'));

titulo('5. La demo elige Básico cada 6 meses y paga (suscripción)');
$o = $opciones($demo)[$BAS];
$cobro = TukanPagoPlan::crear(['cliente_id' => (string) $demo, 'nombre' => 'Demo Que Paga', 'plan' => $BAS, 'plan_nombre' => $o['nombre'], 'periodo' => 'semestral',
    'meses' => $o['precios']['semestral']['meses'], 'subtotal' => $o['precios']['semestral']['monto'], 'suscripcion' => true,
    'comprador' => ['nombre' => 'Dueña Prueba', 'correo' => 'duena@prueba.ec', 'telefono' => '0990000000'], 'ruc' => '1790011001001',
    'volver' => 'https://programa.prueba.ec/?r=mi_plan'], 'duena@prueba.ec');
check('El Panel devuelve la página de pago', true, str_starts_with((string) $cobro['url_pago'], "$sim/pagar?c="));
$p = array_values(array_filter($pedidos(), fn($x) => $x['accion'] === 'crear'));
$p = end($p);
check('Pedido: suscripción', 'suscripcion', $p['tipo']);
check('Pedido: el cliente', (string) $demo, $p['cliente_id']);
check('Pedido: el plan', $BAS, $p['plan']);
check('Pedido: el período', 'semestral', $p['periodo']);
check('Pedido: 6 meses', 6, $p['meses']);
check('Pedido: valor SIN IVA (5 meses)', 64.95, $p['subtotal']);
check('Pedido: adónde volver', 'https://programa.prueba.ec/?r=mi_plan', $p['volver']);
check('Pedido: datos para la factura (RUC)', '1790011001001', $p['fiscal']['identificacion'] ?? null);
check('Pedido: quién compra', 'duena@prueba.ec', $p['comprador']['correo']);
check('Pedido: firmado con el correo del dueño', 'duena@prueba.ec', $p['_email']);
check('Pedido: descripción', 'Plan ' . $o['nombre'] . ' · suscripción cada 6 meses · Demo Que Paga', $p['descripcion']);
check('Todavía no pagó: sigue en demo', 'prueba', $fila($demo)['estado']);

titulo('6. Suscripción esperando el pago: se ve su estado y NO se ofrece crear otra');
$h = $html($demo);
check('Se ve «Esperando el pago» con el total con IVA', true, str_contains($h, 'Esperando el pago · $74,69 con IVA'));
check('Trae «Pagar ahora»', true, str_contains($h, '>Pagar ahora</a>'));
check('No hay botón «Pagar con tarjeta»', false, str_contains($h, '>Pagar con tarjeta</button>'));
check('Puede «Elegir otro plan» (todavía no pagó)', true, str_contains($h, 'value="descartar_pago_tarjeta"'));
$msg = '';
try { TukanPagoPlan::crear(['cliente_id' => (string) $demo, 'nombre' => 'Demo Que Paga', 'plan' => $COM, 'plan_nombre' => 'Completo', 'periodo' => 'mensual', 'meses' => 1, 'subtotal' => 25, 'suscripcion' => true, 'volver' => 'https://programa.prueba.ec/?r=mi_plan']); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Si igual pide otra, el Panel contesta que ya tiene (409)', true, str_contains($msg, 'Ya tiene una suscripción con tarjeta'));
TukanPagoPlan::descartar($estado($demo), (string) $demo, (int) $cobro['id'], 'duena@prueba.ec');
check('«Elegir otro plan» quita la pendiente', null, TukanPagoPlan::suscripcion($estado($demo)));
check('… y vuelve el botón de pagar', true, str_contains($html($demo), '>Pagar con tarjeta</button>'));
$cobro = TukanPagoPlan::crear(['cliente_id' => (string) $demo, 'nombre' => 'Demo Que Paga', 'plan' => $BAS, 'plan_nombre' => $o['nombre'], 'periodo' => 'semestral', 'meses' => 6,
    'subtotal' => 64.95, 'suscripcion' => true, 'volver' => 'https://programa.prueba.ec/?r=mi_plan'], 'duena@prueba.ec');

titulo('7. Plux aprueba: el Panel manda «registrar_pago» y la demo queda como cliente activo');
putenv('TUKAN_CENTRAL_ID=' . $ID);
putenv('TUKAN_CENTRAL_SECRET=' . $SECRETO);
putenv('TUKAN_CENTRAL_URL=' . $sim);
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$registrar = function (int $sid, array $datos) use ($db, $V2, $b64, $ID, $SECRETO): array {   // igual que PagosPlux::registrar() del Panel
    $cuerpo = json_encode(['operacion_id' => bin2hex(random_bytes(16)), 'accion' => 'registrar_pago', 'cliente_id' => (string) $sid, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $t = time();
    $a = $b64(json_encode(['v' => 2, 'tipo' => 'ejecutar', 'inst' => $ID, 'email' => 'sistema@tukanec.com', 'nombre' => 'Tukán Pagos', 'rol' => 'dueno', 'iat' => $t, 'exp' => $t + 30,
                           'nonce' => bin2hex(random_bytes(16)), 'bh' => $b64(hash('sha256', $cuerpo, true))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->exec('DELETE FROM tukan_central_pedidos');
    return (new $V2($db))->procesar('ejecutar', 'POST', 'application/json; charset=utf-8', $cuerpo, $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true)), '198.51.100.20');
};
$r = $registrar($demo, ['plan' => $BAS, 'periodo' => 'semestral', 'metodo' => 'plux', 'monto' => 64.95, 'fecha' => $hoy->format('Y-m-d'), 'nota' => 'Plux (prueba)']);
check('El programa acepta el pago (200)', 200, $r[0]);
$f = $fila($demo);
check('La demo queda ACTIVA', 'activo', $f['estado']);
check('… con el plan que pagó', $BAS, $f['plan_codigo']);
check('… pagada 6 meses', $hoy->modify('+6 months')->modify('-1 day')->format('Y-m-d'), $f['activo_hasta']);
check('… y ya no es demo', false, $Modulos::esDemo($f));
check('El pago quedó anotado con Plux y su valor', ['plux', '64.95', 'semestral'], array_values($db->query("SELECT metodo_pago, monto, periodo FROM suscripciones ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)));
$control(['aprobar' => (int) $cobro['id'], 'pagado_hasta' => $f['activo_hasta']]);
$h = $html($demo, 'aprobado');
check('Mi plan: «Suscripción activa», se cobra sola', true, str_contains($h, 'Suscripción activa') && str_contains($h, 'se cobra sola a tu tarjeta'));
check('… pagada hasta su fecha', true, str_contains($h, 'pagada hasta el ' . date('d/m/Y', strtotime($f['activo_hasta']))));
check('… sin botón de pagar otra vez', false, str_contains($h, '>Pagar con tarjeta</button>'));
check('… ni «Elegir otro plan» (ya cobra: eso es «Cancelar mi plan»)', false, str_contains($h, 'descartar_pago_tarjeta'));
check('… y manda a «Cancelar mi plan» para dejarla', true, str_contains($h, 'Cancelar mi plan'));
$msg = '';
try { TukanPagoPlan::descartar($estado($demo), (string) $demo, (int) $cobro['id']); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Una suscripción que ya cobra no se quita con «Elegir otro plan»', 'Ese pago ya no está pendiente.', $msg);
$r = TukanCancelarPlan::pedir($db, (string) $demo, 'Demo Que Paga', $f['activo_hasta'], 'prueba', 'Dueña', 'duena@prueba.ec');
check('«Cancelar mi plan» sigue cancelando la suscripción en el Panel', [true, 1], [$r['avisado'], $r['canceladas']]);
check('… y vuelve a poder pagar', true, str_contains($html($demo), '>Pagar con tarjeta</button>'));

titulo('8. Cliente que ya paga: su valor con módulos extra, pago de una sola vez y cambio de plan');
$cli = $nuevo('Cliente Con Extras', $BAS);
$extras = array_slice(array_values(array_diff($Modulos::ids(), $Modulos::FUERA_DEL_CUPO)), 0, 4);   // 3 a elegir + 1 más ($5)
$db->prepare('UPDATE ' . TABLA . " SET estado = 'activo', activo_hasta = ?, modulos_extra = ? WHERE id = ?")->execute([$hoy->modify('+10 days')->format('Y-m-d'), implode(',', $Modulos::sinHuerfanos($Modulos::conDependencias($extras))), $cli]);
$det = $mods->detalle($fila($cli));
$op = $opciones($cli);
check('Su Básico cuesta lo que ya calcula el programa (plan + extras)', (float) $det['mensual'], $op[$BAS]['precios']['mensual']['monto']);
check('… que es más que el Básico solo', true, $det['mensual'] > 12.99);
check('… 6 meses = 5 veces su valor', round($det['mensual'] * 5, 2), $op[$BAS]['precios']['semestral']['monto']);
check('El Completo (ya trae esos módulos) le sale $25', 25.0, $op[$COM]['precios']['mensual']['monto']);
check('Su plan sale marcado', true, str_contains($html($cli), '(tu plan)'));
$c1 = TukanPagoPlan::crear(['cliente_id' => (string) $cli, 'nombre' => 'Cliente Con Extras', 'plan' => $COM, 'plan_nombre' => 'Completo', 'periodo' => 'anual', 'meses' => 12,
    'subtotal' => $op[$COM]['precios']['anual']['monto'], 'suscripcion' => false, 'volver' => 'https://programa.prueba.ec/?r=mi_plan'], 'duena@prueba.ec');
$p = array_values(array_filter($pedidos(), fn($x) => $x['accion'] === 'crear')); $p = end($p);
check('Pago de una sola vez: tipo «cobro»', 'cobro', $p['tipo']);
check('… sin RUC no manda datos de factura (consumidor final o lo decide Tukán)', [], $p['fiscal']);
check('… descripción con los meses', 'Plan Completo · 12 meses · Cliente Con Extras', $p['descripcion']);
check('Un pago de una vez pendiente no impide elegir', true, str_contains($html($cli), '>Pagar con tarjeta</button>'));
$r = $registrar($cli, ['plan' => $COM, 'periodo' => 'anual', 'metodo' => 'plux', 'monto' => 250.0, 'fecha' => $hoy->format('Y-m-d'), 'nota' => 'Plux (prueba)']);
$f = $fila($cli);
check('Aprobado: pasa al Completo', [200, $COM, 'activo'], [$r[0], $f['plan_codigo'], $f['estado']]);
check('… y el año se suma después de lo que ya tenía pagado', $hoy->modify('+11 days')->modify('+12 months')->modify('-1 day')->format('Y-m-d'), $f['activo_hasta']);

titulo('9. Precio acordado con Tukán: solo se ofrece su plan, a ese precio');
$aco = $nuevo('Cliente Acordado', $COM);
$db->exec('UPDATE ' . TABLA . " SET estado = 'activo', activo_hasta = CURRENT_DATE + 5, precio_acordado = 18.50 WHERE id = $aco");
$op = $opciones($aco);
check('Solo su plan', [$COM], array_keys($op));
check('Al precio acordado', 18.5, $op[$COM]['precios']['mensual']['monto']);
check('… 1 año = 10 veces', 185.0, $op[$COM]['precios']['anual']['monto']);
$db->exec('UPDATE ' . TABLA . " SET precio_acordado = 0 WHERE id = $aco");
check('Plan de obsequio ($0): no hay nada que pagar con tarjeta', [], $opciones($aco));
check('… y no sale el formulario', false, str_contains($html($aco), 'Pagar con tarjeta</button>'));

titulo('10. Cada cliente ve solo lo suyo');
check('El cliente nuevo no ve los cobros de otro', [], $estado($aco)['cobros']);
check('La demo que pagó sí ve los suyos', true, count($estado($demo)['cobros']) >= 1);

titulo('11. La suscripción se ajusta sola cuando cambian los módulos dentro del programa (y no puede bajar)');
$sus = $nuevo('Cliente Suscrito', $BAS);
$ext3 = array_slice(array_values(array_filter(array_keys($Modulos::CATALOGO), fn($m) => !in_array($m, $Modulos::FUERA_DEL_CUPO, true) && empty($Modulos::CATALOGO[$m][6]))), 0, 5);
$hasta = $hoy->modify('+15 days')->format('Y-m-d');
$db->prepare('UPDATE ' . TABLA . " SET estado = 'activo', activo_hasta = ?, modulos_extra = ? WHERE id = ?")->execute([$hasta, implode(',', array_slice($ext3, 0, 2)), $sus]);
$valor = function (string $per) use ($planes, $mods, $fila, $sus): float { $mods->olvidar(); return (float) $planes->cotizar((string) $fila($sus)['plan_codigo'], $per, $sus)['monto']; };
$cambiar = fn(array $on, array $off) => function () use ($mods, $sus, $on, $off) { $mods->olvidar(); return $mods->cambiar($sus, $on, $off, false, 'prueba', 'Súper <super@prueba.ec>', 'panel'); };
$quita = fn($x) => (bool) $x['apagados'];
$cuidar = fn(callable $c, bool $plux = false, callable $q = null, string $que = 'modulos') => TukanPagoPlan::cambiarCuidando($db, (string) $sus, 'super@prueba.ec', $c, $valor, $q ?? $quita, $plux, $que);
$extraDe = fn() => $Modulos::limpiar($fila($sus)['modulos_extra']);
// Sin suscripción: todo como siempre
$x = $cuidar($cambiar([$ext3[2]], []));
check('Sin suscripción: el cambio se hace y no se manda nada al Panel', [3, null, 0], [count($extraDe()), $x['aviso'], count(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar'))]);
$x = $cuidar($cambiar([], [$ext3[2]]));
check('Sin suscripción: se puede quitar un módulo', [2, null], [count($extraDe()), $x['aviso']]);
// Con suscripción viva y pagada (mensual, Básico con 2 de sus 3 módulos: $12,99)
$c = TukanPagoPlan::crear(['cliente_id' => (string) $sus, 'nombre' => 'Cliente Suscrito', 'plan' => $BAS, 'plan_nombre' => 'Básico', 'periodo' => 'mensual', 'meses' => 1, 'subtotal' => $valor('mensual'), 'suscripcion' => true, 'volver' => 'https://programa.prueba.ec/?r=mi_plan'], 'duena@prueba.ec');
$x = TukanPagoPlan::ajustar((string) $sus, 30.0);
check('Primera cuota sin pagar: el Panel contesta «sin_pagar»', 'sin_pagar', $x['resultado']);
$control(['aprobar' => (int) $c['id'], 'pagado_hasta' => $hasta]);
check('Tiene suscripción viva y pagada', (int) $c['id'], (int) (TukanPagoPlan::pagada($estado($sus))['id'] ?? 0));
check('Una suscripción con el período ya vencido no cuenta como pagada', null, TukanPagoPlan::pagada($estado($sus), $hoy->modify('+16 days')->format('Y-m-d')));
$n0 = count(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar'));
$x = $cuidar($cambiar([$ext3[2]], []));
check('Elegir el 3.er módulo incluido: no mueve el valor, no se manda «cambiar»', [3, null, $n0], [count($extraDe()), $x['aviso'], count(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar'))]);
$x = $cuidar($cambiar([$ext3[3], $ext3[4]], []));   // dos módulos más: +$10
$ped = array_values(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar')); $ped = end($ped);
check('Sube (2 módulos más): se manda «cambiar» con el valor nuevo sin IVA', [(string) $sus, 22.99], [$ped['cliente_id'], $ped['subtotal']]);
check('… resultado «subio»', 'subio', $x['ajuste']['resultado']);
check('… el aviso dice el valor nuevo desde el próximo cobro', true, str_contains($x['aviso']['texto'], 'queda en $22,99 + IVA ($26,44 con IVA) desde el próximo cobro'));
$frac = (strtotime($hasta) - strtotime($hoy->format('Y-m-d'))) / (strtotime($hasta) - strtotime($hasta . ' -1 months'));
$prop = round(round(10 * $frac, 2) * 1.15, 2);
check('… y lo proporcional de los días que faltan, cobrado aparte, con su enlace', true, str_contains($x['aviso']['texto'], 'se cobra aparte ' . TukanPagoPlan::dinero($prop) . ' con IVA: ' . $sim . '/pagar?c='));
check('… el proporcional es menos que el mes completo', true, $prop > 1 && $prop < 11.5);
$h = $html($sus);
check('El dueño ve en Mi plan el cobro «Proporcional módulos» con «Pagar ahora»', true, str_contains($h, 'Proporcional módulos') && str_contains($h, '>Pagar ahora</a>'));
check('… y su suscripción con el valor nuevo', true, str_contains($h, 'Suscripción activa · $26,44 con IVA'));
$msg = '';
try { $cuidar($cambiar([], [$ext3[4]])); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Baja (quitar un módulo contratado): no se permite, con la fecha', 'Los módulos ya contratados no se pueden quitar mientras dure el período pagado (hasta el ' . date('d/m/Y', strtotime($hasta)) . ').', $msg);
$mods->olvidar();
check('… y el módulo NO se quitó', 5, count($extraDe()));
$x = TukanPagoPlan::ajustar((string) $sus, 12.99);
check('Si igual se pide un valor menor, el Panel contesta 409 «no_baja»', ['no_baja', true], [$x['resultado'], str_contains($x['texto'], 'no se pueden quitar mientras dure el período pagado')]);
check('… y la suscripción sigue en su valor', 22.99, (float) TukanPagoPlan::pagada($estado($sus))['subtotal']);
check('Mismo valor: el Panel contesta «igual» (sin cambio)', 'igual', TukanPagoPlan::ajustar((string) $sus, 22.99)['resultado']);
$msg = '';
try { $cuidar(function () use ($mods, $sus) { $mods->olvidar(); return $mods->cambiar($sus, [], [], 15.0, 'prueba', 'Súper', 'panel'); }); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Un precio acordado menor tampoco se acepta durante el período', true, str_contains($msg, 'mientras dure el período pagado'));
$mods->olvidar();
check('… y no quedó guardado', null, $fila($sus)['precio_acordado']);
$panelP = new ($clase('PanelTukan'))($db);
$x = $cuidar(fn() => $panelP->cambiarPlan($sus, $COM), false, fn() => false, 'plan');
check('Subir de plan (a Completo, $25): se hace y se manda al Panel', [$COM, 'subio', 25.0], [$fila($sus)['plan_codigo'], $x['ajuste']['resultado'], (float) TukanPagoPlan::pagada($estado($sus))['subtotal']]);
$msg = '';
try { $cuidar(fn() => $panelP->cambiarPlan($sus, $BAS), false, fn() => false, 'plan'); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Bajar de plan durante el período: no se permite', 'No se puede bajar de plan mientras dure el período pagado (hasta el ' . date('d/m/Y', strtotime($hasta)) . ').', $msg);
check('… y sigue en Completo', $COM, $fila($sus)['plan_codigo']);
// Panel caído
$db->prepare('UPDATE ' . TABLA . ' SET plan_codigo = ?, modulos_extra = ? WHERE id = ?')->execute([$BAS, implode(',', array_slice($ext3, 0, 3)), $sus]);
TukanCancelarPlan::$config = ['url' => 'http://127.0.0.1:' . $libre()] + $conf;
$x = $cuidar($cambiar([$ext3[3]], []), true);
check('Panel caído: el cambio de módulos se hace igual', 4, count($extraDe()));
check('… y avisa que la suscripción se ajustará cuando el Panel responda (su último pago fue con tarjeta)', true, str_contains((string) ($x['aviso']['texto'] ?? ''), 'se ajustará cuando el Panel responda'));
$x = $cuidar($cambiar([$ext3[4]], []), false);
check('Panel caído y cliente que no paga con tarjeta: sin aviso', null, $x['aviso']);
check('«ajustar» con el Panel caído: «sin_panel»', 'sin_panel', TukanPagoPlan::ajustar((string) $sus, 40.0)['resultado']);
TukanCancelarPlan::$config = $conf;
check('Cliente sin suscripción: «cambiar» no hace nada (el Panel contesta que no existe)', 'igual', TukanPagoPlan::ajustar((string) $aco, 40.0)['resultado']);

titulo('12. «Te sugerimos el Completo» y pasarse al Completo a mitad del período');
$mods->olvidar();
$pl = $mods->planes();
$tot = count(array_diff($Modulos::ids(), $Modulos::FUERA_DE_PLANES));
check('El plan Completo es el que trae todos los módulos', $COM, TukanPagoPlan::completo($pl, $tot));
$sug = fn(float $paga, string $plan = null, bool $acordado = false) => TukanPagoPlan::sugerirCompleto($pl, $tot, $plan ?? $BAS, $paga, 25.0, $acordado);
check('Paga más que el Completo ($27,99): aviso fuerte', ['mayor', 27.99, 25.0], array_slice(array_values($sug(27.99)), 0, 3));
check('Paga igual que el Completo ($25): aviso fuerte', 'mayor', $sug(25.0)['nivel']);
check('Cerca ($22,99, a $2,01): aviso suave con lo que falta', ['cerca', 2.01], [$sug(22.99)['nivel'], $sug(22.99)['falta']]);
check('A $5 justos ($20): aviso suave', 'cerca', $sug(20.0)['nivel']);
check('Lejos ($17,99, a más de $5): sin aviso', null, $sug(17.99));
check('Básico solo ($12,99): sin aviso', null, $sug(12.99));
check('Quien ya tiene el Completo: sin aviso', null, $sug(25.0, $COM));
check('Con precio acordado con Tukán: sin aviso', null, $sug(30.0, $BAS, true));
$h = TukanPagoPlan::htmlSugerencia($sug(27.99), '<input type="hidden" name="csrf" value="x">', '/?r=mi_plan');
check('Texto del aviso fuerte', true, str_contains($h, 'Con tus módulos pagas <strong>$27,99</strong> al mes. El plan ' . $pl[$COM]['nombre'] . ' cuesta <strong>$25,00</strong> y trae <strong>TODOS los módulos y el Asistente IA</strong>. Te sugerimos pasarte al ' . $pl[$COM]['nombre'] . '.'));
check('… con el botón «Pasarme al Completo»', true, str_contains($h, 'value="pasar_completo"') && str_contains($h, '>Pasarme al ' . $pl[$COM]['nombre'] . '</button>'));
check('… en color de advertencia', true, str_contains($h, 'data-sugerencia="mayor"') && str_contains($h, 'background:#fff8e1'));
$h = TukanPagoPlan::htmlSugerencia($sug(22.99), '<input type="hidden" name="csrf" value="x">', '/?r=mi_plan');
check('Texto del aviso suave: «Por $2,01 más tienes el Completo con todo»', true, str_contains($h, 'Por <strong>$2,01</strong> más tienes el ' . $pl[$COM]['nombre'] . ' con todo') && str_contains($h, 'data-sugerencia="cerca"'));
check('Antes de pedir un módulo: «Si agregas X pagarías…»', true, str_contains(TukanPagoPlan::htmlSugerencia($sug(27.99), 'x', '/', 'Inventario'), 'Si agregas «Inventario» pagarías <strong>$27,99</strong> al mes'));
check('Para Tukán (ficha del negocio): sin botón y de «usted»', [true, false], [str_contains($h2 = TukanPagoPlan::htmlSugerencia($sug(27.99), '', '', '', '', true), 'Con sus módulos paga <strong>$27,99</strong>'), str_contains($h2, 'pasar_completo')]);
check('Sin sugerencia no sale nada', '', TukanPagoPlan::htmlSugerencia(null, 'x', '/'));
// Con datos de verdad: Básico + 3 incluidos + 3 más ($15) = $27,99
$cs = $nuevo('Cliente Casi Completo', $BAS);
$seis = array_slice(array_values(array_filter(array_keys($Modulos::CATALOGO), fn($m) => !in_array($m, $Modulos::FUERA_DEL_CUPO, true) && empty($Modulos::CATALOGO[$m][6]))), 0, 6);
$hastaC = $hoy->modify('+20 days')->format('Y-m-d');
$db->prepare('UPDATE ' . TABLA . " SET estado = 'activo', activo_hasta = ?, modulos_extra = ? WHERE id = ?")->execute([$hastaC, implode(',', $seis), $cs]);
$mods->olvidar();
$dC = $mods->detalle($fila($cs));
check('Básico con 6 módulos paga $27,99: más que el Completo', 27.99, (float) $dC['mensual']);
$sC = TukanPagoPlan::sugerirCompleto($mods->planes(), $tot, $BAS, (float) $dC['mensual'], (float) $mods->detalle($fila($cs), $COM)['mensual']);
check('… se le sugiere el Completo a $25', ['mayor', 25.0], [$sC['nivel'], $sC['completo']]);
check('La sección de pagar puede traer el aviso y el Completo ya marcado', [true, true], [str_contains($hp = TukanPagoPlan::html($estado($cs), $opciones($cs), 'x', '/', false, '', TukanPagoPlan::htmlSugerencia($sC), $COM), 'data-sugerencia="mayor"'),
    (bool) preg_match('/value="' . $COM . '\|mensual" required checked/', $hp)]);
// Pasarse al Completo con suscripción pagada: se acepta aunque baje, desde el próximo cobro
$cc = TukanPagoPlan::crear(['cliente_id' => (string) $cs, 'nombre' => 'Cliente Casi Completo', 'plan' => $BAS, 'plan_nombre' => 'Básico', 'periodo' => 'mensual', 'meses' => 1, 'subtotal' => 27.99, 'suscripcion' => true, 'volver' => 'https://programa.prueba.ec/?r=mi_plan'], 'duena@prueba.ec');
$control(['aprobar' => (int) $cc['id'], 'pagado_hasta' => $hastaC]);
check('Sin decir que pasa al Completo, bajar a $25 se rechaza (409 no_baja)', 'no_baja', TukanPagoPlan::ajustar((string) $cs, 25.0)['resultado']);
$a = TukanPagoPlan::ajustar((string) $cs, 25.0, 'duena@prueba.ec', true);
$ped = array_values(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar')); $ped = end($ped);
check('Pasarse al Completo: el pedido lleva pasa_a_completo y el valor del Completo', [true, 25.0], [$ped['pasa_a_completo'] ?? null, (float) $ped['subtotal']]);
check('… el Panel lo acepta: «baja_al_renovar»', 'baja_al_renovar', $a['resultado']);
check('… y dice desde cuándo y que lo pagado no se devuelve', true, str_contains($a['texto'], 'Desde el próximo cobro (' . date('d/m/Y', strtotime($hastaC)) . ') la suscripción con tarjeta queda en $25,00 + IVA ($28,75 con IVA). Lo ya pagado no se devuelve.'));
check('… la suscripción queda en $25', 25.0, (float) TukanPagoPlan::pagada($estado($cs))['subtotal']);
// Lo mismo desde la página del súper (cambiarCuidando con $pasaACompleto)
$control(['estado' => (int) $cc['id'], 'a' => 'cancelada']);
$cc = TukanPagoPlan::crear(['cliente_id' => (string) $cs, 'nombre' => 'Cliente Casi Completo', 'plan' => $BAS, 'plan_nombre' => 'Básico', 'periodo' => 'mensual', 'meses' => 1, 'subtotal' => 27.99, 'suscripcion' => true, 'volver' => 'https://programa.prueba.ec/?r=mi_plan'], 'duena@prueba.ec');
$control(['aprobar' => (int) $cc['id'], 'pagado_hasta' => $hastaC]);
$valorC = function (string $per) use ($planes, $mods, $fila, $cs): float { $mods->olvidar(); return (float) $planes->cotizar((string) $fila($cs)['plan_codigo'], $per, $cs)['monto']; };
$x = TukanPagoPlan::cambiarCuidando($db, (string) $cs, 'super@prueba.ec', fn() => $panelP->cambiarPlan($cs, $COM), $valorC, fn() => false, true, 'plan', true);
check('El súper lo pasa al Completo con suscripción pagada: se hace', [$COM, 'baja_al_renovar'], [$fila($cs)['plan_codigo'], $x['ajuste']['resultado']]);
check('… con el aviso «desde el próximo cobro»', true, str_contains($x['aviso']['texto'], 'Desde el próximo cobro'));
$msg = '';
try { TukanPagoPlan::cambiarCuidando($db, (string) $cs, 'super@prueba.ec', fn() => $panelP->cambiarPlan($cs, $BAS), $valorC, fn() => true /* el plan nuevo es más barato */, true, 'plan', false); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
$mods->olvidar();
check('Bajar del Completo al Básico sigue bloqueado durante el período (aunque con sus módulos el Básico le saldría más caro)', [true, $COM], [str_contains($msg, 'No se puede bajar de plan mientras dure el período pagado'), $fila($cs)['plan_codigo']]);
// Sin suscripción: el cambio de plan se hace como siempre
$ss = $nuevo('Cliente Sin Tarjeta', $BAS);
$db->exec('UPDATE ' . TABLA . " SET estado = 'activo', activo_hasta = CURRENT_DATE + 9 WHERE id = $ss");
$nC = count(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar'));
$x = TukanPagoPlan::cambiarCuidando($db, (string) $ss, 'super@prueba.ec', fn() => $panelP->cambiarPlan($ss, $COM), fn(string $per) => (float) $planes->cotizar((string) $fila($ss)['plan_codigo'], $per, $ss)['monto'], fn() => false, false, 'plan', true);
check('Sin suscripción con tarjeta: pasa al Completo como siempre, sin avisar al Panel', [$COM, null, $nC], [$fila($ss)['plan_codigo'], $x['aviso'], count(array_filter($pedidos(), fn($p) => $p['accion'] === 'cambiar'))]);

proc_terminate($proc);
array_map('unlink', glob("$simDir/*") ?: []);
@rmdir($simDir);
echo "\n" . $ok . ' bien, ' . count($fallos) . " mal\n";
exit($fallos ? 1 : 0);
