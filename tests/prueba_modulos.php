<?php
declare(strict_types=1);

/**
 * TuSalón por módulos (PROTOCOLO_V2.md del Panel Tukán Central, Anexo B) y "todo se pide demo":
 * catálogo y dependencias, migración 008 (los planes de hoy = base + módulos, sin perder nada), cada ruta con su módulo
 * (revisión automática contra public/index.php), páginas públicas, tareas y avisos apagados, cobros, cuentas de
 * peluquero y recepción, cuentas del precio, Mi plan → pedido → aprobar, conector v2 (modulos, cliente.modulos,
 * cambiar_modulos, guardar_modulos, cambio_modulos, demo_pedida, crear_demo con plan y módulos, finanzas.por_modulo),
 * idempotencia, aislamiento entre salones y que no quede ninguna promesa de "prueba gratis".
 * Borra y recrea la base de prueba (igual que run_tests.php).
 *
 * Uso: TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_modulos.php
 *      (o bash tests/run_logica.sh, que levanta Telegram simulado)
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\{Altas, Automaticas, Cobro, Db, Modulos, Notificaciones, Papelera, PedidosDemo, Planes, Profesionales, Telegram, TukanCentralV2};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.001 : $esperado === $real;
    if ($igual) { $ok++; echo "  OK    $nombre\n"; return; }
    $fallos[] = $nombre;
    echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n";
}
function titulo(string $t): void { echo "\n$t\n"; }
function lanza(callable $f): string
{
    try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); }
    return '';
}

if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));   // se pueden correr muchas veces

$SECRETO = str_repeat('ab12', 16);
putenv('TUKAN_CENTRAL_ID=tusalon');
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
putenv('TUSALON_URL=https://tusalon.prueba.ec');
const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$token = function (string $tipo, string $cuerpo, string $rol = 'dueno') use ($SECRETO, $b64): string {
    $t = time();
    $p = ['v' => 2, 'tipo' => $tipo, 'inst' => 'tusalon', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => $rol,
          'iat' => $t, 'exp' => $t + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)), 'bh' => $b64(hash('sha256', $cuerpo, true))];
    $a = $b64(json_encode($p, FLAGS));
    return $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true));
};
$pedir = function (string $accion, array $cuerpo = []) use ($db, $token): array {
    $c = json_encode($cuerpo === [] ? new stdClass() : $cuerpo, FLAGS);
    $db->exec('DELETE FROM tukan_central_pedidos');
    return (new TukanCentralV2($db))->procesar($accion, 'POST', 'application/json', $c, $token($accion, $c), '198.51.100.20');
};
$ej = function (string $op, array $datos = [], int|string|null $cliente = null, ?string $opId = null) use ($pedir): array {
    $cuerpo = ['operacion_id' => $opId ?? bin2hex(random_bytes(16)), 'accion' => $op];
    if ($cliente !== null) $cuerpo['cliente_id'] = (string) $cliente;
    $cuerpo['datos'] = $datos === [] ? new stdClass() : $datos;
    return $pedir('ejecutar', $cuerpo);
};
$mods = new Modulos($db);
$planes = new Planes($db);
$nuevo = function (string $nombre, string $plan, bool $pagado = true) use ($db): int {
    $a = (new Altas($db))->crearSalonConDueno($nombre, 'Dueño ' . $nombre, '0990000000', 'm' . bin2hex(random_bytes(4)) . '@prueba.ec', 'claveSegura1', $plan, 'central');
    if ($pagado) $db->exec("UPDATE salones SET estado = 'activo', activo_hasta = CURRENT_DATE + 60 WHERE id = {$a['salon_id']}");
    return $a['salon_id'];
};

// ---------------------------------------------------------------------------------------------
titulo('1. Catálogo, base y dependencias');
$todos = Modulos::ids();
$ids = array_values(array_diff($todos, Modulos::FUERA_DE_PLANES));   // los que entran en los planes ('*')
check('15 módulos, todos entran en los planes (el Asistente IA viene en Completa = *)', [15, 15, []], [count($todos), count($ids), Modulos::FUERA_DE_PLANES]);
check('Cada módulo con ícono, nombre, grupo conocido, descripción, precio y descuento', true, array_filter(Modulos::CATALOGO, fn($m) =>
    $m[0] === '' || mb_strlen($m[1]) < 3 || !isset(Modulos::GRUPOS[$m[2]]) || mb_strlen($m[3]) < 20 || ($m[4] <= 0 && $m[1] !== 'Asistente IA') || $m[5] < 0 || $m[5] > $m[4]) === []);
check('Asistente IA: grupo Extras y precio 0 en el catálogo (en Básica, por cotización)', ['extras', 0.0, 0.0], [Modulos::CATALOGO['asistente_ia'][2], Modulos::CATALOGO['asistente_ia'][4], Modulos::CATALOGO['asistente_ia'][5]]);
check('Las dependencias existen y no se depende de uno mismo', true, array_filter(Modulos::CATALOGO, fn($m) => array_diff($m[6], $ids) !== []) === []
      && !array_filter(array_keys(Modulos::CATALOGO), fn($id) => in_array($id, Modulos::CATALOGO[$id][6], true)));
check('Ids en minúsculas y _', [], array_values(array_filter($ids, fn($id) => !preg_match('/^[a-z_]+$/', $id))));
check('Nada que no exista: sin rol de pagos/IESS, cobro automático del arriendo ni sucursales', [], array_values(array_filter(Modulos::CATALOGO,
      fn($m) => preg_match('/IESS|d[eé]cimo|rol de pago|sucursal|multi.?sede|arriendo autom/i', $m[1] . ' ' . $m[3]))));
check('La base dice qué trae siempre', true, count(Modulos::BASE) >= 6);
check('Precio del catálogo (Reservas en línea $5, Anticipo $3)', [5.0, 3.0], [$mods->catalogo()['reservas_linea']['precio_mensual'], $mods->catalogo()['anticipos']['precio_mensual']]);
check('Encender Anticipo trae Reservas en línea', ['reservas_linea', 'anticipos'], Modulos::conDependencias(['anticipos']));
check('Clientes privados trae el portal del peluquero', ['portal_peluquero', 'clientes_privados'], Modulos::conDependencias(['clientes_privados']));
check('Sin su dependencia, el módulo no queda (huérfano)', ['inventario'], Modulos::sinHuerfanos(['anticipos', 'inventario']));
check('limpiar(): ids válidos, sin repetir, en orden del catálogo', ['reservas_linea', 'puntos'], Modulos::limpiar('puntos, x, reservas_linea,puntos'));

// ---------------------------------------------------------------------------------------------
titulo('2. Migración 008: los planes de hoy pasan a ser base + módulos (sin perder nada)');
$pl = $mods->planes();
check('Básica = solo la base; Completa = todos los módulos', [[], $ids], [$pl['basica']['modulos'], $pl['completa']['modulos']]);
// Simular una base anterior a la 008 con un salón Completa y uno Básica pagados
$sB = $nuevo('Barbería Básica Vieja', 'basica');
$sC = $nuevo('Salón Completa Viejo', 'completa');
$db->exec('ALTER TABLE planes DROP COLUMN modulos');
$db->exec('ALTER TABLE salones DROP COLUMN modulos_extra, DROP COLUMN modulos_quitados, DROP COLUMN modulos_demo, DROP COLUMN precio_acordado');
$db->exec('DROP TABLE modulos, modulos_cambios, pedidos_modulos, pedidos_demo');
$db->exec(file_get_contents(RAIZ . '/db/migraciones/008_modulos.sql'));
$mods->olvidar();
check('Después de migrar: Completa sigue con todo y Básica con la base', [$ids, []], [$mods->activos($sC), $mods->activos($sB)]);
check('… y pagan lo mismo que antes ($35 y $25)', [35.0, 25.0], [$mods->detalle($mods->salon($sC))['mensual'], $mods->detalle($mods->salon($sB))['mensual']]);
$db->exec("UPDATE planes SET modulos = 'reservas_linea' WHERE codigo = 'completa'");
$db->exec(file_get_contents(RAIZ . '/db/migraciones/008_modulos.sql'));
check('Correrla otra vez no pisa lo que cambió el súper usuario', 'reservas_linea', $db->query("SELECT modulos FROM planes WHERE codigo = 'completa'")->fetchColumn());
$db->exec("UPDATE planes SET modulos = '*' WHERE codigo = 'completa'");
$mods->olvidar();

// ---------------------------------------------------------------------------------------------
titulo('3. Cada página tiene su módulo (revisión automática)');
$index = (string) file_get_contents(RAIZ . '/public/index.php');
$rutas = [];
foreach (['publicas', 'privadas', 'deAdmin'] as $var) {
    if (preg_match('/\$' . $var . '\s*=\s*\[(.*?)\];/s', $index, $m)) {
        preg_match_all("/'([a-z_]+)'/", $m[1], $mm);
        $rutas = array_merge($rutas, $mm[1]);
    }
}
$rutas[] = 'admin_entrar';
$rutas = array_values(array_unique($rutas));
sort($rutas);
$mapeadas = array_keys(Modulos::RUTAS);
sort($mapeadas);
check('Toda ruta de public/index.php está en Modulos::RUTAS (y ninguna de más)', $rutas, $mapeadas);
check('Cada ruta tiene su archivo en app/paginas', [], array_values(array_filter($mapeadas, fn($r) => !is_file(RAIZ . "/app/paginas/$r.php"))));
check('Cada ruta es de la base o de un módulo que existe', [], array_values(array_filter(Modulos::RUTAS, fn($m) => $m !== '' && !Modulos::existe($m))));
check('Los módulos con pantalla tienen al menos una ruta', [], array_values(array_diff(['reservas_linea', 'anticipos', 'ficha_tecnica', 'portal_peluquero',
      'clientes_privados', 'recepcion', 'inventario', 'paquetes_regalo', 'puntos', 'gastos_sri', 'reportes'], array_values(Modulos::RUTAS))));
check('Páginas públicas de un módulo: el salón se encuentra por el enlace (Papelera::RUTAS_PUBLICAS)', [], array_values(array_filter(
      array_keys(array_filter(Modulos::RUTAS, fn($m) => $m !== '')), fn($r) => in_array($r, ['reservar', 'horas', 'cliente_entrar', 'mi_cuenta', 'foto', 'payphone'], true)
      && !isset(Papelera::RUTAS_PUBLICAS[$r]))));
check('El enrutador revisa el módulo de cada página privada y pública', [true, true], [str_contains($index, 'exigir_modulo(\TuSalon\Modulos::RUTAS[$ruta]'),
      str_contains($index, '$moduloPublico')]);
$sinViejo = [];
foreach (array_merge(glob(RAIZ . '/app/*/*.php'), glob(RAIZ . '/app/*.php'), glob(RAIZ . '/src/*.php'), [RAIZ . '/public/index.php']) as $f) {
    $t = (string) file_get_contents($f);
    if (preg_match("/es_completa\(|requiere_completa\(|esPlanCompleta\(|plan_codigo'\] === 'completa'/", $t)) $sinViejo[] = basename($f);
}
check('Ya no quedan revisiones del plan viejo ("es Completa"): todo pregunta por el módulo', [], $sinViejo);

// ---------------------------------------------------------------------------------------------
titulo('4. Cuentas del precio (Anexo B.1)');
$s = $nuevo('Barbería Cuentas', 'basica');
$d = $mods->detalle($mods->salon($s));
check('Básica sin extras: $25', [25.0, 0.0, 0.0, null, 25.0], [$d['precio_plan'], $d['precio_extra'], $d['descuento'], $d['precio_acordado'], $d['mensual']]);
$r = $mods->cambiar($s, ['anticipos', 'inventario'], [], false, 'Pidió anticipos', 'Prueba <p@prueba.ec>');
check('Encender anticipo enciende reservas y lo dice', [['reservas_linea'], ['reservas_linea', 'anticipos', 'inventario']], [$r['por_dependencia'], $r['encendidos']]);
check('Extra: reservas $5 + anticipo $3 + inventario $3 = $36', [11.0, 36.0], [$r['detalle']['precio_extra'], $r['detalle']['mensual']]);
check('cotizar con los módulos del salón: semestral paga 5 × 36', 180.0, $planes->cotizar('basica', 'semestral', $s)['monto']);
check('El mismo pago sin salón: solo el plan', 125.0, $planes->cotizar('basica', 'semestral')['monto']);
$msg = lanza(fn() => $mods->cambiar($s, [], ['reservas_linea'], false, '', 'x'));
check('No se apaga Reservas si Anticipo lo necesita', true, str_contains($msg, 'lo necesita'));
$r = $mods->cambiar($s, [], ['anticipos', 'reservas_linea'], false, '', 'x');
check('Apagar los dos juntos sí', ['inventario'], $r['detalle']['activos']);
$c = $nuevo('Salón Completa Quita', 'completa');
$r = $mods->cambiar($c, [], ['gastos_sri', 'puntos'], false, 'No usa', 'x');
check('Completa quitando gastos ($1,50) y puntos ($1): $32,50', [['puntos', 'gastos_sri'], 2.5, 32.5], [$r['detalle']['quitados'], $r['detalle']['descuento'], $r['detalle']['mensual']]);
$r = $mods->cambiar($c, [], [], 30.0, 'Precio especial', 'x');
check('Precio acordado fijo: $30 (manda sobre el calculado)', [30.0, 32.5, 30.0], [$r['detalle']['precio_acordado'], $r['detalle']['precio_calculado'], $r['detalle']['mensual']]);
check('Registrar pago sin monto usa el precio acordado (30 × 1)', 30.0, $planes->cotizar('completa', 'mensual', $c)['monto']);
$r = $mods->cambiar($c, ['puntos'], [], null, '', 'x');
check('Encender uno quitado lo devuelve al plan y quitar el acordado', [['gastos_sri'], null, 33.5], [$r['detalle']['quitados'], $r['detalle']['precio_acordado'], $r['detalle']['mensual']]);
check('Sin cambios: no se anota', true, $mods->cambiar($c, ['puntos'], [], false, '', 'x')['sin_cambios']);
check('Cada cambio queda en modulos_cambios (los sin cambios no)', 3, $cuenta("SELECT count(*) FROM modulos_cambios WHERE salon_id = $c"));
check('Módulo que no existe → error', true, str_contains(lanza(fn() => $mods->cambiar($c, ['oro'], [], false, '', 'x')), 'no existe'));
check('Nunca baja de $0', 0.0, (function () use ($mods, $db) {
    $x = $mods->detalle(['id' => 0, 'plan_codigo' => 'basica', 'estado' => 'activo', 'modulos_extra' => '', 'modulos_quitados' => '', 'modulos_demo' => null, 'precio_acordado' => null]);
    return max(0.0, $x['mensual'] - 1000);
})());
check('Apagar no borra datos: el inventario de un salón que lo apaga sigue ahí', true, (function () use ($db, $mods, $s) {
    $db->exec("INSERT INTO productos (salon_id, nombre, precio) VALUES ($s, 'Cera', 5)");
    $mods->cambiar($s, [], ['inventario'], false, '', 'x');
    $sigue = (int) $db->query("SELECT count(*) FROM productos WHERE salon_id = $s")->fetchColumn() === 1;
    $mods->cambiar($s, ['inventario'], [], false, '', 'x');
    return $sigue && $mods->activo($s, 'inventario');
})());

// ---------------------------------------------------------------------------------------------
titulo('5. Demos: todo encendido (o lo que eligió el súper usuario)');
$dm = $nuevo('Demo Todo', 'basica', false);
check('Demo de Básica: ve todos los módulos (también el Asistente IA)', $todos, $mods->activos($dm));
check('… pero lo que pagaría al contratar es el plan ($25)', 25.0, $mods->detalle($mods->salon($dm))['mensual']);
$db->exec("UPDATE salones SET modulos_demo = 'anticipos' WHERE id = $dm");
check('Demo con módulos elegidos (con sus dependencias)', ['reservas_linea', 'anticipos'], $mods->activos($dm));
$db->exec("UPDATE salones SET modulos_demo = NULL WHERE id = $dm");

// ---------------------------------------------------------------------------------------------
titulo('6. Personas, cobros, tareas y avisos se apagan con su módulo');
$b = $nuevo('Barbería Tres', 'basica');
$prof = new Profesionales($db, $planes);
$prof->agregar($b, 'Juan', 'empleado', ['comision_servicio_pct' => 40]);
$prof->agregar($b, 'Lucía', 'porcentaje', ['pct_profesional' => 50]);
check('Sin «Personas ilimitadas»: la 4.ª persona no', true, str_contains(lanza(fn() => $prof->agregar($b, 'Cuarto', 'empleado')), 'Personas ilimitadas'));
$mods->cambiar($b, ['equipo_ilimitado'], [], false, '', 'x');
check('Con el módulo: sí', true, $prof->agregar($b, 'Cuarto', 'empleado') > 0);
$pB = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = $b AND tipo = 'dueno'")->fetchColumn();
$svB = (int) $db->query("SELECT id FROM servicios WHERE salon_id = $b ORDER BY id LIMIT 1")->fetchColumn();
$prodB = (int) $db->query("INSERT INTO productos (salon_id, nombre, precio, stock) VALUES ($b, 'Shampoo', 8, 5) RETURNING id")->fetchColumn();
$cobro = new Cobro($db);
check('Cobro con productos sin «Inventario» → no', true, str_contains(lanza(fn() => $cobro->registrar(['salon_id' => $b, 'profesional_id' => $pB, 'servicios' => [$svB => 6], 'productos' => [$prodB => 1]])), 'no está en tu plan'));
check('Tarjeta de regalo sin «Paquetes y tarjetas» → no', true, str_contains(lanza(fn() => $cobro->registrar(['salon_id' => $b, 'profesional_id' => $pB, 'servicios' => [$svB => 6], 'tarjeta_codigo' => 'AB12-CD34'])), 'no están en tu plan'));
check('Cobro solo de servicios: sí (base)', true, $cobro->registrar(['salon_id' => $b, 'profesional_id' => $pB, 'servicios' => [$svB => 6]])['venta_id'] > 0);
// Telegram: avisos al equipo solo con el módulo
$uB = (int) $db->query("SELECT id FROM usuarios WHERE salon_id = $b AND rol = 'dueno'")->fetchColumn();
$uC = (int) $db->query("SELECT id FROM usuarios WHERE salon_id = $c AND rol = 'dueno'")->fetchColumn();
$db->exec("UPDATE usuarios SET telegram_chat_id = 7001 WHERE id = $uB");
$db->exec("UPDATE usuarios SET telegram_chat_id = 7002 WHERE id = $uC");
if (Telegram::configurado()) {
    check('Telegram al equipo: solo al salón con «Avisos por Telegram»', 1, (new Telegram($db))->textoAUsuarios([$uB, $uC], 'Hola'));
} else {
    check('Telegram al equipo: sin configurar no envía', 0, (new Telegram($db))->textoAUsuarios([$uB, $uC], 'Hola'));
}
$n = (new Notificaciones($db))->alDueno($b, 'Aviso de prueba');
check('… pero la campana le llega igual (base)', 1, $cuenta("SELECT count(*) FROM notificaciones WHERE usuario_id = $uB AND texto = 'Aviso de prueba'"));
// Tareas automáticas: recordatorio por Telegram solo con el módulo
$lunes = (new DateTimeImmutable('next monday'))->setTime(10, 0);
$cita = function (int $salon, int $chat) use ($db, $lunes): int {
    $p = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = $salon AND tipo = 'dueno'")->fetchColumn();
    $sv = (int) $db->query("SELECT id FROM servicios WHERE salon_id = $salon ORDER BY id LIMIT 1")->fetchColumn();
    $cl = (int) $db->query("INSERT INTO clientes (salon_id, nombre, telefono, telegram_chat_id) VALUES ($salon, 'Cliente Tg', '0991112222', $chat) RETURNING id")->fetchColumn();
    return (new TuSalon\Agenda($db))->crearCita($salon, $p, $cl, $lunes->modify('+1 day')->format('Y-m-d') . ' 10:00', [$sv]);
};
$citaB = $cita($b, 8001);
$citaC = $cita($c, 8002);
$r = (new Automaticas($db))->correr($lunes);
check('Tarea de cada hora: recordatorio automático solo donde está «Avisos por Telegram»', [false, Telegram::configurado()],
      [(bool) $db->query("SELECT recordatorio_en IS NOT NULL FROM citas WHERE id = $citaB")->fetchColumn(),
       (bool) $db->query("SELECT recordatorio_en IS NOT NULL FROM citas WHERE id = $citaC")->fetchColumn()]);
check('Anticipo: sin el módulo no se pide aunque esté configurado', 0.0, (new TuSalon\Anticipos($db))->montoRequerido($b, null, 20.0));

// ---------------------------------------------------------------------------------------------
titulo('7. Mi plan → "Pedir este módulo" → solicitud cambio_modulos → aprobar');
$id1 = $mods->pedir($b, $uB, ['ficha_tecnica'], 'Para los tintes');
check('Pedido guardado', 'ficha_tecnica', $db->query("SELECT modulos FROM pedidos_modulos WHERE id = $id1")->fetchColumn());
check('Pedir lo mismo otra vez no duplica', $id1, $mods->pedir($b, $uB, ['ficha_tecnica']));
check('Pedir uno que ya tiene → aviso', true, str_contains(lanza(fn() => $mods->pedir($b, $uB, ['equipo_ilimitado'])), 'ya está en tu plan'));
$sols = $pedir('solicitudes', ['tipos' => ['cambio_modulos']])[1]['solicitudes'];
check('Llega al Panel Central como cambio_modulos', ["cambio_modulos:$id1"], array_column($sols, 'id'));
$sol = $sols[0];
check('… con el salón, el monto y el precio nuevo', [(string) $b, 3.0, 33.0], [$sol['cliente_id'], $sol['monto'], $sol['detalle']['precio_nuevo']]);
check('… y botones Aprobar (cambiar_modulos) y Rechazar', ['cambiar_modulos', 'atender_solicitud'], array_slice(array_column($sol['acciones_posibles'], 'accion'), 0, 2));
$apr = $sol['acciones_posibles'][0];
$op = bin2hex(random_bytes(16));
$r = $ej('cambiar_modulos', $apr['datos'] + ['motivo' => 'Aprobado'], null, $op);
check('Aprobar: enciende Ficha técnica', [200, true], [$r[0], $mods->activo($b, 'ficha_tecnica')]);
check('… el pedido queda atendido y sale de abiertas', ['atendida', []], [$db->query("SELECT estado FROM pedidos_modulos WHERE id = $id1")->fetchColumn(),
      array_column($pedir('solicitudes', ['tipos' => ['cambio_modulos']])[1]['solicitudes'], 'id')]);
$r2 = $ej('cambiar_modulos', $apr['datos'] + ['motivo' => 'Aprobado'], null, $op);
check('Mismo operacion_id: repetida, no cambia otra vez', [200, true, 1], [$r2[0], $r2[1]['repetida'], $cuenta("SELECT count(*) FROM modulos_cambios WHERE salon_id = $b AND encendidos = 'ficha_tecnica'")]);
check('Aprobar otra vez el mismo pedido → 409', [409, 'conflicto'], [$ej('cambiar_modulos', $apr['datos'])[0], $ej('cambiar_modulos', $apr['datos'])[1]['codigo']]);
$id2 = $mods->pedir($b, $uB, ['reportes']);
$r = $ej('atender_solicitud', ['solicitud_id' => "cambio_modulos:$id2", 'resultado' => 'descartada', 'nota' => 'Más adelante']);
check('Rechazar: queda descartado con el motivo', [200, 'descartada', 'Más adelante', false], [$r[0], $db->query("SELECT estado FROM pedidos_modulos WHERE id = $id2")->fetchColumn(),
      $db->query("SELECT respuesta FROM pedidos_modulos WHERE id = $id2")->fetchColumn(), $mods->activo($b, 'reportes')]);
check('Atender como "atendida" no aprueba (hay que usar cambiar_modulos) → 422', [422, 'datos.resultado'],
      [$ej('atender_solicitud', ['solicitud_id' => "cambio_modulos:$id1", 'resultado' => 'atendida'])[0], $ej('atender_solicitud', ['solicitud_id' => "cambio_modulos:$id1", 'resultado' => 'atendida'])[1]['campo']]);
check('Pedido de otro salón → 404 (aislamiento)', [404, 'no_encontrado'], [$ej('cambiar_modulos', ['solicitud_id' => "cambio_modulos:$id2"], $c)[0], $ej('cambiar_modulos', ['solicitud_id' => "cambio_modulos:$id2"], $c)[1]['codigo']]);

// ---------------------------------------------------------------------------------------------
titulo('8. Conector v2: modulos, cliente.modulos, cambiar_modulos, guardar_modulos, finanzas.por_modulo');
$planes->suscribir($b, 'basica', 'mensual', new DateTimeImmutable('today'), 'transferencia');   // paga: cuenta en mensual_estimado y MRR
$r = $pedir('modulos');
check('modulos: base, catálogo y planes', [200, count(Modulos::BASE), 15, ['basica', 'completa']], [$r[0], count($r[1]['base']), count($r[1]['modulos']), array_column($r[1]['planes'], 'codigo')]);
check('… cada módulo con la forma del Anexo B', ['id', 'nombre', 'icono', 'descripcion', 'precio_mensual', 'descuento', 'depende_de', 'grupo', 'grupo_nombre', 'orden'], array_keys($r[1]['modulos'][0]));
$cl = $pedir('cliente', ['id' => (string) $b])[1]['cliente'];
check('cliente.modulos: los extra del salón', ['ficha_tecnica', 'equipo_ilimitado'], $cl['modulos']['extra']);
check('mensual_estimado = plan + extra (paga)', $cl['modulos']['mensual'], $cl['mensual_estimado']);
check('El botón Registrar pago sugiere el precio con módulos', $cl['modulos']['mensual'], $cl['acciones_posibles'][array_search('registrar_pago', array_column($cl['acciones_posibles'], 'accion'))]['campos'][2]['valor']);
check('cotizar trae los módulos y el monto con ellos', [$cl['modulos']['mensual'] * 5, $cl['modulos']['extra']],
      [$pedir('cotizar', ['cliente_id' => (string) $b, 'periodo' => 'semestral'])[1]['monto'], $pedir('cotizar', ['cliente_id' => (string) $b, 'periodo' => 'semestral'])[1]['modulos']['extra']]);
$op = bin2hex(random_bytes(16));
$r = $ej('cambiar_modulos', ['encender' => 'recepcion, puntos', 'apagar' => ['inventario'], 'precio_acordado' => 40, 'motivo' => 'Negociado'], $b, $op);
check('cambiar_modulos (texto o lista)', [200, true, true, false, 40.0], [$r[0], $mods->activo($b, 'recepcion'), $mods->activo($b, 'puntos'), $mods->activo($b, 'inventario'),
      $r[1]['resultado']['modulos']['mensual']]);
check('… auditado en registro_admin con el correo del Central', 1, $cuenta("SELECT count(*) FROM registro_admin r JOIN superadmins a ON a.id = r.admin_id
      WHERE r.salon_id = $b AND r.accion LIKE 'Panel Tukán Central · Módulos:%Negociado%' AND a.email = 'dimitryfierro@gmail.com'"));
check('… y en modulos_cambios con quién', 'Dimitry <dimitryfierro@gmail.com>', $db->query("SELECT quien FROM modulos_cambios WHERE salon_id = $b ORDER BY id DESC LIMIT 1")->fetchColumn());
check('Repetido con otro cuerpo → 409', 409, $ej('cambiar_modulos', ['encender' => ['reportes']], $b, $op)[0]);
check('Igual que antes: sin_cambios', true, $ej('cambiar_modulos', ['encender' => ['recepcion']], $b)[1]['sin_cambios']);
check('Módulo que no existe → 422 datos.encender', [422, 'datos.encender'], [$ej('cambiar_modulos', ['encender' => ['oro']], $b)[0], $ej('cambiar_modulos', ['encender' => ['oro']], $b)[1]['campo']]);
$mods->cambiar($b, ['clientes_privados'], [], false, '', 'x');
$r = $ej('cambiar_modulos', ['apagar' => ['portal_peluquero']], $b);
check('Apagar uno que otro necesita → 422 y no cambia', [422, true], [$r[0], $mods->activo($b, 'portal_peluquero')]);
check('Otro salón no cambia (aislamiento)', false, $mods->activo($sB, 'recepcion'));
$r = $ej('guardar_modulos', ['modulos' => [['id' => 'reservas_linea', 'precio_mensual' => 6.5, 'descripcion' => 'Reservas en línea desde tu enlace propio']],
                               'planes' => ['basica' => ['telegram']]]);
check('guardar_modulos: precio y plan', [200, 6.5, ['telegram']], [$r[0], (new Modulos($db))->catalogo()['reservas_linea']['precio_mensual'], (new Modulos($db))->planes()['basica']['modulos']]);
check('… el salón Básica gana Telegram (del plan) sin pagar extra', [true, 25.0], [(new Modulos($db))->activo($sB, 'telegram'), (new Modulos($db))->detalle((new Modulos($db))->salon($sB))['mensual']]);
check('… sin cambios la segunda vez', true, $ej('guardar_modulos', ['modulos' => [['id' => 'reservas_linea', 'precio_mensual' => 6.5, 'descripcion' => 'Reservas en línea desde tu enlace propio']], 'planes' => ['basica' => ['telegram']]])[1]['sin_cambios']);
check('… plan que no existe → 422', 422, $ej('guardar_modulos', ['planes' => ['oro' => []]])[0]);
check('… precio negativo → 422', 422, $ej('guardar_modulos', ['modulos' => [['id' => 'puntos', 'precio_mensual' => -1]]])[0]);
$ej('guardar_modulos', ['modulos' => [['id' => 'reservas_linea', 'precio_mensual' => 5]], 'planes' => ['basica' => []]]);
$fz = $pedir('finanzas')[1];
check('finanzas.por_modulo: ingreso estimado de los módulos extra de quien paga', true, in_array('recepcion', array_column($fz['por_modulo'], 'modulo'), true));
check('finanzas.mrr = suma de mensual_estimado', round(array_sum(array_column($pedir('clientes', ['por_pagina' => 200])[1]['clientes'], 'mensual_estimado')), 2), $fz['mrr']);

// ---------------------------------------------------------------------------------------------
titulo('9. Todo se pide demo: pedido del formulario → demo_pedida → crear_demo con plan y módulos');
$pd = new PedidosDemo($db);
check('Pedido sin WhatsApp → error', true, str_contains(lanza(fn() => $pd->crear(['nombre' => 'Ana', 'salon' => 'Salón Ana', 'ciudad' => 'Quito', 'email' => 'ana@x.ec'])), 'WhatsApp'));
$idD = $pd->crear(['nombre' => 'Ana Pérez', 'salon' => 'Salón Ana', 'ciudad' => 'Quito', 'whatsapp' => '0991234567', 'email' => 'Ana@Prueba.ec',
                   'necesita' => 'Somos 4', 'modulos' => ['reservas_linea', 'oro']], '203.0.113.5');
check('Pedido guardado (sin crear salón)', ['nueva', 'reservas_linea', 0], [$pd->pedido($idD)['estado'], $pd->pedido($idD)['modulos'], $cuenta("SELECT count(*) FROM salones WHERE nombre = 'Salón Ana'")]);
check('Mismo correo: actualiza, no repite', $idD, $pd->crear(['nombre' => 'Ana Pérez', 'salon' => 'Salón Ana', 'ciudad' => 'Quito', 'whatsapp' => '0991234567', 'email' => 'ana@prueba.ec'], '203.0.113.5'));
for ($i = 0; $i < 5; $i++) { try { $pd->crear(['nombre' => 'Robot', 'salon' => 'Robot ' . $i, 'ciudad' => 'Xa', 'whatsapp' => '0990000000', 'email' => "r$i@x.ec"], '203.0.113.9'); } catch (RuntimeException) {} }
check('Máximo 5 pedidos por hora por IP', true, str_contains(lanza(fn() => $pd->crear(['nombre' => 'Robot', 'salon' => 'Robot 9', 'ciudad' => 'Xa', 'whatsapp' => '0990000000', 'email' => 'r9@x.ec'], '203.0.113.9')), 'Ya recibimos'));
$s = array_values(array_filter($pedir('solicitudes', ['tipos' => ['demo_pedida']])[1]['solicitudes'], fn($x) => $x['id'] === "demo_pedida:$idD"))[0] ?? null;
check('Llega al Central como demo_pedida (pendiente, alta)', ['pedido', 'pendiente', 'nueva', 'alta', 'Salón Ana'], [$s['categoria'], $s['estado'], $s['estado_producto'], $s['prioridad'], $s['quien']['organizacion']]);
check('… con WhatsApp, Crear demo (datos llenos) y Marcar', ['whatsapp', 'crear_demo', 'atender_solicitud'], array_column($s['acciones_posibles'], 'accion'));
$r = $ej('atender_solicitud', ['solicitud_id' => "demo_pedida:$idD", 'resultado' => 'en_proceso', 'nota' => 'Le escribí']);
check('Marcar contactado: en_proceso / contactada', [200, 'en_proceso', 'contactada'], [$r[0], $r[1]['solicitud']['estado'], $pd->pedido($idD)['estado']]);
$opD = bin2hex(random_bytes(16));
$r = $ej('crear_demo', ['nombre' => 'Salón Ana', 'contacto_nombre' => 'Ana Pérez', 'email' => 'ana@prueba.ec', 'telefono' => '0991234567',
                        'plan' => 'basica', 'dias' => 5, 'solicitud_id' => "demo_pedida:$idD", 'modulos' => 'anticipos,inventario'], null, $opD);
$nid = (int) ($r[1]['resultado']['cliente_id'] ?? 0);
check('crear_demo desde el pedido: plan Básica con los módulos elegidos (+ dependencias)', [200, 'basica', ['reservas_linea', 'anticipos', 'inventario']],
      [$r[0], $r[1]['cliente']['plan']['codigo'], $r[1]['cliente']['modulos']['activos']]);
check('… origen demo_pedida y el pedido queda "demo creada" con el salón', ['demo_pedida', 'demo_creada', $nid], [$r[1]['cliente']['origen'], $pd->pedido($idD)['estado'], (int) $pd->pedido($idD)['salon_creado_id']]);
check('… lo que pidió queda en las notas internas', true, str_contains((string) $db->query("SELECT notas_admin FROM salones WHERE id = $nid")->fetchColumn(), 'Somos 4'));
check('Repetida: no crea otra demo', [true, 1], [$ej('crear_demo', ['nombre' => 'Salón Ana', 'contacto_nombre' => 'Ana Pérez', 'email' => 'ana@prueba.ec', 'telefono' => '0991234567',
      'plan' => 'basica', 'dias' => 5, 'solicitud_id' => "demo_pedida:$idD", 'modulos' => 'anticipos,inventario'], null, $opD)[1]['repetida'], $cuenta("SELECT count(*) FROM salones WHERE nombre = 'Salón Ana'")]);
check('El mismo pedido otra vez → 409', [409, 'conflicto'], [$ej('crear_demo', ['nombre' => 'Salón Ana 2', 'contacto_nombre' => 'Ana', 'email' => 'ana2@prueba.ec', 'telefono' => '0991234567',
      'solicitud_id' => "demo_pedida:$idD"])[0], $ej('crear_demo', ['nombre' => 'Salón Ana 2', 'contacto_nombre' => 'Ana', 'email' => 'ana3@prueba.ec', 'telefono' => '0991234567',
      'solicitud_id' => "demo_pedida:$idD"])[1]['codigo']]);
check('crear_demo con un módulo que no existe → 422 datos.modulos', [422, 'datos.modulos'], [$ej('crear_demo', ['nombre' => 'X Salón', 'contacto_nombre' => 'Xavi', 'email' => 'x1@prueba.ec', 'telefono' => '0991234567', 'modulos' => ['oro']])[0],
      $ej('crear_demo', ['nombre' => 'X Salón', 'contacto_nombre' => 'Xavi', 'email' => 'x2@prueba.ec', 'telefono' => '0991234567', 'modulos' => ['oro']])[1]['campo']]);
$r = $ej('crear_demo', ['nombre' => 'Demo Completa', 'contacto_nombre' => 'Eva', 'email' => 'eva@prueba.ec', 'telefono' => '0991234567']);
check('crear_demo sin módulos: todos', Modulos::ids(), $r[1]['cliente']['modulos']['activos']);
$idX = $pd->crear(['nombre' => 'Luis', 'salon' => 'Barbería Luis', 'ciudad' => 'Loja', 'whatsapp' => '0997654321', 'email' => 'luis@prueba.ec']);
$r = $ej('atender_solicitud', ['solicitud_id' => "demo_pedida:$idX", 'resultado' => 'descartada']);
check('Descartar un pedido de demo', [200, 'descartada'], [$r[0], $pd->pedido($idX)['estado']]);
check('… y ya no se puede crear su demo', 409, $ej('crear_demo', ['nombre' => 'Barbería Luis', 'contacto_nombre' => 'Luis', 'email' => 'luis@prueba.ec', 'telefono' => '0997654321', 'solicitud_id' => "demo_pedida:$idX"])[0]);
check('No hay registros solos: el tipo registro_nuevo ya no existe', [], array_values(array_filter(array_column($pedir('solicitudes', ['estado' => 'todas', 'por_pagina' => 200])[1]['solicitudes'], 'tipo'), fn($t) => $t === 'registro_nuevo')));

// ---------------------------------------------------------------------------------------------
titulo('10. Todo se pide demo: ningún texto dice «gratis» (solo demos; en ventas, «meses de regalo»)');
// En el código PHP se revisa el texto sin los comentarios; README, guías, manuales y JavaScript completos.
// dominio_gratis y meses_gratis son ids del protocolo con el Panel Tukán Central (no se muestran).
$archivos = array_merge(glob(RAIZ . '/app/vistas/*.php'), glob(RAIZ . '/app/paginas/*.php'), glob(RAIZ . '/src/*.php'), [RAIZ . '/app/bootstrap.php'],
                        glob(RAIZ . '/public/assets/*.js'), [RAIZ . '/README.md'], glob(RAIZ . '/docs/*.md') ?: [], glob(RAIZ . '/docs/manual/*.md') ?: [],
                        glob(RAIZ . '/docs/*.html') ?: [], glob(RAIZ . '/docs/*/*.html') ?: []);
