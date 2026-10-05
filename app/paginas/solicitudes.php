<?php
use TuSalon\{Agenda, Notificaciones, WhatsApp};

$sid = (int) $u['salon_id'];
$agenda = new Agenda(db());
$notif = new Notificaciones(db());

if (es_post()) {
    verificar_csrf();
    $citaId = (int) ($_POST['cita'] ?? 0);
    $aceptar = ($_POST['accion'] ?? '') === 'aceptar';
    try {
        $agenda->responderSolicitud($sid, $citaId, $u, $aceptar);
        $c = $agenda->cita($sid, $citaId);
        if ($aceptar) {
            aviso('Cita aceptada. Ya está en la agenda.');
            $ini = new DateTimeImmutable($c['inicio']);
            $msg = 'Hola ' . explode(' ', trim((string) $c['cliente']))[0] . ', tu cita en ' . $u['salon'] . ' quedó confirmada: '
                 . Agenda::DIAS[(int) $ini->format('w')] . ' ' . $ini->format('j/n') . ' a las ' . $ini->format('H:i')
                 . ' con ' . $c['profesional'] . '. ¡Te esperamos!';
        } else {
            aviso('Solicitud rechazada. La hora quedó libre.');
            $msg = 'Hola ' . explode(' ', trim((string) $c['cliente']))[0] . ', lo sentimos: no podemos atenderte en la hora que pediste en '
                 . $u['salon'] . '. ¿Te ayudamos a buscar otra hora?';
        }
        $_SESSION['wa_cliente'] = WhatsApp::enlace($c['telefono'], $msg);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('solicitudes');
}

$pendientes = $agenda->solicitudes($sid, $u);
$avisos = $notif->recientes((int) $u['id'], 20);
$notif->marcarLeidas((int) $u['id']);
$waCliente = $_SESSION['wa_cliente'] ?? null;
unset($_SESSION['wa_cliente']);
vista('solicitudes', compact('pendientes', 'avisos', 'waCliente'), 'Solicitudes · TuSalón');
