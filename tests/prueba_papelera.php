<?php
declare(strict_types=1);

/**
 * Panel Tukán Central v2, Anexo A: pausar, borrar (papelera), restaurar y purga a los 30 días.
 * Efecto real de cada operación, copia completa (todas las tablas del salón, sacadas del esquema, y sus fotos),
 * restaurar devuelve todo, purga con reloj simulado, aislamiento (los otros salones no se tocan), páginas públicas,
 * tareas automáticas, idempotencia, permisos y auditoría en registro_admin.
 * Borra y recrea la base de prueba (igual que run_tests.php).
 *
 * Uso:
 *   TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_papelera.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\{Altas, Automaticas, Db, Fotos, Papelera, PanelTukan, Planes, TukanCentral, TukanCentralV2};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.001 : $esperado === $real;
    if ($igual) {
        $ok++;
        echo "  OK    $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n";
    }
}
function titulo(string $t): void
{
    echo "\n$t\n";
}

if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) {
    exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
}
$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));

$hoy = new DateTimeImmutable('today');
$d = fn(string $m) => $hoy->modify($m)->format('Y-m-d');
$SECRETO = str_repeat('ab12', 16);
putenv('TUKAN_CENTRAL_ID=tusalon');
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
putenv('TUSALON_URL=https://tusalon.prueba.ec');
$TMPP = sys_get_temp_dir() . '/tusalon_papelera_' . getmypid();
putenv("TUSALON_UPLOADS=$TMPP/uploads");
putenv("TUSALON_BORRADOS=$TMPP/borrados");
$COPIAS = "$TMPP/borrados";
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
const FLAGS97 = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

$token = function (string $tipo, string $cuerpo, array $cambios = []) use ($SECRETO, $b64): string {
    $t = time();
    $p = array_merge(['v' => 2, 'tipo' => $tipo, 'inst' => 'tusalon', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => 'dueno',
                      'iat' => $t, 'exp' => $t + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)),
                      'bh' => $b64(hash('sha256', $cuerpo, true))], $cambios);
    $a = $b64(json_encode($p, FLAGS97));
    return $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true));
};
$sinAuditoria = function (array $datos): array { unset($datos['registro_admin']); return $datos; };
$pedir = function (string $accion, array $cuerpo = [], array $o = []) use ($db, $token): array {
    $c = json_encode($cuerpo === [] ? new stdClass() : $cuerpo, FLAGS97);
    $db->exec('DELETE FROM tukan_central_pedidos');
    return (new TukanCentralV2($db, $o['hoy'] ?? null))->procesar($accion, 'POST', 'application/json', $c,
                                                                 $token($accion, $c, $o['cambios'] ?? []), '198.51.100.97');
};
$ej = function (string $op, array $datos, int|string|null $cliente, ?string $opId = null, array $o = []) use ($pedir): array {
    $cuerpo = ['operacion_id' => $opId ?? bin2hex(random_bytes(16)), 'accion' => $op];
    if ($cliente !== null) $cuerpo['cliente_id'] = (string) $cliente;
    $cuerpo['datos'] = $datos === [] ? new stdClass() : $datos;
    return $pedir('ejecutar', $cuerpo, $o);
};
$codigo = fn(array $r) => [$r[0], $r[1]['codigo'] ?? null, $r[1]['detalle']['motivo'] ?? ($r[1]['campo'] ?? null)];
$clientes = fn(array $f = []) => $pedir('clientes', $f + ['por_pagina' => 200])[1];
$idsDe = fn(array $r) => array_map('intval', array_column($r['clientes'] ?? [], 'id'));
$planes = new Planes($db);
$panel = new PanelTukan($db);
$pap = new Papelera($db);
$carpeta = fn(string $c) => "$TMPP/uploads/$c";

/** Salón con datos en muchas tablas (clientes, citas, ventas, fotos, fichas, puntos, tarjetas, calificaciones, pagos…). */
$rico = function (string $nombre) use ($db, $planes, $d, $carpeta): array {
    $a = (new Altas($db))->crearSalonConDueno($nombre, 'Dueño ' . $nombre, '0990000000', 'd' . bin2hex(random_bytes(4)) . '@prueba.ec', 'claveSegura1', 'completa', 'registro');
    $n = $a['salon_id'];
    $q = function (string $sql, array $p = []) use ($db) { $st = $db->prepare($sql); $st->execute($p); return $st->columnCount() ? $st->fetchColumn() : null; };
    $prof = (int) $q('SELECT id FROM profesionales WHERE salon_id = ? ORDER BY id LIMIT 1', [$n]);
    $serv = (int) $q('SELECT id FROM servicios WHERE salon_id = ? ORDER BY id LIMIT 1', [$n]);
    $cli = (int) $q('INSERT INTO clientes (salon_id, nombre, telefono) VALUES (?, ?, ?) RETURNING id', [$n, "Clienta de $nombre", '0991112233']);
    $cita = (int) $q("INSERT INTO citas (salon_id, profesional_id, cliente_id, inicio, fin, estado) VALUES (?, ?, ?, ?, ?, 'confirmada') RETURNING id",
                     [$n, $prof, $cli, $d('+1 day') . ' 10:00', $d('+1 day') . ' 11:00']);
    $q('INSERT INTO cita_servicios (cita_id, servicio_id, precio) VALUES (?, ?, 12)', [$cita, $serv]);
    $pasada = (int) $q("INSERT INTO citas (salon_id, profesional_id, cliente_id, inicio, fin, estado) VALUES (?, ?, ?, ?, ?, 'atendida') RETURNING id",
                       [$n, $prof, $cli, $d('-3 days') . ' 10:00', $d('-3 days') . ' 11:00']);
    $venta = (int) $q('INSERT INTO ventas (salon_id, cliente_id, cita_id, total) VALUES (?, ?, ?, 12) RETURNING id', [$n, $cli, $pasada]);
    $q("INSERT INTO venta_items (venta_id, tipo, servicio_id, profesional_id, precio_unitario, subtotal) VALUES (?, 'servicio', ?, ?, 12, 12)", [$venta, $serv, $prof]);
    $q("INSERT INTO movimientos_profesional (salon_id, profesional_id, venta_id, tipo, monto) VALUES (?, ?, ?, 'comision_servicio', 6)", [$n, $prof, $venta]);
    $q('INSERT INTO calificaciones (salon_id, cita_id, cliente_id, profesional_id, estrellas) VALUES (?, ?, ?, ?, 5)', [$n, $pasada, $cli, $prof]);
    $q('INSERT INTO fichas_tecnicas (salon_id, cliente_id, profesional_id, servicio) VALUES (?, ?, ?, ?)', [$n, $cli, $prof, 'Tinte']);
    $q("INSERT INTO puntos_movimientos (salon_id, cliente_id, venta_id, puntos, motivo) VALUES (?, ?, ?, 12, 'compra')", [$n, $cli, $venta]);
    $q('INSERT INTO tarjetas_regalo (salon_id, codigo, monto, saldo) VALUES (?, ?, 20, 20)', [$n, 'TR' . $n . bin2hex(random_bytes(2))]);
    $q('INSERT INTO productos (salon_id, nombre, precio) VALUES (?, ?, 8)', [$n, "Shampoo de $nombre"]);
    $foto = bin2hex(random_bytes(16)) . '.jpg';
    @mkdir($carpeta('fotos'), 0750, true);
    file_put_contents($carpeta('fotos') . "/$foto", "foto de $nombre");
    $fotoId = (int) $q('INSERT INTO fotos (salon_id, cliente_id, cita_id, profesional_id, archivo) VALUES (?, ?, ?, ?, ?) RETURNING id', [$n, $cli, $pasada, $prof, $foto]);
    // Pago anual hace 40 días (cubre 12 meses)
    $planes->suscribir($n, 'completa', 'anual', new DateTimeImmutable($d('-40 days')), 'transferencia', null, 350.0, new DateTimeImmutable($d('-40 days')));
    $db->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (NULL, ?, ?)')->execute([$n, 'Salón creado desde el panel (prueba)']);
    $db->prepare("UPDATE citas SET anticipo_referencia = ? WHERE id = ?")->execute(['TS' . $cita . '-0a1b2c3d', $cita]);
    return ['id' => $n, 'slug' => $a['slug'], 'cita' => $cita, 'pasada' => $pasada, 'cliente' => $cli, 'foto' => $foto, 'foto_id' => $fotoId,
            'token' => (string) $db->query("SELECT token FROM citas WHERE id = $cita")->fetchColumn()];
};

