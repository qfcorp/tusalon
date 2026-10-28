<?php
declare(strict_types=1);

/**
 * App de Google Play (28 oct 2026): lo que el servidor necesita para publicar con los planes nuevos.
 *   1. Planes de $20 y $45: los 6 códigos de suscripción que reconoce el servidor y los precios sugeridos en Google
 *      ($23,99 / $117,99 / $235,99 y $52,99 / $264,99 / $529,99), sacados de los precios de la web y sus periodos
 *   2. assetlinks.json: paquete com.tukansoftware.tusalon, la huella de nuestra llave y la de Google agregada después
 *   3. Cuenta del revisor de Google (src/DemoGoogle.php): salón de ejemplo aparte, contraseña al azar, sin pagos de suscripción,
 *      datos inventados; volver a crearlo cambia la contraseña; borrarlo no toca a ningún otro salón
 * Base nueva (borra el esquema). Uso: TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_google_play.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\') && is_file($f = RAIZ . '/src/' . substr($clase, 8) . '.php')) require $f;
});

use TuSalon\{Agenda, Altas, Db, DemoGoogle, GooglePlay, Planes};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.0001 : $esperado === $real;
    if ($igual) { $ok++; echo "  OK    $nombre\n"; }
    else { $fallos[] = $nombre; echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}

if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));
$tmp = sys_get_temp_dir() . '/tusalon_gp_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
putenv("TUSALON_BORRADOS=$tmp/borrados");
putenv("TUSALON_UPLOADS=$tmp/uploads");

echo "\n1. Planes de \$20 y \$45 en Google Play\n";
$planes = new Planes($db);
$precios = array_map('floatval', $db->query('SELECT codigo, precio_mensual FROM planes ORDER BY codigo')->fetchAll(PDO::FETCH_KEY_PAIR));
check('Los planes son Básica $20 y Completa $45', ['basica' => 20.0, 'completa' => 45.0], $precios);
$esperado = ['basica' => ['mensual' => 23.99, 'semestral' => 117.99, 'anual' => 235.99],
             'completa' => ['mensual' => 52.99, 'semestral' => 264.99, 'anual' => 529.99]];
foreach ($esperado as $plan => $porPeriodo) {
    foreach ($porPeriodo as $periodo => $google) {
        $prod = GooglePlay::producto($plan, $periodo);
        check("Código $prod", "tusalon_{$plan}_{$periodo}", $prod);
        check("… el servidor lo reconoce", [$plan, $periodo], GooglePlay::leerProducto($prod));
        $web = $planes->cotizar($plan, $periodo)['monto'];
        check("… precio en Google \$" . number_format($google, 2, ',', '') . " (web \$$web)", $google, GooglePlay::precioSugerido((float) $web));
    }
}
check('Un plan viejo o ajeno no se reconoce', [null, null], [GooglePlay::leerProducto('tusalon_basica_trimestral'), GooglePlay::leerProducto('tuspa_basico_mensual')]);

echo "\n2. assetlinks.json\n";
putenv('GOOGLE_PLAY_PAQUETE');
putenv('TWA_SHA256');
check('Sin huellas: vacío', [], GooglePlay::assetlinks());
$nuestra = '39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC';
putenv("TWA_SHA256=$nuestra");
$al = GooglePlay::assetlinks();
check('Paquete com.tukansoftware.tusalon con la huella de nuestra llave', ['delegate_permission/common.handle_all_urls', 'android_app', 'com.tukansoftware.tusalon', [$nuestra]],
      [$al[0]['relation'][0], $al[0]['target']['namespace'], $al[0]['target']['package_name'], $al[0]['target']['sha256_cert_fingerprints']]);
putenv("TWA_SHA256=$nuestra,AB:CD:EF");   // así queda después del paso de docs/GOOGLE_PLAY.md (huella de Google agregada al final)
check('Con la huella de Google agregada: las dos', [$nuestra, 'AB:CD:EF'], GooglePlay::assetlinks()[0]['target']['sha256_cert_fingerprints']);
putenv('TWA_SHA256');

echo "\n3. Cuenta del revisor de Google\n";
$real = (new Altas($db))->crearSalonConDueno('Barbería Real', 'Dueño Real', '0991112222', 'real@prueba.ec', 'claveSegura1', 'basica');
$agenda = new Agenda($db);
$servReal = (int) $db->query("SELECT id FROM servicios WHERE salon_id = {$real['salon_id']} LIMIT 1")->fetchColumn();
$profReal = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = {$real['salon_id']} LIMIT 1")->fetchColumn();
$manana = (new DateTimeImmutable('tomorrow'))->modify((new DateTimeImmutable('tomorrow'))->format('w') === '0' ? '+1 day' : '+0 day');
$agenda->crearCita($real['salon_id'], $profReal, null, $manana->format('Y-m-d') . ' 10:00', [$servReal]);
$foto = fn() => $db->query("SELECT (SELECT count(*) FROM salones WHERE id = {$real['salon_id']}) || '|' || (SELECT count(*) FROM citas WHERE salon_id = {$real['salon_id']}) || '|' ||
                                   (SELECT count(*) FROM usuarios WHERE salon_id = {$real['salon_id']}) || '|' || (SELECT count(*) FROM servicios WHERE salon_id = {$real['salon_id']})")->fetchColumn();
$antes = $foto();
$demo = new DemoGoogle($db);
check('Antes no hay cuenta del revisor', null, $demo->salonId());
check('La contraseña al azar: Revisor- y 6 números', 1, preg_match('/^Revisor-\d{6}$/', DemoGoogle::claveNueva()));
$r = $demo->crear();
$sid = $r['salon_id'];
$s = $db->query("SELECT * FROM salones WHERE id = $sid")->fetch();
$u = $db->query("SELECT * FROM usuarios WHERE email = '" . DemoGoogle::CORREO . "'")->fetch();
check('Salón aparte, del revisor', [$sid, 'dueno', DemoGoogle::NOMBRE], [(int) $u['salon_id'], $u['rol'], $s['nombre']]);
check('La contraseña que se muestra sirve (y se guarda cifrada)', [true, false], [password_verify($r['clave'], $u['password_hash']), str_contains($u['password_hash'], $r['clave'])]);
check('Plan Completa, activo un año y al día', ['completa', 'activo', (new DateTimeImmutable('today +365 days'))->format('Y-m-d'), true],
      [$s['plan_codigo'], $s['estado'], $s['activo_hasta'], $planes->alDia($s)]);
check('Sin pagos de suscripción (no es un cliente de Tukán)', 0, (int) $db->query("SELECT count(*) FROM suscripciones WHERE salon_id = $sid")->fetchColumn());
$n = fn(string $t, string $extra = '') => (int) $db->query("SELECT count(*) FROM $t WHERE salon_id = $sid $extra")->fetchColumn();
check('Datos de ejemplo: 12 clientes, 4 personas, 8 servicios, 3 productos, 1 paquete', [12, 4, 8, 3, 1],
      [$n('clientes'), $n('profesionales'), $n('servicios'), $n('productos'), $n('paquetes')]);
check('Con cobros de las 2 semanas pasadas y citas por atender', [true, true], [$n('ventas') >= 25, $n('citas', 'AND inicio > now()') > 0]);
check('Los clientes son inventados (teléfonos 09900001xx)', 12, $n('clientes', "AND telefono LIKE '09900001%'"));
check('No tocó el salón real', $antes, $foto());
$r2 = $demo->crear();
check('Volver a crear: un solo salón del revisor, otro id', [1, true], [(int) $db->query("SELECT count(*) FROM usuarios WHERE email = '" . DemoGoogle::CORREO . "'")->fetchColumn(), $r2['salon_id'] !== $sid]);
check('… el anterior se borró entero', [0, 0], [(int) $db->query("SELECT count(*) FROM salones WHERE id = $sid")->fetchColumn(), (int) $db->query("SELECT count(*) FROM citas WHERE salon_id = $sid")->fetchColumn()]);
$u2 = $db->query("SELECT password_hash FROM usuarios WHERE email = '" . DemoGoogle::CORREO . "'")->fetchColumn();
check('… y la contraseña es la nueva', true, password_verify($r2['clave'], $u2));
check('… no tocó el salón real', $antes, $foto());
$citas = $demo->borrar();
check('Borrar: dice cuántas citas tenía', true, $citas > 0);
check('… ya no está el revisor ni su salón', [null, 0], [$demo->salonId(), (int) $db->query("SELECT count(*) FROM salones WHERE id = {$r2['salon_id']}")->fetchColumn()]);
check('… el salón real sigue igual', $antes, $foto());
check('Borrar cuando no hay: 0', 0, $demo->borrar());

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n==================================================\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
foreach ($fallos as $f) echo "  - $f\n";
exit($fallos ? 1 : 0);
