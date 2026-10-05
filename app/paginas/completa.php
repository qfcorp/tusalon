<?php
use TuSalon\Planes;

$planes = new Planes(db());
$precios = [];
foreach (['basica', 'completa'] as $p) {
    foreach (['mensual', 'semestral', 'anual'] as $per) {
        $precios[$p][$per] = $planes->cotizar($p, $per);
    }
}
vista('completa', compact('precios'), 'Planes · TuSalón');
