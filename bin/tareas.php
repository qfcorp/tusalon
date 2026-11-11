<?php
declare(strict_types=1);

/**
 * Tareas automáticas de TuSalón. Se corre CADA HORA con cron:
 *   5 * * * * php /var/www/tusalon/bin/tareas.php >> /var/log/tusalon-tareas.log 2>&1
 *
 * Hace: recordatorios de mañana (desde las 9:00), cumpleaños (desde las 8:00),
 * pedir calificación tras el servicio, el reporte del mes el día 1 (desde las 7:00) y la papelera del Panel Tukán Central
 * (borra de verdad los salones con más de 30 días en la papelera y las copias de más de 1 año).
 * Corre en el crontab de www-data (así puede borrar las copias de la papelera).
 * Cada cosa se envía una sola vez aunque el cron corra muchas veces.
 *
 * Para probar con otra fecha:  php bin/tareas.php "2026-11-01 08:00"
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');

$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) putenv("$k=$v");
    }
}
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuSalon\\') && is_file($f = RAIZ . '/src/' . substr($c, 8) . '.php')) require $f;
});

// Evita que dos tareas corran a la vez
// (el nombre lleva el usuario: antes corría con el usuario normal y ahora con www-data, que no puede abrir el candado viejo)
$candado = fopen(sys_get_temp_dir() . '/tusalon-tareas' . (function_exists('posix_geteuid') ? '-' . posix_geteuid() : '') . '.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) { echo "Ya hay una tarea corriendo.\n"; exit(0); }

$ahora = new DateTimeImmutable($argv[1] ?? 'now');
$db = TuSalon\Db::conectar();
$r = (new TuSalon\Automaticas($db))->correr($ahora);
// Subir de plan pagando el prorrateo con tarjeta (src/TukanPagoPlan.php): si el dueño pagó y no volvió a abrir Mi plan, el plan se
// aplica aquí. Solo pregunta al Panel Tukán Central por quienes fueron a pagar una mejora en los últimos días; si el Panel no contesta, nada.
require_once RAIZ . '/src/TukanCancelarPlan.php';
require_once RAIZ . '/src/TukanPagoPlan.php';
try {
    $mejoras = TukanPagoPlan::aplicarMejorasPendientes($db, function (string $cid, array $cobro) use ($db): bool {
        $st = $db->prepare('SELECT plan_codigo FROM salones WHERE id = ?');
        $st->execute([(int) $cid]);
        $actual = $st->fetchColumn();
        if ($actual === false || $actual === (string) $cobro['plan']) return true;   // ya no existe o ya lo tiene: nada que hacer
        try {
            (new TuSalon\PanelTukan($db))->cambiarPlan((int) $cid, (string) $cobro['plan']);
        } catch (RuntimeException) {
            return true;   // ese plan ya no existe: no se insiste
        }
        $db->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (NULL, ?, ?)')
           ->execute([(int) $cid, 'Pasó al plan ' . $cobro['plan'] . ': prorrateo pagado con tarjeta (cobro ' . (int) $cobro['id'] . '), aplicado por la tarea de cada hora']);
        return true;
    });
    if ($mejoras) echo "Cambios de plan pagados con tarjeta aplicados: $mejoras\n";
} catch (Throwable $e) {
    echo 'Cambios de plan pagados: ' . $e->getMessage() . "\n";
}
// Planes pagados en la app de Google Play: renovaciones y cancelaciones
$gp = TuSalon\GooglePlay::activo() ? (new TuSalon\GooglePlay($db))->revisarRenovaciones($ahora) : ['renovadas' => 0, 'revisadas' => 0];
echo $ahora->format('Y-m-d H:i') . " · salones {$r['salones']} · recordatorios {$r['recordatorios']} · cumpleaños {$r['cumpleanos']}"
   . " · calificaciones {$r['calificaciones']} · reportes {$r['reportes']} · google play {$gp['renovadas']}/{$gp['revisadas']}"
   . ($r['purgados'] || $r['copias_borradas'] ? " · papelera: borrados {$r['purgados']}, copias viejas {$r['copias_borradas']}" : '')
   . ($r['errores'] ? " · ERRORES {$r['errores']}" : '') . "\n";
