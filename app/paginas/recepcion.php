<?php
/**
 * Cuentas de recepción (plan Completa): la persona de recepción agenda, cobra, cierra la caja y atiende las solicitudes,
 * pero no ve las ganancias, los gastos, las cuentas del equipo ni la configuración del salón.
 */
$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;
$d = ['nombre' => '', 'email' => ''];

$deRecepcion = function (int $id) use ($pdo, $sid): ?array {
    $st = $pdo->prepare("SELECT id, nombre, email, activo FROM usuarios WHERE id = ? AND salon_id = ? AND rol = 'recepcion'");
    $st->execute([$id, $sid]);
    return $st->fetch() ?: null;
};

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear') {
            $d['nombre'] = trim((string) ($_POST['nombre'] ?? ''));
            $d['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
            $clave = (string) ($_POST['clave'] ?? '');
            if (mb_strlen($d['nombre']) < 2) throw new RuntimeException('Escribe el nombre de la persona de recepción.');
            if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
            if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
            $st->execute([$d['email']]);
            if ($st->fetchColumn()) throw new RuntimeException('Ese correo ya se usa en otra cuenta.');
            $pdo->prepare("INSERT INTO usuarios (salon_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,'recepcion')")
                ->execute([$sid, mb_substr($d['nombre'], 0, 100), $d['email'], password_hash($clave, PASSWORD_DEFAULT)]);
            aviso("Listo. {$d['nombre']} ya puede entrar con {$d['email']}.");
            redirigir('recepcion');
        }
        $r = $deRecepcion((int) ($_POST['id'] ?? 0));
        if (!$r) throw new RuntimeException('No se encontró esa cuenta.');
        if ($accion === 'clave') {
            $clave = (string) ($_POST['clave'] ?? '');
            if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $r['id']]);
            aviso("Contraseña de {$r['nombre']} cambiada.");
        } elseif ($accion === 'desactivar') {
            $pdo->prepare('UPDATE usuarios SET activo = false WHERE id = ?')->execute([$r['id']]);
            aviso("{$r['nombre']} ya no puede entrar.");
        } elseif ($accion === 'activar') {
            $pdo->prepare('UPDATE usuarios SET activo = true WHERE id = ?')->execute([$r['id']]);
            aviso("{$r['nombre']} puede volver a entrar.");
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        redirigir('recepcion');
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) throw $e;
        $error = $e->getMessage();
    }
}

$st = $pdo->prepare("SELECT id, nombre, email, activo, creado_en FROM usuarios WHERE salon_id = ? AND rol = 'recepcion' ORDER BY activo DESC, nombre");
$st->execute([$sid]);
$cuentas = $st->fetchAll();
vista('recepcion', compact('cuentas', 'error', 'd'), 'Recepción · TuSalón');
