<?php
$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $precio = (float) str_replace(',', '.', (string) ($_POST['precio'] ?? ''));
        $min = (int) ($_POST['duracion_minutos'] ?? 30);
        if (mb_strlen($nombre) < 2 || $precio < 0 || $min < 5 || $min > 600) {
            $error = 'Revisa el nombre, el precio y la duración (entre 5 y 600 minutos).';
        } elseif ($accion === 'crear') {
            $pdo->prepare('INSERT INTO servicios (salon_id, nombre, precio, duracion_minutos) VALUES (?,?,?,?)')
                ->execute([$sid, $nombre, $precio, $min]);
            aviso('Servicio agregado.');
            redirigir('servicios');
        } else {
            $pdo->prepare('UPDATE servicios SET nombre=?, precio=?, duracion_minutos=? WHERE id=? AND salon_id=?')
                ->execute([$nombre, $precio, $min, (int) $_POST['id'], $sid]);
            aviso('Servicio guardado.');
            redirigir('servicios');
        }
    } elseif ($accion === 'activar') {
        $pdo->prepare('UPDATE servicios SET activo = NOT activo WHERE id = ? AND salon_id = ?')->execute([(int) $_POST['id'], $sid]);
        redirigir('servicios');
    }
}
$st = $pdo->prepare('SELECT * FROM servicios WHERE salon_id = ? AND profesional_id IS NULL ORDER BY activo DESC, nombre');
$st->execute([$sid]);
$servicios = $st->fetchAll();
vista('servicios', compact('servicios', 'error'), 'Servicios · TuSalón');
