<?php
declare(strict_types=1);

/**
 * Crea (o cambia la contraseña de) un super administrador de TuSalón (panel Tukán).
 * Se corre en el servidor:
 *   php /var/www/tusalon/bin/crear_admin.php
 * Pregunta nombre, correo y contraseña (la contraseña no se ve al escribir).
 * Para pruebas automáticas: TUSALON_ADMIN_NOMBRE, TUSALON_ADMIN_EMAIL, TUSALON_ADMIN_CLAVE.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('RAIZ', dirname(__DIR__));
$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) putenv("$k=$v");
    }
}
require RAIZ . '/src/Db.php';
$db = TuSalon\Db::conectar();

$preguntar = function (string $texto, bool $oculto = false): string {
    echo $texto;
    if ($oculto && DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $r = trim((string) fgets(STDIN));
    if ($oculto && DIRECTORY_SEPARATOR === '/') { system('stty echo'); echo "\n"; }
    return $r;
};

$nombre = getenv('TUSALON_ADMIN_NOMBRE') ?: $preguntar('Tu nombre: ');
$email = strtolower(getenv('TUSALON_ADMIN_EMAIL') ?: $preguntar('Tu correo (con este entras al panel): '));
$clave = getenv('TUSALON_ADMIN_CLAVE') ?: '';
if ($clave === '') {
    $clave = $preguntar('Elige una contraseña (mínimo 10 caracteres, no se ve al escribir): ', true);
    $otra = $preguntar('Escríbela otra vez: ', true);
    if ($clave !== $otra) { fwrite(STDERR, "Las contraseñas no coinciden. Vuelve a intentar.\n"); exit(1); }
}
if (mb_strlen($nombre) < 2) { fwrite(STDERR, "Escribe tu nombre.\n"); exit(1); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "El correo no es válido.\n"); exit(1); }
if (strlen($clave) < 10) { fwrite(STDERR, "La contraseña debe tener al menos 10 caracteres.\n"); exit(1); }

$st = $db->prepare('INSERT INTO superadmins (nombre, email, password_hash) VALUES (?, ?, ?)
                    ON CONFLICT (email) DO UPDATE SET nombre = EXCLUDED.nombre, password_hash = EXCLUDED.password_hash, activo = true
                    RETURNING (xmax = 0) AS nuevo');
$st->execute([$nombre, $email, password_hash($clave, PASSWORD_DEFAULT)]);
echo $st->fetchColumn() ? "Listo: super usuario creado para $email.\n" : "Listo: contraseña cambiada para $email.\n";
$url = getenv('TUSALON_URL') ?: 'https://tusalon.tukanec.com';
echo "Entra en: $url/?r=admin_entrar\n";