// ---------------------------------------------------------------------------------------------
titulo('1. Salones de prueba y capacidades (Anexo A)');
$A = $rico('Barbería Para Borrar');
$B = $rico('Salón Que Se Queda');
$P = $rico('Peluquería Para Pausar');
$X = $rico('Barbería Cancelada');
$db->exec("UPDATE salones SET estado = 'cancelado' WHERE id = {$X['id']}");
check('4 salones', 4, $cuenta('SELECT count(*) FROM salones'));
$cap = $pedir('capacidades')[1];
check('capacidades: operaciones nuevas', [true, true, true], [in_array('pausar', $cap['ejecutar'], true), in_array('borrar_cliente', $cap['ejecutar'], true),
                                                               in_array('restaurar_cliente', $cap['ejecutar'], true)]);
check('capacidades: revisión 3 (Anexo B) y tipos de pausa', [3, ['falta_pago', 'pedido_cliente', 'revision', 'abuso', 'otro']], [$cap['revision'], array_keys($cap['tipos_pausa'])]);
check('capacidades: formularios de pausar y borrar', [['tipo', 'motivo'], ['confirmar', 'motivo']],
      [array_column($cap['formularios']['pausar'], 'campo'), array_column($cap['formularios']['borrar_cliente'], 'campo')]);