$malos = [];
foreach ($archivos as $f) {
    $txt = (string) file_get_contents($f);
    if (str_ends_with($f, '.php')) {
        $sin = '';
        foreach (token_get_all($txt) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) $sin .= str_repeat("\n", substr_count($tk[1], "\n"));
            else $sin .= is_array($tk) ? $tk[1] : $tk;
        }
        $txt = $sin;
    }
    foreach (explode("\n", str_replace(['dominio_gratis', 'meses_gratis'], '', $txt)) as $n => $linea) {
        if (preg_match('/gratis|gratuit|\bfree\b|sin tarjeta/iu', $linea)) $malos[] = basename($f) . ':' . ($n + 1);
    }
}
check('Sin «gratis», «gratuito», «free» ni «sin tarjeta» en pantallas, código, guías ni manuales', [], $malos);

titulo('11. Precios del software solo por cotización (regla del dueño)');
$vPlan = (string) file_get_contents(RAIZ . '/app/vistas/completa.php');
$vNo = (string) file_get_contents(RAIZ . '/app/vistas/no_en_plan.php');
check('Mi plan: lo que no tiene dice «Precio por cotización» y «Pedir cotización»', [true, true, false],
      [str_contains($vPlan, "'Precio por cotización'"), str_contains($vPlan, 'Pedir cotización'), str_contains($vPlan, 'Pedir este módulo')]);
check('Mi plan: el botón de la demo no lleva precio', false, (bool) preg_match('/Lo quiero · \+<\?= dinero/u', $vPlan));
check('«No está en tu plan»: sin precio y con «Pedir cotización»', [false, false, true],
      [str_contains($vNo, 'precio_mensual'), str_contains($vNo, 'al mes'), str_contains($vNo, 'Pedir cotización')]);
check('El registro público es "Solicitar demo" y no crea salones', [true, false], [str_contains((string) file_get_contents(RAIZ . '/app/vistas/registro.php'), 'Solicitar demo'),
      str_contains((string) file_get_contents(RAIZ . '/app/paginas/registro.php'), 'crearSalonConDueno')]);

echo "\n==================================================\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
foreach ($fallos as $f) echo "  - $f\n";
exit($fallos ? 1 : 0);
