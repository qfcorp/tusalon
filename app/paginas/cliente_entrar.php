<?php
use TuSalon\CuentaCliente;

$slug = (string) ($_GET['s'] ?? '');
$st = db()->prepare('SELECT id, nombre, slug FROM salones WHERE slug = ?');
$st->execute([$slug]);
$salon = $st->fetch();
if (!$salon) {
    vista_publica('reservar_no', ['salon' => null], 'TuSalón');
    return;
}
$error = null;
$email = '';
if (es_post()) {
    verificar_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $intentos = $_SESSION['intentos_cliente'] ?? ['n' => 0, 'hasta' => 0];
    if ($intentos['hasta'] > time()) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } elseif ($id = (new CuentaCliente(db()))->entrar((int) $salon['id'], $email, (string) ($_POST['clave'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['cliente'][(int) $salon['id']] = $id;
        unset($_SESSION['intentos_cliente']);
        header('Location: /?r=' . (($_GET['volver'] ?? '') === 'reservar' ? 'reservar' : 'mi_cuenta') . '&s=' . rawurlencode($slug));
        exit;
    } else {
        $intentos['n']++;
        if ($intentos['n'] >= 5) $intentos = ['n' => 0, 'hasta' => time() + 300];
        $_SESSION['intentos_cliente'] = $intentos;
        $error = 'El correo o la contraseña no coinciden.';
    }
}
vista_publica('cliente_entrar', compact('salon', 'error', 'email'), 'Entrar · ' . $salon['nombre']);
