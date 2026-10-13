<?php
use TuSalon\{Agenda, Calificaciones};

/**
 * Enlace personal de cada cita: /?r=confirmar&t=<token>
 * El cliente confirma que viene, cancela, cambia la hora o califica el servicio.
 * No necesita cuenta: el enlace secreto le llega por WhatsApp o Telegram.
 */
$agenda = new Agenda(db());
$token = (string) ($_GET['t'] ?? '');
$cita = $agenda->porToken($token);
if (!$cita) {
    http_response_code(404);
    vista_publica('confirmar_no', [], 'Cita no encontrada · TuSalón');
    return;
}
$st = db()->prepare('SELECT nombre, slug, telefono, google_resenas_url, horas_cancelacion FROM salones WHERE id = ?');
$st->execute([$cita['salon_id']]);
$salon = $st->fetch();
$calif = new Calificaciones(db());
$volver = '/?r=confirmar&t=' . rawurlencode($token);

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'confirmar') {
            $agenda->confirmarPorCliente((int) $cita['id']);
            $_SESSION['aviso_cliente'] = '¡Gracias por confirmar! Te esperamos.';
        } elseif ($accion === 'cancelar') {
            $agenda->cancelarPorCliente((int) $cita['id']);
            $_SESSION['aviso_cliente'] = 'Tu cita quedó cancelada. Gracias por avisar.';
        } elseif ($accion === 'comprobante') {
            (new TuSalon\Anticipos(db()))->subirComprobante((int) $cita['id'], $_FILES['comprobante'] ?? [],
                (string) ($_POST['metodo'] ?? 'transferencia'), (string) ($_POST['referencia'] ?? ''));
            $_SESSION['aviso_cliente'] = '¡Recibimos tu comprobante! El salón lo revisará y te confirmará la cita.';
        } elseif ($accion === 'calificar') {
            $google = $calif->calificar($cita, (int) ($_POST['estrellas'] ?? 0), (string) ($_POST['comentario'] ?? ''));
            $_SESSION['aviso_cliente'] = '¡Gracias por tu calificación!';
            $_SESSION['google_' . $cita['id']] = $google;
        }
    } catch (RuntimeException $e) {
        $_SESSION['aviso_cliente_error'] = $e->getMessage();
    }
    header('Location: ' . $volver);
    exit;
}
$aviso = $_SESSION['aviso_cliente'] ?? null;
$avisoError = $_SESSION['aviso_cliente_error'] ?? null;
$google = $_SESSION['google_' . $cita['id']] ?? null;
unset($_SESSION['aviso_cliente'], $_SESSION['aviso_cliente_error'], $_SESSION['google_' . $cita['id']]);

$noCancelar = $agenda->motivoNoCancelar($cita);
// Anticipo: datos para pagar (transferencia, DeUna o tarjeta con Payphone)
$anticipoConf = null;
$payphone = null;
if (in_array($cita['anticipo_estado'], ['pendiente', 'rechazado', 'por_revisar'], true)
    && in_array($cita['estado'], ['pendiente', 'reservada', 'confirmada'], true)) {
    $antObj = new TuSalon\Anticipos(db());
    $anticipoConf = $antObj->config((int) $cita['salon_id']);
    if (($_GET['pagar'] ?? '') === 'tarjeta' && $anticipoConf['payphone_listo'] && $cita['anticipo_estado'] !== 'por_revisar') {
        try {
            $payphone = $antObj->prepararPayphone((int) $cita['id']);
        } catch (RuntimeException $e) {
            $avisoError = $e->getMessage();
        }
    }
}
$calificacion = $calif->deCita((int) $cita['id']);
vista_publica('confirmar', compact('cita', 'salon', 'aviso', 'avisoError', 'google', 'noCancelar', 'calificacion', 'token', 'anticipoConf', 'payphone'),
              'Tu cita · ' . $salon['nombre']);
