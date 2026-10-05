<?php
use TuSalon\Agenda;

/**
 * Portal público de reservas: /?r=reservar&s=<salón>
 * El cliente elige peluquero, servicio, día y una hora libre (calculada en el momento).
 */
$slug = (string) ($_GET['s'] ?? '');
$st = db()->prepare("SELECT id, nombre, slug, telefono, plan_codigo, estado, prueba_hasta FROM salones WHERE slug = ?");
$st->execute([$slug]);
$salon = $st->fetch();

$disponible = $salon && $salon['plan_codigo'] === 'completa'
    && ($salon['estado'] === 'activo' || ($salon['estado'] === 'prueba' && $salon['prueba_hasta'] >= date('Y-m-d')));
if (!$disponible) {
    http_response_code($salon ? 200 : 404);
    vista_publica('reservar_no', ['salon' => $salon], 'Reservas · TuSalón');
    return;
}
$sid = (int) $salon['id'];
$agenda = new Agenda(db());

$st = db()->prepare("SELECT id, nombre, tipo FROM profesionales WHERE salon_id = ? AND activo ORDER BY tipo = 'dueno' DESC, nombre");
$st->execute([$sid]);
$profesionales = $st->fetchAll();
$st = db()->prepare('SELECT id, nombre, precio, duracion_minutos FROM servicios
                      WHERE salon_id = ? AND activo AND reserva_online AND profesional_id IS NULL ORDER BY nombre');
$st->execute([$sid]);
$servicios = $st->fetchAll();
$horario = $agenda->horario($sid);

$error = null;
$d = [
    'profesional' => (int) ($_POST['profesional'] ?? $_GET['p'] ?? 0),
    'servicio'    => (int) ($_POST['servicio'] ?? 0),
    'fecha'       => (string) ($_POST['fecha'] ?? date('Y-m-d')),
    'hora'        => (string) ($_POST['hora'] ?? ''),
    'nombre'      => trim((string) ($_POST['nombre'] ?? '')),
    'telefono'    => trim((string) ($_POST['telefono'] ?? '')),
];

if (es_post()) {
    verificar_csrf();
    try {
        if (!empty($_POST['sitio_web'])) {   // campo trampa para robots
            throw new RuntimeException('No se pudo reservar.');
        }
        $reservas = array_filter($_SESSION['reservas_online'] ?? [], fn($t) => $t > time() - 3600);
        if (count($reservas) >= 3) {
            throw new RuntimeException('Ya hiciste varias reservas. Si necesitas otra, escribe al salón por WhatsApp.');
        }
        if (!in_array($d['profesional'], array_map('intval', array_column($profesionales, 'id')), true)) {
            throw new RuntimeException('Elige con quién quieres tu cita.');
        }
        if (!in_array($d['servicio'], array_map('intval', array_column($servicios, 'id')), true)) {
            throw new RuntimeException('Elige un servicio.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['fecha']) || !preg_match('/^\d{2}:\d{2}$/', $d['hora'])) {
            throw new RuntimeException('Elige el día y la hora.');
        }
        $citaId = $agenda->reservarOnline($sid, $d['profesional'], $d['fecha'], $d['hora'], [$d['servicio']],
                                          $d['nombre'], $d['telefono']);
        $reservas[] = time();
        $_SESSION['reservas_online'] = array_values($reservas);
        $_SESSION['ultima_reserva'] = $citaId;
        header('Location: /?r=reservar&s=' . rawurlencode($slug) . '&ok=1');
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$confirmada = null;
if (isset($_GET['ok']) && !empty($_SESSION['ultima_reserva'])) {
    $confirmada = $agenda->cita($sid, (int) $_SESSION['ultima_reserva']);
}
vista_publica('reservar', compact('salon', 'profesionales', 'servicios', 'horario', 'd', 'error', 'confirmada'),
              'Reserva tu cita · ' . $salon['nombre']);
