<?php
use TuSalon\{Agenda, Caja, Liquidacion};

$hoy = date('Y-m-d');
$sid = (int) $u['salon_id'];
$citas = (new Agenda(db()))->delDia($sid, $hoy);
$caja = (new Caja(db()))->resumenDia($sid, $hoy);
$resumen = (new Liquidacion(db()))->resumenDueno($sid, $hoy, $hoy);

$pendientes = array_values(array_filter($citas, fn($c) => in_array($c['estado'], ['reservada', 'confirmada'], true)));
$atendidas = count(array_filter($citas, fn($c) => $c['estado'] === 'atendida'));

$solicitudes = count($agendaObj = (new Agenda(db()))->solicitudes($sid, $u));
$auto = new TuSalon\Automaticas(db());
$ahora = new DateTimeImmutable();
$porRecordar = count(array_filter($auto->recordatoriosManana($sid, $ahora), fn($c) => !$c['recordatorio_en'] && !$c['confirmada_cliente_en']));
$cumpleHoy = count(array_filter($auto->cumpleanosHoy($sid, $ahora), fn($c) => !$c['saludado']));
$stockBajo = es_completa($u) ? (new TuSalon\Inventario(db()))->bajos((int) $u['salon_id']) : [];
$st = db()->prepare("SELECT count(*) FROM citas WHERE salon_id = ? AND anticipo_estado = 'por_revisar' AND estado IN ('pendiente','reservada','confirmada')");
$st->execute([(int) $u['salon_id']]);
$comprobantes = (int) $st->fetchColumn();
vista('inicio', compact('citas', 'caja', 'resumen', 'pendientes', 'atendidas', 'solicitudes', 'porRecordar', 'cumpleHoy', 'stockBajo', 'comprobantes'), 'Hoy · TuSalón');
