<?php
if (usuario()) {
    redirigir('inicio');
}
$error = null;
$email = '';
if (es_post()) {
    verificar_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $clave = (string) ($_POST['clave'] ?? '');

    // Freno simple contra intentos repetidos: 5 fallos -> esperar 5 minutos
    $intentos = $_SESSION['intentos_login'] ?? ['n' => 0, 'hasta' => 0];
    if ($intentos['hasta'] > time()) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM usuarios WHERE email = ? AND activo');
        $st->execute([$email]);
        $fila = $st->fetch();
        if ($fila && password_verify($clave, $fila['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $fila['id'];
            unset($_SESSION['intentos_login']);
            redirigir('inicio');
        }
        $intentos['n']++;
        if ($intentos['n'] >= 5) {
            $intentos = ['n' => 0, 'hasta' => time() + 300];
        }
        $_SESSION['intentos_login'] = $intentos;
        $error = 'El correo o la contraseña no coinciden.';
    }
}
vista('login', ['error' => $error, 'email' => $email], 'Entrar · TuSalón');
