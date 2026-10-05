<?php
$sid = (int) $u['salon_id'];
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$cliente = ['nombre' => '', 'telefono' => '', 'email' => '', 'fecha_nacimiento' => '', 'alergias' => '', 'notas' => ''];
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
if (es_post()) {
    verificar_csrf();
    foreach (array_keys(['nombre' => 1, 'telefono' => 1, 'email' => 1, 'fecha_nacimiento' => 1, 'alergias' => 1, 'notas' => 1]) as $k) {
        $cliente[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    if (mb_strlen($cliente['nombre']) < 2) {
        $error = 'Escribe el nombre del cliente.';
    } elseif ($cliente['email'] !== '' && !filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no es válido.';
    } else {
        $vals = [$cliente['nombre'], $cliente['telefono'] ?: null, $cliente['email'] ?: null,
                 $cliente['fecha_nacimiento'] ?: null, $cliente['alergias'] ?: null, $cliente['notas'] ?: null];
        if ($id) {
            $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, email=?, fecha_nacimiento=?, alergias=?, notas=?
                            WHERE id = ? AND salon_id = ?')->execute(array_merge($vals, [$id, $sid]));
            aviso('Cliente guardado.');
        } else {
            $pdo->prepare('INSERT INTO clientes (nombre, telefono, email, fecha_nacimiento, alergias, notas, salon_id)
                           VALUES (?,?,?,?,?,?,?)')->execute(array_merge($vals, [$sid]));
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
vista('cliente', compact('cliente', 'id', 'error', 'historial'), 'Cliente · TuSalón');
