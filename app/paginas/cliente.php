<?php
$sid = (int) $u['salon_id'];
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$cliente = ['nombre' => '', 'telefono' => '', 'email' => '', 'cumple_mes' => null, 'cumple_dia' => null, 'alergias' => '', 'notas' => ''];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? AND salon_id = ? AND profesional_privado_id IS NULL');
    $st->execute([$id, $sid]);
    $cliente = $st->fetch();
    if (!$cliente) {
        aviso('No se encontró el cliente.', 'error');
        redirigir('clientes');
    }
}
$error = null;
$agenda = new TuSalon\Agenda($pdo);
if (es_post() && ($_POST['accion'] ?? '') === 'perdonar' && $id) {
    verificar_csrf();
    $agenda->perdonarFaltas($sid, $id);
    aviso('Listo. Sus faltas anteriores ya no cuentan y puede volver a reservar en línea.');
    redirigir('cliente', ['id' => $id]);
}
if (es_post()) {
    verificar_csrf();
    foreach (['nombre', 'telefono', 'email', 'alergias', 'notas'] as $k) {
        $cliente[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $cliente['cumple_mes'] = (int) ($_POST['cumple_mes'] ?? 0) ?: null;
    $cliente['cumple_dia'] = (int) ($_POST['cumple_dia'] ?? 0) ?: null;
    try {
        TuSalon\CuentaCliente::validarCumple($cliente['cumple_mes'], $cliente['cumple_dia']);
        $errorCumple = null;
    } catch (RuntimeException $e) {
        $errorCumple = $e->getMessage();
    }
    if ($errorCumple) {
        $error = str_replace(['tu cumpleaños', 'Esa fecha'], ['el cumpleaños', 'Esa fecha'], $errorCumple);
    } elseif (mb_strlen($cliente['nombre']) < 2) {
        $error = 'Escribe el nombre del cliente.';
    } elseif ($cliente['email'] !== '' && !filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no es válido.';
    } else {
        $vals = [$cliente['nombre'], $cliente['telefono'] ?: null, $cliente['email'] ?: null,
                 $cliente['cumple_mes'], $cliente['cumple_dia'], $cliente['alergias'] ?: null, $cliente['notas'] ?: null];
        if ($id) {
            $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, email=?, cumple_mes=?, cumple_dia=?, alergias=?, notas=?
                            WHERE id = ? AND salon_id = ?')->execute(array_merge($vals, [$id, $sid]));
            aviso('Cliente guardado.');
        } else {
            $pdo->prepare('INSERT INTO clientes (nombre, telefono, email, cumple_mes, cumple_dia, alergias, notas, salon_id)
                           VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$sid]));
            aviso('Cliente creado.');
        }
        redirigir('clientes');
    }
}
$historial = [];
if ($id) {
    $st = $pdo->prepare(
        "SELECT c.inicio, c.estado, p.nombre AS profesional,
                (SELECT string_agg(s.nombre, ' + ') FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios
           FROM citas c JOIN profesionales p ON p.id = c.profesional_id
          WHERE c.cliente_id = ? AND c.salon_id = ? ORDER BY c.inicio DESC LIMIT 20"
    );
    $st->execute([$id, $sid]);
    $historial = $st->fetchAll();
}
$faltas = $id ? $agenda->faltas($sid, $id) : 0;
$st = $pdo->prepare('SELECT max_faltas FROM salones WHERE id = ?');
$st->execute([$sid]);
$maxFaltas = (int) $st->fetchColumn();
vista('cliente', compact('cliente', 'id', 'error', 'historial', 'faltas', 'maxFaltas'), 'Cliente · TuSalón');
