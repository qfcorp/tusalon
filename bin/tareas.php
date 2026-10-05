<?php
declare(strict_types=1);

/**
 * Tareas automáticas de TuSalón. Se corre CADA HORA con cron:
 *   5 * * * * php /var/www/tusalon/bin/tareas.php >> /var/log/tusalon-tareas.log 2>&1
 *
 * Hace: recordatorios de mañana (desde las 9:00), cumpleaños (desde las 8:00),
 * pedir calificación tras el servicio, y el reporte del mes el día 1 (desde las 7:00).
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
$candado = fopen(sys_get_temp_dir() . '/tusalon-tareas.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) { echo "Ya hay una tarea corriendo.\n"; exit(0); }

$ahora = new DateTimeImmutable($argv[1] ?? 'now');
$r = (new TuSalon\Automaticas(TuSalon\Db::conectar()))->correr($ahora);
echo $ahora->format('Y-m-d H:i') . " · salones {$r['salones']} · recordatorios {$r['recordatorios']} · cumpleaños {$r['cumpleanos']}"
   . " · calificaciones {$r['calificaciones']} · reportes {$r['reportes']}\n";