check('capacidades: 30 días de papelera, copia 1 año, filtro', [['dias' => 30, 'copia_dias' => 365], true], [$cap['papelera'], in_array('papelera', $cap['filtros_clientes'], true)]);

// ---------------------------------------------------------------------------------------------
titulo('2. Tablas del salón sacadas del esquema (no de una lista escrita a mano)');
$esperadas = $db->query("WITH RECURSIVE t(n) AS (
        SELECT 'salones'::text
        UNION SELECT c.table_name::text FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.column_name = 'salon_id'
        UNION SELECT cl.relname::text FROM pg_constraint k JOIN pg_class cl ON cl.oid = k.conrelid JOIN pg_class r ON r.oid = k.confrelid JOIN t ON t.n = r.relname
               WHERE k.contype = 'f')
    SELECT n FROM t JOIN information_schema.tables x ON x.table_name = t.n AND x.table_schema = 'public' AND x.table_type = 'BASE TABLE' ORDER BY n")->fetchAll(PDO::FETCH_COLUMN);
$tablas = $pap->tablas();
$nombres = array_keys($tablas);
sort($nombres);
check('Mismas tablas que el esquema (' . count($esperadas) . ')', $esperadas, $nombres);
check('Más de 30 tablas del salón', true, count($esperadas) > 30);
check('Hijas sin salon_id incluidas (cita_servicios, venta_items, reglas_pago, paquete_usos)', [true, true, true, true],
      array_map(fn($t) => isset($tablas[$t]), ['cita_servicios', 'venta_items', 'reglas_pago', 'paquete_usos']));
check('registro_admin se copia pero no se borra (ON DELETE SET NULL)', [true, false], [isset($tablas['registro_admin']), $tablas['registro_admin']['propia']]);
check('usuarios, citas y venta_items se borran con el salón', [true, true, true], [$tablas['usuarios']['propia'], $tablas['citas']['propia'], $tablas['venta_items']['propia']]);
$pos = array_flip(array_keys($tablas));
check('Orden de madre a hija (salones primero, profesionales antes que citas, ventas antes que venta_items)', [0, true, true],
      [$pos['salones'], $pos['profesionales'] < $pos['citas'], $pos['ventas'] < $pos['venta_items']]);

// ---------------------------------------------------------------------------------------------
titulo('3. pausar: tipo y motivo obligatorios, pantalla del salón y páginas públicas');
check('Sin tipo → 422 datos.tipo', [422, 'invalido', 'datos.tipo'], $codigo($ej('pausar', ['motivo' => 'Debe octubre'], $P['id'])));
check('Tipo que no existe → 422', [422, 'invalido', 'datos.tipo'], $codigo($ej('pausar', ['tipo' => 'enojo', 'motivo' => 'Debe octubre'], $P['id'])));
check('Sin motivo → 422 datos.motivo', [422, 'invalido', 'datos.motivo'], $codigo($ej('pausar', ['tipo' => 'falta_pago'], $P['id'])));
check('… nada cambió', 'activo', $db->query("SELECT estado FROM salones WHERE id = {$P['id']}")->fetchColumn());
$op = bin2hex(random_bytes(16));
$r = $ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'], $op);
check('Pausar: 200', [200, true, false], [$r[0], $r[1]['ok'], $r[1]['sin_cambios']]);
$f = $db->query("SELECT estado, pausa_tipo, pausa_motivo, pausa_en IS NOT NULL AS con_fecha FROM salones WHERE id = {$P['id']}")->fetch();
check('Pausar: en la base queda suspendido con tipo, motivo y fecha', ['suspendido', 'falta_pago', 'Debe la mensualidad de octubre', true],
      [$f['estado'], $f['pausa_tipo'], $f['pausa_motivo'], $f['con_fecha']]);
