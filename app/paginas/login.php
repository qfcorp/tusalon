<?php
if ($yo = usuario()) {
    redirigir(es_peluquero($yo) ? 'mi_portal' : 'inicio');
}
$error = null;
$email = '';
$pedir_codigo = false;
if (es_post()) {
    verificar_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $clave = (string) ($_POST['clave'] ?? '');

    // Freno simple contra intentos repetidos: 5 fallos -> esperar 5 minutos
    $intentos = $_SESSION['intentos_login'] ?? ['n' => 0, 'hasta' => 0];
    if ($intentos['hasta'] > time()) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } else {
        // Un salón en la papelera (Anexo A) ya no puede entrar: para el que intenta, es como si no existiera
        $st = db()->prepare('SELECT u.id, u.password_hash, u.rol FROM usuarios u JOIN salones s ON s.id = u.salon_id
                              WHERE u.email = ? AND u.activo AND s.eliminado_en IS NULL');
        $st->execute([$email]);
        $fila = $st->fetch();
        if ($fila && password_verify($clave, $fila['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $fila['id'];
            unset($_SESSION['intentos_login']);
            redirigir($fila['rol'] === 'profesional' ? 'mi_portal' : 'inicio');
        }
        $error = 'El correo o la contraseña no coinciden.';
        $cuentaFallo = true;
        // Súper usuario: mismo correo, clave y código de 2 pasos del Panel Tukán (lo revisa el panel; también sirve en la app de iPhone).
        // Solo se llama al panel cuando el ingreso del cliente falló.
        if (\TuSalon\TukanCentral::config() && filter_var($email, FILTER_VALIDATE_EMAIL) && $clave !== '') {
            $r = \TuSalon\TukanCentral::verificarSuper($email, $clave, trim((string) ($_POST['codigo'] ?? '')), ip_visitante());
            if ($r['ok']) {
                $id = (new \TuSalon\TukanCentral(db()))->superUsuario(['email' => $r['email'], 'nombre' => $r['nombre']], ip_visitante());
                db()->prepare('INSERT INTO registro_admin (admin_id, accion) VALUES (?, ?)')
                    ->execute([$id, mb_substr('Entró escribiendo su clave y código de 2 pasos (login del programa) · IP ' . ip_visitante(), 0, 300)]);
                session_regenerate_id(true);
                unset($_SESSION['usuario_id'], $_SESSION['intentos_login']);
                $_SESSION['admin_id'] = $id;
                redirigir('admin');
            }
            if ($r['codigo'] === 'codigo') {
                $pedir_codigo = true;
                $cuentaFallo = false;   // clave bien, solo falta el código: no cuenta como intento fallido
                $error = $r['error'];
            } elseif (in_array($r['codigo'], ['bloqueado', 'sin_2fa'], true)) {
                $error = $r['error'];
            }
        }
        if ($cuentaFallo) {
            $intentos['n']++;
            if ($intentos['n'] >= 5) {
                $intentos = ['n' => 0, 'hasta' => time() + 300];
            }
            $_SESSION['intentos_login'] = $intentos;
        }
    }
}
vista('login', ['error' => $error, 'email' => $email, 'pedir_codigo' => $pedir_codigo], 'Entrar · TuSalón');
