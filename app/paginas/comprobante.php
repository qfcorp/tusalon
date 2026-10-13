<?php
use TuSalon\Anticipos;

/** Foto del comprobante del anticipo: solo para el personal del salón. */
$ruta = (new Anticipos(db()))->rutaComprobante((int) $u['salon_id'], (int) ($_GET['cita'] ?? 0));
if ($ruta === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Comprobante no encontrado.';
    return;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=3600');
readfile($ruta);
