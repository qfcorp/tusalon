<?php
use TuSalon\Automaticas;

$sid = (int) $u['salon_id'];
$auto = new Automaticas(db());
$mes = (string) ($_GET['mes'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) || $mes > date('Y-m')) $mes = date('Y-m');

if (es_post() && es_completa($u)) {
    verificar_csrf();
    $ids = (new TuSalon\Notificaciones(db()))->alDueno($sid,
        Automaticas::textoReporte($auto->reporteMes($sid, $mes), $u['salon']), true);
    $st = db()->prepare('SELECT telegram_chat_id IS NOT NULL FROM usuarios WHERE id = ?');
    $st->execute([$u['id']]);
    aviso($st->fetchColumn() ? 'Reporte enviado a tu Telegram.' : 'Reporte guardado en tus avisos. Conecta tu Telegram en Solicitudes para recibirlo allí.');
    redirigir('reporte', ['mes' => $mes]);
}
$reporte = es_completa($u) ? $auto->reporteMes($sid, $mes) : null;
$meses = [];
for ($i = 0; $i < 12; $i++) {
    $m = date('Y-m', strtotime("first day of -$i month"));
    $meses[$m] = ucfirst(Automaticas::nombreMes($m));
}
vista('reporte', compact('reporte', 'mes', 'meses'), 'Reporte del mes · TuSalón');
