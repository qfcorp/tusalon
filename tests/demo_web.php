<?php
declare(strict_types=1);
/**
 * Para las pruebas de pantallas: crea un salón como lo hace Tukán (ya no hay registro con prueba gratis: todo es demo).
 * Uso: php tests/demo_web.php "Salón" "Dueño" telefono correo clave plan [modulos] [pagado]
 *   modulos: vacío = demo con todos · "ninguno" = demo con solo la base · "a,b" = esos módulos
 *   pagado:  "pagado" = plan pagado 30 días (sin demo: tiene los módulos de su plan)
 * Imprime el id del salón. Usa las variables TUSALON_DB_* del sitio de prueba.
 */
define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuSalon\\') && is_file($f = RAIZ . '/src/' . substr($c, 8) . '.php')) require $f;
});
[, $salon, $nombre, $tel, $email, $clave, $plan] = $argv + array_fill(0, 7, '');
$modulos = $argv[7] ?? '';
$db = TuSalon\Db::conectar();
$a = (new TuSalon\Altas($db))->crearSalonConDueno($salon, $nombre, $tel, $email, $clave, $plan ?: 'completa', 'super');
if ($modulos !== '') $db->prepare('UPDATE salones SET modulos_demo = ? WHERE id = ?')->execute([$modulos, $a['salon_id']]);
if (($argv[8] ?? '') === 'pagado') $db->exec("UPDATE salones SET estado = 'activo', activo_hasta = CURRENT_DATE + 30 WHERE id = {$a['salon_id']}");
echo $a['salon_id'], "\n";
