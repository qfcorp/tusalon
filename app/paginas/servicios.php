<?php
$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;
$num = fn($k) => trim((string) ($_POST[$k] ?? '')) === '' ? null : (float) str_replace(',', '.', (string) $_POST[$k]);

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $precio = $num('precio');
        $pago = $num('pago_profesional');
        $min = (int) ($_POST['duracion_minutos'] ?? 30);
        $online = !empty($_POST['reserva_online']);
        if (mb_strlen($nombre) < 2) {
            $error = 'Escribe el nombre del servicio.';
        } elseif ($precio === null || $precio < 0) {
            $error = 'Escribe el precio que cobras al cliente.';
        } elseif ($pago !== null && ($pago < 0 || $pago > $precio)) {
            $error = 'Lo que pagas al peluquero no puede ser mayor que el precio al cliente.';
        } elseif ($min < 5 || $min > 600) {
            $error = 'La duración debe estar entre 5 y 600 minutos.';
        } elseif ($accion === 'crear') {
            $pdo->prepare('INSERT INTO servicios (salon_id, nombre, precio, pago_profesional, duracion_minutos, reserva_online)
                           VALUES (?,?,?,?,?,?)')
                ->execute([$sid, $nombre, $precio, $pago, $min, $online ? 'true' : 'false']);
            aviso("Servicio \"$nombre\" agregado.");
            redirigir('servicios');
        } else {
            $pdo->prepare('UPDATE servicios SET nombre=?, precio=?, pago_profesional=?, duracion_minutos=?, reserva_online=?
                            WHERE id=? AND salon_id=?')
                ->execute([$nombre, $precio, $pago, $min, $online ? 'true' : 'false', (int) $_POST['id'], $sid]);
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
