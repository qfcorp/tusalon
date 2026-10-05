<?php
use TuSalon\{Agenda, Fotos};

/** Fotos de una cita: las sube el dueño o el peluquero que atendió (si el cliente tiene cuenta y aceptó). */
$sid = (int) $u['salon_id'];
$citaId = (int) ($_GET['cita'] ?? 0);
$fotos = new Fotos(db(), Fotos::carpetaPorDefecto());
$cita = (new Agenda(db()))->cita($sid, $citaId);
$volver = es_peluquero($u) ? url('mi_portal', ['p' => 'mes']) : url('cita', ['id' => $citaId]);
if (!$cita || (es_peluquero($u) && (int) $cita['profesional_id'] !== (int) $u['profesional_id'])) {
    aviso('No se encontró la cita.', 'error');
    redirigir(es_peluquero($u) ? 'mi_portal' : 'agenda');
}
if (es_post()) {
    verificar_csrf();
    try {
        if (($_POST['accion'] ?? '') === 'borrar') {
            $fotos->borrar($sid, (int) $_POST['foto'], $u);
            aviso('Foto borrada.');
        } else {
            $fotos->subir($sid, $citaId, $u, $_FILES['foto'] ?? [], (string) ($_POST['momento'] ?? 'despues'));
            aviso('Foto guardada. El cliente ya la ve en su historial.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('fotos', ['cita' => $citaId]);
}
$permiso = null;
try {
    $fotos->verificarPermiso($sid, $citaId, $u);
} catch (RuntimeException $e) {
    $permiso = $e->getMessage();
}
$lista = $fotos->deCita($sid, $citaId);
vista('fotos', compact('cita', 'lista', 'permiso', 'volver'), 'Fotos · TuSalón');