check('Pausar: respuesta con el cliente suspendido y su pausa', ['suspendido', 'falta_pago', true],
      [$r[1]['cliente']['estado'], $r[1]['cliente']['pausa']['tipo'], $r[1]['cliente']['bloqueado']]);
check('Pausar: el salón ve el motivo (falta de pago)', 'Debe la mensualidad de octubre', $r[1]['resultado']['pausa']['texto_cliente']);
check('Pausar: auditoría con el correo del Central', 1,
      $cuenta("SELECT count(*) FROM registro_admin r JOIN superadmins a ON a.id = r.admin_id WHERE r.salon_id = {$P['id']}
               AND r.accion LIKE 'Panel Tukán Central · Pausado (Falta de pago): Debe la mensualidad de octubre%' AND a.email = 'dimitryfierro@gmail.com'"));
check('Mismo operacion_id → repetida', [200, true], array_map(fn($x) => $x, [($r2 = $ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'], $op))[0], $r2[1]['repetida']]));
check('Otra operación con lo mismo → sin_cambios', true, $ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'])[1]['sin_cambios']);
check('Mismo operacion_id con otros datos → 409', [409, 'conflicto', 'otros_datos'], $codigo($ej('pausar', ['tipo' => 'otro', 'motivo' => 'Otra cosa'], $P['id'], $op)));
$r = $ej('pausar', ['tipo' => 'revision', 'motivo' => 'Revisando pagos raros con tarjeta'], $P['id']);
check('Revisión: el salón ve un texto genérico', [200, false, true],
      [$r[0], str_contains($r[1]['resultado']['pausa']['texto_cliente'], 'raros'), str_contains($r[1]['resultado']['pausa']['texto_cliente'], 'WhatsApp')]);
$acc = array_column($pedir('cliente', ['id' => (string) $P['id']])[1]['cliente']['acciones_posibles'], 'accion');
check('Ficha de un pausado: reactivar y borrar, no pausar', [true, false, true], [in_array('reactivar', $acc, true), in_array('pausar', $acc, true), in_array('borrar_cliente', $acc, true)]);
check('Páginas públicas del pausado: "pausado"; su página de reservas es suya', ['pausado', $P['id']],
      [$pap->estadoPublico($P['id']), $pap->salonDeRuta('reservar', ['s' => $P['slug']])]);
$c = $clientes();
check('Un pausado cuenta como suspendido (contadores = estado v1)', [2, 2], [$c['contadores']['suspendido'], (new TukanCentral($db))->estado($hoy)['negocios']['suspendidos']]);
check('Pausar a un cancelado → 409', [409, 'conflicto', 'cancelado'], $codigo($ej('pausar', ['tipo' => 'otro', 'motivo' => 'Prueba de cancelado'], $X['id'])));
$t = (new Automaticas($db))->correr(new DateTimeImmutable('now'));
check('Tareas: solo el salón normal (A y B); ni el pausado ni el cancelado', 2, $t['salones']);
$r = $ej('reactivar', [], $P['id']);
$f = $db->query("SELECT estado, pausa_tipo, pausa_motivo FROM salones WHERE id = {$P['id']}")->fetch();
check('Reactivar: vuelve a activo y se borra la pausa', [200, 'activo', null, null, null], [$r[0], $f['estado'], $f['pausa_tipo'], $f['pausa_motivo'], $r[1]['cliente']['pausa']]);
check('Reactivado: páginas normales', 'normal', $pap->estadoPublico($P['id']));
$r = $ej('suspender', ['motivo' => 'Suspensión vieja sin tipo'], $P['id']);
check('suspender guarda el motivo sin tipo: texto genérico', [null, true],
      [$db->query("SELECT pausa_tipo FROM salones WHERE id = {$P['id']}")->fetchColumn(), str_contains($r[1]['cliente']['pausa']['texto_cliente'], 'WhatsApp')]);
$ej('reactivar', [], $P['id']);

// ---------------------------------------------------------------------------------------------
titulo('4. borrar_cliente: confirmación, permisos y copia completa');
$antesA = $pap->datos($A['id']);
$antesB = $pap->datos($B['id']);
check('Sin confirmar → 422 datos.confirmar', [422, 'invalido', 'datos.confirmar'], $codigo($ej('borrar_cliente', ['motivo' => 'Ya no usa el sistema'], $A['id'])));
check('Nombre mal escrito → 422', [422, 'invalido', 'datos.confirmar'], $codigo($ej('borrar_cliente', ['confirmar' => 'barbería para borrar', 'motivo' => 'xyz'], $A['id'])));
check('Sin motivo → 422 datos.motivo', [422, 'invalido', 'datos.motivo'], $codigo($ej('borrar_cliente', ['confirmar' => 'Barbería Para Borrar'], $A['id'])));
check('Rol soporte → 403 no_permitido', [403, 'no_permitido', 'rol'],
      $codigo($ej('borrar_cliente', ['confirmar' => 'Barbería Para Borrar', 'motivo' => 'Cerró'], $A['id'], null, ['cambios' => ['rol' => 'soporte']])));
$db->exec("UPDATE superadmins SET activo = false WHERE email = 'dimitryfierro@gmail.com'");
check('Super usuario desactivado → 403', 403, $ej('borrar_cliente', ['confirmar' => 'Barbería Para Borrar', 'motivo' => 'Cerró'], $A['id'])[0]);
$db->exec("UPDATE superadmins SET activo = true WHERE email = 'dimitryfierro@gmail.com'");
check('… nada se borró ni se copió', [null, 0], [$db->query("SELECT eliminado_en FROM salones WHERE id = {$A['id']}")->fetchColumn() ?: null, count(glob("$COPIAS/*.json.gz") ?: [])]);
$opB = bin2hex(random_bytes(16));
$r = $ej('borrar_cliente', ['confirmar' => 'Barbería Para Borrar', 'motivo' => 'Cerró el local'], $A['id'], $opB);
$copia = $r[1]['resultado']['copia'] ?? '';
check('Borrar: 200 con copia y fecha del borrado definitivo', [200, false, $copia, $d('+30 days')], [$r[0], $r[1]['sin_cambios'], $r[1]['copia'] ?? null, $r[1]['borrar_definitivo_el'] ?? null]);
check('Borrar: copia en la carpeta de borrados, 600 y carpeta 700', [true, true, '600', '700'],
      [is_file($copia), str_starts_with($copia, "$COPIAS/"), substr(sprintf('%o', fileperms($copia)), -3), substr(sprintf('%o', fileperms($COPIAS)), -3)]);
$f = $db->query("SELECT eliminado_en IS NOT NULL AS borrado, eliminado_motivo, eliminado_por, eliminado_copia FROM salones WHERE id = {$A['id']}")->fetch();
check('Borrar: marcado en la base', [true, 'Cerró el local', 'Dimitry <dimitryfierro@gmail.com>', $copia], [$f['borrado'], $f['eliminado_motivo'], $f['eliminado_por'], $f['eliminado_copia']]);
check('Borrar: auditoría', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE salon_id = {$A['id']} AND accion LIKE 'Panel Tukán Central · Enviado a la papelera: Cerró el local%'"));
$exp = Papelera::leerCopia($copia);
check('Copia: producto, salón y nombre', ['tusalon', $A['id'], 'Barbería Para Borrar'], [$exp['producto'], $exp['salon_id'], $exp['nombre']]);
$enCopia = array_keys($exp['tablas']);
sort($enCopia);
check('Copia: TODAS las tablas del salón (según el esquema) están en la copia', $esperadas, $enCopia);
$faltan = [];
foreach ($db->query("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'salon_id'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $n = $cuenta("SELECT count(*) FROM \"$t\" WHERE salon_id = {$A['id']}" . ($t === 'registro_admin' ? " AND accion NOT LIKE '%Enviado a la papelera%'" : ''));
    if (count($exp['tablas'][$t] ?? []) !== $n) $faltan[] = "$t ($n en la base, " . count($exp['tablas'][$t] ?? []) . ' en la copia)';
    foreach ($exp['tablas'][$t] ?? [] as $fila) if ((int) $fila['salon_id'] !== $A['id']) $faltan[] = "$t: fila de otro salón";
}
check('Copia: cada tabla con salon_id tiene todas sus filas y solo las de este salón', [], $faltan);
check('Copia: filas de tablas hijas (cita_servicios, venta_items, servicio_profesional)',
      [1, 1, $cuenta("SELECT count(*) FROM servicio_profesional sp JOIN servicios s ON s.id = sp.servicio_id WHERE s.salon_id = {$A['id']}")],
      [count($exp['tablas']['cita_servicios']), count($exp['tablas']['venta_items']), count($exp['tablas']['servicio_profesional'])]);
check('Copia: hay filas en más de 12 tablas', true, count(array_filter($exp['cuantas'])) > 12);
check('Copia: lista de fotos subidas con su ruta (y ninguna de otro salón)', [true, false],
      [in_array($carpeta('fotos') . '/' . $A['foto'], array_column($exp['archivos'], 'ruta'), true),
       in_array($carpeta('fotos') . '/' . $B['foto'], array_column($exp['archivos'], 'ruta'), true)]);
check('Copia: nada de otro salón dentro', false, str_contains(gzdecode(file_get_contents($copia)), 'Salón Que Se Queda'));

// ---------------------------------------------------------------------------------------------
titulo('5. En la papelera: desaparece de entradas, listas, cifras, páginas y tareas');
$c = $clientes();
check('clientes: ya no sale; contadores = estado v1 (3 vivos); papelera_total 1', [false, 3, 3, 1],
      [in_array($A['id'], $idsDe($c), true), $c['contadores']['total'], (new TukanCentral($db))->estado($hoy)['negocios']['total'], $c['papelera_total']]);
check('clientes con ids / buscar: vacío', [[], []], [$idsDe($clientes(['ids' => [(string) $A['id']]])), $idsDe($clientes(['buscar' => 'Para Borrar']))]);
$pp = $clientes(['filtros' => ['estado' => 'papelera']]);
check('filtros.estado = papelera: solo él, con papelera {hasta, motivo}', [[$A['id']], $d('+30 days'), 'Cerró el local'],
      [$idsDe($pp), $pp['clientes'][0]['papelera']['hasta'], $pp['clientes'][0]['papelera']['motivo']]);
check('Ficha en la papelera: solo restaurar y ver', ['restaurar_cliente', 'abrir'],
      array_column($pedir('cliente', ['id' => (string) $A['id']])[1]['cliente']['acciones_posibles'], 'accion'));
check('Panel Tukán propio: no sale; filtro papelera sí', [false, [$A['id']], 3],
      [in_array($A['id'], array_map('intval', array_column($panel->salones(), 'id')), true), array_map('intval', array_column($panel->salones('', '', $hoy, true), 'id')),
       $panel->resumen($hoy)['total']]);
check('Solicitudes: ninguna de él', [], array_values(array_filter(array_column($pedir('solicitudes', ['estado' => 'todas', 'por_pagina' => 200])[1]['solicitudes'], 'cliente_id'),
                                                                 fn($x) => $x === (string) $A['id'])));
$fin = $pedir('finanzas', ['desde' => $hoy->modify('-2 months')->format('Y-m'), 'hasta' => $hoy->modify('+2 months')->format('Y-m')])[1];
$porMes = array_column($fin['meses'], null, 'mes');
check('finanzas: el pago de hace 40 días sigue en su mes (4 pagaron)', 4 * 350.0, $porMes[substr($d('-40 days'), 0, 7)]['ingresos']);
check('finanzas: devengado del mes que viene solo de los 3 vivos', true, abs($porMes[$hoy->modify('first day of next month')->format('Y-m')]['devengado'] - 3 * 350 / 12) < 3.0);
check('finanzas: MRR sin él (3 que pagan Completa, $35 al mes cada uno)', 3 * 35.0, $fin['mrr']);
check('estado v1: clientes, usuarios y peluqueros sin los suyos', [$cuenta("SELECT count(*) FROM clientes WHERE salon_id <> {$A['id']}"),
                                                                   $cuenta("SELECT count(*) FROM usuarios WHERE activo AND salon_id <> {$A['id']}")],
      [(new TukanCentral($db))->estado($hoy)['extra']['Clientes'], (new TukanCentral($db))->estado($hoy)['extra']['Usuarios activos']]);
// Páginas públicas: cada página pública es de un salón conocido o no es de ningún salón
$idx = (string) file_get_contents(RAIZ . '/public/index.php');
preg_match('/\$publicas = \[(.*?)\];/s', $idx, $m);
preg_match_all("/'([a-z_]+)'/", $m[1], $mm);
$publicas = $mm[1];
if (preg_match_all('/\$publicas\[\] = \'([a-z_]+)\';/', $idx, $mm3)) $publicas = array_merge($publicas, $mm3[1]);
check('Todas las páginas públicas (' . count($publicas) . ') están cubiertas por la papelera', [],
      array_values(array_diff($publicas, array_keys(Papelera::RUTAS_PUBLICAS), Papelera::PUBLICAS_SIN_SALON)));
$pedidos = ['reservar' => ['s' => $A['slug']], 'horas' => ['s' => $A['slug']], 'cliente_entrar' => ['s' => $A['slug']], 'cliente_salir' => ['s' => $A['slug']],
            'mi_cuenta' => ['s' => $A['slug']], 'foto' => ['id' => (string) $A['foto_id']], 'confirmar' => ['t' => $A['token']],
            'payphone' => ['clientTransactionId' => 'TS' . $A['cita'] . '-0a1b2c3d']];
$mal = [];
foreach (Papelera::RUTAS_PUBLICAS as $ruta => $_) if ($pap->salonDeRuta($ruta, $pedidos[$ruta] ?? []) !== $A['id']) $mal[] = $ruta;
check('Cada página pública encuentra su salón por su enlace (' . count(Papelera::RUTAS_PUBLICAS) . ')', [], $mal);
check('Enlace que no existe: null', [null, null], [$pap->salonDeRuta('confirmar', ['t' => 'no-existe']), $pap->salonDeRuta('login', [])]);
check('Páginas del borrado: "eliminado"; del otro: normal', ['eliminado', 'normal'], [$pap->estadoPublico($A['id']), $pap->estadoPublico($B['id'])]);
check('Operaciones sobre él → 409 en_papelera', [[409, 'conflicto', 'en_papelera'], 409, 409],
      [$codigo($ej('registrar_pago', ['plan' => 'completa', 'periodo' => 'mensual', 'metodo' => 'transferencia'], $A['id'])),
       $ej('pausar', ['tipo' => 'otro', 'motivo' => 'xyz'], $A['id'])[0], $ej('nota_interna', ['texto' => 'x'], $A['id'])[0]]);
$r = $ej('borrar_cliente', ['confirmar' => 'Barbería Para Borrar', 'motivo' => 'Otra vez'], $A['id']);
check('Borrar otra vez: sin_cambios, misma copia, ninguna copia nueva', [200, true, $copia, 1], [$r[0], $r[1]['sin_cambios'], $r[1]['copia'], count(glob("$COPIAS/*.json.gz") ?: [])]);
check('Mismo operacion_id: repetida', [200, true], [($r = $ej('borrar_cliente', ['confirmar' => 'Barbería Para Borrar', 'motivo' => 'Cerró el local'], $A['id'], $opB))[0], $r[1]['repetida']]);
$t = (new Automaticas($db))->correr(new DateTimeImmutable('now'));
check('Tareas: el borrado no recibe tareas (solo B y P)', 2, $t['salones']);
check('Aislamiento: el otro salón no cambió en nada', true, $sinAuditoria($pap->datos($B['id'])) == $sinAuditoria($antesB));
check('Los datos del borrado siguen ahí (borrado lógico)', $antesA['citas'], $pap->datos($A['id'])['citas']);

// ---------------------------------------------------------------------------------------------
titulo('6. restaurar_cliente: vuelve todo tal cual');
check('Restaurar a uno que no está en la papelera: sin_cambios', true, $ej('restaurar_cliente', [], $B['id'])[1]['sin_cambios']);
$r = $ej('restaurar_cliente', [], $A['id']);
check('Restaurar: 200 y fuera de la papelera', [200, false, null], [$r[0], $r[1]['cliente']['eliminado'], $db->query("SELECT eliminado_en FROM salones WHERE id = {$A['id']}")->fetchColumn() ?: null]);
check('Restaurar: todas las filas iguales que antes de borrar', true, $sinAuditoria($pap->datos($A['id'])) == $sinAuditoria($antesA));
check('Restaurar: vuelve a la lista y a las cifras', [true, 4, 0], [in_array($A['id'], $idsDe($clientes()), true), $clientes()['contadores']['total'], $clientes()['papelera_total']]);
check('Restaurar: auditoría', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE salon_id = {$A['id']} AND accion LIKE 'Panel Tukán Central · Restaurado de la papelera%'"));

// ---------------------------------------------------------------------------------------------
titulo('7. Purga a los 30 días (reloj simulado) y copias de más de 1 año');
$r = $ej('borrar_cliente', ['confirmar' => (string) $A['id'], 'motivo' => 'Ahora sí, cerró'], $A['id']);
$copia2 = $r[1]['copia'];
check('Borrar con el número del salón también sirve', [200, false], [$r[0], $r[1]['sin_cambios']]);
check('Restaurar el día 30 → 409 papelera_vencida', [409, 'conflicto', 'papelera_vencida'], $codigo($ej('restaurar_cliente', [], $A['id'], null, ['hoy' => $hoy->modify('+30 days')])));
check('A los 29 días no se purga nada', [0, 1], [$pap->purgar(new DateTimeImmutable('+29 days'))['salones'], $cuenta("SELECT count(*) FROM salones WHERE id = {$A['id']}")]);
$p = $pap->purgar(new DateTimeImmutable('+30 days +1 hour'));
check('A los 30 días se borra de verdad', [1, 0], [$p['salones'], $cuenta("SELECT count(*) FROM salones WHERE id = {$A['id']}")]);
$quedan = [];
foreach ($db->query("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'salon_id'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (($n = $cuenta("SELECT count(*) FROM \"$t\" WHERE salon_id = {$A['id']}")) > 0) $quedan[] = "$t ($n)";
}
check('Purga: no queda ninguna fila suya en las tablas con salon_id', [], $quedan);
check('Purga: tampoco en las hijas (cita_servicios de su cita)', 0, $cuenta("SELECT count(*) FROM cita_servicios WHERE cita_id = {$A['cita']}"));
check('Purga: auditoría sin el salón + línea de borrado definitivo', 1,
      $cuenta("SELECT count(*) FROM registro_admin WHERE salon_id IS NULL AND accion LIKE 'Borrado definitivo%Barbería Para Borrar (N.º {$A['id']})%'"));
check('Purga: el otro salón sigue igual', true, $sinAuditoria($pap->datos($B['id'])) == $sinAuditoria($antesB));
check('Purga: las fotos del borrado pasan junto a su copia; las del otro siguen', [false, true, true],
      [is_file($carpeta('fotos') . '/' . $A['foto']), is_file(preg_replace('/\.json\.gz$/', '', $copia2) . '.archivos/fotos/' . $A['foto']),
       is_file($carpeta('fotos') . '/' . $B['foto'])]);
check('Después de la purga: 404', [404, 'no_encontrado'], array_slice($codigo($ej('restaurar_cliente', [], $A['id'])), 0, 2));
touch($copia, (new DateTimeImmutable('-366 days'))->getTimestamp());
file_put_contents("$COPIAS/LEEME.txt", 'no tocar');
$p = $pap->purgar(new DateTimeImmutable('now'));
check('Copias de más de 1 año: se borra la vieja; quedan la nueva y lo demás', [1, false, true, true], [$p['copias'], is_file($copia), is_file($copia2), is_file("$COPIAS/LEEME.txt")]);
$C = $rico('Salón Para La Tarea');
$ej('borrar_cliente', ['confirmar' => 'Salón Para La Tarea', 'motivo' => 'Prueba de la tarea'], $C['id']);
$db->exec("UPDATE salones SET eliminado_en = now() - interval '31 days' WHERE id = {$C['id']}");
$t = (new Automaticas($db))->correr(new DateTimeImmutable('now'));
check('Tareas automáticas (bin/tareas.php): purgan los de más de 30 días, sin errores', [1, 0, 0],
      [$t['purgados'], $cuenta("SELECT count(*) FROM salones WHERE id = {$C['id']}"), $t['errores']]);

foreach (['TUKAN_CENTRAL_ID', 'TUKAN_CENTRAL_SECRET', 'TUKAN_CENTRAL_URL', 'TUSALON_URL', 'TUSALON_UPLOADS', 'TUSALON_BORRADOS'] as $v) putenv($v);
exec('rm -rf ' . escapeshellarg($TMPP));

echo "\n==================================================\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
foreach ($fallos as $f) echo "  - $f\n";
exit($fallos ? 1 : 0);
