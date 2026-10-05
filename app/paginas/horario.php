<?php
use TuSalon\Agenda;

$sid = (int) $u['salon_id'];
$agenda = new Agenda(db());
$error = null;

if (es_post()) {
    verificar_csrf();
    $dias = [];
    for ($d = 0; $d <= 6; $d++) {
        $dias[$d] = !empty($_POST['abierto'][$d])
            ? ['abre' => (string) ($_POST['abre'][$d] ?? ''), 'cierra' => (string) ($_POST['cierra'][$d] ?? '')]
            : null;
    }
    $acepta = (string) ($_POST['acepta_reservas'] ?? 'dueno');
    $avisar = (string) ($_POST['avisar_a'] ?? 'ambos');
    $minAceptar = (int) ($_POST['minutos_para_aceptar'] ?? 120);
    $intervalo = (int) ($_POST['intervalo_reservas'] ?? 15);
    $anticipacion = (int) ($_POST['anticipacion_minutos'] ?? 30);
    $horasCancel = (int) ($_POST['horas_cancelacion'] ?? 2);
    $maxFaltas = (int) ($_POST['max_faltas'] ?? 2);
    $semanas = (int) ($_POST['semanas_sin_volver'] ?? 6);
    $google = trim((string) ($_POST['google_resenas_url'] ?? ''));
    try {
        if (!in_array($intervalo, [10, 15, 20, 30, 60], true)) throw new RuntimeException('Intervalo no válido.');
        if ($anticipacion < 0 || $anticipacion > 2880) throw new RuntimeException('La anticipación debe estar entre 0 y 48 horas.');
        if (!in_array($acepta, ['automatico', 'dueno', 'peluquero', 'cualquiera'], true)) throw new RuntimeException('Elige quién acepta las reservas.');
        if (!in_array($avisar, ['dueno', 'peluquero', 'ambos'], true)) throw new RuntimeException('Elige a quién avisar.');
        if (!in_array($minAceptar, [30, 60, 120, 240, 720, 1440], true)) throw new RuntimeException('Tiempo para aceptar no válido.');
        if (!in_array($horasCancel, [0, 1, 2, 3, 6, 12, 24, 48], true)) throw new RuntimeException('Límite para cancelar no válido.');
        if ($maxFaltas < 0 || $maxFaltas > 10) throw new RuntimeException('Faltas permitidas: de 0 a 10.');
        if ($semanas < 2 || $semanas > 52) throw new RuntimeException('Semanas sin volver: de 2 a 52.');
        if ($google !== '' && !preg_match('#^https://[^\s"<>]+$#', $google)) throw new RuntimeException('El enlace de Google debe empezar con https://');
        $agenda->guardarHorario($sid, $dias);
        db()->prepare('UPDATE salones SET intervalo_reservas = ?, anticipacion_minutos = ?, acepta_reservas = ?, avisar_a = ?,
                              minutos_para_aceptar = ?, horas_cancelacion = ?, max_faltas = ?, semanas_sin_volver = ?,
                              google_resenas_url = ? WHERE id = ?')
            ->execute([$intervalo, $anticipacion, $acepta, $avisar, $minAceptar, $horasCancel, $maxFaltas, $semanas, $google ?: null, $sid]);
        aviso('Horario guardado.');
        redirigir('horario');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$horario = $agenda->horario($sid);
$st = db()->prepare('SELECT intervalo_reservas, anticipacion_minutos, acepta_reservas, avisar_a, minutos_para_aceptar, horas_cancelacion, max_faltas, semanas_sin_volver, google_resenas_url FROM salones WHERE id = ?');
$st->execute([$sid]);
$conf = $st->fetch();
$esquema = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$enlace = $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'tusalon.qfradioec.com') . '/?r=reservar&s=' . rawurlencode($u['slug']);
vista('horario', compact('horario', 'conf', 'error', 'enlace'), 'Horario y reservas · TuSalón');
