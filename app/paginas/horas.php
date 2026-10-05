<?php
use TuSalon\Agenda;

/**
 * Horas libres en tiempo real (JSON) para el portal de reservas.
 * /?r=horas&s=<salón>&p=<peluquero>&serv=<servicio>&f=<AAAA-MM-DD>
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$st = db()->prepare("SELECT id, plan_codigo, estado, prueba_hasta FROM salones WHERE slug = ?");
$st->execute([(string) ($_GET['s'] ?? '')]);
$salon = $st->fetch();
$fecha = (string) ($_GET['f'] ?? '');
$prof = (int) ($_GET['p'] ?? 0);
$serv = (int) ($_GET['serv'] ?? 0);

$ok = $salon && $salon['plan_codigo'] === 'completa'
    && ($salon['estado'] === 'activo' || ($salon['estado'] === 'prueba' && $salon['prueba_hasta'] >= date('Y-m-d')));
if (!$ok || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')
    || $fecha > date('Y-m-d', strtotime('+60 days')) || !$prof || !$serv) {
    echo json_encode(['horas' => [], 'en_confirmacion' => []]);
    return;
}
$sid = (int) $salon['id'];
$st = db()->prepare('SELECT 1 FROM profesionales WHERE id = ? AND salon_id = ? AND activo');
$st->execute([$prof, $sid]);
$st2 = db()->prepare('SELECT duracion_minutos FROM servicios WHERE id = ? AND salon_id = ? AND activo AND reserva_online');
$st2->execute([$serv, $sid]);
$minutos = $st2->fetchColumn();
if (!$st->fetchColumn() || !$minutos) {
    echo json_encode(['horas' => [], 'en_confirmacion' => []]);
    return;
}
$agenda = new Agenda(db());
$ofrece = array_column($agenda->serviciosDe($sid, $prof, true), 'id');
if (!in_array($serv, array_map('intval', $ofrece), true)) {
    echo json_encode(['horas' => [], 'en_confirmacion' => [], 'cerrado' => true,
                      'mensaje' => 'Ese peluquero no hace este servicio. Elige otro servicio u otro peluquero.']);
    return;
}
$horarioDia = $agenda->horarioDelDia($sid, $prof, $fecha);
$mensaje = null;
if ($horarioDia === null) {
    $mensaje = 'Ese día no atiende. Prueba otro día u otro peluquero.';
} else {
    // ¿Vacaciones o permiso todo el día?
    $st = db()->prepare('SELECT 1 FROM bloqueos WHERE profesional_id = ? AND desde <= ? AND hasta >= ?');
    $st->execute([$prof, "$fecha {$horarioDia['abre']}", "$fecha {$horarioDia['cierra']}"]);
    if ($st->fetchColumn()) $mensaje = 'Ese día no atiende (vacaciones o permiso). Prueba otro día u otro peluquero.';
}
if ($mensaje) {
    echo json_encode(['horas' => [], 'en_confirmacion' => [], 'cerrado' => true, 'mensaje' => $mensaje]);
    return;
}
$dia = $agenda->horasDelDia($sid, $prof, $fecha, (int) $minutos);
echo json_encode([
    'horas'  => $dia['libres'],
    'en_confirmacion' => $dia['en_confirmacion'],
    'cerrado'=> false,
    'hora_servidor' => date('H:i:s'),
]);
