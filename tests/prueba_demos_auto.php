<?php
declare(strict_types=1);

/**
 * Demos automáticas de 3 días (src/TukanDemos.php y PedidosDemo, 10 nov 2026): los 6 datos obligatorios del pedido (un solo teléfono: el celular) campo por campo,
 * correo y celular malos, el pedido completo guardado y entregado al Panel con sus claves exactas (acciones «solicitudes» y
 * «solicitud» del conector v2), el aviso al instante al Panel SIMULADO (tests/panel_simulado.php) y qué pasa si el Panel no
 * contesta, la demo de 3 días que crea el Panel y la tarjeta con los dos PDF. Borra y recrea la base de prueba.
 * Uso: lo corre tests/run_logica.sh.
 */
const NS = 'TuSalon';          // espacio de nombres del programa
const TABLA = 'salones';        // tabla de los negocios
const PRE = 'TUSALON';         // prefijo de las variables del programa
const NEG = 'salon';           // columna del nombre del negocio en pedidos_demo (y nombre de antes en el formulario)
define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, NS . '\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, strlen(NS) + 1) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});
require_once RAIZ . '/src/TukanDemos.php';

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
$migs = glob(RAIZ . '/db/migraciones/*.sql') ?: [];
foreach ($migs as $m) $db->exec(file_get_contents($m));
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$bueno = ['nombre' => 'Ana Pérez', 'nombre_comercial' => 'Negocio de Ana', 'celular' => '0991234567',
          'direccion' => 'Av. Amazonas N34-12 y Colón', 'ciudad' => 'Quito', 'email' => 'ana@prueba.ec'];

titulo('1. Los 6 datos obligatorios, campo por campo (un solo teléfono: el celular)');
check('Los obligatorios son 6, sin teléfono fijo', ['nombre', 'nombre_comercial', 'celular', 'direccion', 'ciudad', 'email'], TukanDemos::CAMPOS);
check('Al Panel van además con «telefono» (el mismo celular)', ['nombre', 'nombre_comercial', 'telefono', 'celular', 'direccion', 'ciudad', 'email'], TukanDemos::DETALLE);
check('Un teléfono fijo que llegue de un formulario viejo se ignora (ni se exige ni se valida)', [], TukanDemos::validar(['telefono' => 'no tengo'] + $bueno)[1]);
[$v, $e] = TukanDemos::validar($bueno);
check('Pedido completo: sin errores', [], $e);
check('… y devuelve los 6 datos', TukanDemos::CAMPOS, array_keys($v));
check('Formulario vacío: falta cada uno de los 6', TukanDemos::CAMPOS, array_keys(TukanDemos::validar([])[1]));
foreach (TukanDemos::CAMPOS as $c) {
    check("Falta solo «{$c}»: el error es de ese campo", [$c], array_keys(TukanDemos::validar([$c => ''] + $bueno)[1]));
}
$err = fn(array $cambio) => TukanDemos::validar($cambio + $bueno)[1];
check('Correo malo (sin @)', ['email'], array_keys($err(['email' => 'ana.prueba.ec'])));
check('Correo malo (sin dominio)', ['email'], array_keys($err(['email' => 'ana@'])));
check('… con un mensaje que dice para qué es', true, str_contains($err(['email' => 'malo'])['email'], 'ahí te llega la clave de tu demo'));
check('El correo se pasa a minúsculas y sin espacios', 'ana.perez@prueba.ec', TukanDemos::validar(['email' => '  Ana.Perez@Prueba.EC '] + $bueno)[0]['email']);
check('Celular 09XXXXXXXX: vale', [], $err(['celular' => '0991234567']));
check('Celular +5939XXXXXXXX: vale', [], $err(['celular' => '+593991234567']));
check('Celular con espacios y guiones: se limpia y vale', '0991234567', TukanDemos::validar(['celular' => '099 123-4567'] + $bueno)[0]['celular']);
check('Celular de otro país (9 a 15 números): vale', [], $err(['celular' => '+573001234567']));
check('Celular corto', ['celular'], array_keys($err(['celular' => '09912345'])));
check('Celular de Ecuador que no empieza con 09', ['celular'], array_keys($err(['celular' => '0291234567'])));
check('Celular +593 que no es celular', ['celular'], array_keys($err(['celular' => '+59322345678'])));
check('Celular con letras', ['celular'], array_keys($err(['celular' => '09912abc67'])));
check('Dirección muy corta: no', ['direccion'], array_keys($err(['direccion' => 'Sur'])));
check('Nombre comercial muy corto: no', ['nombre_comercial'], array_keys($err(['nombre_comercial' => 'X'])));
check('El mensaje nombra el negocio de este programa', true, str_contains(TukanDemos::validar([], 'salón')[1]['nombre_comercial'], 'tu salón'));
check('El formulario avisa cuánto tarda', 'Preparar tu demo toma entre 5 y 10 minutos aproximadamente.', TukanDemos::AVISO_MINUTOS);
check('Textos fijos del contrato', ['Te enviaremos la clave de tu demo a este correo: escríbelo bien.', 'La demo dura 3 días.',
      '¡Listo! Estamos preparando tu demo: toma entre 5 y 10 minutos aproximadamente. Cuando esté lista te llega al correo tu clave, el manual y la guía de primeros pasos. Revisa también la carpeta de spam.'], [TukanDemos::AVISO_CORREO, TukanDemos::AVISO_DIAS, TukanDemos::GRACIAS]);

