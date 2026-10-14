<?php
use TuSalon\Agenda;

$sid = (int) $u['salon_id'];
$agenda = new Agenda(db());
$id = (int) ($_GET['id'] ?? 0);

if (es_post() && in_array($_POST['accion'] ?? '', ['anticipo_ok', 'anticipo_no'], true)) {
    verificar_csrf();
    try {
        $ant = new TuSalon\Anticipos(db());
        if ($_POST['accion'] === 'anticipo_ok') {
            $ant->confirmarPago($sid, $id, (string) ($_POST['metodo'] ?? ''));
            aviso('Anticipo confirmado. Entró a la caja de hoy y se descontará al cobrar.');
        } else {
            $ant->rechazarComprobante($sid, $id);
            aviso('Comprobante rechazado. El cliente puede subir otro desde su enlace.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('cita', ['id' => $id]);
}
if (es_post()) {
    verificar_csrf();
    $estado = (string) ($_POST['estado'] ?? '');
    try {
        // "atendida" solo se pone al cobrar
        if (!in_array($estado, ['reservada', 'confirmada', 'no_asistio', 'cancelada'], true)) {
            throw new RuntimeException('Acción no válida.');
        }
        $agenda->cambiarEstado($sid, $id, $estado);
        $textos = ['confirmada' => 'Cita confirmada.', 'no_asistio' => 'Marcada como no asistió.',
                   'cancelada' => 'Cita cancelada.', 'reservada' => 'Cita reabierta.'];
        aviso($textos[$estado] ?? 'Cita actualizada.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('cita', ['id' => $id]);
}

$cita = $agenda->cita($sid, $id);
if ($cita) $cita = ocultar_cliente_privado($cita, $u);
if (!$cita) {
    aviso('No se encontró la cita.', 'error');
    redirigir('agenda');
}
$calificacion = (new TuSalon\Calificaciones(db()))->deCita($id);
vista('cita', ['cita' => $cita, 'calificacion' => $calificacion], 'Cita · TuSalón');
