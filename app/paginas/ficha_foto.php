<?php
use TuSalon\FichaTecnica;

/** Foto de la ficha técnica: solo para el personal del salón que puede ver a ese cliente. */
$ruta = (new FichaTecnica(db()))->rutaFoto((int) ($_GET['id'] ?? 0), (string) ($_GET['m'] ?? ''), $u);
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
