<?php
use TuSalon\{Fidelidad, WhatsApp};

$sid = (int) $u['salon_id'];
$fid = new Fidelidad(db());
$error = null;

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'vender') {
            $t = $fid->venderTarjeta($sid, numero($_POST['monto'] ?? '') ?? 0, $_POST, (string) ($_POST['metodo_pago'] ?? 'efectivo'));
            aviso('Tarjeta de regalo creada. El dinero quedó en la caja de hoy.');
            redirigir('tarjetas', ['ver' => $t['id']]);
        } elseif ($accion === 'anular') {
            $fid->anularTarjeta($sid, (int) ($_POST['tarjeta_id'] ?? 0));
            aviso('Tarjeta anulada.');
            redirigir('tarjetas');
        }
        throw new RuntimeException('Acción no válida.');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$buscar = trim((string) ($_GET['codigo'] ?? ''));
$ver = null;
if ($buscar !== '') {
    $ver = $fid->tarjeta($sid, $buscar);
    if (!$ver) $error = 'No hay ninguna tarjeta con el código ' . Fidelidad::normalizarCodigo($buscar) . '.';
} elseif (!empty($_GET['ver'])) {
    $ver = $fid->tarjetaPorId($sid, (int) $_GET['ver']);
}
$usos = $ver ? $fid->usosTarjeta((int) $ver['id']) : [];
$waTarjeta = null;
if ($ver && $ver['para_telefono']) {
    $texto = '🎁 ' . ($ver['de_nombre'] ? "{$ver['de_nombre']} te regaló" : 'Te regalaron') . ' una tarjeta de regalo de ' . dinero($ver['monto'])
        . " en {$u['salon']}." . ($ver['mensaje'] ? "\n\"{$ver['mensaje']}\"" : '')
        . "\nTu código: {$ver['codigo']}" . ($ver['vence_en'] ? "\nVálida hasta el " . (new DateTimeImmutable($ver['vence_en']))->format('d/m/Y') : '')
        . "\nReserva tu cita y muéstralo al pagar.";
    $waTarjeta = WhatsApp::enlace($ver['para_telefono'], $texto);
}
$tarjetas = $fid->tarjetas($sid);
vista('tarjetas', compact('tarjetas', 'ver', 'usos', 'buscar', 'waTarjeta', 'error'), 'Tarjetas de regalo · TuSalón');
