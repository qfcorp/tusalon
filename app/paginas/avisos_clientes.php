<?php
use TuSalon\Automaticas;

/** Avisos a clientes: recordatorios de mañana, cumpleaños de hoy y clientes por recuperar (WhatsApp con un toque). */
$sid = (int) $u['salon_id'];
$auto = new Automaticas(db());
$ahora = new DateTimeImmutable();

if (es_post()) {
    verificar_csrf();
    if (($_POST['accion'] ?? '') === 'recordado') {
        $auto->marcarRecordado($sid, (int) ($_POST['cita'] ?? 0));
    } elseif (($_POST['accion'] ?? '') === 'saludado') {
        db()->prepare('UPDATE clientes SET cumple_saludo_anio = ? WHERE id = ? AND salon_id = ?')
            ->execute([(int) $ahora->format('Y'), (int) ($_POST['cliente'] ?? 0), $sid]);
    }
    redirigir('avisos_clientes');
}
$manana = $auto->recordatoriosManana($sid, $ahora);
$cumples = $auto->cumpleanosHoy($sid, $ahora);
$recuperar = es_completa($u) ? $auto->porRecuperar($sid, $ahora) : [];
$st = db()->prepare('SELECT semanas_sin_volver FROM salones WHERE id = ?');
$st->execute([$sid]);
$semanas = (int) $st->fetchColumn();
vista('avisos_clientes', compact('manana', 'cumples', 'recuperar', 'semanas'), 'Avisos a clientes · TuSalón');
