<?php
use TuSalon\{Asistente, Ia};

/**
 * Módulo «Asistente IA» (el enrutador ya revisó que esté encendido: Modulos::RUTAS['asistente']).
 *  - Agendar a partir de un mensaje: borrador de cita con una hora libre de la agenda real (se confirma en Nueva cita).
 *  - Mensaje para clientes que no vuelven: texto de WhatsApp personalizado.
 * Si la IA no se puede usar ahora: el mensaje neutro con el enlace para hacerlo a mano (nunca el motivo).
 */
$sid = (int) $u['salon_id'];
$asis = new Asistente(db(), $sid);
$ahora = new DateTimeImmutable();
$mensaje = '';
$clienteId = (int) ($_POST['cliente_id'] ?? $_GET['cliente_id'] ?? 0);
$borrador = null;
$recuperar = null;
$neutro = null;   // ['seccion' => 'agendar'|'recuperar', 'html' => …]
if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    if (!Ia::puedePreguntar($_SESSION)) {
        aviso('Espere un minuto antes de pedir otra vez, por favor.', 'error');
        redirigir('asistente');
    }
    if ($accion === 'agendar') {
        $mensaje = trim(mb_substr((string) ($_POST['mensaje'] ?? ''), 0, Asistente::MAX_MENSAJE));
        $r = $asis->agendar($mensaje, $ahora);
        if ($r['ok']) $borrador = $r['borrador'];
        else $neutro = ['seccion' => 'agendar', 'html' => $r['motivo'] === 'sin_hora'
            ? 'No encontramos una hora libre para eso en las próximas dos semanas. Puede elegirla a mano en <a href="' . e(url('cita_nueva')) . '">Nueva cita</a>.'
            : Ia::neutroHtml('texto', url('cita_nueva'))];
    } elseif ($accion === 'recuperar') {
        $r = $asis->mensajeRecuperar((int) ($_POST['cliente'] ?? 0), $ahora);
        if ($r['ok']) $recuperar = $r;
        else $neutro = ['seccion' => 'recuperar', 'html' => Ia::neutroHtml('texto', url('avisos_clientes'))];
    }
}
$inactivos = $asis->porRecuperar($ahora);
$clientes = clientes_para_elegir($sid);
vista('asistente', compact('mensaje', 'clienteId', 'borrador', 'recuperar', 'neutro', 'inactivos', 'clientes'), 'Asistente IA · TuSalón');