titulo('2. El pedido se guarda solo si está completo');
$PD = $clase('PedidosDemo');
$pd = new $PD($db);
$msg = '';
try { $pd->crear(['nombre' => 'Ana Pérez', 'nombre_comercial' => 'Negocio de Ana', 'ciudad' => 'Quito', 'email' => 'ana@prueba.ec']); } catch (RuntimeException $x) { $msg = $x->getMessage(); }
check('Sin celular ni dirección: rechazado', ['celular', 'direccion'], array_keys($pd->errores));
check('… con el mensaje de cada campo', true, str_contains($msg, 'celular') && str_contains($msg, 'dirección'));
check('… y no se guardó nada', 0, $cuenta('SELECT count(*) FROM pedidos_demo'));
try { $pd->crear(['email' => 'malo'] + $bueno); } catch (RuntimeException) {}
check('Correo malo: rechazado y nada guardado', [['email'], 0], [array_keys($pd->errores), $cuenta('SELECT count(*) FROM pedidos_demo')]);
$id = $pd->crear(['email' => ' ANA@Prueba.ec', 'necesita' => 'Somos 3'] + $bueno, '203.0.113.7');
$f = $db->query("SELECT * FROM pedidos_demo WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
check('Completo: guardado con sus datos (el teléfono es el celular)', ['Ana Pérez', 'Negocio de Ana', '0991234567', '0991234567', 'Av. Amazonas N34-12 y Colón', 'Quito', 'ana@prueba.ec'],
      [$f['nombre'], $f[NEG], $f['telefono'], $f['whatsapp'], $f['direccion'], $f['ciudad'], $f['email']]);
check('… queda como pedido nuevo y sin errores', ['nueva', []], [$f['estado'], $pd->errores]);
check('Guardar un pedido NO crea la cuenta (la crea el Panel)', 0, $cuenta('SELECT count(*) FROM ' . TABLA));
$id2 = $pd->crear(['nombre' => 'Luis Mora', NEG => 'Negocio de Luis', 'whatsapp' => '0987654321', 'direccion' => 'Calle 10 y Av. 4', 'ciudad' => 'Guayaquil', 'email' => 'luis@prueba.ec']);
check('También acepta los nombres de antes («' . NEG . '» y «whatsapp»)', ['Negocio de Luis', '0987654321'], array_values($db->query('SELECT ' . NEG . ", whatsapp FROM pedidos_demo WHERE id = $id2")->fetch(PDO::FETCH_ASSOC)));
check('El mismo correo con el pedido abierto: se actualiza, no se repite', [$id, 'Av. Nueva 55'],
      [$pd->crear(['direccion' => 'Av. Nueva 55'] + $bueno), $db->query("SELECT direccion FROM pedidos_demo WHERE id = $id")->fetchColumn()]);

titulo('3. El Panel lo lee con sus claves exactas: celular y telefono con el mismo valor (conector v2)');
$SECRETO = str_repeat('ab12', 16);
$ID = strtolower(PRE) . '-prueba';
putenv('TUKAN_CENTRAL_ID=' . $ID);
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
putenv(PRE . '_URL=https://programa.prueba.ec');
$TC = $clase('TukanCentral'); $V2 = $clase('TukanCentralV2');
$pedir = function (string $accion, array $cuerpo) use ($db, $TC, $V2, $ID, $SECRETO): array {
    $c = json_encode($cuerpo === [] ? new stdClass() : $cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $t = time();
    $tok = $TC::firmar(['v' => 2, 'tipo' => $accion, 'inst' => $ID, 'email' => 'sistema@tukanec.com', 'nombre' => 'Panel', 'rol' => 'dueno', 'iat' => $t, 'exp' => $t + 30,
                        'nonce' => bin2hex(random_bytes(16)), 'bh' => $TC::b64(hash('sha256', $c, true))], $SECRETO);
    $db->exec('DELETE FROM tukan_central_pedidos');
    return (new $V2($db))->procesar($accion, 'POST', 'application/json; charset=utf-8', $c, $tok, '198.51.100.20');
};
$r = $pedir('solicitudes', ['tipos' => ['demo_pedida'], 'por_pagina' => 50]);
$s = array_values(array_filter($r[1]['solicitudes'] ?? [], fn($x) => $x['id'] === "demo_pedida:$id"))[0] ?? null;
check('«solicitudes»: el pedido sale como demo_pedida pendiente', [200, 'demo_pedida', 'pendiente'], [$r[0], $s['tipo'] ?? null, $s['estado'] ?? null]);
$siete = ['nombre' => 'Ana Pérez', 'nombre_comercial' => 'Negocio de Ana', 'telefono' => '0991234567', 'celular' => '0991234567', 'direccion' => 'Av. Nueva 55', 'ciudad' => 'Quito', 'email' => 'ana@prueba.ec'];
check('… con las claves exactas en el detalle (telefono = celular)', $siete, array_intersect_key(array_merge(array_flip(TukanDemos::DETALLE), $s['detalle'] ?? []), array_flip(TukanDemos::DETALLE)));
check('… y sigue mandando lo propio (lo que necesita, módulos)', ['Somos 3', true], [$s['detalle']['necesita'] ?? null, array_key_exists('modulos', $s['detalle'] ?? [])]);
$r = $pedir('solicitud', ['id' => "demo_pedida:$id"]);
$uno = $r[1]['solicitud'] ?? [];
check('«solicitud» (una sola): las mismas claves', [200, $siete], [$r[0], array_intersect_key(array_merge(array_flip(TukanDemos::DETALLE), $uno['detalle'] ?? []), array_flip(TukanDemos::DETALLE))]);
check('… y trae la acción «crear_demo» con los datos llenos', true, in_array('crear_demo', array_column($uno['acciones_posibles'] ?? [], 'accion'), true));

titulo('4. Aviso al instante al Panel (demo_aviso.php)');
$libre = function (): int { $x = stream_socket_server('tcp://127.0.0.1:0'); $n = stream_socket_get_name($x, false); fclose($x); return (int) substr($n, strrpos($n, ':') + 1); };
$puerto = (int) (getenv('PANEL_SIM_PUERTO') ?: $libre());
$simDir = sys_get_temp_dir() . '/panel_sim_demos_' . strtolower(PRE) . '_' . getmypid();
@mkdir($simDir);
$proc = proc_open(['php', '-S', "127.0.0.1:$puerto", RAIZ . '/tests/panel_simulado.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null,
    ['PANEL_SIM_DIR' => $simDir, 'TUKAN_CENTRAL_ID' => $ID, 'TUKAN_CENTRAL_SECRET' => $SECRETO] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) usleep(100000);
$conf = ['id' => $ID, 'secreto' => $SECRETO, 'url' => "http://127.0.0.1:$puerto"];
$firmar = [$TC, 'firmar'];
$avisos = fn(): array => array_map(fn($l) => json_decode($l, true), is_file("$simDir/avisos_demo.jsonl") ? file("$simDir/avisos_demo.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
check('El Panel recibe el aviso y contesta bien', true, TukanDemos::avisar("demo_pedida:$id", $firmar, $conf));
check('… con la acción y el id de la solicitud (firma, tipo «demo» y cuerpo revisados por el Panel simulado)', [['accion' => 'demo_pedida', 'id' => "demo_pedida:$id"]], $avisos());
check('Secreto equivocado: el Panel lo rechaza, no pasa nada', false, TukanDemos::avisar("demo_pedida:$id", $firmar, ['secreto' => str_repeat('ff00', 16)] + $conf));
check('… y no quedó anotado otro aviso', 1, count($avisos()));
$t0 = microtime(true);
check('Panel caído: devuelve «no avisado» sin romper nada', false, TukanDemos::avisar("demo_pedida:$id", $firmar, ['url' => 'http://127.0.0.1:' . $libre()] + $conf));
check('… y no se queda esperando (menos de 6 segundos)', true, microtime(true) - $t0 < 6);
check('Programa sin conectar al Panel: no avisa', false, TukanDemos::avisar("demo_pedida:$id", $firmar, ['id' => '', 'secreto' => '', 'url' => '']));
check('Si la función de firma falla, tampoco rompe nada', false, TukanDemos::avisar("demo_pedida:$id", fn() => throw new RuntimeException('x'), $conf));
check('El pedido sigue guardado pase lo que pase', 'nueva', $db->query("SELECT estado FROM pedidos_demo WHERE id = $id")->fetchColumn());
proc_terminate($proc);
array_map('unlink', glob("$simDir/*") ?: []);
@rmdir($simDir);

titulo('5. Demos de 3 días');
$Planes = $clase('Planes');
check('El ajuste de fábrica es 3 días', '3', $Planes::AJUSTES['dias_prueba']);
check('Después de las migraciones la base dice 3 días', '3', (new $Planes($db))->ajuste('dias_prueba'));
check('Quedó la marca de «una sola vez»', 1, $cuenta("SELECT count(*) FROM ajustes WHERE clave = 'demos_3_dias'"));
$mig = (string) file_get_contents(current(array_filter($migs, fn($m) => str_ends_with($m, '_demos_auto.sql'))));
$db->exec("UPDATE ajustes SET valor = '7' WHERE clave = 'dias_prueba'");
$db->exec($mig);
check('Si Tukán ya lo cambió (p. ej. a 7), correr otra vez la migración NO lo pisa', '7', $db->query("SELECT valor FROM ajustes WHERE clave = 'dias_prueba'")->fetchColumn());
$db->exec("UPDATE ajustes SET valor = '5' WHERE clave = 'dias_prueba'; DELETE FROM ajustes WHERE clave = 'demos_3_dias'");
$db->exec($mig);
check('Un valor distinto de 7 tampoco se toca la primera vez', '5', $db->query("SELECT valor FROM ajustes WHERE clave = 'dias_prueba'")->fetchColumn());
$db->exec("UPDATE ajustes SET valor = '7' WHERE clave = 'dias_prueba'; DELETE FROM ajustes WHERE clave = 'demos_3_dias'");
$db->exec($mig);
check('El 7 de fábrica pasa a 3 la primera vez', '3', $db->query("SELECT valor FROM ajustes WHERE clave = 'dias_prueba'")->fetchColumn());
// El Panel crea la demo del pedido con «dias: 3»
$planDemo = (string) $db->query('SELECT codigo FROM planes ORDER BY precio_mensual DESC LIMIT 1')->fetchColumn();
$c = json_encode(['operacion_id' => bin2hex(random_bytes(16)), 'accion' => 'crear_demo', 'datos' => ['nombre' => 'Negocio de Ana', 'contacto_nombre' => 'Ana Pérez', 'email' => 'ana@prueba.ec',
    'telefono' => '0991234567', 'plan' => $planDemo, 'dias' => 3, 'solicitud_id' => "demo_pedida:$id"]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$t = time();
$tok = $TC::firmar(['v' => 2, 'tipo' => 'ejecutar', 'inst' => $ID, 'email' => 'sistema@tukanec.com', 'nombre' => 'Panel', 'rol' => 'dueno', 'iat' => $t, 'exp' => $t + 30,
                    'nonce' => bin2hex(random_bytes(16)), 'bh' => $TC::b64(hash('sha256', $c, true))], $SECRETO);
$db->exec('DELETE FROM tukan_central_pedidos');
$r = (new $V2($db))->procesar('ejecutar', 'POST', 'application/json; charset=utf-8', $c, $tok, '198.51.100.20');
$cta = $db->query('SELECT estado, prueba_hasta FROM ' . TABLA . " WHERE nombre = 'Negocio de Ana'")->fetch(PDO::FETCH_ASSOC) ?: [];
check('El Panel crea la demo del pedido: cuenta en demo por 3 días', [true, 'prueba', (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d')], [in_array($r[0], [200, 201], true), $cta['estado'] ?? null, $cta['prueba_hasta'] ?? null]);
check('… y el pedido queda como «demo creada»', 'demo_creada', $db->query("SELECT estado FROM pedidos_demo WHERE id = $id")->fetchColumn());

// «3 días en todo» (Dimitry, 10 nov 2026): también la demo que Tukán crea a mano desde el panel del programa y la del Panel sin decir días
$tres = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
$alt = new ($clase('Altas'))($db);
$crearAlta = current(array_filter(get_class_methods($alt), fn($m) => preg_match('/^crear\w+ConDueno$/', $m) === 1));
$a = $alt->$crearAlta('Demo A Mano', 'Dueña Mano', '0990001111', 'amano@prueba.ec', 'claveSegura1', $planDemo, 'super');
check('Demo creada a mano por Tukán en el panel del programa («Nuevo»): 3 días', ['prueba', $tres], array_values($db->query('SELECT estado, prueba_hasta FROM ' . TABLA . ' WHERE id = ' . (int) reset($a))->fetch(PDO::FETCH_ASSOC)));
$c = json_encode(['operacion_id' => bin2hex(random_bytes(16)), 'accion' => 'crear_demo', 'datos' => ['nombre' => 'Demo Sin Decir Días', 'contacto_nombre' => 'Luis Mora', 'email' => 'sindias@prueba.ec',
    'telefono' => '0987654321', 'plan' => $planDemo]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$t = time();
$tok = $TC::firmar(['v' => 2, 'tipo' => 'ejecutar', 'inst' => $ID, 'email' => 'sistema@tukanec.com', 'nombre' => 'Panel', 'rol' => 'dueno', 'iat' => $t, 'exp' => $t + 30,
                    'nonce' => bin2hex(random_bytes(16)), 'bh' => $TC::b64(hash('sha256', $c, true))], $SECRETO);
$db->exec('DELETE FROM tukan_central_pedidos');
(new $V2($db))->procesar('ejecutar', 'POST', 'application/json; charset=utf-8', $c, $tok, '198.51.100.20');
check('Demo creada por el Panel sin decir cuántos días: 3 días', $tres, $db->query('SELECT prueba_hasta FROM ' . TABLA . " WHERE nombre = 'Demo Sin Decir Días'")->fetchColumn());
$r = $pedir('planes', []);
check('El programa le dice al Panel que sus demos son de 3 días', [3, 3], [$r[1]['ajustes']['dias_demo'] ?? null, $r[1]['ajustes']['dias_prueba'] ?? null]);
$sol = array_values(array_filter($pedir('solicitudes', ['tipos' => ['demo_pedida'], 'por_pagina' => 50])[1]['solicitudes'] ?? [], fn($x) => $x['id'] === "demo_pedida:$id2"))[0] ?? [];
$campoDias = array_values(array_filter(array_merge(...array_map(fn($x) => $x['campos'] ?? [], array_values(array_filter($sol['acciones_posibles'] ?? [], fn($x) => $x['accion'] === 'crear_demo')))), fn($x) => $x['campo'] === 'dias'))[0] ?? [];
check('«Crear demo» de un pedido ya viene con 3 días (se puede alargar)', [3, true], [$campoDias['valor'] ?? null, ($campoDias['max'] ?? 0) >= 30]);
check('Alargar la demo sigue disponible para Tukán', true, in_array('extender_prueba', $V2::OPERACIONES, true));

titulo('6. Tarjeta de la demo y botones de descarga');
$h = TukanDemos::tarjetaDemo('2026-11-13');
check('Tarjeta: «Tu demo dura hasta el dd/mm. Descarga el manual y la guía de primeros pasos»', true, str_contains($h, 'Tu demo dura hasta el 13/11.</strong> Descarga el manual y la guía de primeros pasos.'));
check('… con los dos botones', [true, true], [str_contains($h, 'href="/manual.pdf"'), str_contains($h, 'href="/primeros_pasos.pdf"')]);
$h = TukanDemos::ayuda(true);
check('Ayuda: los mismos dos botones', [true, true, true], [str_contains($h, 'href="/manual.pdf"'), str_contains($h, 'href="/primeros_pasos.pdf"'), str_contains($h, 'Descargue')]);
check('Los dos PDF existen en docs/', [1, 1], [count(glob(RAIZ . '/docs/Manual_*.pdf') ?: []), count(glob(RAIZ . '/docs/*_Primeros_Pasos.pdf') ?: [])]);
check('Si un programa no tiene guía de primeros pasos, no se ofrece ese botón', false, str_contains(TukanDemos::botones(false), 'primeros_pasos'));

echo "\n" . $ok . ' bien, ' . count($fallos) . " mal\n";
exit($fallos ? 1 : 0);
