<?php
declare(strict_types=1);

/**
 * Cuenta de prueba para que Google revise la app de Android "TuSalón" sin ver datos reales.
 * Arma un SALON DE EJEMPLO aparte («Salón de Ejemplo Tukán», plan Completa, sin pagos de suscripción, datos inventados)
 * con la dueña revisor.google@tukanec.com (src/DemoGoogle.php). No toca ningún salón real.
 *
 *   Crear (o volver a crear con fechas de hoy y contraseña nueva):  sudo -u www-data php /var/www/tusalon/deploy/demo_google.php crear
 *   Borrar (cuando Google ya aprobó la app):                        sudo -u www-data php /var/www/tusalon/deploy/demo_google.php borrar
 * La contraseña sale UNA sola vez: cópiala en Play Console -> Contenido de la app -> Acceso a la app.
 * Los mensajes van sin tildes a propósito (se leen bien por SSH desde el celular).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) if (getenv($k) === false) putenv("$k=$v");
}
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuSalon\\') && is_file($f = RAIZ . '/src/' . substr($c, 8) . '.php')) require $f;
});

use TuSalon\DemoGoogle;

$accion = $argv[1] ?? '';
try {
    $demo = new DemoGoogle(TuSalon\Db::conectar());
    if ($accion === 'crear') {
        $r = $demo->crear();
        echo "\nListo: SALON DE EJEMPLO TUKAN (N. {$r['salon_id']}) con {$r['clientes']} clientes y {$r['citas']} citas de ejemplo.\n";
        echo "Datos inventados, sin pagos de suscripcion. No toca ningun salon real.\n";
        echo "\nCopia esto en Google Play Console -> Contenido de la app -> Acceso a la app:\n";
        echo "  Usuario:    " . DemoGoogle::CORREO . "\n";
        echo "  Contrasena: " . $r['clave'] . "\n";
        echo "\n(La contrasena no se guarda en ningun lado: si la pierdes, corre otra vez 'crear'.)\n";
        echo "Pruebala entrando en " . (getenv('TUSALON_URL') ?: 'https://tusalon.tukanec.com') . "\n\n";
        exit(0);
    }
    if ($accion === 'borrar') {
        $n = $demo->borrar();
        echo "Listo: se borro el SALON DE EJEMPLO, sus $n citas y el usuario " . DemoGoogle::CORREO . ".\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nNo se pudo: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Uso: sudo -u www-data php /var/www/tusalon/deploy/demo_google.php crear | borrar\n";
exit(1);
