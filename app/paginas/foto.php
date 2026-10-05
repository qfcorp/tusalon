<?php
use TuSalon\Fotos;

/** Entrega una foto solo a quien puede verla (el propio cliente o el personal del salón). */
$ruta = (new Fotos(db(), Fotos::carpetaPorDefecto()))
    ->rutaSiPuedeVer((int) ($_GET['id'] ?? 0), usuario(), (array) ($_SESSION['cliente'] ?? []));
if ($ruta === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Foto no encontrada.';
    return;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=86400');
readfile($ruta);
