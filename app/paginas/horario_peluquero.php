<?php
use TuSalon\Agenda;

$sid = (int) $u['salon_id'];
$agenda = new Agenda(db());
$error = null;
$pid = (int) ($_GET['p'] ?? 0);
$st = db()->prepare('SELECT id, nombre FROM profesionales WHERE id = ? AND salon_id = ? AND activo');
$st->execute([$pid, $sid]);
$prof = $st->fetch();
if (!$prof) { aviso('Peluquero no encontrado.', 'error'); redirigir('equipo'); }

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'horario') {
            $dias = [];
            for ($d = 0; $d <= 6; $d++) {
                $dias[$d] = !empty($_POST['trabaja'][$d]) ? [
                    'abre' => (string) ($_POST['abre'][$d] ?? ''), 'cierra' => (string) ($_POST['cierra'][$d] ?? ''),
                    'almuerzo_desde' => (string) ($_POST['alm_desde'][$d] ?? ''), 'almuerzo_hasta' => (string) ($_POST['alm_hasta'][$d] ?? ''),
                ] : null;
            }
            $agenda->guardarHorarioProfesional($sid, $pid, $dias, ($_POST['modo'] ?? '') === 'salon');
            aviso("Horario de {$prof['nombre']} guardado.");
        } elseif ($accion === 'bloqueo') {
            $desde = (string) ($_POST['desde'] ?? '');
            $hasta = (string) ($_POST['hasta'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
                throw new RuntimeException('Elige las fechas.');
            }
            $hDesde = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['hora_desde'] ?? '')) ? $_POST['hora_desde'] : '00:00';
            $hHasta = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['hora_hasta'] ?? '')) ? $_POST['hora_hasta'] : null;
            $fin = $hHasta ? "$hasta $hHasta" : (new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d 00:00');
            $agenda->agregarBloqueo($sid, $pid, "$desde $hDesde", $fin, (string) ($_POST['motivo'] ?? ''));
            aviso('Listo. En esas fechas no se ofrecerán horas de ' . $prof['nombre'] . '.');
        } elseif ($accion === 'borrar_bloqueo') {
            $agenda->borrarBloqueo($sid, (int) ($_POST['id'] ?? 0));
            aviso('Bloqueo quitado.');
        }
        redirigir('horario_peluquero', ['p' => $pid]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$propio = $agenda->horarioProfesional($pid);
$salon = $agenda->horario($sid);
$bloqueos = $agenda->bloqueos($pid);
vista('horario_peluquero', compact('prof', 'propio', 'salon', 'bloqueos', 'error'), 'Horario de ' . $prof['nombre'] . ' · TuSalón');
