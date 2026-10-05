<?php

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
        try {
            $alta = (new \TuSalon\Altas(db()))->crearSalonConDueno($d['salon'], $d['nombre'], $d['telefono'], $d['email'], $clave, $d['plan']);
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $alta['usuario_id'];
            $dias = (int) (new \TuSalon\Planes(db()))->ajuste('dias_prueba');
            aviso("¡Listo! Tu salón está creado. Tienes $dias días gratis para probar todo.");
            redirigir('inicio');
        } catch (RuntimeException $e) {
            $errores[] = $e->getMessage();
        }
    }
}
vista('registro', ['errores' => $errores, 'd' => $d], 'Prueba gratis · TuSalón');
