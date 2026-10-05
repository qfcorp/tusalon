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
$calificacion = $calif->deCita((int) $cita['id']);
vista_publica('confirmar', compact('cita', 'salon', 'aviso', 'avisoError', 'google', 'noCancelar', 'calificacion', 'token'),
              'Tu cita · ' . $salon['nombre']);
