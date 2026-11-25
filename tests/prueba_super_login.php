<?php
declare(strict_types=1);

/**
 * Ingreso del super usuario desde el login del programa (Anexo C del protocolo v2): TukanCentral::verificarSuper contra un
 * Panel Central de mentira (tests/panel_super_simulado.php, php -S en un puerto libre). Las pantallas (login.php) están en
 * tests/prueba_web_super_login.sh. No usa la base de datos.
 *
 * Uso: php tests/prueba_super_login.php
 */
define('RAIZ', dirname(__DIR__));
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\TukanCentral;

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    if ($esperado === $real) {
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

titulo('Ingreso del super usuario por el login (verificarSuper)');
$s = stream_socket_server('tcp://127.0.0.1:0');
$PUERTO = (int) substr(stream_socket_get_name($s, false), strrpos(stream_socket_get_name($s, false), ':') + 1);   // puerto libre al azar
fclose($s);
$SECRETO = str_repeat('cd34', 16);
$dirSim = sys_get_temp_dir() . '/panel_super_sim_' . getmypid();
@mkdir($dirSim, 0777, true);
if (@fsockopen('127.0.0.1', $PUERTO)) { check('El puerto de prueba ' . $PUERTO . ' está libre', true, false); return; }
$proc = proc_open(['php', '-S', "127.0.0.1:$PUERTO", RAIZ . '/tests/panel_super_simulado.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dirSim/web.log", 'a'], 2 => ['file', "$dirSim/web.log", 'a']], $tt, null,
    ['PATH' => getenv('PATH'), 'PANEL_SIM_DIR' => $dirSim, 'TUKAN_CENTRAL_ID' => 'tusalon', 'TUKAN_CENTRAL_SECRET' => $SECRETO]);
register_shutdown_function(function () use ($proc, $dirSim) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } exec('rm -rf ' . escapeshellarg($dirSim)); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $PUERTO); $i++) usleep(100000);
$conectar = function (string $secreto = null, string $url = null) use ($SECRETO, $PUERTO) {
    putenv('TUKAN_CENTRAL_ID=tusalon');
    putenv('TUKAN_CENTRAL_SECRET=' . ($secreto ?? $SECRETO));
    putenv('TUKAN_CENTRAL_URL=' . ($url ?? "http://127.0.0.1:$PUERTO"));
};
$log = fn() => array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents("$dirSim/super.log"))));
$conectar();

$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Clave y código buenos: ok con correo y nombre del panel', [true, 'ok', 'dimitry@tukan.ec', 'Dimitry Panel'], [$r['ok'], $r['codigo'], $r['email'] ?? null, $r['nombre'] ?? null]);
$todos = $log(); $ult = end($todos);
check('El panel recibió el token bien firmado (tipo super, vida ≤ 60 s) y la IP de quien escribe', ['super', true, 'dimitry@tukan.ec', '123456', '203.0.113.9'],
      [$ult['tipo'], $ult['vida'] <= 60, $ult['email'], $ult['codigo'], $ult['ip']]);
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '', '203.0.113.9');
check('Sin código: el panel lo pide', [false, 'codigo'], [$r['ok'], $r['codigo']]);
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '000000', '203.0.113.9');
check('Código malo: codigo con el texto del panel', [false, 'codigo', 'El código de 2 pasos no es correcto.'], [$r['ok'], $r['codigo'], $r['error']]);
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'mala', '', '203.0.113.9');
check('Clave mala: credenciales', [false, 'credenciales'], [$r['ok'], $r['codigo']]);
$r = TukanCentral::verificarSuper('bloq@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Bloqueado por intentos', [false, 'bloqueado'], [$r['ok'], $r['codigo']]);
$r = TukanCentral::verificarSuper('sin2@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Sin 2 pasos activado en el panel: sin_2fa', [false, 'sin_2fa'], [$r['ok'], $r['codigo']]);
$conectar(str_repeat('ef56', 16));
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Secreto distinto al del panel: el panel rechaza la firma (no entra)', [false, 'firma'], [$r['ok'], $r['codigo']]);
$conectar();
touch("$dirSim/roto");
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Respuesta que no es JSON: red', [false, 'red'], [$r['ok'], $r['codigo']]);
unlink("$dirSim/roto");
touch("$dirSim/caido");
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Panel en mantenimiento (503, sin JSON): red', [false, 'red'], [$r['ok'], $r['codigo']]);
unlink("$dirSim/caido");
$conectar(null, 'http://127.0.0.1:1');
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Panel apagado (no hay conexión): red, con texto en español', [false, 'red', 'No se pudo hablar con el Panel Tukán. Intenta de nuevo.'], [$r['ok'], $r['codigo'], $r['error']]);
$conectar();
putenv('TUKAN_CENTRAL_ID');
$n = count($log());
$r = TukanCentral::verificarSuper('dimitry@tukan.ec', 'ClaveBuena1', '123456', '203.0.113.9');
check('Conector sin configurar: red y no se llama al panel', [false, 'red', $n], [$r['ok'], $r['codigo'], count($log())]);
$conectar();

echo "\n==================================================\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
exit($fallos ? 1 : 0);
