<?php
use TuSalon\Caja;

$sid = (int) $u['salon_id'];
$fecha = (string) ($_GET['fecha'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}
$caja = new Caja(db());

if (es_post()) {
    verificar_csrf();
    $contado = (float) str_replace(',', '.', (string) ($_POST['efectivo_contado'] ?? ''));
    try {
        $r = $caja->cerrar($sid, $fecha, $contado);
        $dif = $r['diferencia'];
        aviso($dif == 0 ? 'Caja cerrada. El efectivo cuadra.'
            : 'Caja cerrada con una diferencia de ' . dinero($dif) . ($dif < 0 ? ' (falta dinero).' : ' (sobra dinero).'),
            $dif == 0 ? 'ok' : 'error');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('caja', ['fecha' => $fecha]);
}

$resumen = $caja->resumenDia($sid, $fecha);
vista('caja', compact('fecha', 'resumen'), 'Caja · TuSalón');
