<?php
$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;
$st = $pdo->prepare('SELECT id, nombre FROM profesionales WHERE salon_id = ? AND activo ORDER BY nombre');
$st->execute([$sid]);
$profesionales = $st->fetchAll();
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
        $desc = mb_substr(trim((string) ($_POST['descripcion'] ?? '')), 0, 600);
        // Quién lo hace y a qué precio
        $quien = [];
        $todos = true;
        $marcoQuien = !empty($_POST['quien_enviado']);   // el formulario trae la lista de peluqueros
        foreach ($marcoQuien ? $profesionales : [] as $p) {
            $pid = (int) $p['id'];
            if (empty($_POST['hace'][$pid])) { $todos = false; continue; }
            $pp = trim((string) ($_POST['precio_prof'][$pid] ?? ''));
            $quien[$pid] = $pp === '' ? null : (float) str_replace(',', '.', $pp);
            if ($quien[$pid] !== null) $todos = false;
        }
        if (mb_strlen($nombre) < 2) {
            $error = 'Escribe el nombre del servicio.';
        } elseif ($precio === null || $precio < 0) {
            $error = 'Escribe el precio que cobras al cliente.';
        } elseif ($pago !== null && ($pago < 0 || $pago > $precio)) {
            $error = 'Lo que pagas al peluquero no puede ser mayor que el precio al cliente.';
        } elseif ($min < 5 || $min > 600) {
            $error = 'La duración debe estar entre 5 y 600 minutos.';
        } elseif ($marcoQuien && $profesionales && !$quien) {
            $error = 'Marca al menos un peluquero que haga este servicio.';
        } else {
            try {
                if ($accion === 'crear') {
                    $st = $pdo->prepare('INSERT INTO servicios (salon_id, nombre, descripcion, precio, pago_profesional, duracion_minutos, reserva_online)
                                         VALUES (?,?,?,?,?,?,?) RETURNING id');
                    $st->execute([$sid, $nombre, $desc ?: null, $precio, $pago, $min, $online ? 'true' : 'false']);
                    $servId = (int) $st->fetchColumn();
                    aviso("Servicio \"$nombre\" agregado.");
                } else {
                    $servId = (int) $_POST['id'];
                    $pdo->prepare('UPDATE servicios SET nombre=?, descripcion=?, precio=?, pago_profesional=?, duracion_minutos=?, reserva_online=?
                                    WHERE id=? AND salon_id=?')
                        ->execute([$nombre, $desc ?: null, $precio, $pago, $min, $online ? 'true' : 'false', $servId, $sid]);
                    aviso('Servicio guardado.');
                }
                if ($marcoQuien) (new TuSalon\Agenda($pdo))->guardarServicioProfesionales($sid, $servId, $todos ? [] : $quien);
                redirigir('servicios');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($accion === 'activar') {
        $pdo->prepare('UPDATE servicios SET activo = NOT activo WHERE id = ? AND salon_id = ?')->execute([(int) $_POST['id'], $sid]);
        redirigir('servicios');
    }
}
$st = $pdo->prepare('SELECT * FROM servicios WHERE salon_id = ? AND profesional_id IS NULL ORDER BY activo DESC, nombre');
$st->execute([$sid]);
$servicios = $st->fetchAll();
$quienHace = [];   // servicio_id => [prof_id => precio|null]
$st = $pdo->prepare('SELECT sp.* FROM servicio_profesional sp JOIN servicios s ON s.id = sp.servicio_id WHERE s.salon_id = ?');
$st->execute([$sid]);
foreach ($st->fetchAll() as $r) $quienHace[(int) $r['servicio_id']][(int) $r['profesional_id']] = $r['precio'];
vista('servicios', compact('servicios', 'error', 'profesionales', 'quienHace'), 'Servicios · TuSalón');
