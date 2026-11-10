<?php
use TuSalon\FacturaTukan;

/**
 * Factura electrónica con TuFactura (módulo «facturacion», apagado de fábrica).
 * Apagado: explica qué es y deja pedirlo (Mi plan). Encendido: conectar la cuenta de TuFactura, facturar cobros y ver las facturas.
 * ?r=tufactura&pdf=ID baja el PDF de una factura autorizada (con la clave del negocio, que nunca sale al navegador).
 */
$sid = (int) $u['salon_id'];
$activo = FacturaTukan::activo($sid);
$admin = es_dueno_o_admin($u);
if (!$activo) {
    vista('tufactura', ['html' => (new \TukanFactura(db(), 'solo-para-mostrar-la-pagina', FacturaTukan::PROGRAMA))->pantalla($sid, ['modulo' => false, 'programa' => FacturaTukan::PROGRAMA, 'sin_compras' => en_app_play(),
        'pedir' => $admin ? url('completa', ['modulo' => \TukanFactura::MODULO]) : null])], 'Factura electrónica · ' . FacturaTukan::PROGRAMA);
    return;
}
$tf = FacturaTukan::enlace();
if (isset($_GET['pdf'])) {
    try {
        $pdf = $tf->pdf($sid, (int) $_GET['pdf']);
        $d = $tf->documento($sid, (int) $_GET['pdf']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="factura-' . preg_replace('/[^0-9-]/', '', (string) $d['numero']) . '.pdf"');
        header('Cache-Control: private, no-store');
        echo $pdf;
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        redirigir('tufactura');
    }
    return;
}
if (es_post()) {
    verificar_csrf();
    $acc = (string) ($_POST['tf_accion'] ?? '');
    if (!$admin && in_array($acc, ['conectar', 'opciones', 'desconectar'], true)) {
        aviso('Solo el dueño o un administrador conecta TuFactura.', 'error');
    } elseif ($r = $tf->atender($sid, $_POST, fn(string $id) => FacturaTukan::datos($sid, $id), FacturaTukan::ORIGEN)) {
        aviso($r[1], $r[0]);
    }
    $volver = (string) ($_POST['volver'] ?? '');
    if (preg_match('/^[a-z_]{2,30}$/', $volver) && $volver !== 'tufactura') redirigir($volver, array_filter(['id' => (int) ($_POST['volver_id'] ?? 0)]));
    redirigir('tufactura');
}
vista('tufactura', ['html' => $tf->pantalla($sid, ['modulo' => true, 'csrf' => campo_csrf(), 'accion' => url('tufactura'), 'es_admin' => $admin,
    'pdf' => fn(int $id) => url('tufactura', ['pdf' => $id]), 'cobros' => FacturaTukan::cobros($sid), 'origen' => FacturaTukan::ORIGEN,
    'programa' => FacturaTukan::PROGRAMA])], 'Factura electrónica · ' . FacturaTukan::PROGRAMA);
