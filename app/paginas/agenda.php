<?php
use TuSalon\Agenda;

$fecha = (string) ($_GET['fecha'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
    $fecha = date('Y-m-d');
}
$sid = (int) $u['salon_id'];
$st = db()->prepare('SELECT id, nombre, tipo, color_agenda FROM profesionales WHERE salon_id = ? AND activo ORDER BY tipo = \'dueno\' DESC, nombre');
$st->execute([$sid]);
$sillas = $st->fetchAll();
$citas = (new Agenda(db()))->delDia($sid, $fecha);

$porSilla = [];
foreach ($citas as $c) {
    $porSilla[$c['profesional_id']][] = $c;
}
$horaInicio = 8;
$horaFin = 21;
vista('agenda', compact('fecha', 'sillas', 'porSilla', 'horaInicio', 'horaFin'), 'Agenda · TuSalón');
