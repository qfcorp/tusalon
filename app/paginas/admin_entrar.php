<?php
/** Entrada del super administrador (Tukán). Las cuentas se crean en el servidor con bin/crear_admin.php */
if (superadmin()) redirigir('admin');
$error = null;
$email = '';
// TUKAN_SOLO_CENTRAL=1: el super usuario solo entra desde el Panel Tukán Central (emergencia: ponerlo en 0 y usar bin/crear_admin.php)
$soloCentral = \TuSalon\TukanCentral::soloCentral() ? \TuSalon\TukanCentral::urlCentral() : null;
if ($soloCentral) {
    if (es_post()) http_response_code(403);   // no se revisa ninguna contraseña
    vista_admin('admin_entrar', compact('error', 'email', 'soloCentral'), 'Entrar · Panel Tukán');
    return;
}
if (es_post()) {
    verificar_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $clave = (string) ($_POST['clave'] ?? '');
    // Freno: 5 fallos con ese correo en 15 minutos (aunque cambien de navegador) o en esta sesión
    $st = db()->prepare("SELECT count(*) FROM registro_admin WHERE accion = ? AND creado_en > now() - interval '15 minutes'");
    $st->execute(['Entrada fallida: ' . $email]);
    $fallos = (int) $st->fetchColumn();
    $sesion = $_SESSION['intentos_admin'] ?? ['n' => 0, 'hasta' => 0];
    if ($fallos >= 5 || $sesion['hasta'] > time()) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM superadmins WHERE email = ? AND activo');
        $st->execute([$email]);
        $a = $st->fetch();
        if ($a && password_verify($clave, $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $a['id'];
            unset($_SESSION['intentos_admin'], $_SESSION['desde_central']);
            db()->prepare('UPDATE superadmins SET ultimo_ingreso = now() WHERE id = ?')->execute([$a['id']]);
            redirigir('admin');
        }
        db()->prepare('INSERT INTO registro_admin (accion) VALUES (?)')->execute(['Entrada fallida: ' . mb_substr($email, 0, 120)]);
        $sesion['n']++;
        if ($sesion['n'] >= 5) $sesion = ['n' => 0, 'hasta' => time() + 900];
        $_SESSION['intentos_admin'] = $sesion;
        $error = 'El correo o la contraseña no coinciden.';
    }
}
vista_admin('admin_entrar', compact('error', 'email', 'soloCentral'), 'Entrar · Panel Tukán');
