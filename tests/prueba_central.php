<?php
declare(strict_types=1);

/**
 * Pruebas del conector con el Panel Tukán Central (protocolo v1): configuración, token (firma, versión, tipo,
 * instancia, tiempo, nonce de un solo uso, correo), límite por IP, SSO (crea el super usuario la primera vez y lo
 * reutiliza, registro), estado (mismas cifras que el panel, sin datos personales) y modo "solo central".
 * Borra y recrea la base de prueba (igual que run_tests.php).
 *
 * Uso:
 *   TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_central.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\{Altas, Db, PanelTukan, Planes, TukanCentral, TukanCentralRechazo};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
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
function check_error(string $nombre, string $texto, callable $f): void
{
    global $ok, $fallos;
    try {
        $f();
        $fallos[] = $nombre;
        echo "  FALLA $nombre — esperaba un error con [$texto] y no hubo error\n";
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $texto)) {
            $ok++;
            echo "  OK    $nombre\n";
        } else {
            $fallos[] = $nombre;
            echo "  FALLA $nombre — el error fue [" . $e->getMessage() . "]\n";
        }
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
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));   // se pueden correr muchas veces
$alta = function (string $nombre, string $plan = 'completa') use ($db): array {
    $a = (new Altas($db))->crearSalonConDueno($nombre, 'Dimitry Prueba', '0990000000', 'dueno' . bin2hex(random_bytes(3)) . '@prueba.ec', 'claveSegura1', $plan);
    return [$a['salon_id'], $a['usuario_id']];
};

$hoy = new DateTimeImmutable('today');
$SECRETO = str_repeat('ab12', 16);   // 64 caracteres, como los que da el panel central
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$conectar = function (?string $id = 'tusalon', ?string $secreto = null, ?string $url = 'https://panel.prueba.ec', ?string $solo = null) use ($SECRETO): void {
    putenv($id === null ? 'TUKAN_CENTRAL_ID' : "TUKAN_CENTRAL_ID=$id");
    putenv('TUKAN_CENTRAL_SECRET=' . ($secreto ?? $SECRETO));
    putenv($url === null ? 'TUKAN_CENTRAL_URL' : "TUKAN_CENTRAL_URL=$url");
    putenv($solo === null ? 'TUKAN_SOLO_CENTRAL' : "TUKAN_SOLO_CENTRAL=$solo");
};
/** Token como lo arma el panel central (ejemplo de referencia del protocolo, §7). */
$token = function (array $cambios = [], ?string $secreto = null) use ($SECRETO): string {
    $t = time();
    $p = array_merge(['v' => 1, 'tipo' => 'sso', 'inst' => 'tusalon', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry',
                      'iat' => $t, 'exp' => $t + 60, 'nonce' => bin2hex(random_bytes(16))], $cambios);
    $b = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $a . '.' . $b(hash_hmac('sha256', $a, $secreto ?? $SECRETO, true));
};
$central = new TukanCentral($db);
$rechazo = function (string $nombre, string $texto, string $tok, string $accion = 'sso', ?int $ahora = null) use ($central): void {
    check_error($nombre, $texto, function () use ($central, $tok, $accion, $ahora) {
        try { $central->validar($tok, $accion, $ahora); }
        catch (TukanCentralRechazo $e) { throw $e; }
        catch (Throwable $e) { throw new RuntimeException('Error que no es un rechazo: ' . $e->getMessage()); }
    });
};

