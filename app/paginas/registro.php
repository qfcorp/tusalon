<?php
use TuSalon\{Planes, Profesionales};

if (usuario()) {
    redirigir('inicio');
}
$errores = [];
$d = ['salon' => '', 'nombre' => '', 'telefono' => '', 'email' => '', 'plan' => 'completa'];

if (es_post()) {
    verificar_csrf();
    foreach ($d as $k => $_) {
        $d[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $d['email'] = strtolower($d['email']);
    $clave = (string) ($_POST['clave'] ?? '');

    if (mb_strlen($d['salon']) < 3) $errores[] = 'Escribe el nombre de tu salón o barbería.';
    if (mb_strlen($d['nombre']) < 2) $errores[] = 'Escribe tu nombre.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es válido.';
    if (strlen($clave) < 8) $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    if (!in_array($d['plan'], ['basica', 'completa'], true)) $errores[] = 'Elige un plan.';

    if (!$errores) {
        $st = db()->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $st->execute([$d['email']]);
        if ($st->fetchColumn()) {
            $errores[] = 'Ya existe una cuenta con ese correo. Entra con tu contraseña.';
        }
    }

    if (!$errores) {
        // Dirección corta única del salón (para el enlace de reservas)
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $d['salon']))), '-') ?: 'salon';
        $slug = substr($base, 0, 50);
        $n = 1;
        $chk = db()->prepare('SELECT 1 FROM salones WHERE slug = ?');
        while (true) {
            $chk->execute([$slug]);
            if (!$chk->fetchColumn()) break;
            $slug = substr($base, 0, 46) . '-' . (++$n);
        }

        $pdo = db();
        $planes = new Planes($pdo);
        $salonId = $planes->crearSalon($d['salon'], $slug, $d['plan']);
        $pdo->prepare('UPDATE salones SET telefono = ? WHERE id = ?')->execute([$d['telefono'] ?: null, $salonId]);

        $profId = (new Profesionales($pdo, $planes))->agregar($salonId, $d['nombre'], 'dueno');
        $pdo->prepare('UPDATE profesionales SET telefono = ? WHERE id = ?')->execute([$d['telefono'] ?: null, $profId]);

        $st = $pdo->prepare("INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol)
                             VALUES (?,?,?,?,?, 'dueno') RETURNING id");
        $st->execute([$salonId, $profId, $d['nombre'], $d['email'], password_hash($clave, PASSWORD_DEFAULT)]);
        $usuarioId = (int) $st->fetchColumn();

        // Servicios de ejemplo para empezar (se pueden cambiar)
        $ins = $pdo->prepare('INSERT INTO servicios (salon_id, nombre, precio, duracion_minutos) VALUES (?,?,?,?)');
        foreach ([['Corte de caballero', 6, 30], ['Barba', 4, 20], ['Corte y barba', 9, 45],
                  ['Corte de dama', 10, 45], ['Tinte', 25, 90]] as [$nom, $precio, $min]) {
            $ins->execute([$salonId, $nom, $precio, $min]);
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $usuarioId;
        aviso('¡Listo! Tu salón está creado. Tienes 7 días gratis para probar todo.');
        redirigir('inicio');
    }
}
vista('registro', ['errores' => $errores, 'd' => $d], 'Prueba gratis · TuSalón');
