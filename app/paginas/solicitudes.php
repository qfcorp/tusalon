<?php
use TuSalon\{Agenda, Notificaciones, WhatsApp};

$sid = (int) $u['salon_id'];
$agenda = new Agenda(db());
$notif = new Notificaciones(db());

$telegram = new \TuSalon\Telegram(db());
if (es_post() && in_array($_POST['accion'] ?? '', ['tg_conectar', 'tg_desconectar'], true)) {
    verificar_csrf();
    if ($_POST['accion'] === 'tg_desconectar') {
        $telegram->desconectar((int) $u['id']);
        aviso('Ya no recibirás avisos en Telegram.');
        redirigir('solicitudes');
    }
    $_SESSION['tg_enlace'] = $telegram->enlaceConectar((int) $u['id']);
    redirigir('solicitudes');
}
if (es_post()) {
    verificar_csrf();
    $citaId = (int) ($_POST['cita'] ?? 0);
    $aceptar = ($_POST['accion'] ?? '') === 'aceptar';
    try {
        $precios = [];
        foreach ((array) ($_POST['precio'] ?? []) as $servId => $v) {
            $v = trim((string) $v);
            $precios[(int) $servId] = $v === '' ? null : str_replace(',', '.', $v);
        }
        $agenda->responderSolicitud($sid, $citaId, $u, $aceptar, $precios);
        $c = $agenda->cita($sid, $citaId);
        if ($aceptar) {
            $msg = $agenda->mensajeConfirmacion($c, $u['salon'], Agenda::urlBase());
            $porTg = $telegram->confirmacionAlCliente($sid, $citaId);
            aviso('Cita aceptada. Ya está en la agenda.' . ($porTg ? ' Al cliente le llegó la confirmación con el valor por Telegram.' : ''));
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
foreach ($pendientes as &$p) {
    $p['lista_servicios'] = $agenda->cita($sid, (int) $p['id'])['lista_servicios'] ?? [];
}
unset($p);
$avisos = $notif->recientes((int) $u['id'], 20);
$notif->marcarLeidas((int) $u['id']);
$waCliente = $_SESSION['wa_cliente'] ?? null;
unset($_SESSION['wa_cliente']);
$st = db()->prepare('SELECT telegram_chat_id IS NOT NULL FROM usuarios WHERE id = ?');
$st->execute([$u['id']]);
$tgConectado = (bool) $st->fetchColumn();
$tgEnlace = $_SESSION['tg_enlace'] ?? null;
unset($_SESSION['tg_enlace']);
$tgDisponible = \TuSalon\Telegram::configurado();
vista('solicitudes', compact('pendientes', 'avisos', 'waCliente', 'tgConectado', 'tgEnlace', 'tgDisponible'), 'Solicitudes · TuSalón');