titulo('1. Configuración');
$conectar(null, '', null);
check('Sin ID ni secreto: conector apagado', null, TukanCentral::config());
$conectar('tusalon', str_repeat('a', 31));
check('Secreto de menos de 32 caracteres: apagado', null, TukanCentral::config());
$conectar('TuSalon');
check('ID con mayúsculas: apagado', null, TukanCentral::config());
$conectar('x');
check('ID de 1 letra: apagado', null, TukanCentral::config());
$conectar();
check('ID y secreto correctos: prendido', ['id' => 'tusalon', 'secreto' => $SECRETO], TukanCentral::config());
check('URL del panel central', 'https://panel.prueba.ec', TukanCentral::urlCentral());
$conectar('tusalon', null, 'https://panel.prueba.ec/');
check('URL sin la barra final', 'https://panel.prueba.ec', TukanCentral::urlCentral());
$conectar('tusalon', null, 'javascript:alert(1)');
check('URL rara: se usa la del panel oficial', TukanCentral::URL_POR_DEFECTO, TukanCentral::urlCentral());
$conectar();
check('Firma igual al ejemplo del protocolo', true, str_contains($token(), '.'));
$p = ['v' => 1, 'tipo' => 'estado', 'inst' => 'tusalon', 'email' => 'a@b.ec', 'nombre' => 'Ñandú', 'iat' => 1, 'exp' => 2, 'nonce' => str_repeat('0', 32)];
$b = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
check('TukanCentral::firmar = referencia del protocolo', $a . '.' . $b(hash_hmac('sha256', $a, $SECRETO, true)), TukanCentral::firmar($p, $SECRETO));

titulo('2. Token correcto y rechazos (en el orden del protocolo)');
$valido = $central->validar($token(['email' => ' Dimitry@Tukan.EC ']), 'sso');
check('Token correcto entra', 'sso', $valido['tipo']);
check('El correo llega en minúsculas', 'dimitry@tukan.ec', $valido['email']);
check('Token de estado correcto', 'estado', $central->validar($token(['tipo' => 'estado']), 'estado')['tipo']);
$rechazo('Sin token', 'no es válido', '');
$rechazo('Una sola parte', 'no es válido', 'abc');
$rechazo('Tres partes', 'no es válido', $token() . '.x');
$rechazo('Firma mala (otro secreto)', 'firma', $token([], str_repeat('cd34', 16)));
$t = $token();
$rechazo('Firma alterada', 'firma', substr($t, 0, -2) . (str_ends_with($t, 'AA') ? 'BB' : 'AA'));
$rechazo('Contenido cambiado con la firma vieja', 'firma', $b(json_encode(['v' => 1, 'tipo' => 'sso', 'inst' => 'tusalon'])) . '.' . explode('.', $t)[1]);
$basura = $b('esto no es json');
$rechazo('Firma correcta pero contenido que no es JSON', 'no es válido', $basura . '.' . $b(hash_hmac('sha256', $basura, $SECRETO, true)));
$rechazo('Versión 2', 'Versión', $token(['v' => 2]));
$rechazo('Versión "1" como texto', 'Versión', $token(['v' => '1']));
$rechazo('Tipo cambiado (token de estado usado para entrar)', 'acción', $token(['tipo' => 'estado']));
$rechazo('Tipo cambiado (token de entrar usado para el estado)', 'acción', $token(), 'estado');
$rechazo('Otra instancia (inst)', 'otra instalación', $token(['inst' => 'tuevento']));
$ahora = time();
$rechazo('Vencido (más de 30 s después de exp)', 'venció', $token(['iat' => $ahora - 100, 'exp' => $ahora - 40]));
$rechazo('Futuro (iat más de 30 s adelante)', 'futura', $token(['iat' => $ahora + 45, 'exp' => $ahora + 90]));
$rechazo('Dura más de 60 s', 'más de lo permitido', $token(['iat' => $ahora, 'exp' => $ahora + 61]));
$rechazo('exp antes que iat', 'más de lo permitido', $token(['iat' => $ahora, 'exp' => $ahora - 5]));
$rechazo('Fechas como texto', 'fecha válida', $token(['iat' => (string) $ahora]));
$t = $token(['iat' => $ahora - 70, 'exp' => $ahora - 30]);
check('Justo en la tolerancia (ahora = exp + 30) entra', 'sso', $central->validar($t, 'sso', $ahora)['tipo']);
check('Reloj del central 30 s adelantado: entra', 'sso', $central->validar($token(['iat' => $ahora + 30, 'exp' => $ahora + 60]), 'sso', $ahora)['tipo']);
$rechazo('Nonce que no es de 32 hex', 'nonce', $token(['nonce' => 'abc']));
$rechazo('Nonce en mayúsculas', 'nonce', $token(['nonce' => strtoupper(bin2hex(random_bytes(16)))]));
$t = $token();
$central->validar($t, 'sso');
$rechazo('Nonce repetido (el mismo enlace dos veces)', 'ya se usó', $t);
$n = bin2hex(random_bytes(16));
$central->validar($token(['nonce' => $n]), 'sso');
$rechazo('Mismo nonce en otro token bien firmado', 'ya se usó', $token(['nonce' => $n, 'tipo' => 'sso']));
check('Los nonces se guardan con su vencimiento', 1, $cuenta("SELECT count(*) FROM tukan_central_nonces WHERE nonce = '$n' AND vence > now()"));
$db->exec("INSERT INTO tukan_central_nonces (nonce, vence) VALUES ('" . str_repeat('f', 32) . "', now() - interval '1 hour')");
$central->validar($token(), 'sso');
check('Los nonces vencidos se borran', 0, $cuenta("SELECT count(*) FROM tukan_central_nonces WHERE nonce = '" . str_repeat('f', 32) . "'"));
$rechazo('Correo inválido', 'correo', $token(['email' => 'no-es-correo']));
$rechazo('Sin correo', 'correo', $token(['email' => null]));
$rechazo('Correo inválido también en el estado', 'correo', $token(['tipo' => 'estado', 'email' => 'x@']), 'estado');
$conectar(null, '');
$rechazo('Sin configurar: rechaza todo', 'no está configurado', $token());
$conectar();

titulo('3. Límite: 30 pedidos por minuto por IP');
$t0 = 1760371200;   // un minuto exacto
$bien = 0;
for ($i = 0; $i < 30; $i++) $bien += $central->dentroDelLimite('203.0.113.7', $t0 + $i) ? 1 : 0;
check('Los primeros 30 pasan', 30, $bien);
check('El 31 se frena', false, $central->dentroDelLimite('203.0.113.7', $t0 + 40));
check('Otra IP no se frena', true, $central->dentroDelLimite('203.0.113.8', $t0 + 41));
check('Al minuto siguiente vuelve a pasar', true, $central->dentroDelLimite('203.0.113.7', $t0 + 61));
$central->dentroDelLimite('203.0.113.7', $t0 + 3600);
check('Los conteos viejos se borran', 0, $cuenta("SELECT count(*) FROM tukan_central_pedidos WHERE minuto < to_timestamp($t0 + 3000)"));

titulo('4. SSO: busca o crea el super usuario');
$pSso = $central->validar($token(['email' => 'Dimitry@Tukan.ec', 'nombre' => '  Dimitry   Fierro ']), 'sso');
$id1 = $central->superUsuario($pSso, '198.51.100.4');
$a = $db->query("SELECT * FROM superadmins WHERE id = $id1")->fetch();
check('La primera vez crea el super usuario', 1, $cuenta('SELECT count(*) FROM superadmins'));
check('Con el correo en minúsculas', 'dimitry@tukan.ec', $a['email']);
check('Con el nombre del token', 'Dimitry Fierro', $a['nombre']);
check('Marcado como creado por el panel central', true, $a['creado_por_central']);
check('Con contraseña (aleatoria)', true, strlen($a['password_hash']) > 20);
check('Nadie conoce la contraseña (vacía no sirve)', false, password_verify('', $a['password_hash']));
check('Guarda el último ingreso', true, $a['ultimo_ingreso'] !== null);
check('Registro: "Super usuario creado por el Panel Tukán Central"', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE admin_id = $id1 AND accion = 'Super usuario creado por el Panel Tukán Central'"));
check('Registro: "Entró desde el Panel Tukán Central" con la IP', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE admin_id = $id1 AND accion = 'Entró desde el Panel Tukán Central · IP 198.51.100.4'"));
$id2 = $central->superUsuario($central->validar($token(['email' => 'dimitry@tukan.ec', 'nombre' => 'Otro Nombre']), 'sso'));
check('La segunda vez reutiliza el mismo', $id1, $id2);
check('Sigue habiendo uno solo', 1, $cuenta('SELECT count(*) FROM superadmins'));
check('No le cambia el nombre', 'Dimitry Fierro', $db->query("SELECT nombre FROM superadmins WHERE id = $id1")->fetchColumn());
check('Cada entrada queda en el registro', 2, $cuenta("SELECT count(*) FROM registro_admin WHERE admin_id = $id1 AND accion LIKE 'Entró desde el Panel Tukán Central%'"));
// Un super usuario creado antes con bin/crear_admin.php: se usa ese y conserva su contraseña
$db->prepare('INSERT INTO superadmins (nombre, email, password_hash) VALUES (?, ?, ?)')->execute(['Xavier', 'xavier@tukan.ec', password_hash('ClaveLocal1234', PASSWORD_DEFAULT)]);
$idX = $central->superUsuario($central->validar($token(['email' => 'XAVIER@tukan.ec', 'nombre' => 'X']), 'sso'));
check('Usa el super usuario que ya existía', (int) $db->query("SELECT id FROM superadmins WHERE email = 'xavier@tukan.ec'")->fetchColumn(), $idX);
check('No lo marca como creado por el central', false, $db->query("SELECT creado_por_central FROM superadmins WHERE id = $idX")->fetchColumn());
check('Conserva su contraseña local', true, password_verify('ClaveLocal1234', (string) $db->query("SELECT password_hash FROM superadmins WHERE id = $idX")->fetchColumn()));
$idN = $central->superUsuario($central->validar($token(['email' => 'sin.nombre@tukan.ec', 'nombre' => '']), 'sso'));
check('Sin nombre en el token: usa la parte del correo', 'sin.nombre', $db->query("SELECT nombre FROM superadmins WHERE id = $idN")->fetchColumn());
$db->exec("UPDATE superadmins SET activo = false WHERE id = $idX");
check_error('Un super usuario desactivado no entra', 'desactivado', fn() => $central->superUsuario($central->validar($token(['email' => 'xavier@tukan.ec']), 'sso')));
check('Y no se crea otro', 1, $cuenta("SELECT count(*) FROM superadmins WHERE lower(email) = 'xavier@tukan.ec'"));

titulo('5. Estado: mismas cifras que el panel');
$planes = new Planes($db);
$panel = new PanelTukan($db);
$d = fn(string $m) => $hoy->modify($m)->format('Y-m-d');
[$nA] = $alta('Barbería Los Álamos');                 // en prueba, vence en 3 días
$db->prepare('UPDATE salones SET prueba_hasta = ? WHERE id = ?')->execute([$d('+3 days'), $nA]);
[$nB] = $alta('Salón Imperial', 'basica');            // pagó, venció hace 10 días (vencido)
[$nC] = $alta('Peluquería Al Día');                   // al día
[$nD] = $alta('Salón En Gracia', 'basica');           // venció ayer (en gracia)
[$nE] = $alta('Barbería Prueba Terminada', 'basica'); // prueba terminada
$db->prepare('UPDATE salones SET prueba_hasta = ? WHERE id = ?')->execute([$d('-2 days'), $nE]);
[$nF] = $alta('Salón Suspendido', 'basica');
$db->exec("UPDATE salones SET estado = 'suspendido' WHERE id = $nF");
[$nG] = $alta('Salón Cancelado', 'basica');
$db->exec("UPDATE salones SET estado = 'cancelado' WHERE id = $nG");
[$nH] = $alta('Barbería Antigua');                    // en prueba larga, registrada hace 40 días
$db->prepare("UPDATE salones SET creado_en = now() - interval '40 days', prueba_hasta = ? WHERE id = ?")->execute([$d('+20 days'), $nH]);
$mesAnt = $hoy->modify('first day of last month');
$planes->suscribir($nB, 'basica', 'mensual', $hoy->modify('-1 month -10 days'), 'transferencia', null, 25.0);
$db->prepare('UPDATE suscripciones SET pagado_en = ? WHERE salon_id = ?')->execute([$mesAnt->format('Y-m-d'), $nB]);
$db->prepare('UPDATE salones SET activo_hasta = ? WHERE id = ?')->execute([$d('-10 days'), $nB]);
$planes->suscribir($nC, 'completa', 'semestral', $hoy, 'transferencia');   // 5 × 35 = 175
$planes->suscribir($nD, 'basica', 'mensual', $hoy->modify('-1 month'), 'deuna', null, 20.0);
$db->prepare('UPDATE salones SET activo_hasta = ? WHERE id = ?')->execute([$d('-1 day'), $nD]);
$anulado = $planes->suscribir($nA, 'completa', 'mensual', $hoy, 'efectivo', null, 500.0);
$planes->anularPago($anulado);
$db->exec("UPDATE salones SET estado = 'prueba', prueba_hasta = '" . $d('+3 days') . "' WHERE id = $nA");
// Citas de este mes en la Peluquería Al Día
$prof = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = $nC ORDER BY id LIMIT 1")->fetchColumn();
$cli = (int) $db->query("INSERT INTO clientes (salon_id, nombre, telefono) VALUES ($nC, 'Cliente Secreto', '0987654321') RETURNING id")->fetchColumn();
$enMes = $hoy->modify('first day of this month')->modify('+5 days')->format('Y-m-d');
foreach (['atendida', 'reservada', 'cancelada', 'atendida'] as $i => $estado) {
    $h = 9 + $i;
    $db->prepare("INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, estado) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$nC, $cli, $prof, "$enMes $h:00", "$enMes $h:30", $estado]);
}
$otroMes = $hoy->modify('first day of next month')->modify('+3 days')->format('Y-m-d');
$db->prepare("INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, estado) VALUES (?, ?, ?, ?, ?, 'reservada')")
   ->execute([$nC, $cli, $prof, "$otroMes 10:00", "$otroMes 10:30"]);

$e = $central->estado($hoy);
$r = $panel->resumen($hoy);
check('ok y protocolo 1', [true, 1], [$e['ok'], $e['protocolo']]);
check('Producto', ['tusalon', 'TuSalón'], [$e['producto'], $e['nombre']]);
check('Todas las claves del protocolo', ['ok', 'protocolo', 'producto', 'nombre', 'version', 'generado', 'negocios', 'nuevos_30d', 'ingresos_mes',
      'ingresos_mes_anterior', 'pruebas_vencen_7d', 'pagos_vencidos', 'extra'], array_keys($e));
check('Claves de negocios', ['total', 'prueba', 'activos', 'por_vencer', 'vencidos', 'suspendidos'], array_keys($e['negocios']));
check('Fecha con zona horaria', true, (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-05:00$/', $e['generado']));
check('Total = panel', $r['total'], $e['negocios']['total']);
check('Total: 8 salones', 8, $e['negocios']['total']);
check('En prueba = panel', $r['cuenta']['prueba'], $e['negocios']['prueba']);
check('En prueba: 2', 2, $e['negocios']['prueba']);
check('Activos = panel ("Al día")', $r['cuenta']['activo'], $e['negocios']['activos']);
check('Activos: 1', 1, $e['negocios']['activos']);
check('Por vencer = panel (en gracia)', $r['cuenta']['por_vencer'], $e['negocios']['por_vencer']);
check('Por vencer: 1', 1, $e['negocios']['por_vencer']);
check('Vencidos = panel "Sin pagar" (vencido + prueba terminada)', count($r['sin_pagar']), $e['negocios']['vencidos']);
check('Vencidos: 2', 2, $e['negocios']['vencidos']);
check('Suspendidos = suspendidos + cancelados del panel', $r['cuenta']['suspendido'] + $r['cuenta']['cancelado'], $e['negocios']['suspendidos']);
check('Los grupos suman el total', $e['negocios']['total'], array_sum(array_slice($e['negocios'], 1)));
check('Nuevos en 30 días (no cuenta la de hace 40)', 7, $e['nuevos_30d']);
check('Ingresos del mes = "Cobrado este mes" del panel', $r['cobrado_mes'], $e['ingresos_mes']);
check('Ingresos del mes: 175 + 20 (el anulado no cuenta)', 195.0, $e['ingresos_mes']);
check('Ingresos del mes es número decimal', true, is_float($e['ingresos_mes']));
check('Ingresos del mes anterior', 25.0, $e['ingresos_mes_anterior']);
check('Pruebas que vencen en 7 días (solo los nombres y la fecha)', [['nombre' => 'Barbería Los Álamos', 'vence' => $d('+3 days')]], $e['pruebas_vencen_7d']);
check('Pagos vencidos (los "Sin pagar" del panel), del más antiguo', [
    ['nombre' => 'Salón Imperial', 'desde' => $d('-9 days')], ['nombre' => 'Barbería Prueba Terminada', 'desde' => $d('-1 day')]], $e['pagos_vencidos']);
check('Extra: citas de este mes (sin canceladas)', 3, $e['extra']['Citas este mes']);
check('Extra: citas atendidas este mes', 2, $e['extra']['Citas atendidas este mes']);
check('Extra: clientes', $cuenta('SELECT count(*) FROM clientes'), $e['extra']['Clientes']);
check('Extra: peluqueros activos', $cuenta('SELECT count(*) FROM profesionales WHERE activo'), $e['extra']['Peluqueros activos']);
check('Extra: peluqueros (un dueño por salón)', 8, $e['extra']['Peluqueros activos']);
check('Extra: usuarios activos', $cuenta('SELECT count(*) FROM usuarios WHERE activo'), $e['extra']['Usuarios activos']);
check('Extra: ingreso mensual recurrente = panel', round($r['mensual'], 2), $e['extra']['Ingreso mensual recurrente']);
check('Extra: pruebas terminadas sin pagar', 1, $e['extra']['Pruebas terminadas sin pagar']);
check('Extra: cancelados', 1, $e['extra']['Cancelados']);
check('Extra: máximo 8, todos texto → número', true, count($e['extra']) <= 8
    && array_filter($e['extra'], fn($v, $k) => !is_string($k) || !(is_int($v) || is_float($v)), ARRAY_FILTER_USE_BOTH) === []);
$json = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
check('Se puede convertir a JSON', true, is_string($json) && json_decode($json, true)['ok'] === true);
check('JSON: 195.0 con decimales', true, str_contains($json, '"ingresos_mes":195.0'));
$dueno = $db->query("SELECT email FROM usuarios WHERE salon_id = $nA AND rol = 'dueno'")->fetch();
$slug = $db->query("SELECT slug FROM salones WHERE id = $nA")->fetchColumn();
check('Sin correos de los dueños', false, str_contains($json, $dueno['email']) || str_contains($json, '@'));
check('Sin teléfonos', false, str_contains($json, '0990000000') || str_contains($json, '0987654321'));
check('Sin slug ni clientes', false, str_contains($json, '"' . $slug . '"') || str_contains($json, 'Cliente Secreto'));
check('Sin datos de los super usuarios', false, str_contains($json, 'Dimitry') || str_contains($json, 'xavier'));
check('Solo nombres de salones en las listas', true, array_filter(array_merge($e['pruebas_vencen_7d'], $e['pagos_vencidos']),
    fn($x) => array_diff(array_keys($x), ['nombre', 'vence', 'desde']) !== []) === []);
// Máximo 20 en cada lista
$st = $db->prepare("INSERT INTO salones (nombre, slug, plan_codigo, estado, prueba_hasta) VALUES (?, ?, 'basica', 'prueba', ?)");
for ($i = 1; $i <= 22; $i++) $st->execute([sprintf('Prueba Masiva %02d', $i), "masiva-$i", $d('+1 day')]);
for ($i = 1; $i <= 22; $i++) $st->execute([sprintf('Vencida Masiva %02d', $i), "vencida-$i", $d('-5 days')]);
$e = $central->estado($hoy);
check('Pruebas por vencer: máximo 20', 20, count($e['pruebas_vencen_7d']));
check('Pagos vencidos: máximo 20', 20, count($e['pagos_vencidos']));
check('Pero las cifras cuentan todos', [52, 24, 24], [$e['negocios']['total'], $e['negocios']['prueba'], $e['negocios']['vencidos']]);
check('La lista empieza por la que vence antes', 'Prueba Masiva 01', $e['pruebas_vencen_7d'][0]['nombre']);

titulo('6. Versión (commit corto leído de .git, sin ejecutar comandos)');
$esperada = trim((string) shell_exec('git -C ' . escapeshellarg(RAIZ) . ' rev-parse --short=7 HEAD 2>/dev/null'));
check('Versión = commit actual de git', $esperada, TukanCentral::version(RAIZ));
check('Va en el estado', $esperada, $e['version']);
$tmp = sys_get_temp_dir() . '/tc95_' . getmypid();
@mkdir("$tmp/a/.git/refs/heads", 0777, true);
file_put_contents("$tmp/a/.git/HEAD", "ref: refs/heads/main\n");
file_put_contents("$tmp/a/.git/refs/heads/main", str_repeat('1234567890', 4) . "\n");
check('Rama con archivo propio', '1234567', TukanCentral::version("$tmp/a"));
@mkdir("$tmp/b/.git", 0777, true);
file_put_contents("$tmp/b/.git/HEAD", "ref: refs/heads/main\n");
file_put_contents("$tmp/b/.git/packed-refs", "# pack-refs with: peeled\n" . str_repeat('ab', 20) . " refs/heads/otra\n" . str_repeat('cd', 20) . " refs/heads/main\n");
check('Rama en packed-refs', 'cdcdcdc', TukanCentral::version("$tmp/b"));
@mkdir("$tmp/c/.git", 0777, true);
file_put_contents("$tmp/c/.git/HEAD", str_repeat('ef', 20) . "\n");
check('Commit suelto (detached)', 'efefefe', TukanCentral::version("$tmp/c"));
file_put_contents("$tmp/c/.git/HEAD", "ref: refs/heads/../../../etc/passwd\n");
check('Ruta rara: no se lee', 'sin-version', TukanCentral::version("$tmp/c"));
check('Sin .git', 'sin-version', TukanCentral::version("$tmp/nada"));
shell_exec('rm -rf ' . escapeshellarg($tmp));

titulo('7. Ingreso solo desde el panel central (TUKAN_SOLO_CENTRAL)');
$conectar('tusalon', null, 'https://panel.prueba.ec', '1');
check('Con 1 y conector configurado: solo central', true, TukanCentral::soloCentral());
$conectar('tusalon', null, 'https://panel.prueba.ec', '0');
check('Con 0: ingreso local prendido', false, TukanCentral::soloCentral());
$conectar('tusalon', null, 'https://panel.prueba.ec', null);
check('Sin la variable: ingreso local prendido', false, TukanCentral::soloCentral());
$conectar(null, '', null, '1');
check('Con 1 pero sin conector: no se apaga (si no, nadie podría entrar)', false, TukanCentral::soloCentral());

// Dejar el entorno como estaba
foreach (['TUKAN_CENTRAL_ID', 'TUKAN_CENTRAL_SECRET', 'TUKAN_CENTRAL_URL', 'TUKAN_SOLO_CENTRAL'] as $v) putenv($v);

echo "\n==================================================\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
foreach ($fallos as $f) echo "  - $f\n";
exit($fallos ? 1 : 0);
